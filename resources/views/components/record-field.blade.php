@props(['name', 'label', 'value' => null, 'type' => 'text', 'help' => null])
@php $value = is_scalar($value) ? $value : ''; @endphp

<div class="record-field">
    <x-input-label :for="$name" :value="$label" />
    @if($type === 'select')
        <select id="{{ $name }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'record-input']) }} @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $slot }}</select>
    @elseif($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="4" {{ $attributes->merge(['class' => 'record-input']) }} @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $value }}</textarea>
    @else
        <x-text-input :id="$name" :name="$name" :type="$type" :value="$value" {{ $attributes->merge(['class' => 'record-input']) }} :aria-invalid="$errors->has($name) ? 'true' : 'false'" :aria-describedby="$errors->has($name) ? $name.'-error' : null" />
    @endif
    @if($help)<p class="record-help">{{ $help }}</p>@endif
    <x-input-error :id="$name.'-error'" :messages="$errors->get($name)" />
</div>
