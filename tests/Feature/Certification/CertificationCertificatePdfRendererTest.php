<?php

namespace Tests\Feature\Certification;

use App\Enums\CertificationCertificateStatus;
use App\Models\CertificationCertificate;
use App\Services\Certification\CertificationCertificatePdfRenderer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CertificationCertificatePdfRendererTest extends TestCase
{
    public function test_branded_pdf_keeps_snapshot_wording_and_omits_unapproved_marks(): void
    {
        $certificate = new CertificationCertificate;
        $certificate->forceFill([
            'recipient_name' => 'Sample Ambassador Chidi',
            'programme_name' => 'Professional Brand Ambassadorship & Marketing Certification',
            'programme_version_number' => 1,
            'certificate_number' => 'REVIEW-SAMPLE-C19',
            'issuer_name' => 'MarcatursHub',
            'status' => CertificationCertificateStatus::Issued,
        ]);
        $certificate->issued_at = Carbon::parse('2026-10-29 12:00:00', 'UTC');

        $pdf = app(CertificationCertificatePdfRenderer::class)->render($certificate);
        $content = $this->contentStream($pdf);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('/Im1 Do', $content);
        $this->assertStringContainsString('Sample Ambassador Chidi', $content);
        $this->assertStringContainsString('Professional Brand Ambassadorship & Marketing Certification', $content);
        $this->assertStringContainsString('Programme Version 1', $content);
        $this->assertStringContainsString('MarcatursHub', $content);
        $this->assertStringContainsString('REVIEW-SAMPLE-C19', $content);
        $this->assertStringContainsString('29 Oct 2026', $content);
        $this->assertStringContainsString('has successfully completed', $content);
        $this->assertStringContainsString('Status', $content);
        $this->assertStringNotContainsString('Private MarcatursHub certificate artifact', $content);
        $this->assertStringNotContainsString('Not a public verification document', $content);
        $this->assertStringNotContainsString('public verification', $content);
        $this->assertStringNotContainsString('Expires', $content);
        $this->assertStringNotContainsString('QR', $content);
        $this->assertStringNotContainsString('accreditation', $content);
        $this->assertStringNotContainsString('capstone', $content);
        $this->assertStringNotContainsString('Signature', $content);
        $this->assertStringNotContainsString('/Users/', $pdf);
        $this->assertStringNotContainsString('CEO-Signature', $pdf);
        $this->assertStringNotContainsString('brand-resources', $pdf);
    }

    private function contentStream(string $pdf): string
    {
        $this->assertSame(1, preg_match('/obj<< \/Length \d+ >>stream\n(.*?)\nendstream/s', $pdf, $matches));

        return $matches[1];
    }
}
