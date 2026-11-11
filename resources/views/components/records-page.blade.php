@props(['title', 'description' => ''])

<x-app-layout>
    <div class="dashboard-page records-page">
        <div class="records-heading">
            <div><p class="records-eyebrow">Scholarship administration</p><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>
            <div class="records-actions">{{ $actions ?? '' }}</div>
        </div>
        <nav class="records-tabs" aria-label="Scholarship records">
            @can('viewAny', \App\Models\ScholarshipApplication::class)<a href="{{ route('evaluations.index') }}" @class(['is-current' => request()->routeIs('evaluations.*')])><x-dashboard-icon name="clipboard" :size="18" /> Evaluations</a>@endcan
            @can('viewAny', \App\Models\AcademicRecord::class)<a href="{{ route('academic-verifications.index') }}" @class(['is-current' => request()->routeIs('academic-verifications.*')])><x-dashboard-icon name="check-circle" :size="18" /> Academic verification</a>@endcan
            @can('viewAny', \App\Models\Student::class)<a href="{{ route('scholars.index') }}" @class(['is-current' => request()->routeIs('scholars.*')])><x-dashboard-icon name="users" :size="18" /> Scholars</a>@endcan
            @can('viewAny', \App\Models\ScholarshipProgram::class)<a href="{{ route('scholarship-programs.index') }}" @class(['is-current' => request()->routeIs('scholarship-programs.*')])><x-dashboard-icon name="graduation-cap" :size="18" /> Scholarship programs</a>@endcan
            @can('viewAny', \App\Models\AcademicPeriod::class)<a href="{{ route('academic-periods.index') }}" @class(['is-current' => request()->routeIs('academic-periods.*')])><x-dashboard-icon name="calendar" :size="18" /> Academic periods</a>@endcan
        </nav>
        @if(session('status'))<div class="records-notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="records-alert" role="alert"><strong>Please check the highlighted fields.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </div>
</x-app-layout>
