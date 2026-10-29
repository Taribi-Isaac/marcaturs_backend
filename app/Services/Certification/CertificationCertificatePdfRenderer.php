<?php

namespace App\Services\Certification;

use App\Models\CertificationCertificate;
use App\Services\Certification\Pdf\TrueTypeFont;

/**
 * Branded landscape certificate for an existing Certificate snapshot.
 *
 * D11 approved this visual design. D12 Option B approved the completion
 * sentence "has successfully completed". The internal artifact footer stays
 * off the page. This writer adds no further claim, CEO signature, expiry
 * line, or public verification mark.
 *
 * No PDF package is added. This writer embeds the Syne and Figtree files the
 * participant interface already uses, plus the white-background logo.
 */
class CertificationCertificatePdfRenderer
{
    private const WIDTH = 842.0;

    private const HEIGHT = 595.0;

    /**
     * Issuance wording approved under D12. Keep these isolated from the layout.
     */
    private const WORD_TITLE = 'CERTIFICATE';

    private const WORD_CERTIFIES = 'This certifies that';

    private const WORD_COMPLETED = 'has successfully completed';

    /** @var array{0: float, 1: float, 2: float} */
    private const IVORY = [0.957, 0.937, 0.894];

    /** @var array{0: float, 1: float, 2: float} */
    private const EVERGREEN = [0.047, 0.361, 0.310];

    /** @var array{0: float, 1: float, 2: float} */
    private const INK = [0.047, 0.086, 0.075];

    /** @var array{0: float, 1: float, 2: float} */
    private const MUTED = [0.325, 0.388, 0.369];

    public function render(CertificationCertificate $certificate): string
    {
        $display = new TrueTypeFont((string) file_get_contents($this->asset('fonts/Syne-Bold.ttf')));
        $body = new TrueTypeFont((string) file_get_contents($this->asset('fonts/Figtree-Regular.ttf')));
        $emphasis = new TrueTypeFont((string) file_get_contents($this->asset('fonts/Figtree-SemiBold.ttf')));
        $logo = $this->logoSamples($this->asset('logo-mark.png'));

        $ops = [];
        $this->fill($ops, 0, 0, self::WIDTH, self::HEIGHT, self::IVORY);
        $this->strokeRect($ops, 28, 28, 786, 539, 1.7);
        $this->strokeRect($ops, 36, 36, 770, 523, 0.7);

        $logoHeight = 46.0;
        $logoWidth = $logoHeight * ($logo['width'] / $logo['height']);
        $ops[] = $this->image($logoWidth, $logoHeight, (self::WIDTH - $logoWidth) / 2, 478);

        $this->text($ops, $display, 'F1', 12, 452, self::WORD_TITLE, self::EVERGREEN, 0.34);
        $this->text($ops, $body, 'F2', 13, 428, self::WORD_CERTIFIES, self::MUTED);
        $this->text($ops, $display, 'F1', $this->fitSize($display, (string) $certificate->recipient_name, 30, 700), 390, (string) $certificate->recipient_name, self::INK);

        $rule = 168.0;
        $ops[] = sprintf('%.3F %.3F %.3F RG', ...self::EVERGREEN);
        $ops[] = '0.8 w';
        $ops[] = sprintf('%.2F 374 m %.2F 374 l S', (self::WIDTH - $rule) / 2, (self::WIDTH + $rule) / 2);

        $this->text($ops, $body, 'F2', 13, 348, self::WORD_COMPLETED, self::INK);
        $programmeSize = $this->fitSize($display, (string) $certificate->programme_name, 16, 720);
        $this->text($ops, $display, 'F1', $programmeSize, 322, (string) $certificate->programme_name, self::EVERGREEN);
        $version = 'Programme Version '.(int) $certificate->programme_version_number.'  ·  '.(string) $certificate->issuer_name;
        $this->text($ops, $body, 'F2', 12, 298, $version, self::MUTED);

        $issued = $certificate->issued_at?->timezone(config('app.timezone'))->format('d M Y') ?? '';
        $meta = [
            ['Certificate ID', (string) $certificate->certificate_number],
            ['Issued', $issued],
            ['Status', 'Issued'],
        ];
        $y = 132.0;
        foreach ($meta as [$label, $value]) {
            $this->pair($ops, 118, $y, $label, $value);
            $y -= 22;
        }

        $content = implode("\n", $ops);
        $objects = [];
        $contentId = 4;
        $objects[$contentId] = '<< /Length '.strlen($content).' >>stream'."\n".$content."\n".'endstream';

        $fontIds = [
            'F1' => $this->fontObjects($objects, $display, 'SyneBold'),
            'F2' => $this->fontObjects($objects, $body, 'Figtree'),
            'F3' => $this->fontObjects($objects, $emphasis, 'FigtreeSemibold'),
        ];
        $imageId = $this->imageObjects($objects, $logo);

        $fontRefs = '';
        foreach ($fontIds as $name => $id) {
            $fontRefs .= "/{$name} {$id} 0 R ";
        }
        $resources = "<< /Font<< {$fontRefs}>> /XObject<< /Im1 {$imageId} 0 R >> >>";
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.(int) self::WIDTH.' '.(int) self::HEIGHT.'] /Contents 4 0 R /Resources '.$resources.' >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        return $this->assemble($objects);
    }

    private function asset(string $name): string
    {
        $path = resource_path('certification/'.$name);
        if (! is_file($path)) {
            throw new \RuntimeException('Certificate asset is missing.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $ops
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private function fill(array &$ops, float $x, float $y, float $width, float $height, array $rgb): void
    {
        $ops[] = sprintf('q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q', $rgb[0], $rgb[1], $rgb[2], $x, $y, $width, $height);
    }

    /**
     * @param  list<string>  $ops
     */
    private function strokeRect(array &$ops, float $x, float $y, float $width, float $height, float $stroke): void
    {
        $ops[] = sprintf(
            'q %.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S Q',
            self::EVERGREEN[0],
            self::EVERGREEN[1],
            self::EVERGREEN[2],
            $stroke,
            $x,
            $y,
            $width,
            $height,
        );
    }

    private function image(float $width, float $height, float $x, float $y): string
    {
        return sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $width, $height, $x, $y);
    }

    /**
     * @param  list<string>  $ops
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private function text(
        array &$ops,
        TrueTypeFont $font,
        string $fontId,
        float $size,
        float $y,
        string $text,
        array $rgb,
        float $tracking = 0,
    ): void {
        $encoded = $this->encode($text);
        $width = $font->pdfWidth($encoded, $size) + ($tracking * $size * max(0, strlen($encoded) - 1));
        $x = max(48.0, (self::WIDTH - $width) / 2);
        $ops[] = sprintf('BT %.3F %.3F %.3F rg /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm %s ET', $rgb[0], $rgb[1], $rgb[2], $fontId, $size, $x, $y, $this->show($encoded, $tracking));
    }

    /**
     * @param  list<string>  $ops
     */
    private function pair(array &$ops, float $x, float $y, string $label, string $value): void
    {
        $ops[] = sprintf(
            'BT %.3F %.3F %.3F rg /F2 11 Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            self::MUTED[0],
            self::MUTED[1],
            self::MUTED[2],
            $x,
            $y,
            $this->escape($this->encode($label)),
        );
        $ops[] = sprintf(
            'BT %.3F %.3F %.3F rg /F3 12 Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            self::INK[0],
            self::INK[1],
            self::INK[2],
            $x + 150,
            $y,
            $this->escape($this->encode($value)),
        );
    }

    private function fitSize(TrueTypeFont $font, string $text, float $preferred, float $maxWidth): float
    {
        $size = $preferred;
        while ($size > 12 && $font->pdfWidth($this->encode($text), $size) > $maxWidth) {
            $size -= 0.5;
        }

        return $size;
    }

    private function show(string $encoded, float $tracking): string
    {
        if ($tracking <= 0 || strlen($encoded) < 2) {
            return '('.$this->escape($encoded).') Tj';
        }

        $adjustment = (string) (int) round($tracking * -1000);
        $parts = [];
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $parts[] = '('.$this->escape($encoded[$i]).')';
            if ($i < $length - 1) {
                $parts[] = $adjustment;
            }
        }

        return '['.implode(' ', $parts).'] TJ';
    }

    private function encode(string $value): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        }

        return $converted;
    }

    private function escape(string $encoded): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function fontObjects(array &$objects, TrueTypeFont $font, string $baseName): int
    {
        $compressed = gzcompress($font->bytes);
        $fileId = $this->nextId($objects);
        $objects[$fileId] = '<< /Length '.strlen($compressed).' /Length1 '.strlen($font->bytes).' /Filter /FlateDecode >>stream'."\n".$compressed."\n".'endstream';

        $descriptorId = $this->nextId($objects);
        $objects[$descriptorId] = sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $baseName,
            $font->bbox[0],
            $font->bbox[1],
            $font->bbox[2],
            $font->bbox[3],
            $font->ascent,
            $font->descent,
            $font->ascent,
            $fileId,
        );

        $widths = implode(' ', $font->widthArray());
        $fontId = $this->nextId($objects);
        $objects[$fontId] = "<< /Type /Font /Subtype /TrueType /BaseFont /{$baseName} /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 255 /Widths [{$widths}] /FontDescriptor {$descriptorId} 0 R >>";

        return $fontId;
    }

    /**
     * @param  array<int, string>  $objects
     * @param  array{width: int, height: int, rgb: string, alpha: string}  $logo
     */
    private function imageObjects(array &$objects, array $logo): int
    {
        $alpha = gzcompress($logo['alpha']);
        $maskId = $this->nextId($objects);
        $objects[$maskId] = "<< /Type /XObject /Subtype /Image /Width {$logo['width']} /Height {$logo['height']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Length ".strlen($alpha).' /Filter /FlateDecode >>stream'."\n".$alpha."\n".'endstream';

        $rgb = gzcompress($logo['rgb']);
        $imageId = $this->nextId($objects);
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$logo['width']} /Height {$logo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /SMask {$maskId} 0 R /Length ".strlen($rgb).' /Filter /FlateDecode >>stream'."\n".$rgb."\n".'endstream';

        return $imageId;
    }

    /**
     * @return array{width: int, height: int, rgb: string, alpha: string}
     */
    private function logoSamples(string $path): array
    {
        $image = imagecreatefrompng($path);
        if ($image === false) {
            throw new \RuntimeException('Certificate logo could not be read.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        $rgb = '';
        $alpha = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $rgb .= chr(($color >> 16) & 255).chr(($color >> 8) & 255).chr($color & 255);
                $gdAlpha = ($color >> 24) & 127;
                $alpha .= chr((int) round((127 - $gdAlpha) * 255 / 127));
            }
        }
        imagedestroy($image);

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb, 'alpha' => $alpha];
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function nextId(array $objects): int
    {
        $id = 5;
        while (isset($objects[$id])) {
            $id++;
        }

        return $id;
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function assemble(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $max = max(array_keys($objects));
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id.' 0 obj'.$body."endobj\n";
        }
        $xref = strlen($pdf);
        $count = $max + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
