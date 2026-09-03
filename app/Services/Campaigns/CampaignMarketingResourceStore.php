<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignMarketingResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignMarketingResourceStore
{
    public function store(Campaign $campaign, UploadedFile $file): array
    {
        $disk = (string) config('campaigns.media_disk');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf(
            'campaigns/%d/resources/%s.%s',
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

    public function replace(CampaignMarketingResource $resource, UploadedFile $file): array
    {
        $resource->loadMissing('campaign');
        $stored = $this->store($resource->campaign, $file);
        $this->deleteFile($resource);

        return $stored;
    }

    public function deleteFile(CampaignMarketingResource $resource): void
    {
        Storage::disk($resource->disk)->delete($resource->path);
    }

    public function stream(CampaignMarketingResource $resource): StreamedResponse
    {
        return Storage::disk($resource->disk)->response(
            $resource->path,
            $resource->original_filename,
            ['Content-Type' => $resource->mime_type],
        );
    }

    private function safeFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));

        return Str::limit($basename !== '' ? $basename : 'resource', 180, '');
    }
}
