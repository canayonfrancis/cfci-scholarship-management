<x-records-page title="Student login account" :description="$account->name.' - '.$account->studentNumber">
    <x-slot:actions><a class="records-button" href="{{ route('student-accounts.index') }}">Back to student accounts</a><a class="records-button" href="{{ route('scholars.show', $account->id) }}">View student record</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card" aria-labelledby="student-identity-title">
        <h2 class="records-section-title" id="student-identity-title">Review student identity</h2>
        <dl class="records-details">
            <div><dt>Student ID</dt><dd>{{ $account->studentNumber }}</dd></div>
            <div><dt>Student name</dt><dd>{{ $account->name }}</dd></div>
            <div><dt>Student contact email</dt><dd>{{ $account->contactEmail ?: 'Not recorded' }}</dd></div>
            <div><dt>Student record status</dt><dd>{{ ucfirst($account->recordStatus) }}</dd></div>
        </dl>
    </section>
    <section class="dashboard-card records-card records-form-card" aria-labelledby="login-access-title">
        <h2 class="records-section-title" id="login-access-title">Current login access</h2>
        <dl class="records-details">
            <div><dt>Account association</dt><dd>{{ $account->linked ? 'Linked' : 'No account linked' }}</dd></div>
            <div><dt>Account status</dt><dd><span class="records-badge {{ $account->status === 'linked' ? 'is-green' : 'is-amber' }}">{{ $account->statusLabel }}</span></dd></div>
            <div><dt>Linked login email</dt><dd>{{ $account->loginEmail ?: ($account->status === 'restricted' ? 'Restricted account' : ($account->linked ? 'Unavailable for review' : 'Not linked')) }}</dd></div>
            <div><dt>Student profile access</dt><dd>{{ $account->profileAllowed ? 'Configured' : 'Not configured' }}</dd></div>
        </dl>
        <p class="record-help">Student record status and scholarship approval do not activate or deactivate login access. Account access is managed separately below.</p>
    </section>

    @if($account->status === 'not_linked')
        <section class="dashboard-card records-card records-form-card"><h2 class="records-section-title">No login account</h2><p>This student does not have an associated login account. Review their identity and recorded contact email before creating access.</p></section>
    @elseif($account->status === 'linked')
        <section class="dashboard-card records-card records-form-card"><h2 class="records-section-title">No account change needed</h2><p>This student's existing login account is linked and Student profile access is configured. The student signs in using their existing credentials.</p></section>
    @elseif(in_array($account->status, ['inactive', 'pending_setup'], true))
        <section class="dashboard-card records-card records-form-card"><h2 class="records-section-title">{{ $account->statusLabel }}</h2><p>{{ $account->status === 'inactive' ? 'This account cannot sign in or use the Student portal. Reactivation retains any password setup requirement.' : 'The account is created. Complete the initial Student password setup below to enable sign-in.' }}</p></section>
    @else
        <section class="dashboard-card records-card records-form-card"><h2 class="records-section-title">Authorized technical review required</h2><p>This linked account cannot be managed through the Student account interface. Its credentials and permissions have not been changed. Contact the authorized system operator for review.</p></section>
    @endif

    @if($account->manageable && $account->status === 'pending_setup')
        <section class="dashboard-card records-card records-form-card" aria-labelledby="student-password-title">
            <h2 class="records-section-title" id="student-password-title">Set Student password</h2>
            <p>Complete the initial password setup for <strong>{{ $account->loginEmail }}</strong>. This sets the Student's login password. No email is sent.</p>
            @if(!$passwordConfirmed)
                <p class="record-help">First confirm your own operator password. That confirmation is separate from the Student password you will enter here.</p>
                <a class="records-button is-primary" href="{{ route('student-accounts.confirm', $account->id) }}">Confirm password to continue</a>
            @else
                @php($initialPassword = $errors->any() ? '' : config('student_accounts.initial_password'))
                <form class="mt-6" method="POST" action="{{ route('student-accounts.initial-password', $account->id) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                    @csrf
                    <input type="hidden" name="login_email" value="{{ $account->loginEmail }}">
                    <p class="record-help">Default initial password: <strong>{{ config('student_accounts.initial_password') }}</strong>. You can replace it with a unique password before saving.</p>
                    <div class="records-form-grid mt-4">
                        <x-record-field name="password" label="New Student password" type="password" :value="$initialPassword" autocomplete="new-password" maxlength="255" required help="Choose a private password that meets the existing password policy." />
                        <x-record-field name="password_confirmation" label="Confirm Student password" type="password" :value="$initialPassword" autocomplete="new-password" maxlength="255" required help="Enter the same Student password again." />
                    </div>
                    <div class="record-field">
                        <x-input-label for="student_password_reason" value="Identity review / setup reason" />
                        <textarea id="student_password_reason" name="reason" class="record-input" rows="3" maxlength="1000" required @if($errors->has('reason')) aria-invalid="true" aria-describedby="student-password-reason-error" @endif>{{ old('reason') }}</textarea>
                        <p class="record-help">Record the identity review and reason only. Never enter the password in this field.</p>
                        <x-input-error id="student-password-reason-error" :messages="$errors->get('reason')" />
                    </div>
                    <div class="records-form-footer">
                        <div>
                            <label class="inline-flex items-start gap-2"><input name="identity_confirmed" type="checkbox" value="1" required><span>I reviewed this Student identity and linked login before setting the initial password.</span></label>
                            <x-input-error :messages="$errors->get('identity_confirmed')" />
                        </div>
                        <x-primary-button class="records-submit" x-bind:disabled="submitting" x-bind:aria-busy="submitting">Save Student password</x-primary-button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    @if($account->manageable && in_array($account->status, ['linked', 'inactive', 'pending_setup'], true))
        <section class="dashboard-card records-card records-form-card" aria-labelledby="manage-student-access-title">
            <h2 class="records-section-title" id="manage-student-access-title">{{ $account->status === 'inactive' ? 'Reactivate Student access' : 'Deactivate Student access' }}</h2>
            <p>Only login access changes. The Student profile, scholarship, billing and audit history are preserved. The student must sign in again after reactivation.</p>
            @if(!$passwordConfirmed)
                @if($account->status === 'pending_setup')
                    <p class="record-help">Use the operator password confirmation above before changing Student access.</p>
                @else
                    <a class="records-button is-primary" href="{{ route('student-accounts.confirm', $account->id) }}">Confirm password to continue</a>
                @endif
            @else
                <form method="POST" action="{{ route('student-accounts.'.($account->status === 'inactive' ? 'reactivate' : 'deactivate'), $account->id) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                    @csrf @method('PATCH')
                    <div class="records-form-grid">
                        <x-record-field name="login_email" label="Confirmed login email" type="email" :value="$account->loginEmail" readonly required />
                        <x-record-field name="reason" label="Management reason" type="textarea" :value="old('reason')" maxlength="1000" required help="Record the authorized reason. Do not include passwords or secret tokens." />
                    </div>
                    <label class="inline-flex items-start gap-2"><input name="identity_confirmed" type="checkbox" value="1" required><span>I reviewed the Student identity, linked account and requested access change.</span></label>
                    <div class="records-form-footer"><x-primary-button class="records-submit" x-bind:disabled="submitting" x-bind:aria-busy="submitting">{{ $account->status === 'inactive' ? 'Reactivate Student access' : 'Deactivate Student access' }}</x-primary-button></div>
                </form>
            @endif
        </section>
    @endif

    @if(!$account->linked)
        <section class="dashboard-card records-card records-form-card" aria-labelledby="create-student-account-title">
            <h2 class="records-section-title" id="create-student-account-title">Create Student login account</h2>
            @if(filter_var($account->contactEmail, FILTER_VALIDATE_EMAIL) === false)
                <p>A valid reviewed Student contact email is required. Update the official Student record before creating login access.</p>
                <a class="records-button" href="{{ route('scholars.edit', $account->id) }}">Review Student contact email</a>
            @elseif(!$passwordConfirmed)
                <a class="records-button is-primary" href="{{ route('student-accounts.confirm', $account->id) }}">Confirm password to continue</a>
            @else
                <p>Create a Student account without sending email. Password setup will remain pending, and the student cannot sign in until secure setup is completed.</p>
                <form method="POST" action="{{ route('student-accounts.create', $account->id) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                    @csrf
                    <div class="records-form-grid">
                        <x-record-field name="login_email" label="Confirmed login email" type="email" :value="$account->contactEmail" readonly required />
                        <x-record-field name="reason" label="Identity review / management reason" type="textarea" :value="old('reason')" maxlength="1000" required help="Describe the identity review. Do not include passwords or secret tokens." />
                    </div>
                    <label class="inline-flex items-start gap-2"><input name="identity_confirmed" type="checkbox" value="1" required><span>I verified this Student identity and reviewed the login email for the requested action.</span></label>
                    <div class="records-form-footer"><x-primary-button class="records-submit" x-bind:disabled="submitting" x-bind:aria-busy="submitting">Create new account</x-primary-button></div>
                </form>
            @endif
        </section>
    @endif
</x-records-page>
