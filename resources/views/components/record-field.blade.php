@props(['name', 'label', 'value' => null, 'type' => 'text', 'help' => null, 'id' => null, 'errorBag' => 'default'])
@php
    $value = is_scalar($value) ? $value : '';
    $fieldId = $id ?? $name;
    $fieldErrors = $errors->getBag($errorBag);
@endphp

<div class="record-field">
    <x-input-label :for="$fieldId" :value="$label" />
    @if($type === 'select')
        <select id="{{ $fieldId }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'record-input']) }} @if($fieldErrors->has($name)) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>{{ $slot }}</select>
    @elseif($type === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="4" {{ $attributes->merge(['class' => 'record-input']) }} @if($fieldErrors->has($name)) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>{{ $value }}</textarea>
    @else
        <x-text-input :id="$fieldId" :name="$name" :type="$type" :value="$value" {{ $attributes->merge(['class' => 'record-input']) }} :aria-invalid="$fieldErrors->has($name) ? 'true' : 'false'" :aria-describedby="$fieldErrors->has($name) ? $fieldId.'-error' : null" />
    @endif
    @if($help)<p class="record-help">{{ $help }}</p>@endif
    <x-input-error :id="$fieldId.'-error'" :messages="$fieldErrors->get($name)" data-record-field-error />
</div>
