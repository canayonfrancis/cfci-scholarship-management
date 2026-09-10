<x-guest-layout :immersive="true">
    <main class="login-page">
        <section
            class="login-panel"
            aria-labelledby="login-heading"
            style="--login-background-image: url('{{ asset('images/cronasia-school.webp') }}')"
        >
            <div class="login-content">
                <a class="login-brand" href="/" aria-label="Cronasia Foundation College home">
                    <img src="{{ asset('images/cronasia-logo-white.png') }}" alt="Cronasia Foundation College, Inc.">
                </a>

                <div class="login-intro">
                    <h1 id="login-heading">Welcome <span>Back.</span></h1>
                    <p>Please login to your account to continue<br class="login-intro__break"> to the Scholarship Management System.</p>
                </div>

                <x-auth-session-status class="login-status" :status="session('status')" />

                <form class="login-form" method="POST" action="{{ route('login') }}" x-data="{ passwordVisible: false }">
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
                                autocomplete="username"
                                @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                            >
                        </div>
                        @error('email')
                            <p class="login-error" id="email-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="login-field-group">
                        <label class="sr-only" for="password">{{ __('Password') }}</label>
                        <div @class(['login-field', 'login-field--invalid' => $errors->has('password')])>
                            <x-dashboard-icon class="login-field__icon" name="lock" size="22" />
                            <input
                                id="password"
                                type="password"
                                :type="passwordVisible ? 'text' : 'password'"
                                name="password"
                                placeholder="Password"
                                required
                                autocomplete="current-password"
                                @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif
                            >
                            <button
                                class="password-toggle"
                                type="button"
                                @click="passwordVisible = ! passwordVisible"
                                :aria-label="passwordVisible ? 'Hide password' : 'Show password'"
                                :aria-pressed="passwordVisible.toString()"
                            >
                                <x-dashboard-icon x-show="! passwordVisible" name="eye" size="21" />
                                <x-dashboard-icon x-cloak x-show="passwordVisible" name="eye-off" size="21" />
                            </button>
                        </div>
                        @error('password')
                            <p class="login-error" id="password-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="login-options">
                        <label class="remember-option" for="remember_me">
                            <input id="remember_me" type="checkbox" name="remember">
                            <span>{{ __('Remember me') }}</span>
                        </label>

                        @if (Route::has('password.request'))
                            <a class="forgot-link" href="{{ route('password.request') }}">
                                {{ __('Forgot password?') }}
                            </a>
                        @endif
                    </div>

                    <button class="login-submit" type="submit">
                        <span>{{ __('Log in') }}</span>
                        <x-dashboard-icon name="arrow-right" size="23" />
                    </button>

                    @if (Route::has('register'))
                        <p class="register-prompt">
                            {{ __("Don't have an account?") }}
                            <a href="{{ route('register') }}">{{ __('Create your account') }}</a>
                        </p>
                    @endif
                </form>
            </div>
        </section>
    </main>
</x-guest-layout>
