<x-app-layout>
    @if($canReport)
    @php
        $chartLeft = 36;
        $chartWidth = 714;
        $chartBottom = 174;
        $chartHeight = 140;
        $pointString = fn ($values) => collect($values)->map(fn ($value, $index) => round($chartLeft + ($index * $chartWidth / 11), 1).','.round($chartBottom - ($value * $chartHeight / $chartMax), 1))->implode(' ');
        $applicationPoints = $pointString($applications);
        $qualifiedPoints = $pointString($qualified);
        $statusClass = fn ($status) => match ($status) {
            'School eligible' => 'status-green',
            'School ineligible' => 'status-red',
            default => 'status-blue',
        };
    @endphp
    @endif

    <div class="dashboard-page">
        <section
            class="dashboard-hero"
            aria-labelledby="welcome-heading"
            x-data="{ imageReady: false, imageFailed: false }"
            x-init="$nextTick(() => { if ($refs.heroImage.complete) { imageReady = $refs.heroImage.naturalWidth > 0; imageFailed = !imageReady } })"
        >
            <div class="hero-image-skeleton" x-show="!imageReady && !imageFailed" aria-hidden="true"></div>
            <div class="hero-image-fallback" x-cloak x-show="imageFailed" aria-hidden="true"><i></i><i></i></div>
            <img
                x-ref="heroImage"
                src="{{ asset('images/cronasia-foundation-college.webp') }}"
                alt="Cronasia Foundation College campus building"
                class="hero-image"
                :class="{ 'is-loaded': imageReady, 'is-hidden': imageFailed }"
                x-on:load="imageReady = true"
                x-on:error="imageFailed = true"
                loading="eager"
                fetchpriority="high"
                decoding="async"
            >
            <div class="hero-overlay"></div>
            <div class="hero-copy">
                <h1 id="welcome-heading" class="welcome-heading">{{ $hero['greeting'] }}, <span>{{ $hero['firstName'] }}!</span></h1>
                <p>Together, we create opportunities for a brighter tomorrow.</p>
            </div>
            <div class="hero-meta">
                <time datetime="{{ $hero['dateTime'] }}">{{ $hero['date'] }}</time>
                <a class="hero-program" href="#scholars-by-program" aria-label="View CFCI scholarship programs"><strong>CFCI</strong><br>Scholarship Program</a>
            </div>
        </section>

        @if(!$canReport)
            <section class="dashboard-card dashboard-access-notice"><p>Your account does not have staff reporting access. Contact a Coordinator to assign the appropriate role.</p></section>
        @else
        <section class="stats-grid" aria-label="Scholarship validation overview">
            @foreach ($stats as $stat)
                <article class="stat-card stat-{{ $stat['tone'] }}">
                    <span class="stat-icon"><x-dashboard-icon :name="$stat['icon']" :size="34" /></span>
                    <div class="stat-content">
                        <p>{{ $stat['label'] }}</p>
                        <div class="stat-value-row">
                            <strong>{{ number_format($stat['value']) }}</strong>
                            <span class="trend-badge">Live</span>
                        </div>
                        <small>{{ $stat['comparison'] }}</small>
                    </div>
                </article>
            @endforeach

            <article id="create-application" class="create-card">
                <span class="cta-leaves" aria-hidden="true"><i></i><i></i><b></b></span>
                <div>
                    <h2>Direct Scholarship Validation</h2>
                    <p>Review existing academic records.</p>
                </div>
                <a href="{{ auth()->user()->can('viewAny', \App\Models\ScholarshipApplication::class) ? route('evaluations.index') : '#recent-applications' }}" class="create-button">View validations <x-dashboard-icon name="arrow-right" :size="17" /></a>
                <span class="cta-watermark" aria-hidden="true"></span>
            </article>
        </section>

        <section class="stats-grid dashboard-secondary-stats" aria-label="Funding and approval overview">
            @foreach($additionalStats as $stat)
                <article class="stat-card stat-{{ $stat['tone'] }}"><span class="stat-icon"><x-dashboard-icon :name="$stat['icon']" :size="30" /></span><div class="stat-content"><p>{{ $stat['label'] }}</p><div class="stat-value-row"><strong>{{ $stat['value'] === null ? 'Unconfirmed' : ($stat['money'] ? '₱'.number_format((float) $stat['value'], 2) : number_format($stat['value'])) }}</strong></div><small>{{ $stat['comparison'] }}</small></div></article>
            @endforeach
        </section>

        <section class="analytics-grid" aria-label="Validation analytics">
            <article id="applications-trend" class="dashboard-card trend-card" x-data="{ chartState: 'ready', activePoint: 11 }">
                <header class="card-header trend-header">
                    <div>
                        <h2><span class="title-icon"><x-dashboard-icon name="trend" :size="20" /></span>Validations Trend</h2>
                        <p>Latest semester evaluations · last 12 calendar months (Manila)</p>
                    </div>
                </header>

                <template x-if="chartState === 'loading'"><x-dashboard-skeleton type="chart" /></template>
                <div x-cloak x-show="chartState === 'error'" class="dashboard-error"><p>Unable to load validation statistics.</p><button type="button" @click="chartState = 'ready'"><x-dashboard-icon name="reload" :size="15" /> Retry</button></div>
                <div class="trend-chart" x-show="chartState === 'ready'">
                    <div class="chart-legend" aria-hidden="true"><span><i class="application-dot"></i>Validations</span><span><i class="blue-dot"></i>Qualified</span></div>
                    <svg viewBox="0 0 790 210" role="img" aria-labelledby="trend-chart-title trend-chart-desc" preserveAspectRatio="none">
                        <title id="trend-chart-title">Latest evaluations and current school eligibility by month</title>
                        <desc id="trend-chart-desc">{{ array_sum($applications) }} latest semester evaluation runs in this period; {{ array_sum($qualified) }} currently school-eligible contexts. Historical reruns are not counted again.</desc>
                        <defs>
                            <linearGradient id="applications-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#A80E0E" stop-opacity=".18"/><stop offset="1" stop-color="#A80E0E" stop-opacity="0"/></linearGradient>
                            <linearGradient id="qualified-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4A9DDB" stop-opacity=".13"/><stop offset="1" stop-color="#4A9DDB" stop-opacity="0"/></linearGradient>
                        </defs>
                        @foreach ([0, $chartMax / 4, $chartMax / 2, $chartMax * 3 / 4, $chartMax] as $tick)
                            @php $y = $chartBottom - ($tick * $chartHeight / $chartMax); @endphp
                            <line class="chart-grid-line" x1="{{ $chartLeft }}" y1="{{ $y }}" x2="{{ $chartLeft + $chartWidth }}" y2="{{ $y }}" />
                            <text class="chart-axis" x="3" y="{{ $y + 4 }}">{{ $tick }}</text>
                        @endforeach
                        @foreach ($months as $index => $month)
                            @php $x = round($chartLeft + ($index * $chartWidth / 11), 1); @endphp
                            <line class="chart-grid-line vertical" x1="{{ $x }}" y1="34" x2="{{ $x }}" y2="174" />
                            <text class="chart-month" x="{{ $x }}" y="202" text-anchor="middle">{{ $month }}</text>
                        @endforeach
                        <polygon points="{{ $chartLeft }},{{ $chartBottom }} {{ $applicationPoints }} {{ $chartLeft + $chartWidth }},{{ $chartBottom }}" fill="url(#applications-fill)" />
                        <polygon points="{{ $chartLeft }},{{ $chartBottom }} {{ $qualifiedPoints }} {{ $chartLeft + $chartWidth }},{{ $chartBottom }}" fill="url(#qualified-fill)" />
                        <polyline points="{{ $applicationPoints }}" class="chart-line application-line" />
                        <polyline points="{{ $qualifiedPoints }}" class="chart-line qualified-line" />
                        @foreach ($months as $index => $month)
                            @php
                                $x = round($chartLeft + ($index * $chartWidth / 11), 1);
                                $appY = round($chartBottom - ($applications[$index] * $chartHeight / $chartMax), 1);
                                $qualifiedY = round($chartBottom - ($qualified[$index] * $chartHeight / $chartMax), 1);
                            @endphp
                            <g class="chart-points" tabindex="0" role="button" aria-label="{{ $month }}: {{ $applications[$index] }} validations, {{ $qualified[$index] }} qualified" @mouseenter="activePoint = {{ $index }}" @focus="activePoint = {{ $index }}">
                                <circle cx="{{ $x }}" cy="{{ $appY }}" r="4.5" class="application-point" />
                                <circle cx="{{ $x }}" cy="{{ $qualifiedY }}" r="4.5" class="qualified-point" />
                            </g>
                        @endforeach
                        <line class="active-guide" :x1="36 + (activePoint * 714 / 11)" y1="34" :x2="36 + (activePoint * 714 / 11)" y2="174" />
                    </svg>
                    @foreach ($months as $index => $month)
                        <div class="chart-tooltip" x-cloak x-show="activePoint === {{ $index }}" :style="`left: clamp(76px, ${5 + ({{ $index }} * 8.1)}%, calc(100% - 144px))`">
                            <strong>{{ $month }}</strong>
                            <span><i class="application-dot"></i>Validations <b>{{ $applications[$index] }}</b></span>
                            <span><i class="blue-dot"></i>Qualified <b>{{ $qualified[$index] }}</b></span>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="dashboard-card status-card" x-data="{ statusState: 'ready' }">
                <header class="card-header"><h2><span class="title-icon"><x-dashboard-icon name="sparkle" :size="20" /></span>Validation Status</h2></header>
                <template x-if="statusState === 'loading'"><x-dashboard-skeleton type="status" /></template>
                <div x-cloak x-show="statusState === 'error'" class="dashboard-error"><p>Unable to load validation status.</p><button type="button" @click="statusState = 'ready'"><x-dashboard-icon name="reload" :size="15" /> Retry</button></div>
                <div x-show="statusState === 'ready'">
                    <div class="donut" style="background: {{ $donutStyle }}" role="img" aria-label="{{ array_sum(array_column($statuses, 'count')) }} semester validations: {{ collect($statuses)->map(fn ($s) => $s['count'].' '.$s['label'])->implode(', ') }}">
                        <div><strong>{{ array_sum(array_column($statuses, 'count')) }}</strong><span>Semester checks</span></div>
                    </div>
                    <ul class="status-list">
                        @foreach ($statuses as $status)
                            <li><span><i class="status-dot dot-{{ $status['tone'] }}"></i>{{ $status['label'] }}</span><strong>{{ $status['count'] }}</strong><small>{{ $status['percent'] }}%</small></li>
                        @endforeach
                    </ul>
                </div>
            </article>

        </section>

        <section class="lower-grid" aria-label="Validation and scholar details">
            <article id="recent-applications" class="dashboard-card recent-card" x-data="{ tableState: 'ready' }">
                <header class="card-header card-header-inline">
                    <h2><span class="title-icon"><x-dashboard-icon name="document" :size="20" /></span>Recent Validations</h2>
                    @can('viewAny', \App\Models\ScholarshipApplication::class)<a href="{{ route('evaluations.index') }}" class="text-link">View All <x-dashboard-icon name="arrow-right" :size="15" /></a>@endcan
                </header>
                <template x-if="tableState === 'loading'"><x-dashboard-skeleton type="table" /></template>
                <div x-cloak x-show="tableState === 'error'" class="dashboard-error"><p>Unable to load recent validations.</p><button type="button" @click="tableState = 'ready'"><x-dashboard-icon name="reload" :size="15" /> Retry</button></div>
                @if (!auth()->user()->can('viewAny', \App\Models\ScholarshipApplication::class))
                    <p class="record-help">Detailed academic evaluations are restricted to authorized Registrar and Coordinator accounts. Aggregate counts remain visible above.</p>
                @elseif (count($recentApplications) || request()->has('tables.dashboard_validations'))
                    <div class="table-wrap" x-show="tableState === 'ready'" tabindex="0" role="region" aria-label="Recent validations table, scroll horizontally for more columns">
                        <x-table-state :paginator="$recentApplications" />
                        <table class="recent-validations-table">
                            <caption class="sr-only">Semester checks, newest first</caption>
                            <thead><tr><th scope="col">Student ID</th><th scope="col">Name</th><th scope="col">Program</th><th scope="col">Status</th><th scope="col">Date Recorded</th><th scope="col">View</th></tr></thead>
                            <tbody>
                                @forelse ($recentApplications as $application)
                                    <tr tabindex="0">
                                        <td data-label="#">{{ $application['id'] }}</td>
                                        <td data-label="Name"><span class="applicant"><i>{{ $application['initials'] }}</i><span class="scholar-name">{{ $application['name'] }}</span></span></td>
                                        <td data-label="Program">{{ $application['program'] }}</td>
                                        <td data-label="Status"><span class="status-pill {{ $statusClass($application['status']) }}">{{ $application['status'] }}</span></td>
                                        <td data-label="Date Recorded">{{ $application['submitted'] }}</td>
                                        <td data-label="Actions" class="actions-cell">
                                            <a class="ellipsis-button" href="{{ $application['url'] }}" aria-label="View validation for {{ $application['name'] }}" title="View validation"><x-dashboard-icon name="eye" :size="19" /></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6">No validations match these column filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="records-pagination">{{ $recentApplications->links() }}</div>
                @else
                    <x-dashboard-empty />
                @endif
            </article>

            <article id="scholars-by-program" class="dashboard-card programs-card">
                <header class="card-header card-header-inline">
                    <h2 id="scholars-by-program-title"><span class="title-icon"><x-dashboard-icon name="graduation-cap" :size="20" /></span>Scholars by Program</h2>
                    <a href="{{ route('scholars.index') }}" class="text-link">View All <x-dashboard-icon name="arrow-right" :size="15" /></a>
                </header>
                <div class="program-list" tabindex="0" role="region" aria-labelledby="scholars-by-program-title">
                    @foreach ($programs as $program)
                        <div class="program-row">
                            <span>{{ $program['name'] }}</span>
                            <div class="progress-track" role="progressbar" aria-label="{{ $program['name'] }} active scholars" aria-valuenow="{{ $program['percent'] }}" aria-valuemin="0" aria-valuemax="100"><i class="shade-{{ $program['shade'] }}" style="width: {{ $program['percent'] }}%"></i></div>
                            <strong>{{ $program['count'] }}</strong><small>{{ $program['percent'] }}%</small>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="dashboard-card quick-links-card">
                <header class="card-header"><h2><span class="title-icon"><x-dashboard-icon name="clipboard" :size="20" /></span>Quick Links</h2></header>
                <div class="quick-links-grid">
                    @foreach ($quickLinks as $link)
                        <a href="{{ $link['href'] }}"><x-dashboard-icon :name="$link['icon']" :size="30" /><span>{{ $link['label'] }}</span></a>
                    @endforeach
                </div>
            </article>
        </section>

        @endif
    </div>
</x-app-layout>
