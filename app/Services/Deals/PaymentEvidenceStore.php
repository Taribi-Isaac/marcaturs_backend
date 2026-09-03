<?php

namespace App\Services\Deals;

use App\Models\Deal;
use App\Models\PaymentEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentEvidenceStore
{
    /**
     * @return array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int}
     */
    public function store(Deal $deal, UploadedFile $file): array
    {
        $disk = (string) config('deals.payment_evidence_disk');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf(
            'deals/%d/payment-evidence/%s.%s',
            $deal->id,
            Str::uuid(),
            $extension !== '' ? $extension : 'bin',
        );

        Storage::disk($disk)->put($path, $file->getContent());

        return [
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $this->safeFilename((string) $file->getClientOriginalName()),
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    public function deleteStored(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    public function stream(PaymentEvidence $evidence): StreamedResponse
    {
        return Storage::disk((string) $evidence->disk)->response(
            (string) $evidence->path,
            $evidence->original_filename ?: 'payment-evidence',
            ['Content-Type' => $evidence->mime_type ?: 'application/octet-stream'],
        );
    }

    private function safeFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));

        return Str::limit($basename !== '' ? $basename : 'evidence', 180, '');
    }
}
