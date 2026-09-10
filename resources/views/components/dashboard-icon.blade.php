@props(['name', 'size' => 20])

@php
    $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M9 20v-6h6v6"/>',
        'document' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v5h5M10 12h5M10 16h5"/>',
        'applications' => '<path d="M7 3h10v18H7z"/><path d="M10 7h4M10 11h4M10 15h4"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c.4-4 2.5-6 6-6s5.6 2 6 6M16 5.5a3 3 0 0 1 0 5.8M17 14c2.3.5 3.7 2.4 4 5"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 21c.4-4.7 2.8-7 7-7s6.6 2.3 7 7"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14.5v2"/>',
        'eye' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="2.5"/>',
        'eye-off' => '<path d="m3 3 18 18M10.6 6.2A9.9 9.9 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-2.1 2.8M6.2 6.2C3.8 8 2.5 12 2.5 12s3.5 6 9.5 6c1 0 2-.2 2.8-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'user-group' => '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20c.4-4 2.3-6 5.5-6s5.1 2 5.5 6M14 15c3.8-.8 6.7 1.1 7.3 4.5"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10l2 2 4-4M9 16h6"/>',
        'graduation-cap' => '<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11.5V16c3.5 3 8.5 3 12 0v-4.5M22 9v6"/>',
        'chart' => '<path d="M4 20V9h4v11M10 20V4h4v16M16 20v-7h4v7"/>',
        'megaphone' => '<path d="M4 14V9h4l10-5v15L8 14z"/><path d="M7 14l2 6h4l-2-5M20 8c1.3 1.1 1.3 2.9 0 4"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'bell' => '<path d="M6 9a6 6 0 0 1 12 0c0 7 3 7 3 8H3c0-1 3-1 3-8M9.5 21h5"/>',
        'chevron-down' => '<path d="m7 9 5 5 5-5"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'arrow-right' => '<path d="M5 12h14M14 7l5 5-5 5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
        'trend' => '<path d="M4 17v-5M9 17V7M14 17v-3M19 17V4"/>',
        'sparkle' => '<path d="M12 3c.8 4.2 2.8 6.2 7 7-4.2.8-6.2 2.8-7 7-.8-4.2-2.8-6.2-7-7 4.2-.8 6.2-2.8 7-7z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'ellipsis' => '<circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1" fill="currentColor" stroke="none"/>',
        'reload' => '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M18.5 9A7.5 7.5 0 0 0 5.7 6.5L4 9M5.5 15A7.5 7.5 0 0 0 18.3 17.5L20 15"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'dashboard-icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? $paths['document'] !!}
</svg>
