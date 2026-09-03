<?php

namespace App\Services\Verification;

use App\Models\VerificationEvidence;
use App\Models\VerificationSubmission;
use App\Models\VerificationSubmissionVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerificationEvidenceStore
{
    public function store(
        VerificationSubmission $submission,
        VerificationSubmissionVersion $version,
        UploadedFile $file,
    ): VerificationEvidence {
        $disk = (string) config('verification.evidence_disk');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf(
            'verification/%d/%d/%s.%s',
            $submission->user_id,
            $submission->id,
            Str::uuid(),
            $extension !== '' ? $extension : 'bin',
        );

        Storage::disk($disk)->put($path, $file->getContent());

        $evidence = new VerificationEvidence;
        $evidence->verification_submission_id = $submission->id;
        $evidence->verification_submission_version_id = $version->id;
        $evidence->disk = $disk;
        $evidence->path = $path;
        $evidence->original_filename = $this->safeFilename((string) $file->getClientOriginalName());
        $evidence->mime_type = (string) $file->getMimeType();
        $evidence->size_bytes = (int) $file->getSize();
        $evidence->save();

        return $evidence;
    }

    public function stream(VerificationEvidence $evidence)
    {
        return Storage::disk($evidence->disk)->response(
            $evidence->path,
            $evidence->original_filename,
            ['Content-Type' => $evidence->mime_type],
        );
    }

    private function safeFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));

        return Str::limit($basename !== '' ? $basename : 'evidence', 180, '');
    }
}
