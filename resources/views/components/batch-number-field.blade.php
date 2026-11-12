@props(['options', 'value' => null, 'placeholder' => 'All matching batches', 'help' => ''])
@php($selected = is_scalar($value) ? (string) $value : '')

<x-record-field name="billing_batch_id" label="Batch Number" type="select" x-ref="batch" :disabled="$options->isEmpty()" x-bind:disabled="loading || optionsCount === 0 || !!loadError" @change="selected = $event.target.value" :help="$help">
    <option value="" @selected($selected === '')>{{ $options->isEmpty() ? 'No matching billing batches' : $placeholder }}</option>
    @foreach($options as $option)
        <option value="{{ $option['id'] }}" @selected($selected === (string) $option['id'])>{{ $option['label'] }}</option>
    @endforeach
</x-record-field>
