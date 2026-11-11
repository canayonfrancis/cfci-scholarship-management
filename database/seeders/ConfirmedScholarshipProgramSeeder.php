<?php

namespace Database\Seeders;

use App\Models\AuditEvent;
use App\Models\ScholarshipProgram;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicit one-time adoption of the user's confirmed reference values, not semester authorization. */
class ConfirmedScholarshipProgramSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([['TES', 'TES', '7500.00'], ['TDP', 'TDP', '13500.00'], ['COSCHO', 'CoScho', '45000.00']] as [$code, $name, $amount]) {
                $program = ScholarshipProgram::firstOrNew(['code' => $code]);
                if ($program->exists && AuditEvent::where('action', 'confirmed_program_reference_adopted')->where('subject_type', ScholarshipProgram::class)->where('subject_id', $program->id)->exists()) {
                    continue;
                }
                $before = $program->exists ? $program->only($program->getFillable()) : [];
                if (! $program->exists) {
                    $program->name = $name;
                    $program->is_active = true;
                }
                $program->school_stated_semester_amount = $amount;
                $program->source_notes = trim(($program->source_notes ?? '')."\n[confirmed-allocation-policy-2026-11-11] User-confirmed per-semester reference amount. Supersedes earlier TES/TDP reference figures; semester rate approval and existing allocations remain separate.");
                $program->save();
                AuditEvent::create([
                    'actor_snapshot' => ['source' => 'Trusted console adoption of the user-confirmed allocation policy, 2026-11-11'],
                    'action' => 'confirmed_program_reference_adopted', 'subject_type' => ScholarshipProgram::class, 'subject_id' => $program->id,
                    'occurred_at' => now(), 'before_state' => $before, 'after_state' => $program->only($program->getFillable()),
                ]);
            }
        });
    }
}
