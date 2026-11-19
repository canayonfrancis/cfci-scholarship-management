@props(['id', 'title', 'action', 'submitLabel', 'show' => false, 'defaults' => [], 'method' => 'POST', 'busyLabel' => 'Creating…', 'dismissQuery' => [], 'hasErrors' => null, 'triggerId' => null, 'enctype' => 'application/x-www-form-urlencoded'])

<dialog id="{{ $id }}" class="academic-subject-dialog record-form-dialog"
    x-data="recordFormDialog({ reopen: @js($show), hasErrors: @js($hasErrors), defaults: @js($defaults), dismissQuery: @js($dismissQuery), triggerId: @js($triggerId) })"
    x-on:open-record-form-dialog.window="if ($event.detail.id === $el.id) open($event.detail.trigger)"
    x-on:record-form-dialog-navigation.window="if ($event.detail === $el.id) navigating = true"
    x-on:cancel.prevent="cancel()" x-on:close="afterClose()" x-on:keydown.tab="trapFocus($event)"
    aria-modal="true" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-description">
    <div class="academic-dialog-header">
        <div><h3 id="{{ $id }}-title">{{ $title }}</h3><div id="{{ $id }}-description" class="record-help">{{ $description ?? '' }}</div></div>
        <button type="button" class="records-button academic-dialog-close" x-on:click="cancel()" x-bind:disabled="submitting" aria-label="Close {{ $title }}"><x-dashboard-icon name="close" :size="18" /></button>
    </div>
    <form method="POST" action="{{ $action }}" enctype="{{ $enctype }}" x-ref="form" x-on:submit="submit($event)" x-bind:aria-busy="submitting">
        @csrf
        @if(strtoupper($method) !== 'POST') @method($method) @endif
        <div class="academic-dialog-body">{{ $slot }}</div>
        <div class="academic-dialog-footer">
            @isset($footer)
                {{ $footer }}
            @else
            <button type="button" class="records-button" x-on:click="cancel()" x-bind:disabled="submitting">Cancel</button>
            <button type="submit" class="records-button is-primary" x-bind:disabled="submitting"><span x-show="!submitting">{{ $submitLabel }}</span><span x-show="submitting" x-cloak>{{ $busyLabel }}</span></button>
            @endisset
        </div>
    </form>
</dialog>
