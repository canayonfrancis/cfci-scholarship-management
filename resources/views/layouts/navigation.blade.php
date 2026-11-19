@php
    $user = auth()->user();
    $link = fn ($key, $label, $icon, $href, $active = false) => compact('key', 'label', 'icon', 'href', 'active');
    $group = fn ($key, $label, $icon, $children) => ['key' => $key, 'label' => $label, 'icon' => $icon, 'children' => $children, 'active' => collect($children)->contains(fn ($child) => $child['active'] ?? false)];
    $navItems = $user->isStudentOnly() ? [] : [$link('dashboard', 'Dashboard', 'home', route('dashboard'), request()->routeIs('dashboard'))];
    $registrySection = request()->query('registry');
    $registry = [];
    if ($user->can('viewAny', \App\Models\ScholarshipProgram::class)) {
        $registry[] = $link('programs', 'Scholarship Grant / Program', 'graduation-cap', route('scholarship-programs.index'), request()->routeIs('scholarship-programs.*'));
    }
    if ($user->can('viewAny', \App\Models\ScholarshipBatch::class)) {
        $registry[] = $link('batch', 'Batch', 'clipboard', route('scholarship-batches.index', ['registry' => 'batch']), request()->routeIs('scholarship-batches.*') && $registrySection === 'batch');
    }
    if ($user->can('viewAny', \App\Models\AcademicProgram::class)) {
        $registry[] = $link('courses', 'Course / Program', 'graduation-cap', route('academic-programs.index'), request()->routeIs('academic-programs.*'));
    }
    if ($user->can('viewAny', \App\Models\Student::class)) {
        $registry[] = $link('scholars', 'Scholars', 'users', route('scholars.index'), request()->routeIs('scholars.*'));
    }
    if ($user->can('viewAny', \App\Models\AcademicPeriod::class)) {
        $registry[] = $link('academic-year', 'Academic Year', 'calendar', route('academic-periods.index', ['registry' => 'academic-year']), request()->routeIs('academic-periods.*') && $registrySection === 'academic-year');
        $registry[] = $link('semester', 'Semester', 'calendar', route('academic-periods.index', ['registry' => 'semester']), request()->routeIs('academic-periods.*') && $registrySection !== 'academic-year');
    }
    if ($user->can('viewAny', \App\Models\Student::class)) {
        // No standalone registry exists; the user explicitly requested an unavailable item.
        $registry[] = ['key' => 'delisting', 'label' => 'Reason for Delisting', 'icon' => 'document', 'active' => false, 'unavailable' => 'Unavailable — no separate registry exists.'];
    }
    if ($user->can('viewAny', \App\Models\ScholarshipBatch::class)) {
        $registry[] = $link('roster', 'Roster', 'user-group', route('scholarship-batches.index', ['registry' => 'roster']), request()->routeIs('scholarship-batches.*') && $registrySection !== 'batch');
    }
    if ($user->can('student-accounts.provision')) {
        $registry[] = $link('student-accounts', 'Student accounts', 'user', route('student-accounts.index'), request()->routeIs('student-accounts.*'));
    }
    if ($registry) $navItems[] = $group('registry', 'Information Registry', 'document', $registry);
    $validation = [];
    if ($user->can('viewAny', \App\Models\BillingBatch::class)) {
        $validation[] = $link('billing', 'Billing batches', 'clipboard', route('billing-batches.index'), request()->routeIs('billing-batches.*'));
    }
    if ($user->can('viewAny', \App\Models\ScholarshipApplication::class)) {
        $validation[] = $link('evaluations', 'Evaluations', 'clipboard', route('evaluations.index'), request()->routeIs('evaluations.*'));
    }
    if ($validation) $navItems[] = $group('validation', 'Billing and Validation', 'clipboard', $validation);
    if ($user->can('financial-view')) {
        $navItems[] = $group('financial', 'Liquidation and Payment', 'document', [
            $link('payments', 'Payments and liquidation', 'document', route('financial.index'), request()->routeIs('financial.*') && !request()->routeIs('financial.funding-status')),
            $link('funding-status', 'Billing stages / awaiting funds', 'chart', route('financial.funding-status'), request()->routeIs('financial.funding-status')),
        ]);
        $navItems[] = $link('reports', 'Reports', 'chart', route('scholarship-reports.index', ['report' => $user->can('viewAny', \App\Models\ScholarshipBatch::class) ? 'grants' : 'billed']), request()->routeIs('scholarship-reports.*'));
    }
    if ($user->isStudentOnly() && $user->can('viewSelf', \App\Models\Student::class)) {
        $navItems[] = $link('my-profile', 'My profile', 'user', route('student.profile'), request()->routeIs('student.profile*'));
    }
    $roleLabels = $user->roles->pluck('role')->map(fn ($role) => match ($role) {
        'coordinator' => 'School Scholarship Coordinator', 'registrar' => 'Registrar Staff',
        'administrator' => 'Test Administrator', 'student' => 'Student',
        default => in_array($role, \App\Models\User::STAFF_ROLES, true) ? Str::headline($role).' (legacy; review required)' : 'Unrecognized role',
    });
    $initials = Str::of($user->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
@endphp
<header class="site-header" x-data="primaryNavigation" @pointerdown.window="pointerDown($event)" @focusin.window="focusChanged($event)" @keydown.window="keyboardInput($event)" @resize.window="closeAll()" @scroll.window.passive="positionDropdown()" @navigation-scroll="navigationScrolled()">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="topbar">
        <a href="{{ route('dashboard') }}" class="brand" aria-label="CFCI Scholarship Management dashboard"><img class="brand-logo" src="{{ asset('images/cfc-logo.webp') }}" alt="Cronasia Foundation College, Inc."></a>
        <div class="desktop-nav-wrap" x-data="scrollableNavigation" :class="{ 'can-scroll-left': canScrollLeft, 'can-scroll-right': canScrollRight }">
            <button class="nav-scroll-button is-left" type="button" x-cloak x-show="canScrollLeft" :disabled="!canScrollLeft" @click="scrollNavigation(-1)" aria-label="Scroll navigation left" aria-controls="primary-navigation"><x-dashboard-icon name="chevron-right" :size="20" /></button>
            <nav id="primary-navigation" class="desktop-nav" x-ref="navigation" aria-label="Primary navigation" tabindex="0" @scroll.passive="updateScrollEdges(); $dispatch('navigation-scroll')" @keydown="rootKey($event)">
                @foreach($navItems as $item)
                    @if(isset($item['children']))
                        <div class="nav-group" @pointerenter="hoverParent('{{ $item['key'] }}', $event)" @pointerleave="scheduleClose('{{ $item['key'] }}')">
                            <button id="desktop-nav-{{ $item['key'] }}" type="button" data-primary-item @class(['nav-item', 'nav-parent', 'is-active' => $item['active']]) data-active-navigation="{{ $item['active'] ? 'true' : 'false' }}" aria-controls="desktop-dropdown-{{ $item['key'] }}" aria-expanded="false" :aria-expanded="openMenu === '{{ $item['key'] }}'" :class="{ 'is-open': openMenu === '{{ $item['key'] }}' }" @click="clickParent('{{ $item['key'] }}', $event)" @focus="focusParent('{{ $item['key'] }}')" @keydown="parentKey('{{ $item['key'] }}', $event)">
                                <x-dashboard-icon :name="$item['icon']" :size="18" /><span>{{ $item['label'] }}</span><x-dashboard-icon name="chevron-down" :size="15" class="nav-parent-chevron" />
                            </button>
                        </div>
                    @else
                        <a data-primary-item href="{{ $item['href'] }}" @class(['nav-item', 'is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif @pointerenter="closeDropdown()"><x-dashboard-icon :name="$item['icon']" :size="18" /><span>{{ $item['label'] }}</span></a>
                    @endif
                @endforeach
            </nav>
            <button class="nav-scroll-button is-right" type="button" x-cloak x-show="canScrollRight" :disabled="!canScrollRight" @click="scrollNavigation(1)" aria-label="Scroll navigation right" aria-controls="primary-navigation"><x-dashboard-icon name="chevron-right" :size="20" /></button>
        </div>
        <div class="topbar-actions">
            <div class="profile-wrap">
                <button class="profile-button" data-profile-trigger type="button" @click="toggleProfile()" :aria-expanded="profileOpen" aria-controls="user-navigation" aria-label="Open user menu"><span class="avatar" aria-hidden="true">{{ $initials }}</span><span class="profile-copy"><strong>{{ $user->name }}</strong><small>{{ $roleLabels->isNotEmpty() ? $roleLabels->implode(', ') : 'No role assigned' }}</small></span><x-dashboard-icon name="chevron-down" :size="17" /></button>
                <div id="user-navigation" class="popover profile-popover" x-cloak x-show="profileOpen" @click.outside="profileOpen = false">
                    @can('viewSelf', \App\Models\Student::class)<a href="{{ route('student.profile') }}">My student profile</a>@endcan
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Log out</button></form>
                </div>
            </div>
            <button class="round-button mobile-menu-button" data-mobile-trigger type="button" @click="toggleMobile()" :aria-expanded="mobileOpen" aria-controls="mobile-navigation" aria-label="Toggle navigation"><x-dashboard-icon name="menu" :size="23" x-show="!mobileOpen" /><x-dashboard-icon name="close" :size="23" x-cloak x-show="mobileOpen" /></button>
        </div>
    </div>
    <nav id="mobile-navigation" class="mobile-nav" x-cloak x-show="mobileOpen" aria-label="Mobile navigation">
        @foreach($navItems as $item)
            @if(isset($item['children']))
                <div class="mobile-nav-group">
                    <button id="mobile-nav-{{ $item['key'] }}" type="button" @class(['nav-item', 'nav-parent', 'is-active' => $item['active']]) aria-controls="mobile-dropdown-{{ $item['key'] }}" aria-expanded="false" :aria-expanded="mobileGroup === '{{ $item['key'] }}'" @click="toggleMobileGroup('{{ $item['key'] }}')"><x-dashboard-icon :name="$item['icon']" :size="19" /><span>{{ $item['label'] }}</span><x-dashboard-icon name="chevron-down" :size="16" class="nav-parent-chevron" /></button>
                    <ul id="mobile-dropdown-{{ $item['key'] }}" class="mobile-dropdown" x-show="mobileGroup === '{{ $item['key'] }}'" x-cloak aria-labelledby="mobile-nav-{{ $item['key'] }}">
                        @foreach($item['children'] as $child)<li>@if(isset($child['unavailable']))<span class="primary-nav-child is-unavailable" role="link" aria-disabled="true"><x-dashboard-icon :name="$child['icon']" :size="17" /><span>{{ $child['label'] }}<small>{{ $child['unavailable'] }}</small></span></span>@else<a href="{{ $child['href'] }}" @class(['primary-nav-child', 'is-active' => $child['active']]) @if($child['active']) aria-current="page" @endif @click="closeAll()"><x-dashboard-icon :name="$child['icon']" :size="17" /><span>{{ $child['label'] }}</span></a>@endif</li>@endforeach
                    </ul>
                </div>
            @else<a href="{{ $item['href'] }}" @class(['nav-item', 'is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif @click="closeAll()"><x-dashboard-icon :name="$item['icon']" :size="19" /><span>{{ $item['label'] }}</span></a>@endif
        @endforeach
    </nav>
    {{-- Portal outside both the overflow scroll wrapper and filtered header containing block. --}}
    @foreach($navItems as $item)@if(isset($item['children']))
        <template x-teleport="body">
            <div id="desktop-dropdown-{{ $item['key'] }}" class="primary-dropdown" role="region" aria-labelledby="desktop-nav-{{ $item['key'] }}" x-cloak x-show="openMenu === '{{ $item['key'] }}'" :style="popupStyle" @pointerenter="cancelClose()" @pointerleave="scheduleClose('{{ $item['key'] }}')" @focusin="cancelClose()" @focusout="scheduleClose('{{ $item['key'] }}')" @keydown="panelKey('{{ $item['key'] }}', $event)">
                <div class="primary-dropdown-content"><ul>
                    @foreach($item['children'] as $child)<li>@if(isset($child['unavailable']))<span class="primary-nav-child is-unavailable" role="link" aria-disabled="true"><x-dashboard-icon :name="$child['icon']" :size="17" /><span>{{ $child['label'] }}<small>{{ $child['unavailable'] }}</small></span></span>@else<a href="{{ $child['href'] }}" @class(['primary-nav-child', 'is-active' => $child['active']]) @if($child['active']) aria-current="page" @endif @click="closeAll()"><x-dashboard-icon :name="$child['icon']" :size="17" /><span>{{ $child['label'] }}</span></a>@endif</li>@endforeach
                </ul></div>
            </div>
        </template>
    @endif @endforeach
</header>
