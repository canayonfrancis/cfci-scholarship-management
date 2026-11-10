<?php

namespace Database\Seeders;

use App\Models\ScholarshipProgram;
use Illuminate\Database\Seeder;

class ScholarshipProgramSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['TDP', '7500.00', 4], ['TES', '13500.00', 4], ['EDUKAR', null, 5]] as [$code, $amount, $years]) {
            // Reference information only. Never overwrite locally confirmed terms or rates.
            ScholarshipProgram::firstOrCreate(['code' => $code], [
                'name' => $code,
                'school_stated_semester_amount' => $amount,
                'planning_duration_years' => $years,
                'source_notes' => 'School-stated planning information; not an eligibility cutoff or an authorized entitlement.',
            ]);
        }
    }
}
