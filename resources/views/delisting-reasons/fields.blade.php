@php
    $formErrors = $errors->getBag('reasonForm');
    $fieldErrorBag = $recover ? 'reasonForm' : 'freshReasonForm';
    $name = $recover ? old('name') : ($reason?->name ?? '');
    $active = $recover ? old('is_active') : (int) ($reason?->is_active ?? true);
@endphp
@if($recover && $formErrors->any())<div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>The reason was not saved.</strong><ul>@foreach($formErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="records-form-grid">
    <div>
        <x-record-field :id="$prefix.'-name'" name="name" label="Reason *" :value="$name" :error-bag="$fieldErrorBag" maxlength="255" required />
    </div>
    <x-record-field :id="$prefix.'-active'" name="is_active" label="Status *" type="select" :error-bag="$fieldErrorBag" required><option value="1" @selected((string) $active === '1')>Active</option><option value="0" @selected((string) $active === '0')>Inactive</option></x-record-field>
</div>
