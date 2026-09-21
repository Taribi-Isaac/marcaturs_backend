<?php

namespace App\Services\Certification;

use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificationResourceStore
{
    /**
     * @return array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int}
     */
    public function store(CertificationProgrammeVersion $version, UploadedFile $file): array
    {
        $disk = (string) config('certification.resource_disk');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf(
            'certification/programmes/%d/versions/%d/resources/%s.%s',
            $version->programme_id,
            $version->id,
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

    public function deleteFile(CertificationResource $resource): void
    {
        if (! $resource->hasPrivateFile()) {
            return;
        }

        Storage::disk((string) $resource->disk)->delete((string) $resource->path);
    }

    public function stream(CertificationResource $resource): StreamedResponse
    {
        return Storage::disk((string) $resource->disk)->response(
            (string) $resource->path,
            (string) $resource->original_filename,
            ['Content-Type' => (string) ($resource->mime_type ?: 'application/octet-stream')],
        );
    }

    private function safeFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));

        return Str::limit($basename !== '' ? $basename : 'resource', 180, '');
    }
}
