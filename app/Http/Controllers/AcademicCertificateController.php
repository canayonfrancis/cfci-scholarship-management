<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Protected Registrar sources; enrollment writes remain scoped to the billing workspace. */
class AcademicCertificateController extends Controller
{
    public function __invoke(AcademicRecord $record, Document $document): StreamedResponse
    {
        Gate::authorize('view', $record);
        abort_unless($document->academic_record_id === $record->id && $document->disk === 'academic' && in_array($document->document_type, ['grade_certificate', 'enrollment_certificate'], true) && str_starts_with($document->storage_path, 'certificates/'.$record->id.'/'), 404);
        $disk = Storage::disk('academic');
        abort_unless($document->checksum && $disk->exists($document->storage_path), 404);
        $stream = $disk->readStream($document->storage_path);
        abort_unless(is_resource($stream), 404);
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $matches = hash_equals($document->checksum, hash_final($hash));
        } finally {
            fclose($stream);
        }
        abort_unless($matches, 404);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $document->original_name))) ?: 'certificate';

        return $disk->download($document->storage_path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
