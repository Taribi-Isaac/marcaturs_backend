<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignCover;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignCoverStore
{
    /**
     * @return array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int}
     */
    public function store(Campaign $campaign, UploadedFile $file): array
    {
        $disk = (string) config('campaigns.media_disk');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf(
            'campaigns/%d/cover/%s.%s',
            $campaign->id,
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

    public function deleteFile(?string $disk, ?string $path): void
    {
        if ($disk === null || $path === null || $path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    public function stream(CampaignCover $cover): StreamedResponse
    {
        return Storage::disk($cover->disk)->response(
            $cover->path,
            $cover->original_filename,
            ['Content-Type' => $cover->mime_type],
        );
    }

    private function safeFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));

        return Str::limit($basename !== '' ? $basename : 'cover', 180, '');
    }
}
