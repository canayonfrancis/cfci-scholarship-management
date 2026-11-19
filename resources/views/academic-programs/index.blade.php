<x-records-page title="Academic programs" description="Registered programs of study for student profiles. Scholarship programs are maintained separately.">
    <x-slot:actions><a class="records-button" href="{{ route('scholars.index') }}">Back to scholars</a></x-slot:actions>
    @can('create', \App\Models\AcademicProgram::class)
        <section class="dashboard-card records-card records-form-card">
            <h2 class="records-section-title">Register academic program</h2>
            <form method="POST" action="{{ route('academic-programs.store') }}">
                @csrf
                <div class="records-form-grid">
                    <x-record-field name="name" label="Complete program name" :value="old('name')" required maxlength="255" />
                    <x-record-field name="is_active" label="Available for new profiles" type="select" required><option value="1" @selected(old('is_active', '1') == '1')>Yes</option><option value="0" @selected(old('is_active', '1') == '0')>No</option></x-record-field>
                </div>
                <x-primary-button class="records-submit">Register program</x-primary-button>
            </form>
        </section>
    @endcan
    <section class="dashboard-card records-card">
        <h2 class="records-section-title">Registered programs</h2>
        <p class="record-help">Existing profiles retain inactive programs. Linked names are preserved; register a new entry for a different program.</p>
        <div class="records-list-scroll" tabindex="0" role="region" aria-label="Registered academic programs; scroll for more programs">
        @forelse($programs as $program)
            <div class="scholar-program-row">
                <p><strong>{{ $program->name }}</strong><small class="records-muted">{{ $program->students_count }} student profiles · {{ $program->is_active ? 'Available' : 'Inactive' }}</small></p>
                @can('update', $program)
                    <form method="POST" action="{{ route('academic-programs.update', $program) }}" class="records-actions">
                        @csrf @method('PATCH')
                        <input type="hidden" name="name" value="{{ $program->name }}">
                        <input type="hidden" name="is_active" value="{{ $program->is_active ? '0' : '1' }}">
                        <button class="records-button" type="submit">{{ $program->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                    </form>
                @endcan
            </div>
        @empty<p class="record-help">No academic programs are registered. A Coordinator can add the school’s confirmed program names above.</p>@endforelse
        </div>
        <div class="records-pagination">{{ $programs->links() }}</div>
    </section>
</x-records-page>
