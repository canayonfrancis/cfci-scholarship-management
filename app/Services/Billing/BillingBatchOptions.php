<?php

namespace App\Services\Billing;

use App\Models\BillingBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BillingBatchOptions
{
    public function query(array $filters): Builder
    {
        $query = BillingBatch::query()
            ->when($filters['program_id'] ?? null, fn ($query, $id) => $query->where('program_id', $id))
            ->when($filters['academic_period_id'] ?? null, fn ($query, $id) => $query->where('academic_period_id', $id))
            ->when($filters['academic_year'] ?? null, fn ($query, $year) => $query->whereHas('academicPeriod', fn ($period) => $period->where('academic_year', $year)));

        if (($filters['status'] ?? null) === 'approved') {
            $query->where(fn ($query) => $query->whereHas('approval')->orWhere('status', 'finalized'));
        } elseif (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
            if ($filters['status'] === 'submitted') {
                $query->whereDoesntHave('approval');
            }
        }

        return $query;
    }

    public function forScope(array $filters): Collection
    {
        $batches = $this->query($filters)
            ->with(['program:id,code', 'academicPeriod:id,academic_year,semester_code'])
            ->get(['id', 'batch_number', 'program_id', 'academic_period_id']);
        $labels = $batches->mapWithKeys(fn ($batch) => [$batch->id => $this->numberLabel($batch).' - '.$batch->program->code]);
        $duplicates = $labels->map(fn ($label) => mb_strtolower($label))->countBy();

        return $batches->sort(function ($first, $second) {
            return strnatcasecmp($this->numberSortKey($first), $this->numberSortKey($second))
                ?: strcasecmp($first->program->code, $second->program->code)
                ?: strcmp($second->academicPeriod->academic_year, $first->academicPeriod->academic_year)
                ?: strnatcasecmp($first->academicPeriod->semester_code, $second->academicPeriod->semester_code)
                ?: $first->id <=> $second->id;
        })->map(function ($batch) use ($labels, $duplicates) {
            $label = $labels[$batch->id];
            if ($duplicates[mb_strtolower($label)] > 1) {
                $label .= ' ('.$batch->academicPeriod->academic_year.' / Semester '.$batch->academicPeriod->semester_code.')';
            }

            return ['id' => $batch->id, 'label' => $label];
        })->values();
    }

    private function numberLabel(BillingBatch $batch): string
    {
        $label = trim($batch->batch_number);

        return preg_match('/^(?:Batch\s+)?([0-9]+)$/iD', $label, $matches) ? 'Batch '.$matches[1] : $label;
    }

    private function numberSortKey(BillingBatch $batch): string
    {
        $label = $this->numberLabel($batch);

        return preg_match('/^Batch ([0-9]+)$/D', $label, $matches) ? (ltrim($matches[1], '0') ?: '0') : $label;
    }
}
