@props(['title' => 'No applications yet', 'message' => 'New scholarship applications will appear here.'])

<div {{ $attributes->merge(['class' => 'dashboard-empty']) }}>
    <span class="empty-icon"><x-dashboard-icon name="document" :size="24" /></span>
    <strong>{{ $title }}</strong>
    <p>{{ $message }}</p>
    <a href="#create-application" class="text-link">Create Application <x-dashboard-icon name="arrow-right" :size="15" /></a>
</div>
