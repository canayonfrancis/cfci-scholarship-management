<?php

namespace App\Services\Records;

use App\Actions\Records\RecordAudit;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScholarImports
{
    public function __construct(private ScholarSpreadsheet $spreadsheet, private ScholarImportValidator $validator) {}

    public function preview(User $actor, UploadedFile $file): string
    {
        Gate::forUser($actor)->authorize('create', Student::class);
        $id = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true) || ! $file->isValid() || $file->getSize() > config('scholar-imports.max_upload_kb') * 1024) {
            $this->invalid('Use a valid .xlsx or .csv upload within the configured file size limit.');
        }
        $disk = Storage::disk('scholar-imports');
        try {
            $stored = $file->storeAs($id, 'source.'.$extension, 'scholar-imports');
            if (! is_string($stored)) {
                throw new \RuntimeException('Cannot retain scholar import.');
            }
            $path = $disk->path($stored);
            $result = $this->validator->validate($this->spreadsheet->read($path, $extension));
            $state = [
                'owner_id' => $actor->id, 'extension' => $extension, 'hash' => hash_file('sha256', $path),
                'expires_at' => now()->addMinutes(config('scholar-imports.preview_minutes'))->timestamp,
                'result' => $result,
            ];
            $this->save($id, $state);

            return $id;
        } catch (\Throwable $e) {
            $disk->deleteDirectory($id);
            throw $e;
        }
    }

    public function state(User $actor, string $id, bool $allowExpired = false): array
    {
        $this->identifier($id);
        $disk = Storage::disk('scholar-imports');
        if (! $disk->exists($id.'/preview.enc')) {
            $this->invalid('This upload is unavailable. Upload the file again to generate a preview.');
        }
        $state = json_decode(Crypt::decryptString($disk->get($id.'/preview.enc')), true, flags: JSON_THROW_ON_ERROR);
        abort_unless((int) $state['owner_id'] === (int) $actor->id, 404);
        if (! $allowExpired && $state['expires_at'] <= now()->timestamp) {
            $this->invalid('This preview has expired. Upload the file again for fresh validation.');
        }

        return $state;
    }

    public function receipt(User $actor, string $id): ?array
    {
        $this->identifier($id);

        return AuditEvent::where('actor_id', $actor->id)->where('action', 'scholar_bulk_imported')
            ->where('correlation_id', $id)->first()?->after_state;
    }

    public function confirm(User $actor, string $id): array
    {
        Gate::forUser($actor)->authorize('create', Student::class);
        $this->identifier($id);
        if ($receipt = $this->receipt($actor, $id)) {
            return $receipt;
        }

        return $this->locked($id, function () use ($actor, $id) {
            $state = $this->state($actor, $id);
            $source = Storage::disk('scholar-imports')->path($id.'/source.'.$state['extension']);
            if (! is_file($source) || ! hash_equals($state['hash'], hash_file('sha256', $source))) {
                $this->invalid('The retained file has changed or is missing. Upload it again.');
            }
            $freshResult = null;
            try {
                $receipt = DB::transaction(function () use ($actor, $id, $state, $source, &$freshResult) {
                    // Serialize this actor's confirmations and recheck roles inside the transaction.
                    $freshActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                    $freshActor->setRelation('roles', $freshActor->roles()->lockForUpdate()->get());
                    Gate::forUser($freshActor)->authorize('create', Student::class);
                    if ($receipt = $this->receipt($freshActor, $id)) {
                        return $receipt;
                    }
                    if ($state['expires_at'] <= now()->timestamp) {
                        $this->invalid('This preview has expired. Upload the file again.');
                    }
                    $freshResult = $this->validator->validate($this->spreadsheet->read($source, $state['extension']), true);
                    if ($freshResult['invalid'] > 0 || ! hash_equals($state['result']['fingerprint'], $freshResult['fingerprint'])) {
                        $this->invalid('Validation changed or errors remain. Review the refreshed preview, then correct and upload the file again if needed.');
                    }
                    $audit = app(RecordAudit::class);
                    $firstStudentId = null;
                    foreach ($freshResult['rows'] as $row) {
                        $student = Student::create($row['attributes']);
                        if (! $student->exists) {
                            throw new \RuntimeException('Scholar creation was canceled.');
                        }
                        $saved = $student->fresh();
                        if ($saved === null || $saved->user_id !== null) {
                            throw new \RuntimeException('Scholar identity postcondition failed.');
                        }
                        foreach ($row['attributes'] as $field => $expected) {
                            $actual = $saved->getAttribute($field);
                            if (($expected === null && $actual !== null) || ($expected !== null && (string) $actual !== (string) $expected)) {
                                throw new \RuntimeException('Scholar import field postcondition failed.');
                            }
                        }
                        $firstStudentId ??= $student->id;
                        $audit->record($freshActor, $student, 'scholar_created', reason: 'Bulk scholar upload '.$id);
                        if (! AuditEvent::where('actor_id', $freshActor->id)->where('subject_type', Student::class)
                            ->where('subject_id', $student->id)->where('action', 'scholar_created')->exists()) {
                            throw new \RuntimeException('Scholar audit could not be saved.');
                        }
                    }
                    $receipt = ['total' => $freshResult['total'], 'imported' => $freshResult['total'], 'duplicates' => 0, 'invalid' => 0, 'status' => 'completed'];
                    $event = AuditEvent::create([
                        'actor_id' => $freshActor->id, 'actor_snapshot' => ['name' => $freshActor->name, 'email' => $freshActor->email],
                        'action' => 'scholar_bulk_imported', 'subject_type' => Student::class, 'subject_id' => $firstStudentId, 'occurred_at' => now(),
                        'correlation_id' => $id, 'after_state' => $receipt,
                    ]);
                    if (! $event->exists) {
                        throw new \RuntimeException('Import receipt could not be saved.');
                    }

                    return $receipt;
                }, 3);
            } catch (UniqueConstraintViolationException) {
                $state['result'] = $this->validator->validate($this->spreadsheet->read($source, $state['extension']));
                $this->save($id, $state);
                $this->invalid('A Student ID was registered while this import was processing. No part of this batch was saved. Review the updated duplicate rows.');
            } catch (ValidationException $e) {
                if ($freshResult !== null) {
                    $state['result'] = $freshResult;
                    $this->save($id, $state);
                }
                throw $e;
            }
            // The durable audit receipt allows safe retries even if temporary cleanup fails.
            try {
                Storage::disk('scholar-imports')->deleteDirectory($id);
            } catch (\Throwable) {
                report(new \RuntimeException('Completed scholar import temporary-file cleanup requires attention.'));
            }

            return $receipt;
        });
    }

    public function cancel(User $actor, string $id): void
    {
        $this->locked($id, function () use ($actor, $id) {
            $this->state($actor, $id, allowExpired: true);
            Storage::disk('scholar-imports')->deleteDirectory($id);
        });
    }

    public function cleanup(): int
    {
        $disk = Storage::disk('scholar-imports');
        $removed = 0;
        foreach ($disk->directories() as $id) {
            if (! Str::isUuid($id)) {
                continue;
            }
            try {
                $this->locked($id, function () use ($disk, $id, &$removed) {
                    $expired = filemtime($disk->path($id)) < now()->subMinutes(config('scholar-imports.preview_minutes') + 5)->timestamp;
                    if ($disk->exists($id.'/preview.enc')) {
                        try {
                            $expired = json_decode(Crypt::decryptString($disk->get($id.'/preview.enc')), true, flags: JSON_THROW_ON_ERROR)['expires_at'] < now()->timestamp;
                        } catch (\Throwable) {
                            // Invalidated encryption keys/corrupted previews still age out safely.
                        }
                    }
                    if ($expired) {
                        $disk->deleteDirectory($id);
                        $removed++;
                    }
                });
            } catch (ValidationException) {
                // An active import is left for the next scheduled cleanup.
            }
        }

        return $removed;
    }

    private function locked(string $id, callable $callback): mixed
    {
        $this->identifier($id);
        $disk = Storage::disk('scholar-imports');
        if (! $disk->directoryExists($id)) {
            $this->invalid('This upload is unavailable. Upload the file again.');
        }
        // Bounded stable lock files survive removal of individual upload directories.
        $disk->makeDirectory('.locks');
        $handle = fopen($disk->path('.locks/'.substr(hash('sha256', $id), 0, 2).'.lock'), 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot lock scholar import.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                $this->invalid('An import operation is currently processing. Wait, then try again or refresh the Scholars page.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function save(string $id, array $state): void
    {
        $disk = Storage::disk('scholar-imports');
        $path = $disk->path($id.'/preview.enc');
        $temporary = $path.'.'.Str::random(12).'.tmp';
        try {
            if (file_put_contents($temporary, Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), LOCK_EX) === false) {
                throw new \RuntimeException('Cannot save scholar preview.');
            }
            @chmod($temporary, 0600);
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish scholar preview.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function identifier(string $id): void
    {
        abort_unless(Str::isUuid($id), 404);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
