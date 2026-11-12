<header class="site-header" x-data="{ mobileOpen: false, profileOpen: false }" @keydown.escape.window="mobileOpen = profileOpen = false">
    <a class="skip-link" href="#main-content">Skip to dashboard</a>
    <div class="topbar">
        <a href="{{ route('dashboard') }}" class="brand" aria-label="CFCI Scholarship Management dashboard">
            <img class="brand-logo" src="{{ asset('images/cfc-logo.webp') }}" alt="Cronasia Foundation College, Inc.">
        </a>

        <div class="desktop-nav-wrap" x-data="scrollableNavigation" :class="{ 'can-scroll-left': canScrollLeft, 'can-scroll-right': canScrollRight }">
        <button class="nav-scroll-button is-left" type="button" x-cloak x-show="canScrollLeft" x-transition.opacity.duration.150ms :disabled="!canScrollLeft" @click="scrollNavigation(-1)" aria-label="Scroll navigation left" aria-controls="primary-navigation"><x-dashboard-icon name="chevron-right" :size="20" /></button>
        <nav id="primary-navigation" class="desktop-nav" x-ref="navigation" aria-label="Primary navigation" tabindex="0" @scroll.passive="updateScrollEdges()">
            @php
                $navItems = [
                    ['Dashboard', 'home', route('dashboard'), request()->routeIs('dashboard')],
                ];
                if (auth()->user()->can('viewAny', \App\Models\ScholarshipApplication::class)) {
                    $navItems[] = ['Evaluations', 'clipboard', route('evaluations.index'), request()->routeIs('evaluations.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\AcademicRecord::class)) {
                    $navItems[] = ['Academic verification', 'check-circle', route('academic-verifications.index'), request()->routeIs('academic-verifications.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\Student::class)) {
                    $navItems[] = ['Scholars', 'users', route('scholars.index'), request()->routeIs('scholars.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\ScholarshipProgram::class)) {
                    $navItems[] = ['Scholarship programs', 'graduation-cap', route('scholarship-programs.index'), request()->routeIs('scholarship-programs.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\AcademicPeriod::class)) {
                    $navItems[] = ['Academic periods', 'calendar', route('academic-periods.index'), request()->routeIs('academic-periods.*')];
                }
                if (auth()->user()->can('viewAny', \App\Models\BillingBatch::class)) {
                    $navItems[] = ['Billing batches', 'clipboard', route('billing-batches.index'), request()->routeIs('billing-batches.*')];
                }
                if (auth()->user()->can('financial-view')) {
                    $navItems[] = ['Payments & liquidation', 'clipboard', route('financial.index'), request()->routeIs('financial.*')];
                    $navItems[] = ['Scholarship reports', 'clipboard', route('scholarship-reports.index', ['report' => auth()->user()->can('viewAny', \App\Models\ScholarshipBatch::class) ? 'grants' : 'billed']), request()->routeIs('scholarship-reports.*')];
                }
                $roleLabels = auth()->user()->roles->pluck('role')->map(fn ($role) => match ($role) {
                    'coordinator' => 'School Scholarship Coordinator',
                    'registrar' => 'Registrar Staff',
                    'administrator' => 'Test Administrator',
                    default => in_array($role, \App\Models\User::STAFF_ROLES, true) ? Str::headline($role).' (legacy; review required)' : 'Unrecognized role',
                });
                $initials = Str::of(auth()->user()->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
            @endphp
            @foreach ($navItems as [$label, $icon, $href, $active])
                <a href="{{ $href }}" @class(['nav-item', 'is-active' => $active]) @if($active) aria-current="page" @endif>
                    <x-dashboard-icon :name="$icon" :size="18" /><span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>
        <button class="nav-scroll-button is-right" type="button" x-cloak x-show="canScrollRight" x-transition.opacity.duration.150ms :disabled="!canScrollRight" @click="scrollNavigation(1)" aria-label="Scroll navigation right" aria-controls="primary-navigation"><x-dashboard-icon name="chevron-right" :size="20" /></button>
        </div>

        <div class="topbar-actions">
            <div class="profile-wrap">
                <button class="profile-button" type="button" @click="profileOpen = !profileOpen" :aria-expanded="profileOpen" aria-label="Open user menu">
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
