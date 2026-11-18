@props(['title', 'description' => '', 'eyebrow' => 'Scholarship administration'])

<x-app-layout>
    <div class="dashboard-page records-page">
        <div class="records-heading">
            <div><p class="records-eyebrow">{{ $eyebrow }}</p><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>
            <div class="records-actions">{{ $actions ?? '' }}</div>
        </div>
        @if(session('status'))<div class="records-notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="records-alert" role="alert"><strong>Please check the highlighted fields.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </div>
</x-app-layout>
