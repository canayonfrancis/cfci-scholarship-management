<x-records-page title="Semesters" description="Select an academic year, then manage semester dates and Open/Closed availability. Historical records and grading sources are preserved.">
    <x-slot:actions>
        <a class="records-button" href="{{ route('academic-years.index') }}">Academic years</a>
        @can('create', \App\Models\AcademicPeriod::class)<button class="records-button is-primary" type="button" x-data @click="$dispatch('open-record-form-dialog', { id: 'create-semester', trigger: $el })" aria-haspopup="dialog" aria-controls="create-semester">Add semester</button>@endcan
    </x-slot:actions>
    <section class="dashboard-card records-card">
        <form class="records-filters" method="GET" action="{{ route('academic-periods.index') }}"><x-record-field id="semester-year-filter" name="academic_year" label="Academic year" type="select"><option value="">All academic years</option>@foreach($years as $year)<option value="{{ $year }}" @selected(($filters['academic_year'] ?? '') === $year)>{{ $year }}</option>@endforeach</x-record-field><button class="records-button" type="submit">Filter</button><a class="records-button" href="{{ route('academic-periods.index') }}">Clear</a></form>
        <div class="records-table-wrap"><x-table-state :paginator="$periods" /><table class="records-table"><caption class="sr-only">Semesters by academic year</caption><thead><tr><th scope="col">Academic year</th><th scope="col">Semester</th><th scope="col">Start date</th><th scope="col">End date</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
            @forelse($periods as $period)<tr><td>{{ $period->academic_year }}</td><td>{{ $period->semester_code }}</td><td>{{ $period->starts_on?->format('M j, Y') ?? 'Not recorded' }}</td><td>{{ $period->ends_on?->format('M j, Y') ?? 'Not recorded' }}</td><td><span class="records-badge {{ $period->is_open ? 'is-green' : '' }}">{{ $period->is_open ? 'Open' : 'Closed' }}</span></td><td><div class="records-actions"><a class="records-link" href="{{ route('academic-periods.show', $period) }}">View</a>@can('update', $period)<a id="edit-semester-trigger-{{ $period->id }}" class="records-link" href="{{ route('academic-periods.index', request()->except('edit', 'create') + ['edit' => $period->id]) }}" aria-haspopup="dialog" aria-controls="edit-semester">Edit</a>@endcan</div></td></tr>@empty<tr><td colspan="6">No semesters match this academic year.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $periods->appends(['edit' => null, 'create' => null])->links() }}</div>
    </section>
    <details class="dashboard-card records-card"><summary>Active validation period and historical grading source</summary><x-academic-context-form :context="$context" :periods="$allPeriods" /></details>
    @can('create', \App\Models\AcademicPeriod::class)
        <x-record-form-dialog id="create-semester" title="Add semester" :action="route('academic-periods.store')" submit-label="Save semester" :show="!$editing && ($errors->getBag('periodForm')->any() || request()->boolean('create'))" :has-errors="!$editing && $errors->getBag('periodForm')->any()" :dismiss-query="['create']" :defaults="['academic_year' => $filters['academic_year'] ?? '', 'semester_code' => '', 'starts_on' => '', 'ends_on' => '', 'is_open' => '0']">
            <x-slot:description>Select an existing academic year and enter the institution's semester code. Closed periods remain accessible in history and grading sources.</x-slot:description>
            @include('periods.fields', ['newYear' => false, 'recover' => !$editing && $errors->getBag('periodForm')->any(), 'prefix' => 'semester-create', 'identityLocked' => false, 'yearChoices' => $years, 'period' => new \App\Models\AcademicPeriod(['is_open' => false])])
        </x-record-form-dialog>
    @endcan
    @if($editing)
        <x-record-form-dialog id="edit-semester" :trigger-id="'edit-semester-trigger-'.$editing->id" title="Edit semester" :action="route('academic-periods.update', $editing)" method="PATCH" submit-label="Save changes" busy-label="Saving…" :show="true" :has-errors="$errors->getBag('periodForm')->any()" :dismiss-query="['edit', 'create']" :defaults="['academic_year' => $editing->academic_year, 'semester_code' => $editing->semester_code, 'starts_on' => $editing->starts_on?->format('Y-m-d') ?? '', 'ends_on' => $editing->ends_on?->format('Y-m-d') ?? '', 'is_open' => (int) $editing->is_open]">
            <x-slot:description>Update this semester's dates and availability. Existing assignments, billing and academic history are retained.</x-slot:description>
            @include('periods.fields', ['newYear' => false, 'recover' => $errors->getBag('periodForm')->any(), 'prefix' => 'semester-edit', 'yearChoices' => $years, 'period' => $editing])
        </x-record-form-dialog>
    @endif
</x-records-page>
