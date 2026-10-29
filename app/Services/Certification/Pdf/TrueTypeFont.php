<?php

namespace App\Services\Certification\Pdf;

/**
 * Reads advance widths from a TrueType file so the certificate renderer
 * can centre text. The font bytes are embedded unchanged.
 */
final class TrueTypeFont
{
    /** @var array<int, int> */
    private const WIN1252_EXTRA = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E,
        0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
        0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
        0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];

    public readonly int $unitsPerEm;

    public readonly int $ascent;

    public readonly int $descent;

    /** @var array{0: int, 1: int, 2: int, 3: int} */
    public readonly array $bbox;

    /** @var array<int, int> */
    private array $advanceByUnicode = [];

    private int $missingAdvance;

    public function __construct(public readonly string $bytes)
    {
        $tables = $this->tables($bytes);
        foreach (['cmap', 'hhea', 'hmtx', 'head'] as $required) {
            if (! isset($tables[$required])) {
                throw new \RuntimeException('Certificate font is missing the '.$required.' table.');
            }
        }

        [$head, $headLength] = $tables['head'];
        $this->unitsPerEm = $this->u16($bytes, $head + 18);
        $this->bbox = [
            $this->s16($bytes, $head + 36),
            $this->s16($bytes, $head + 38),
            $this->s16($bytes, $head + 40),
            $this->s16($bytes, $head + 42),
        ];
        unset($headLength);

        [$hhea] = $tables['hhea'];
        $this->ascent = $this->s16($bytes, $hhea + 4);
        $this->descent = $this->s16($bytes, $hhea + 6);
        $metrics = $this->u16($bytes, $hhea + 34);

        $glyphForUnicode = $this->cmapFormat4($bytes, $tables['cmap'][0]);
        [$hmtx] = $tables['hmtx'];
        $advances = [];
        for ($glyph = 0; $glyph < $metrics; $glyph++) {
            $advances[$glyph] = $this->u16($bytes, $hmtx + ($glyph * 4));
        }
        $last = $advances[$metrics - 1] ?? 500;
        $this->missingAdvance = $advances[0] ?? $last;

        foreach ($glyphForUnicode as $unicode => $glyph) {
            $this->advanceByUnicode[$unicode] = $advances[$glyph] ?? $last;
        }
    }

    public function pdfWidth(string $encoded, float $fontSize): float
    {
        $width = 0.0;
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $width += $this->advanceForByte(ord($encoded[$i]));
        }

        return $width * $fontSize / $this->unitsPerEm;
    }

    /**
     * @return list<int>
     */
    public function widthArray(): array
    {
        $widths = [];
        for ($byte = 32; $byte <= 255; $byte++) {
            $widths[] = (int) round($this->advanceForByte($byte) * 1000 / $this->unitsPerEm);
        }

        return $widths;
    }

    private function advanceForByte(int $byte): int
    {
        $unicode = $byte;
        if ($byte >= 0x80 && $byte <= 0x9F) {
            $unicode = self::WIN1252_EXTRA[$byte] ?? 0xFFFD;
        }

        return $this->advanceByUnicode[$unicode] ?? $this->missingAdvance;
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    private function tables(string $bytes): array
    {
        $count = $this->u16($bytes, 4);
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = 12 + ($i * 16);
            $tag = substr($bytes, $offset, 4);
            $tables[$tag] = [$this->u32($bytes, $offset + 8), $this->u32($bytes, $offset + 12)];
        }

        return $tables;
    }

    /**
     * @return array<int, int>
     */
    private function cmapFormat4(string $bytes, int $table): array
    {
        $subtables = $this->u16($bytes, $table + 2);
        $chosen = null;
        for ($i = 0; $i < $subtables; $i++) {
            $record = $table + 4 + ($i * 8);
            $platform = $this->u16($bytes, $record);
            $encoding = $this->u16($bytes, $record + 2);
            $offset = $table + $this->u32($bytes, $record + 4);
            if (($platform === 3 && $encoding === 1) || $platform === 0) {
                $chosen = $offset;
                if ($platform === 3) {
                    break;
                }
            }
        }
        if ($chosen === null || $this->u16($bytes, $chosen) !== 4) {
            throw new \RuntimeException('Certificate font has no usable character map.');
        }

        $segCount = (int) ($this->u16($bytes, $chosen + 6) / 2);
        $end = $chosen + 14;
        $start = $end + ($segCount * 2) + 2;
        $delta = $start + ($segCount * 2);
        $range = $delta + ($segCount * 2);
        $glyphArray = $range + ($segCount * 2);
        $map = [];
        for ($i = 0; $i < $segCount; $i++) {
            $endCode = $this->u16($bytes, $end + ($i * 2));
            $startCode = $this->u16($bytes, $start + ($i * 2));
            $idDelta = $this->s16($bytes, $delta + ($i * 2));
            $idRange = $this->u16($bytes, $range + ($i * 2));
            for ($code = $startCode; $code <= $endCode; $code++) {
                if ($idRange === 0) {
                    $glyph = ($idDelta + $code) & 0xFFFF;
                } else {
                    $index = (int) ($idRange / 2) - ($segCount - $i) + ($code - $startCode);
                    $glyph = $this->u16($bytes, $glyphArray + ($index * 2));
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $idDelta) & 0xFFFF;
                    }
                }
                $map[$code] = $glyph;
            }
        }

        return $map;
    }

    private function u16(string $bytes, int $offset): int
    {
        return unpack('n', substr($bytes, $offset, 2))[1];
    }

    private function s16(string $bytes, int $offset): int
    {
        $value = $this->u16($bytes, $offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(string $bytes, int $offset): int
    {
        return unpack('N', substr($bytes, $offset, 4))[1];
    }
}
