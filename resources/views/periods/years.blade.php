<x-records-page title="Academic years" description="View registered academic years and manage their semesters. A year is registered when its first semester is saved.">
    <x-slot:actions>
        <a class="records-button" href="{{ route('academic-periods.index') }}">Manage semesters</a>
        @can('create', \App\Models\AcademicPeriod::class)<button class="records-button is-primary" type="button" x-data @click="$dispatch('open-record-form-dialog', { id: 'create-academic-year', trigger: $el })" aria-haspopup="dialog" aria-controls="create-academic-year">Add academic year</button>@endcan
    </x-slot:actions>
    <section class="dashboard-card records-card">
        <form method="GET" class="records-filters" action="{{ route('academic-years.index') }}">
            <x-record-field id="year-search" name="q" label="Search academic year" :value="$filters['q'] ?? ''" maxlength="30" />
            <button class="records-button" type="submit">Search</button><a class="records-button" href="{{ route('academic-years.index') }}">Clear</a>
        </form>
        <div class="records-table-wrap"><x-table-state :paginator="$years" /><table class="records-table"><thead><tr><th>Academic year</th><th>Start year</th><th>End year</th><th>Semesters</th><th>Open semesters</th><th>Actions</th></tr></thead><tbody>
            @forelse($years as $year)<tr><td>{{ $year->academic_year }}</td><td>{{ explode('-', $year->academic_year)[0] }}</td><td>{{ explode('-', $year->academic_year)[1] ?? 'Not recorded' }}</td><td>{{ $year->semester_count }}</td><td>{{ $year->open_count }}</td><td><a class="records-link" href="{{ route('academic-periods.index', ['academic_year' => $year->academic_year]) }}">Manage semesters / availability</a></td></tr>@empty<tr><td colspan="6">No academic years match this search. Register a year with its first semester.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $years->links() }}</div>
    </section>
    @can('create', \App\Models\AcademicPeriod::class)
        <x-record-form-dialog id="create-academic-year" title="Add academic year and first semester" :action="route('academic-periods.store')" submit-label="Save academic year and semester" :show="$errors->getBag('periodForm')->any()" :defaults="['year_start' => '', 'year_end' => '', 'semester_code' => '', 'starts_on' => '', 'ends_on' => '', 'is_open' => '0']">
            <x-slot:description>Enter the academic year and its first approved semester. This creates one academic period; no placeholder semester or duplicate year record is created.</x-slot:description>
            @include('periods.fields', ['newYear' => true, 'recover' => $errors->getBag('periodForm')->any(), 'prefix' => 'year-create', 'identityLocked' => false, 'period' => new \App\Models\AcademicPeriod(['is_open' => false])])
        </x-record-form-dialog>
    @endcan
</x-records-page>
