<header class="site-header" x-data="{ mobileOpen: false, profileOpen: false, notificationsOpen: false, searching: false }" @keydown.escape.window="mobileOpen = profileOpen = notificationsOpen = searching = false">
    <a class="skip-link" href="#main-content">Skip to dashboard</a>
    <div class="topbar">
        <a href="{{ route('dashboard') }}" class="brand" aria-label="CFCI Scholarship Management dashboard">
            <img class="brand-logo" src="{{ asset('images/cfc-logo.webp') }}" alt="Cronasia Foundation College, Inc.">
        </a>

        <nav class="desktop-nav" aria-label="Primary navigation">
            @php
                $navItems = [
                    ['Dashboard', 'home', route('dashboard'), request()->routeIs('dashboard')],
                ];
                if (auth()->user()->can('viewAny', \App\Models\AcademicRecord::class)) {
                    $navItems[] = ['Academic records', 'applications', route('academic-verifications.index'), request()->routeIs('academic-verifications.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\Student::class)) {
                    $navItems[] = ['Scholars', 'users', route('scholars.index'), request()->routeIs('scholars.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\ScholarshipApplication::class)) {
                    $navItems[] = ['Evaluations', 'clipboard', route('evaluations.index'), request()->routeIs('evaluations.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\ScholarshipProgram::class)) {
                    $navItems[] = ['Scholarships', 'graduation-cap', route('scholarship-programs.index'), request()->routeIs('scholarship-programs.*', 'academic-periods.*')];
                }
                $navItems[] = ['Reports', 'chart', route('dashboard').'#applications-trend', false];
                $navItems[] = ['Announcements', 'megaphone', route('dashboard').'#announcements', false];
                $roleLabels = auth()->user()->roles->pluck('role')->map(fn ($role) => Str::headline($role));
                $initials = Str::of(auth()->user()->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
            @endphp
            @foreach ($navItems as [$label, $icon, $href, $active])
                <a href="{{ $href }}" @class(['nav-item', 'is-active' => $active]) @if($active) aria-current="page" @endif>
                    <x-dashboard-icon :name="$icon" :size="18" /><span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>

        <div class="topbar-actions">
            <div class="search-wrap" :class="{ 'is-open': searching }">
                <label class="sr-only" for="dashboard-search">Search dashboard</label>
                <input id="dashboard-search" x-ref="searchInput" x-cloak x-show="searching" x-transition type="search" placeholder="Search" @click.outside="searching = false">
                <button class="round-button" type="button" aria-label="Search" @click="searching = !searching; $nextTick(() => searching && $refs.searchInput.focus())" :aria-expanded="searching">
                    <x-dashboard-icon name="search" :size="21" />
                </button>
            </div>

            <div class="action-dropdown">
                <button class="round-button notification-button" type="button" aria-label="Notifications, 1 unread" @click="notificationsOpen = !notificationsOpen; profileOpen = false" :aria-expanded="notificationsOpen">
                    <x-dashboard-icon name="bell" :size="21" /><span class="notification-dot"></span>
                </button>
                <div class="popover notification-popover" x-cloak x-show="notificationsOpen" x-transition @click.outside="notificationsOpen = false">
                    <strong>Notifications</strong>
                    <p>Dashboard notifications are demonstration data.</p>
                    <a href="#recent-applications" @click="notificationsOpen = false">View validation overview</a>
                </div>
            </div>

            <div class="profile-wrap">
                <button class="profile-button" type="button" @click="profileOpen = !profileOpen; notificationsOpen = false" :aria-expanded="profileOpen" aria-label="Open user menu">
                    <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                    <span class="profile-copy"><strong>{{ Auth::user()->name }}</strong><small>{{ $roleLabels->isNotEmpty() ? $roleLabels->implode(', ') : 'No staff role assigned' }}</small></span>
                    <x-dashboard-icon name="chevron-down" :size="17" />
                </button>
                <div class="popover profile-popover" x-cloak x-show="profileOpen" x-transition @click.outside="profileOpen = false">
                    <a href="{{ route('profile.edit') }}">Profile &amp; settings</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">Log out</button>
                    </form>
                </div>
            </div>

            <button class="round-button mobile-menu-button" type="button" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen" aria-controls="mobile-navigation" aria-label="Toggle navigation">
                <x-dashboard-icon name="menu" :size="23" x-show="!mobileOpen" />
                <x-dashboard-icon name="close" :size="23" x-cloak x-show="mobileOpen" />
            </button>
        </div>
    </div>

    <nav id="mobile-navigation" class="mobile-nav" x-cloak x-show="mobileOpen" x-transition @click.outside="mobileOpen = false" aria-label="Mobile navigation">
        @foreach ($navItems as [$label, $icon, $href, $active])
            <a href="{{ $href }}" @class(['nav-item', 'is-active' => $active]) @click="mobileOpen = false">
                <x-dashboard-icon :name="$icon" :size="19" /><span>{{ $label }}</span>
            </a>
        @endforeach
        <a href="{{ route('profile.edit') }}" class="nav-item"><x-dashboard-icon name="settings" :size="19" /><span>Settings</span></a>
    </nav>
</header>
