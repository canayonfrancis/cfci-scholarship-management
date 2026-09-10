<x-guest-layout :immersive="true">
    <main class="login-page">
        <section
            class="login-panel"
            aria-labelledby="forgot-password-heading"
            style="--login-background-image: url('{{ asset('images/cronasia-school.webp') }}')"
        >
            <div class="login-content password-reset-content">
                <a class="login-brand" href="/" aria-label="Cronasia Foundation College home">
                    <img src="{{ asset('images/cronasia-logo-white.png') }}" alt="Cronasia Foundation College, Inc.">
                </a>

                <div class="login-intro forgot-password-intro">
                    <h1 id="forgot-password-heading">Forgot <span>Password?</span></h1>
                    <p>Enter your email address and we'll send you<br class="login-intro__break"> a password reset link.</p>
                </div>

                <x-auth-session-status role="status" class="login-status" :status="session('status')" />

                <form class="login-form password-reset-form" method="POST" action="{{ route('password.email') }}">
                    @csrf

                    <div class="login-field-group">
                        <label class="sr-only" for="email">{{ __('Email address') }}</label>
                        <div @class(['login-field', 'login-field--invalid' => $errors->has('email')])>
                            <x-dashboard-icon class="login-field__icon" name="user" size="22" />
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                placeholder="Email address"
                                required
                                autofocus
                                autocomplete="email"
                                @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                            >
                        </div>
                        @error('email')
                            <p class="login-error" id="email-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="login-submit password-reset-submit" type="submit">
                        <span>{{ __('Send Reset Link') }}</span>
                        <x-dashboard-icon name="arrow-right" size="23" />
                    </button>
                </form>

                <a class="back-to-login" href="{{ route('login') }}">
                    <x-dashboard-icon class="back-to-login__arrow" name="arrow-right" size="20" />
                    <span>{{ __('Back to') }} <strong>{{ __('Login') }}</strong></span>
                </a>
            </div>
        </section>
    </main>
</x-guest-layout>
