<?php

namespace App\Services\Certification;

use App\Models\CertificationCertificate;

/**
 * Deterministic, dependency-free PDF renderer for Certificate snapshots.
 *
 * Uses only authoritative Certificate fields. Legal/branded certificate
 * wording and visual design remain Product/Counsel open decisions.
 *
 * Packagist was unavailable in the engineering environment; a minimal PDF 1.4
 * writer avoids introducing an uninstalled third-party package.
 */
class CertificationCertificatePdfRenderer
{
    public function render(CertificationCertificate $certificate): string
    {
        $width = 842.0;
        $height = 595.0;

        $ops = ['BT'];
        $ops = array_merge($ops, $this->centered('F2', 14, $width, 520, $certificate->issuer_name));
        $ops = array_merge($ops, $this->centered('F1', 28, $width, 460, 'Certificate'));
        $ops = array_merge($ops, $this->centered('F2', 12, $width, 420, 'This certifies that'));
        $ops = array_merge($ops, $this->centered('F1', 22, $width, 380, $certificate->recipient_name));
        $ops = array_merge($ops, $this->centered('F2', 12, $width, 340, 'has successfully completed'));
        $ops = array_merge($ops, $this->centered('F1', 16, $width, 310, $certificate->programme_name));
        $ops = array_merge($ops, $this->centered(
            'F2',
            12,
            $width,
            280,
            'Programme Version '.(int) $certificate->programme_version_number,
        ));
        $ops = array_merge($ops, $this->centered(
            'F2',
            12,
            $width,
            220,
            'Certificate ID: '.$certificate->certificate_number,
        ));
        $ops = array_merge($ops, $this->centered(
            'F2',
            12,
            $width,
            198,
            'Issued: '.($certificate->issued_at?->timezone(config('app.timezone'))->format('d M Y') ?? ''),
        ));
        $ops = array_merge($ops, $this->centered('F2', 12, $width, 176, 'Status: Issued'));
        $ops = array_merge($ops, $this->centered(
            'F2',
            10,
            $width,
            120,
            'Private MarcatursHub certificate artifact. Not a public verification document.',
        ));
        $ops[] = 'ET';

        $content = implode("\n", $ops);
        $contentLength = strlen($content);

        $objects = [];
        $objects[] = '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj';
        $objects[] = '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj';
        $objects[] = '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
            .(int) $width.' '.(int) $height.'] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R /F2 6 0 R >> >> >>endobj';
        $objects[] = '4 0 obj<< /Length '.$contentLength.' >>stream'."\n".$content."\n".'endstream endobj';
        $objects[] = '5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>endobj';
        $objects[] = '6 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xrefPosition = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPosition}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return list<string>
     */
    private function centered(string $font, int $size, float $pageWidth, float $y, string $text): array
    {
        $escaped = $this->escape($text);
        $approxWidth = strlen($escaped) * $size * 0.5;
        $x = max(36.0, ($pageWidth - $approxWidth) / 2);

        return [
            "/{$font} {$size} Tf",
            sprintf('1 0 0 1 %.2F %.2F Tm', $x, $y),
            '('.$escaped.') Tj',
        ];
    }

    private function escape(string $value): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}
