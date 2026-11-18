<x-records-page title="Student accounts" description="Find existing students, review login access, and manage account status.">
    <section class="dashboard-card records-card" aria-label="Student login accounts">
        <form class="records-filters" method="GET" action="{{ route('student-accounts.index') }}">
            <x-record-field name="q" label="Search students" :value="$filters['q'] ?? ''" type="search" maxlength="100" placeholder="Student number, name, or email" />
            <x-record-field name="account_status" label="Account status" type="select">
                <option value="">All account statuses</option>
                @foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['account_status'] ?? '') === $value)>{{ $label }}</option>@endforeach
            </x-record-field>
            <div class="records-actions"><x-primary-button class="records-submit">Search</x-primary-button><a class="records-button" href="{{ route('student-accounts.index') }}">Clear</a></div>
        </form>
        <div class="records-table-wrap">
            <x-table-state :paginator="$students" />
            <table class="records-table">
                <caption class="sr-only">Student login account directory</caption>
                <thead><tr><th scope="col">Student ID</th><th scope="col">Student</th><th scope="col">Contact email</th><th scope="col">Login email</th><th scope="col">Account status</th><th scope="col">Student profile access</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @forelse($students as $account)
                    <tr>
                        <td>{{ $account->studentNumber }}</td><td>{{ $account->name }}</td>
                        <td>{{ $account->contactEmail ?: 'Not recorded' }}</td>
                        <td>{{ $account->loginEmail ?: ($account->status === 'restricted' ? 'Restricted account' : ($account->linked ? 'Unavailable for review' : 'Not linked')) }}</td>
                        <td><span class="records-badge {{ $account->status === 'linked' ? 'is-green' : ($account->status === 'needs_review' ? 'is-amber' : '') }}">{{ $account->statusLabel }}</span></td>
                        <td>{{ $account->profileAllowed ? 'Configured' : 'Not configured' }}</td>
                        <td><a class="records-link" href="{{ route('student-accounts.show', $account->id) }}" aria-label="Manage login for {{ $account->name }}">Manage login</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="records-empty"><x-dashboard-icon name="users" :size="36" /><strong>No students match these filters</strong><p>Try another student number, name, email, or account status.</p><a class="records-link" href="{{ route('student-accounts.index') }}">Clear filters</a></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="records-pagination">{{ $students->links() }}</div>
    </section>
</x-records-page>
