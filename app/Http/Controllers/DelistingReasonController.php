<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Models\DelistingReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DelistingReasonController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', DelistingReason::class);
        $ready = Schema::hasTable('delisting_reasons');
        if (! $ready) {
            return view('delisting-reasons.index', ['ready' => false]);
        }
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'in:0,1'],
            'edit' => ['nullable', 'integer', 'exists:delisting_reasons,id'],
        ]);
        $editing = isset($filters['edit']) ? DelistingReason::findOrFail($filters['edit']) : null;
        if ($editing) {
            Gate::authorize('update', $editing);
        }
        $query = DelistingReason::query();
        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(Str::squish($filters['q']))).'%';
            $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", [$term]);
        }
        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', $filters['is_active']);
        }

        return view('delisting-reasons.index', [
            'ready' => true, 'filters' => $filters, 'editing' => $editing,
            'reasons' => $query->orderBy('name')->tablePaginate('delisting_reasons')->appends(['edit' => null, 'create' => null]),
        ]);
    }

    public function store(Request $request, RecordAudit $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(Request $request, DelistingReason $delistingReason, RecordAudit $audit)
    {
        return $this->save($request, $audit, $delistingReason);
    }

    private function save(Request $request, RecordAudit $audit, ?DelistingReason $reason = null)
    {
        Gate::authorize($reason ? 'update' : 'create', $reason ?? DelistingReason::class);
        abort_unless(Schema::hasTable('delisting_reasons'), 503, 'The delisting reasons registry migration has not been applied.');
        $name = is_string($request->input('name')) ? Str::squish($request->input('name')) : $request->input('name');
        $request->merge(['name' => $name, 'normalized_name' => is_string($name) ? Str::lower($name) : null]);
        try {
            $data = $request->validateWithBag('reasonForm', [
                'name' => ['required', 'string', 'max:255'],
                'normalized_name' => ['required', 'string', 'max:255', Rule::unique('delisting_reasons')->ignore($reason)],
                'is_active' => ['required', 'boolean'],
            ], ['normalized_name.unique' => 'This reason already exists, including inactive reasons. Edit the existing entry instead.']);
            DB::transaction(function () use ($reason, $data, $request, $audit) {
                $record = $reason ? DelistingReason::whereKey($reason->id)->lockForUpdate()->firstOrFail() : new DelistingReason;
                $before = $record->exists ? $record->only($record->getFillable()) : [];
                $record->fill($data)->save();
                $audit->record($request->user(), $record, $reason ? 'delisting_reason_updated' : 'delisting_reason_created', $before);
            }, 3);
        } catch (ValidationException $exception) {
            $messages = $exception->errors();
            if (isset($messages['normalized_name'])) {
                $messages['name'] = array_merge($messages['name'] ?? [], $messages['normalized_name']);
                unset($messages['normalized_name']);
            }
            throw ValidationException::withMessages($messages)->errorBag('reasonForm');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This reason already exists, including inactive reasons.'])->errorBag('reasonForm');
        }

        return to_route('delisting-reasons.index', ['q' => $request->query('q'), 'is_active' => $request->query('is_active')])
            ->with('status', 'Delisting reason saved. No scholarship status was changed.');
    }
}
