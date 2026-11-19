<x-records-page title="Reason for Delisting" description="Maintain the institution's available reason descriptions. This registry does not change a scholar's status or billing eligibility.">
    @if(!$ready)
        <section class="dashboard-card records-card"><p class="records-notice">This registry is awaiting its database migration. The developer must apply the delisting reasons migration before records can be managed.</p></section>
    @else
        <x-slot:actions>@can('create', \App\Models\DelistingReason::class)<button class="records-button is-primary" type="button" x-data @click="$dispatch('open-record-form-dialog', { id: 'create-delisting-reason', trigger: $el })" aria-haspopup="dialog" aria-controls="create-delisting-reason">Add reason</button>@endcan</x-slot:actions>
        <section class="dashboard-card records-card">
            <form class="records-filters" method="GET" action="{{ route('delisting-reasons.index') }}">
                <x-record-field id="reason-search" name="q" label="Search reasons" :value="$filters['q'] ?? ''" maxlength="100" />
                <x-record-field id="reason-status-filter" name="is_active" label="Status" type="select"><option value="">All statuses</option><option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option><option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option></x-record-field>
                <button class="records-button" type="submit">Search</button><a class="records-button" href="{{ route('delisting-reasons.index') }}">Clear</a>
            </form>
            <div class="records-table-wrap"><x-table-state :paginator="$reasons" /><table class="records-table"><thead><tr><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                @forelse($reasons as $reason)<tr><td>{{ $reason->name }}</td><td><span class="records-badge {{ $reason->is_active ? 'is-green' : '' }}">{{ $reason->is_active ? 'Active' : 'Inactive' }}</span></td><td>@can('update', $reason)<a id="edit-reason-trigger-{{ $reason->id }}" class="records-link" href="{{ route('delisting-reasons.index', request()->except('edit', 'create') + ['edit' => $reason->id]) }}" aria-haspopup="dialog" aria-controls="edit-delisting-reason">Edit</a>@else<span class="records-muted">Read only</span>@endcan</td></tr>@empty<tr><td colspan="3">No reasons match this search. No official reasons are prepopulated.</td></tr>@endforelse
            </tbody></table></div><div class="records-pagination">{{ $reasons->links() }}</div>
        </section>
        @can('create', \App\Models\DelistingReason::class)
            <x-record-form-dialog id="create-delisting-reason" title="Add delisting reason" :action="route('delisting-reasons.store', request()->only('q', 'is_active'))" submit-label="Save reason" :show="!$editing && $errors->getBag('reasonForm')->any()" :defaults="['name' => '', 'is_active' => '1']">
                <x-slot:description>Enter a reason approved by the institution. Saving a reason does not delist any scholar.</x-slot:description>
                @include('delisting-reasons.fields', ['prefix' => 'reason-create', 'reason' => null, 'recover' => !$editing && $errors->getBag('reasonForm')->any()])
            </x-record-form-dialog>
        @endcan
        @if($editing)
            <x-record-form-dialog id="edit-delisting-reason" :trigger-id="'edit-reason-trigger-'.$editing->id" title="Edit delisting reason" :action="route('delisting-reasons.update', ['delisting_reason' => $editing] + request()->only('q', 'is_active'))" method="PATCH" submit-label="Save changes" busy-label="Saving…" :show="true" :has-errors="$errors->getBag('reasonForm')->any()" :dismiss-query="['edit', 'create']" :defaults="['name' => $editing->name, 'is_active' => (int) $editing->is_active]">
                <x-slot:description>Update the reason or mark it inactive. Records are retained; there is no permanent-delete action.</x-slot:description>
                @include('delisting-reasons.fields', ['prefix' => 'reason-edit', 'reason' => $editing, 'recover' => $errors->getBag('reasonForm')->any()])
            </x-record-form-dialog>
        @endif
    @endif
</x-records-page>
