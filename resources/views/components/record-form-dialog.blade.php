@props(['id', 'title', 'action', 'submitLabel', 'show' => false, 'defaults' => []])

<dialog id="{{ $id }}" class="academic-subject-dialog record-form-dialog"
    x-data="recordFormDialog({ reopen: @js($show), defaults: @js($defaults) })"
    x-on:open-record-form-dialog.window="if ($event.detail.id === $el.id) open($event.detail.trigger)"
    x-on:cancel.prevent="cancel()" x-on:close="afterClose()" x-on:keydown.tab="trapFocus($event)"
    aria-modal="true" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-description">
    <div class="academic-dialog-header">
        <div><h3 id="{{ $id }}-title">{{ $title }}</h3><div id="{{ $id }}-description" class="record-help">{{ $description ?? '' }}</div></div>
        <button type="button" class="records-button academic-dialog-close" x-on:click="cancel()" x-bind:disabled="submitting" aria-label="Close {{ $title }}"><x-dashboard-icon name="close" :size="18" /></button>
    </div>
    <form method="POST" action="{{ $action }}" x-ref="form" x-on:submit="submit($event)" x-bind:aria-busy="submitting">
        @csrf
        <div class="academic-dialog-body">{{ $slot }}</div>
        <div class="academic-dialog-footer">
            <button type="button" class="records-button" x-on:click="cancel()" x-bind:disabled="submitting">Cancel</button>
            <button type="submit" class="records-button is-primary" x-bind:disabled="submitting"><span x-show="!submitting">{{ $submitLabel }}</span><span x-show="submitting" x-cloak>Creating…</span></button>
        </div>
    </form>
</dialog>
