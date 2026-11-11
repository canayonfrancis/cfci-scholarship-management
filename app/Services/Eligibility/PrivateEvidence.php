<?php

namespace App\Services\Eligibility;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PrivateEvidence
{
    public function store(Model $owner, User $actor, UploadedFile $file, string $type, bool $verified = false): Document
    {
        $path = $file->store('evidence', 'scholarship');
        try {
            return $owner->documents()->create([
                'disk' => 'scholarship', 'storage_path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'document_type' => $type, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $actor->id,
                'review_status' => $verified ? 'accepted' : 'pending', 'reviewed_by' => $verified ? $actor->id : null, 'reviewed_at' => $verified ? now() : null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('scholarship')->delete($path);
            throw $exception;
        }
    }

    public function usable(Document $document): bool
    {
        if ($document->disk !== 'scholarship' || ! str_starts_with($document->storage_path, 'evidence/') ||
            $document->review_status !== 'accepted' || ! $document->reviewed_by || ! $document->reviewed_at || ! $document->checksum) {
            return false;
        }
        $disk = Storage::disk('scholarship');
        if (! $disk->exists($document->storage_path)) {
            return false;
        }
        $stream = $disk->readStream($document->storage_path);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_equals($document->checksum, hash_final($hash));
        } finally {
            fclose($stream);
        }
    }

    public function snapshot(Document $document): array
    {
        return $document->only(['id', 'document_type', 'checksum', 'reviewed_by', 'reviewed_at']);
    }
}
