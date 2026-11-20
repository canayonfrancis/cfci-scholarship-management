<x-app-layout>
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
                <div class="hero-program"><strong>CFCI</strong><br>Scholarship Program</div>
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

            @can('viewAny', \App\Models\ScholarshipApplication::class)
            <article id="create-application" class="create-card">
                <span class="cta-leaves" aria-hidden="true"><i></i><i></i><b></b></span>
                <div>
                    <h2>Direct Scholarship Validation</h2>
                    <p>Review existing academic records.</p>
                </div>
                <a href="{{ route('evaluations.index') }}" class="create-button">View validations <x-dashboard-icon name="arrow-right" :size="17" /></a>
                <span class="cta-watermark" aria-hidden="true"></span>
            </article>
            @endcan
        </section>

        <section class="stats-grid dashboard-secondary-stats" aria-label="Funding and approval overview">
            @foreach($additionalStats as $stat)
                <article class="stat-card stat-{{ $stat['tone'] }}"><span class="stat-icon"><x-dashboard-icon :name="$stat['icon']" :size="30" /></span><div class="stat-content"><p>{{ $stat['label'] }}</p><div class="stat-value-row"><strong>{{ $stat['value'] === null ? 'Unconfirmed' : ($stat['money'] ? '₱'.number_format((float) $stat['value'], 2) : number_format($stat['value'])) }}</strong></div><small>{{ $stat['comparison'] }}</small></div></article>
            @endforeach
        </section>

        @endif
    </div>
</x-app-layout>
