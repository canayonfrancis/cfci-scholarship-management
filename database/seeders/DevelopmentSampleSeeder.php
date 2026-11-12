<?php

namespace Database\Seeders;

use App\Actions\Eligibility\EvaluateApplication;
use App\Actions\Records\RecordAudit;
use App\Actions\Records\SaveAcademicVerification;
use App\Actions\Records\SaveScholarshipAssignment;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicRecordGrade;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\Support\SampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Nonfinancial sample data. Production grading/eligibility policy is always retained. */
class DevelopmentSampleSeeder extends Seeder
{
    public function run(): void
    {
        SampleData::guard();
        if (SampleData::completed('development')) {
            return;
        }
        $files = [];
        try {
            DB::transaction(function () use (&$files) {
                $this->callSilent(AcademicYearSeeder::class);
                $year = SampleData::academicYear();
                $period = AcademicPeriod::where('academic_year', $year)->where('semester_code', '1')->firstOrFail();
                $programs = SampleData::programs();
                $staff = [];
                foreach (User::OPERATIONAL_ROLES as $role) {
                    $staff[$role] = SampleData::staff($role);
                }
                for ($i = 0; $i < 60; $i++) {
                    $student = $this->student($i, $year);
                    if (! $student->wasRecentlyCreated) {
                        continue;
                    }
                    app(RecordAudit::class)->record($staff['coordinator'], $student, 'sample_profile_created');
                    $this->academic($student, $period, $staff['registrar'], $i % 3 !== 2, $files);
                    $code = $i >= 48 && $i < 54 ? 'COSCHO' : ($i % 2 === 0 ? 'TES' : 'TDP');
                    $assignment = app(SaveScholarshipAssignment::class)->save($student, $staff['coordinator'], [
                        'program_id' => $programs[$code]->id, 'start_period_id' => $period->id, 'status' => 'pending',
                        'status_reason' => 'SAMPLE: development assignment awaiting the actual eligibility/approval workflow.',
                    ]);
                    $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $programs[$code]->id, 'academic_period_id' => $period->id]);
                    $assessment = ScholarshipApplication::create([
                        'student_id' => $student->id, 'program_id' => $programs[$code]->id, 'academic_period_id' => $period->id,
                        'program_period_term_id' => $terms->id, 'assignment_id' => $assignment->id,
                    ]);
                    $data = SampleData::facts() + ['submission_revision' => $assessment->fresh()->submission_revision, 'previous_run' => 0];
                    if ($i >= 36 && $i < 42) {
                        $data['facts']['filipino'] = 'no';
                    }
                    app(EvaluateApplication::class)->handle($assessment, $staff['coordinator'], $data);
                    $assessment->externalApprovals()->create([
                        'student_id' => $student->id, 'program_id' => $programs[$code]->id, 'scope' => 'semester',
                        'assignment_id' => $assignment->id, 'academic_period_id' => $period->id,
                        'decision' => 'pending', 'authority' => $programs[$code]->expectedApprovalAuthority() ?? 'SAMPLE: authority not yet confirmed',
                        'reference' => 'SAMPLE-PENDING-'.$student->student_number, 'recorded_by' => $staff['coordinator']->id,
                    ]);
                }
                $firstYear = (int) substr($year, 0, 4);
                for ($i = 0; $i < 20; $i++) {
                    $cohort = 2007 + ($i % max(1, $firstYear - 2007));
                    $historicalYear = $cohort.'-'.($cohort + 1);
                    $student = $this->student($i, $historicalYear, true);
                    if (! $student->wasRecentlyCreated) {
                        continue;
                    }
                    app(RecordAudit::class)->record($staff['coordinator'], $student, 'sample_profile_created');
                    foreach (['1', '2'] as $semester) {
                        $historicalPeriod = AcademicPeriod::where('academic_year', $historicalYear)->where('semester_code', $semester)->firstOrFail();
                        $this->academic($student, $historicalPeriod, $staff['registrar'], false, $files, true);
                    }
                    // Historical academics do not imply historic scholarship programs, rates or payments.
                }
                SampleData::complete('development');
            });
        } catch (Throwable $error) {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $error;
        }
    }

    private function student(int $index, string $year, bool $historical = false): Student
    {
        $prototype = Student::factory()->sample($index, $year, $historical)->make();
        $existing = Student::where('student_number', $prototype->student_number)->first();
        if ($existing) {
            if (! SampleData::owned($existing)) {
                throw new RuntimeException('Reserved sample student number belongs to an existing manual record: '.$prototype->student_number);
            }

            return $existing;
        }
        $prototype->save();
        SampleData::mark($prototype);

        return $prototype;
    }

    private function academic(Student $student, AcademicPeriod $period, $registrar, bool $verified, array &$files, bool $historical = false): AcademicRecord
    {
        $prototype = AcademicRecord::factory()->make(['student_id' => $student->id, 'academic_period_id' => $period->id]);
        $grades = [];
        foreach (['Mathematics', 'Communication', 'Filipino', 'Science', 'Physical Education'] as $index => $subject) {
            $grades[] = AcademicRecordGrade::factory()->make(['academic_record_id' => null, 'subject_code' => 'SAMPLE-'.($index + 1), 'subject_name' => $subject.' (SAMPLE)'])->only((new AcademicRecordGrade)->getFillable());
        }
        $record = app(SaveAcademicVerification::class)->handle($registrar, [
            ...$prototype->only($prototype->getFillable()), 'student_id' => $student->id, 'academic_period_id' => $period->id,
            'course' => $historical ? 'Historical course not specified (SAMPLE)' : ($student->academicProgram?->name ?? $prototype->course),
            'year_level' => $historical ? $prototype->year_level : (string) $student->year_level,
            'action' => $verified ? 'verify' : 'save', 'grades' => $grades,
            'source_reference' => 'SAMPLE: '.$student->student_number.' / '.$period->academic_year.' / '.$period->semester_code.'. Fictional reported values; no calendar or grade conversion is asserted.',
        ], [SampleData::pdf('academic-'.$student->student_number.'-'.$period->semester_code)]);
        foreach ($record->documents()->get() as $document) {
            $files[] = [$document->disk, $document->storage_path];
        }

        return $record;
    }
}
