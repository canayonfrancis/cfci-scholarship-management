<?php

namespace App\Services\Records;

use App\Models\AcademicProgram;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScholarImportValidator
{
    public function validate(array $source, bool $lock = false): array
    {
        $courses = AcademicProgram::where('is_active', true)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $ids = array_column(array_column($source, 'values'), 0);
        $existing = Student::whereIn('student_number', $ids)->when($lock, fn ($q) => $q->lockForUpdate())->pluck('student_number')->all();
        $idKeys = $this->keys([...$ids, ...$existing], 'students', 'student_number');
        $courseNames = $courses->pluck('name')->all();
        $courseKeys = $this->keys([...$courseNames, ...array_column(array_column($source, 'values'), 6)], 'academic_programs', 'name');
        $registered = [];
        foreach ($courses as $course) {
            $registered[$courseKeys[$course->name]][] = $course;
        }
        $frequencies = array_count_values(array_map(fn ($id) => $idKeys[$id], $ids));
        $existingKeys = array_fill_keys(array_map(fn ($id) => $idKeys[$id], $existing), true);
        $rows = [];
        foreach ($source as $row) {
            $values = $row['values'];
            $sex = match (Str::lower($values[5])) {
                'male', '0' => 0,
                'female', '1' => 1,
                default => null,
            };
            $year = $values[7];
            foreach (ScholarProfile::yearLevels() as $key => $label) {
                if (strcasecmp($year, $label) === 0) {
                    $year = (string) $key;
                }
            }
            $matches = $registered[$courseKeys[$values[6]]] ?? [];
            $course = count($matches) === 1 ? $matches[0] : null;
            $attributes = [
                'student_number' => $values[0], 'last_name' => $values[1], 'first_name' => $values[2],
                'middle_name' => $values[3] === '' ? null : $values[3], 'suffix' => ScholarProfile::normalizeSuffix($values[4]),
                'sex' => $sex, 'academic_program_id' => $course?->id, 'year_level' => ctype_digit($year) ? (int) $year : $year,
                'email' => Str::lower($values[8]), 'phone' => $values[9], 'record_status' => 'active',
            ];
            $validation = Validator::make($attributes, [
                'student_number' => ['required', 'string', 'max:50'],
                'last_name' => ['required', 'string', 'max:255'],
                'first_name' => ['required', 'string', 'max:255'],
                'middle_name' => ['nullable', 'string', 'max:255'],
                'suffix' => ['nullable', 'string', 'max:30'],
                'sex' => ['required', Rule::in(array_keys(ScholarProfile::SEX))],
                'academic_program_id' => ['required'],
                'year_level' => ['required', 'integer', Rule::in(array_keys(ScholarProfile::yearLevels()))],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['required', 'string', 'max:40', function ($attribute, $value, $fail) {
                    if (ScholarProfile::normalizePhone($value) === null) {
                        $fail('Contact must be a recognized Philippine mobile number or an eight-digit landline.');
                    }
                }],
            ], [
                'sex.required' => 'Sex must be Male, Female, 0 (Male) or 1 (Female).',
                'academic_program_id.required' => 'Course must match one existing active registered course name. Unknown, inactive or ambiguous courses are not accepted.',
                'year_level.in' => 'Year Level must be one of the configured year levels.',
                'suffix.max' => 'EXT must not exceed 30 characters. Common forms such as JR, Jr., SR, Sr., II, III and IV are accepted.',
            ], [
                'student_number' => 'Student ID', 'last_name' => 'Last Name', 'first_name' => 'First Name',
                'middle_name' => 'Middle Name', 'suffix' => 'EXT', 'email' => 'Email', 'phone' => 'Contact', 'year_level' => 'Year Level',
            ]);
            $issues = [...$row['issues'], ...$validation->errors()->all()];
            foreach ($values as $index => $value) {
                // CSV formula injection is rejected as well as real XLSX formulas.
                if (preg_match('/^[=+@-]/u', ltrim($value)) && ! ($index === 9 && ScholarProfile::normalizePhone($value) !== null)) {
                    $issues[] = ScholarSpreadsheet::HEADERS[$index].' must contain a plain value, without a spreadsheet formula prefix.';
                }
            }
            $duplicate = false;
            if ($values[0] !== '') {
                if ($frequencies[$idKeys[$values[0]]] > 1) {
                    $duplicate = true;
                    $issues[] = 'Student ID appears more than once in this file. Keep only the correct record.';
                }
                if (isset($existingKeys[$idKeys[$values[0]]])) {
                    $duplicate = true;
                    $issues[] = 'Student ID already exists. Use its existing scholar profile; imports never overwrite or merge records.';
                }
            }
            $rows[] = [
                ...$row, 'issues' => array_values(array_unique($issues)), 'duplicate' => $duplicate,
                'attributes' => $attributes,
                'course_version' => $course ? [$course->id, $course->name, $course->getRawOriginal('updated_at')] : null,
            ];
        }
        $valid = count(array_filter($rows, fn ($row) => $row['issues'] === []));

        return [
            'rows' => $rows, 'total' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid,
            'duplicates' => count(array_filter($rows, fn ($row) => $row['duplicate'])),
            'fingerprint' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
        ];
    }

    /** Use actual database comparison weights, including case/accent-insensitive IDs. */
    private function keys(array $values, string $table, string $column): array
    {
        $values = array_values(array_unique($values));
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Scholar imports require the configured MySQL/MariaDB database.');
        }
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);
        $collation = $definition['collation'] ?? '';
        if (! preg_match('/\Autf8mb4_[a-z0-9_]+\z/D', $collation)) {
            throw new \RuntimeException('Unsupported scholar identifier collation.');
        }
        $padding = DB::selectOne("SELECT (CONVERT('a' USING utf8mb4) COLLATE ".$collation." = CONVERT('a ' USING utf8mb4) COLLATE ".$collation.') AS ignores_spaces');
        $expression = 'CONVERT(? USING utf8mb4)';
        if ($padding->ignores_spaces) {
            $expression = 'RTRIM('.$expression.')';
        }
        $keys = [];
        foreach (array_chunk($values, 200) as $chunk) {
            $sql = implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS original_value, HEX(WEIGHT_STRING('.$expression.' COLLATE '.$collation.')) AS comparison_key'));
            $bindings = [];
            foreach ($chunk as $value) {
                array_push($bindings, $value, $value);
            }
            foreach (DB::select($sql, $bindings) as $result) {
                $keys[$result->original_value] = $result->comparison_key;
            }
        }

        return $keys;
    }
}
