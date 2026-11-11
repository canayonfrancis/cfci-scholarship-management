<?php

namespace Database\Seeders;

use App\Models\AcademicPeriod;
use Database\Seeders\Support\SampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {
        SampleData::guard();
        $last = (int) substr(SampleData::academicYear(), 0, 4);
        DB::transaction(function () use ($last) {
            for ($start = 2007; $start <= $last; $start++) {
                foreach (['1', '2'] as $semester) {
                    // is_open is maintenance access, not the active-validation setting.
                    AcademicPeriod::firstOrCreate(['academic_year' => $start.'-'.($start + 1), 'semester_code' => $semester], [
                        'starts_on' => null, 'ends_on' => null, 'is_open' => false,
                    ]);
                }
            }
        });
    }
}
