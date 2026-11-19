export default function rosterScholarSelection(initial) {
    return {
        selected: initial.selected.map(String),
        startsOn: initial.startsOn,
        details: initial.details,
        reviewing: false,
        selectionError: '',
        root: null,
        init() {
            this.root = this.$el;
            this.$watch('selected', () => { this.reviewing = false; this.syncUrl(); });
            this.$watch('startsOn', () => { this.reviewing = false; this.syncUrl(); });
        },
        get selectedRows() {
            return this.selected.map(id => this.details[id] ?? { id, label: `Unavailable scholar #${id}`, eligible: false, reason: 'This scholar is no longer available. Remove this selection.' });
        },
        get needsNew() {
            return this.selectedRows.some(row => row.needs_assignment);
        },
        syncParameters(url) {
            for (const key of [...url.searchParams.keys()]) {
                if (key === 'selected' || key.startsWith('selected[')) url.searchParams.delete(key);
            }
            this.selected.forEach(id => url.searchParams.append('selected[]', id));
            if (this.startsOn) url.searchParams.set('starts_on', this.startsOn);
            else url.searchParams.delete('starts_on');
            return url;
        },
        syncUrl() {
            const url = this.syncParameters(new URL(window.location.href));
            if (this.root.querySelector('dialog').open) url.searchParams.set('add', '1');
            else url.searchParams.delete('add');
            window.history.replaceState(window.history.state, '', url);
        },
        opened(event) {
            if (event.detail.id !== 'add-roster-scholars') return;
            this.reviewing = false;
            this.$nextTick(() => this.syncUrl());
        },
        closed(event) {
            if (event.target.id !== 'add-roster-scholars') return;
            this.selected = [];
            this.startsOn = '';
            this.reviewing = false;
            this.selectionError = '';
            this.syncUrl();
        },
        selectDisplayed() {
            const ids = [...this.root.querySelectorAll('[data-roster-selectable]')]
                .filter(field => !field.disabled && field.getClientRects().length).map(field => field.value);
            const selected = [...new Set([...this.selected, ...ids])];
            if (selected.length > 200) {
                this.selectionError = 'Select no more than 200 scholars in one submission.';
                return;
            }
            this.selected = selected;
            this.selectionError = '';
        },
        reviewSelection() {
            this.selectionError = '';
            if (!this.selected.length || this.selected.length > 200) {
                this.selectionError = 'Select between 1 and 200 scholars.';
                return;
            }
            if (this.selectedRows.some(row => !row.eligible)) {
                this.selectionError = 'Remove unavailable or conflicting selections before confirming.';
                return;
            }
            if (this.needsNew && !this.startsOn) {
                this.selectionError = 'Enter the starting date for the new pending assignments.';
                this.root.querySelector('#roster-new-start').focus();
                return;
            }
            if (!this.root.querySelector('#roster-new-start').reportValidity()) return;
            this.reviewing = true;
            this.$nextTick(() => this.root.querySelector('[data-roster-review]').focus());
        },
        guardSubmit(event) {
            if (event.target.id === 'roster-candidate-search') {
                this.allowNavigation();
                return;
            }
            if (!this.reviewing) {
                event.preventDefault();
                this.reviewSelection();
            }
        },
        allowNavigation() {
            window.dispatchEvent(new CustomEvent('record-form-dialog-navigation', { detail: 'add-roster-scholars' }));
        },
        tableNavigation(event) {
            if ((event.type === 'click' && event.target.closest('.data-table-sort-button'))
                || (event.type === 'change' && event.target.matches('.data-table-size select'))) this.allowNavigation();
        },
        pageLink(event) {
            const link = event.target.closest('.data-table-footer a');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            const url = this.syncParameters(new URL(link.href));
            url.searchParams.set('add', '1');
            this.allowNavigation();
            window.location.assign(url.href);
        },
    };
}
