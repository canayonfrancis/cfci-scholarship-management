<x-guest-layout :immersive="true">
    <main class="login-page">
        <section class="login-panel password-confirm-panel" aria-labelledby="confirm-password-heading"
                 style="--login-background-image: url('{{ asset('images/cronasia-school.webp') }}')">
            <div class="login-content password-confirm-content">
                <a class="login-brand" href="{{ route('dashboard') }}" aria-label="Cronasia Foundation College dashboard">
                    <img src="{{ asset('images/cronasia-logo-white.png') }}" alt="Cronasia Foundation College, Inc.">
                </a>

                <div class="login-intro">
                    <h1 id="confirm-password-heading">Confirm your <span>password.</span></h1>
                    <p>Before continuing, confirm the password you use to sign in to your own account.</p>
                </div>

                <div class="password-confirm-account">
                    <span>You're signed in as</span>
                    <strong>{{ auth()->user()->email }}</strong>
                </div>

                <aside class="password-confirm-note" aria-labelledby="confirm-password-info-title">
                    <x-dashboard-icon name="lock" size="20" />
                    <div>
                        @can('student-accounts.provision')
                            <h2 id="confirm-password-info-title">Use your Registrar or Administrator password</h2>
                            <p>This confirms your identity. Student account creation and password setup are completed separately on the Manage login page.</p>
                        @else
                            <h2 id="confirm-password-info-title">A quick identity check</h2>
                            <p>This verifies that it's you before a protected action. Confirming here does not create or change your password.</p>
                        @endcan
                    </div>
                </aside>

                <form class="login-form password-confirm-form" method="POST" action="{{ route('password.confirm') }}"
                      x-data="{ passwordVisible: false, submitting: false }"
                      @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                    @csrf
                    <div class="login-field-group">
                        <label class="password-confirm-label" for="password">Your current login password</label>
                        <div @class(['login-field', 'login-field--invalid' => $errors->has('password')])>
                            <x-dashboard-icon class="login-field__icon" name="lock" size="22" />
                            <input id="password" name="password" type="password" :type="passwordVisible ? 'text' : 'password'"
                                   placeholder="Enter your own login password" autocomplete="current-password" required
                                   aria-describedby="confirm-password-help{{ $errors->has('password') ? ' password-error' : '' }}"
                                   @if($errors->has('password')) aria-invalid="true" @endif>
                            <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false"
                                    :aria-label="passwordVisible ? 'Hide password' : 'Show password'" :aria-pressed="passwordVisible.toString()"
                                    @click="passwordVisible = ! passwordVisible">
                                <x-dashboard-icon x-show="! passwordVisible" name="eye" size="21" />
                                <x-dashboard-icon x-cloak x-show="passwordVisible" name="eye-off" size="21" />
                            </button>
                        </div>
                        @error('password')
                            <p class="login-error" id="password-error" role="alert">{{ $message }}</p>
                        @enderror
                        <p class="password-confirm-help" id="confirm-password-help">Enter the password for the signed-in account shown above.</p>
                    </div>

                    <button class="login-submit password-confirm-submit" type="submit" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                        <span>Confirm and continue</span><x-dashboard-icon name="arrow-right" size="22" />
                    </button>
                    <p class="password-confirm-next">After confirmation, you'll return to the page you were working on.</p>
                </form>

                @can('student-accounts.provision')
                    <a class="password-confirm-back" href="{{ route('student-accounts.index') }}">Back to Student accounts</a>
                @else
                    <a class="password-confirm-back" href="{{ route('dashboard') }}">Back to dashboard</a>
                @endcan
            </div>
        </section>
    </main>
</x-guest-layout>
