<?php

namespace App\Services\Disputes;

use App\Models\Dispute;
use App\Models\DisputeAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisputeAttachmentStore
{
    /**
     * @return array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int}
     */
    public function store(Dispute $dispute, UploadedFile $file): array
    {
        $disk = (string) config('disputes.attachment_disk');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf(
            'disputes/%d/attachments/%s.%s',
            $dispute->id,
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

    public function stream(DisputeAttachment $attachment): StreamedResponse
    {
        return Storage::disk((string) $attachment->disk)->response(
            (string) $attachment->path,
            $attachment->original_filename ?: 'dispute-attachment',
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }

    private function safeFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));

        return Str::limit($basename !== '' ? $basename : 'attachment', 180, '');
    }
}
