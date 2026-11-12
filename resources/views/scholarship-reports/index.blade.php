<x-records-page :title="$result['title']" description="Current recorded scholarship facts. Preparing a report changes no grant, payment or liquidation.">
    <x-slot:actions>
        <a class="records-button" href="{{ route('scholarship-reports.index', ['report' => $result['report']] + $result['filters'] + ['format' => 'csv']) }}">Export CSV</a>
        <a class="records-button" href="{{ route('scholarship-reports.index', ['report' => $result['report']] + $result['filters'] + ['format' => 'print']) }}" target="_blank" rel="noopener">Print complete report</a>
    </x-slot:actions>
    <section class="dashboard-card records-card">
        <nav class="records-actions" aria-label="Scholarship report selection">
            @foreach($titles as $key => $title)
                @if(in_array($key, ['grants', 'not-billed'], true))
                    @can('viewAny', \App\Models\ScholarshipBatch::class)
                        <a @class(['records-button', 'is-primary' => $key === $result['report']]) href="{{ route('scholarship-reports.index', ['report' => $key] + array_diff_key($result['filters'], ['billing_batch_id' => true])) }}" @if($key === $result['report']) aria-current="page" @endif>{{ $title }}</a>
                    @endcan
                @else
                    @can('financial-view')
                        <a @class(['records-button', 'is-primary' => $key === $result['report']]) href="{{ route('scholarship-reports.index', ['report' => $key] + $result['filters']) }}" @if($key === $result['report']) aria-current="page" @endif>{{ $title }}</a>
                    @endcan
                @endif
            @endforeach
        </nav>
        <form class="records-filters" method="GET" action="{{ route('scholarship-reports.index', ['report' => $result['report']]) }}">
            <x-record-field name="program_id" label="Scholarship program" type="select"><option value="">All programs</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($result['filters']['program_id'] ?? '') == $program->id)>{{ $program->code }} · {{ $program->name }}</option>@endforeach</x-record-field>
            <x-record-field name="scholarship_batch_id" label="Semester roster batch (recorded link)" type="select"><option value="">All rosters, including unlinked financial history</option>@foreach($rosters as $roster)<option value="{{ $roster->id }}" @selected(($result['filters']['scholarship_batch_id'] ?? '') == $roster->id)>{{ $roster->program->code }} · {{ $roster->batch_number }} · {{ $roster->academicPeriod->academic_year }} / {{ $roster->academicPeriod->semester_code }}</option>@endforeach</x-record-field>
            @unless(in_array($result['report'], ['grants', 'not-billed'], true))
                <x-record-field name="billing_batch_id" label="Financial billing submission" type="select"><option value="">All billing submissions</option>@foreach($billings as $billing)<option value="{{ $billing->id }}" @selected(($result['filters']['billing_batch_id'] ?? '') == $billing->id)>{{ $billing->program->code }} · {{ $billing->batch_number }} · {{ $billing->academicPeriod->academic_year }} / {{ $billing->academicPeriod->semester_code }}</option>@endforeach</x-record-field>
            @endunless
            <x-record-field name="academic_year" label="Academic year" type="select"><option value="">All academic years</option>@foreach($periods->pluck('academic_year')->unique() as $year)<option value="{{ $year }}" @selected(($result['filters']['academic_year'] ?? '') === $year)>{{ $year }}</option>@endforeach</x-record-field>
            <x-record-field name="semester_code" label="Semester" type="select"><option value="">All semesters</option>@foreach($periods->pluck('semester_code')->unique() as $semester)<option value="{{ $semester }}" @selected(($result['filters']['semester_code'] ?? '') === $semester)>{{ $semester }}</option>@endforeach</x-record-field>
            @if(filled($result['filters']['academic_period_id'] ?? null))<input type="hidden" name="academic_period_id" value="{{ $result['filters']['academic_period_id'] }}">@endif
            <button class="records-button is-primary" type="submit">Generate report</button>
            <a class="records-button" href="{{ route('scholarship-reports.index', ['report' => $result['report']]) }}">Clear filters</a>
        </form>
    </section>
    @include('scholarship-reports.summary')
    <section class="dashboard-card records-card">
        @include('scholarship-reports.table', ['reportRows' => $rows])
        <div class="records-pagination">{{ $rows->links() }}</div>
    </section>
    @include('scholarship-reports.context')
</x-records-page>
