<?php

namespace Tests\Feature\Records;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Services\Billing\BillingEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class ScholarInformationTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function basic(array $overrides = []): array
    {
        return array_replace(['student_number' => 'CFCI-PROFILE-001', 'first_name' => 'Maria Ana', 'last_name' => 'Dela Cruz', 'record_status' => 'active'], $overrides);
    }

    private function complete(array $overrides = []): array
    {
        return $this->basic(array_replace([
            'lrn' => '000000000001', 'philsys_id' => '0000-0000-0000-0000', 'four_ps_id' => '0000-SAMPLE-001',
            'middle_name' => 'Reyes', 'suffix' => 'Jr.', 'sex' => '0', 'birthdate' => '2004-03-12',
            'academic_program_id' => AcademicProgram::firstOrCreate(['name' => 'SAMPLE Bachelor of Science in Information Technology'])->id,
            'year_level' => '2', 'academic_period_id' => AcademicPeriod::firstOrCreate(['academic_year' => '2026-2027', 'semester_code' => '1'])->id,
            'father_last_name' => 'Dela Cruz', 'father_first_name' => 'Roberto', 'father_middle_name' => 'Santos',
            'mother_maiden_last_name' => 'Reyes', 'mother_maiden_first_name' => 'Elena', 'mother_maiden_middle_name' => 'Garcia',
            'street_barangay' => 'SAMPLE 1 Test Street, Barangay Lagao', 'city_municipality' => 'General Santos City', 'province' => 'South Cotabato', 'region' => 'Region XII', 'zip_code' => '0950',
            'phone' => '09171234567', 'email' => 'maria.ana@example.test', 'disability_type' => 'none',
            'solo_parent_dependent' => '0', 'first_generation_student' => '1', 'indigenous_people_group' => 'SAMPLE self-reported group',
        ], $overrides));
    }

    public function test_complete_registration_supports_every_requested_field_without_creating_accounts_or_scholarships(): void
    {
        $this->actingAs($this->staff('administrator'));
        $data = $this->complete();
        $this->post(route('scholars.store'), $data)->assertSessionHasNoErrors();
        $scholar = Student::sole();
        foreach ($data as $key => $value) {
            if ($key === 'phone') {
                $value = '9171234567';
            }
            $actual = $key === 'birthdate' ? $scholar->birthdate->toDateString() : $scholar->getAttribute($key);
            $this->assertEquals($value, $actual, 'Saved field: '.$key);
        }
        $this->assertTrue($scholar->hasCompleteProfile());
        $this->assertFalse($scholar->solo_parent_dependent);
        $this->assertTrue($scholar->first_generation_student);
        $this->assertSame(0, $scholar->sex);
        $this->assertStringEndsWith('Jr.', $scholar->full_name);
        $response = $this->get(route('scholars.show', $scholar))->assertOk()->assertSee('Complete')->assertSee('Male')->assertSee('0950')->assertSee('0000-SAMPLE-001');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('scholars.edit', $scholar))->assertOk()->assertSee('value="0" selected', false);
        $this->assertDatabaseCount('users', 1);
        foreach (['academic_records', 'scholarship_assignments', 'evaluation_runs', 'external_approvals', 'billing_items', 'funding_receipts', 'payouts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_basic_registration_is_saveable_unknown_values_stay_null_and_missing_fields_are_explained(): void
    {
        $this->actingAs($this->staff('coordinator'));
        $this->post(route('scholars.store'), $this->basic())->assertSessionHasNoErrors();
        $scholar = Student::sole();
        foreach (['lrn', 'sex', 'birthdate', 'disability_type', 'solo_parent_dependent', 'first_generation_student', 'mother_maiden_last_name', 'academic_period_id'] as $field) {
            $this->assertNull($scholar->getAttribute($field));
        }
        $this->assertFalse($scholar->hasCompleteProfile());
        $this->assertArrayHasKey('mother_maiden_first_name', $scholar->missingProfileFields());
        $this->assertArrayHasKey('solo_parent_dependent', $scholar->missingProfileFields());
        $this->get(route('scholars.show', $scholar))->assertOk()->assertSee('Incomplete')->assertSee('Solo-parent dependent indicator')->assertSee('Not provided');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_protected_identifiers_are_encrypted_hidden_and_redacted_in_all_audit_states(): void
    {
        $this->actingAs($this->staff('registrar'));
        $data = $this->complete();
        $this->post(route('scholars.store'), $data)->assertSessionHasNoErrors();
        $student = Student::sole();
        $raw = DB::table('students')->first();
        foreach (Student::PROTECTED_IDENTIFIERS as $field) {
            $this->assertNotSame($data[$field], $raw->$field);
            $this->assertStringNotContainsString($data[$field], $raw->$field);
            $this->assertArrayNotHasKey($field, $student->toArray());
        }
        $this->patch(route('scholars.update', $student), array_replace($data, ['first_name' => 'Updated']))->assertSessionHasNoErrors();
        $audits = AuditEvent::where('subject_type', Student::class)->get()->toJson();
        foreach (Student::PROTECTED_IDENTIFIERS as $field) {
            $this->assertStringNotContainsString($data[$field], $audits);
        }
        $this->assertStringContainsString('[REDACTED]', $audits);
    }

    public static function invalidFields(): array
    {
        return [
            ['lrn', '12345678901'], ['lrn', '1234567890123'], ['lrn', '00000000000A'], ['lrn', '00'],
            ['philsys_id', '0000000000000000'], ['philsys_id', 'ABCD-0000-0000-0000'],
            ['sex', '2'], ['birthdate', '2035-01-01'], ['birthdate', '2004-02-30'],
            ['year_level', '0'], ['year_level', '99'], ['academic_program_id', '999999'], ['academic_period_id', '999999'],
            ['solo_parent_dependent', 'unknown'], ['first_generation_student', 'yes'], ['disability_type', 'invented'],
            ['phone', '123456789'], ['phone', ['malformed']], ['email', 'invalid-email'], ['four_ps_id', ['malformed']],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_profile_fields_are_rejected_without_partial_writes(string $field, mixed $value): void
    {
        $this->actingAs($this->staff('administrator'));
        $this->post(route('scholars.store'), $this->complete([$field => $value]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function phoneFormats(): array
    {
        return [['09171234567', '9171234567'], ['9171234567', '9171234567'], ['+63 917 123 4567', '9171234567'], ['00639171234567', '9171234567'], ['(8123) 4567', '81234567']];
    }

    #[DataProvider('phoneFormats')]
    public function test_supported_contacts_are_normalized(string $input, string $expected): void
    {
        $this->actingAs($this->staff('administrator'));
        $this->post(route('scholars.store'), $this->basic(['phone' => $input]))->assertSessionHasNoErrors();
        $this->assertSame($expected, Student::sole()->phone);
    }

    public function test_lrn_zero_and_leading_zero_identifiers_survive_and_optional_fields_can_be_omitted(): void
    {
        $this->actingAs($this->staff('administrator'));
        $data = $this->complete(['lrn' => '0', 'four_ps_id' => '000000-SAMPLE', 'disability_type' => null, 'first_generation_student' => null, 'indigenous_people_group' => null, 'father_last_name' => null, 'father_first_name' => null, 'father_middle_name' => null, 'mother_maiden_middle_name' => null]);
        $this->post(route('scholars.store'), $data)->assertSessionHasNoErrors();
        $student = Student::sole();
        $this->assertSame('0', $student->lrn);
        $this->assertSame('000000-SAMPLE', $student->four_ps_id);
        $this->assertTrue($student->hasCompleteProfile());
        $this->assertNull($student->first_generation_student);
        $this->assertNull($student->disability_type);
    }

    public function test_validation_preserves_normal_input_without_flashing_protected_identifiers(): void
    {
        $this->actingAs($this->staff('administrator'));
        $data = $this->complete(['email' => 'invalid-email']);
        $this->from(route('scholars.create'))->post(route('scholars.store'), $data)->assertRedirect(route('scholars.create'))->assertSessionHasErrors('email')->assertSessionHasInput('mother_maiden_last_name', 'Reyes');
        foreach (Student::PROTECTED_IDENTIFIERS as $field) {
            $this->assertNull(session()->getOldInput($field));
        }
        $this->get(route('scholars.create'))->assertOk()->assertSee('value="Reyes"', false)->assertSee('invalid-email')->assertDontSee('0000-0000-0000-0000');
    }

    public static function protectedRoles(): array
    {
        return [['administrator', true], ['registrar', true], ['coordinator', true], ['finance', false], ['viewer', false]];
    }

    #[DataProvider('protectedRoles')]
    public function test_roles_restrict_protected_profile_fields_and_lists_never_display_identifiers(string $role, bool $allowed): void
    {
        $student = Student::create($this->complete());
        $this->actingAs($this->staff($role));
        $show = $this->get(route('scholars.show', $student))->assertOk();
        if ($allowed) {
            $show->assertSee('0000-SAMPLE-001')->assertSee('Mother’s maiden last name');
        } else {
            $show->assertDontSee('0000-SAMPLE-001')->assertDontSee('0000-0000-0000-0000')->assertDontSee('2004')->assertDontSee('SAMPLE 1 Test Street');
        }
        $this->get(route('scholars.index'))->assertOk()->assertDontSee('0000-SAMPLE-001')->assertDontSee('0000-0000-0000-0000')->assertDontSee('000000000001')->assertSee('SAMPLE Bachelor')->assertSee('2026-2027');
        $this->get(route('scholars.edit', $student))->assertStatus($allowed ? 200 : 403);
        $this->patch(route('scholars.update', $student), $this->basic(['philsys_id' => '0000-0000-0000-0001']))->assertStatus($allowed ? 302 : 403);
        if (! $allowed) {
            $this->assertSame('0000-0000-0000-0000', $student->fresh()->philsys_id);
        }
    }

    public function test_student_id_remains_unique_and_disabled_program_cannot_be_newly_selected(): void
    {
        $this->actingAs($this->staff('administrator'));
        $student = Student::create($this->basic());
        $this->post(route('scholars.store'), $this->basic())->assertSessionHasErrors('student_number');
        $inactive = AcademicProgram::create(['name' => 'SAMPLE unavailable program', 'is_active' => false]);
        $this->post(route('scholars.store'), $this->basic(['student_number' => 'OTHER', 'academic_program_id' => $inactive->id]))->assertSessionHasErrors('academic_program_id');
        $student->update(['academic_program_id' => $inactive->id]);
        $this->patch(route('scholars.update', $student), $this->basic(['academic_program_id' => $inactive->id]))->assertSessionHasNoErrors();
        $this->get(route('scholars.edit', $student))->assertOk()->assertSee('inactive; retained');
        $this->assertDatabaseCount('students', 1);
    }

    public function test_legacy_contacts_are_retained_for_review_when_other_details_are_edited(): void
    {
        $student = Student::create($this->basic(['phone' => '+63 83 123 4567']));
        $this->actingAs($this->staff('coordinator'));
        $this->patch(route('scholars.update', $student), $this->basic(['phone' => '+63 83 123 4567', 'first_name' => 'Edited']))->assertSessionHasNoErrors();
        $this->assertSame('+63 83 123 4567', $student->fresh()->phone);
        $this->assertArrayHasKey('phone', $student->fresh()->missingProfileFields());
    }

    public function test_program_registry_uses_admin_authority_and_retains_linked_names(): void
    {
        $this->actingAs($this->staff('finance'));
        $this->post(route('academic-programs.store'), ['name' => 'SAMPLE course', 'is_active' => 1])->assertForbidden();
        $this->actingAs($this->staff('administrator'));
        $this->post(route('academic-programs.store'), ['name' => 'SAMPLE course', 'is_active' => 1])->assertSessionHasNoErrors();
        $program = AcademicProgram::sole();
        Student::create($this->basic(['academic_program_id' => $program->id]));
        $this->patch(route('academic-programs.update', $program), ['name' => 'Changed name', 'is_active' => 1])->assertSessionHasErrors('name');
        $this->patch(route('academic-programs.update', $program), ['name' => 'SAMPLE course', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertSame('SAMPLE course', $program->fresh()->name);
        $this->assertFalse($program->fresh()->is_active);
        $this->get(route('academic-programs.index'))->assertOk()->assertSee('SAMPLE course');
    }

    public function test_profile_progression_retains_academic_and_scholarship_history_and_eligible_billing(): void
    {
        $g = $this->ready('TES', '7500.00');
        $before = [];
        foreach (['academic_records', 'scholarship_assignments', 'evaluation_runs', 'external_approvals', 'program_period_terms', 'scholarship_rates', 'billing_batches'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->actingAs($this->staff('administrator'));
        $first = $this->complete(['student_number' => $g['student']->student_number, 'academic_period_id' => $g['period']->id]);
        $this->patch(route('scholars.update', $g['student']), $first)->assertSessionHasNoErrors();
        $next = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '2']);
        $this->patch(route('scholars.update', $g['student']), array_replace($first, ['academic_period_id' => $next->id, 'year_level' => 3]))->assertSessionHasNoErrors();
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson(), $table.' unchanged');
        }
        $this->get(route('scholars.show', $g['student']))->assertOk()->assertSee('2026-2027')->assertSee('2027-2028')->assertSee('Academic profile activity');
        $basis = app(BillingEligibility::class)->basis($g['application']->fresh(), $g['terms']->fresh());
        $this->assertSame('7500.00', $basis['gross_amount']);
        $this->assertDatabaseCount('academic_records', 1);
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertDatabaseCount('payouts', 0);
        $this->assertTrue($next->isInUse());
        $this->patch(route('academic-periods.update', $next), ['academic_year' => '2028-2029', 'semester_code' => '2', 'is_open' => '0'])->assertSessionHasErrors('academic_year');
    }

    public function test_profile_and_audit_changes_roll_back_together(): void
    {
        $student = Student::create($this->basic());
        $before = $student->fresh()->getRawOriginal();
        $this->actingAs($this->staff('administrator'));
        $this->mock(RecordAudit::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable')));
        $this->withoutExceptionHandling();
        try {
            $this->patch(route('scholars.update', $student), $this->complete(['first_name' => 'Must roll back']));
            $this->fail('Audit failure should abort the transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertSame($before, $student->fresh()->getRawOriginal());
    }

    public function test_previous_profile_semester_identity_is_retained_after_progression(): void
    {
        $this->actingAs($this->staff('administrator'));
        $data = $this->complete();
        $this->post(route('scholars.store'), $data)->assertSessionHasNoErrors();
        $student = Student::sole();
        $previous = $student->academicPeriod;
        $next = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '1']);
        $this->patch(route('scholars.update', $student), array_replace($data, ['academic_period_id' => $next->id]))->assertSessionHasNoErrors();
        $this->assertSame(0, $previous->students()->count());
        $this->assertTrue($previous->isInUse());
        $this->patch(route('academic-periods.update', $previous), ['academic_year' => '2028-2029', 'semester_code' => '1', 'is_open' => '0'])->assertSessionHasErrors('academic_year');
        $this->assertDatabaseCount('academic_records', 0);
    }
}
