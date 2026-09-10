@props(['type' => 'card'])

<div {{ $attributes->merge(['class' => 'dashboard-skeleton dashboard-skeleton--'.$type]) }} role="status" aria-live="polite">
    <span class="sr-only">Loading dashboard information</span>
    <i></i><i></i><i></i>
</div>
