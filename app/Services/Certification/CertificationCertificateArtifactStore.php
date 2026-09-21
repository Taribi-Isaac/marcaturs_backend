<?php

namespace App\Services\Certification;

use App\Models\CertificationCertificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificationCertificateArtifactStore
{
    /**
     * @return array{disk: string, path: string}
     */
    public function store(CertificationCertificate $certificate, string $pdfBinary): array
    {
        $disk = (string) config('certification.certificate_disk');
        $path = sprintf(
            'certification/certificates/%d/%s.pdf',
            $certificate->id,
            Str::lower((string) Str::ulid()),
        );

        Storage::disk($disk)->put($path, $pdfBinary);

        return [
            'disk' => $disk,
            'path' => $path,
        ];
    }

    public function deleteIfPresent(?string $disk, ?string $path): void
    {
        if ($disk === null || $disk === '' || $path === null || $path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    public function stream(CertificationCertificate $certificate, string $downloadName): StreamedResponse
    {
        $response = Storage::disk((string) $certificate->artifact_disk)->response(
            (string) $certificate->artifact_path,
            $downloadName,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ],
        );

        return $response;
    }

    public function exists(CertificationCertificate $certificate): bool
    {
        if ($certificate->artifact_disk === null || $certificate->artifact_path === null) {
            return false;
        }

        return Storage::disk((string) $certificate->artifact_disk)->exists((string) $certificate->artifact_path);
    }
}
