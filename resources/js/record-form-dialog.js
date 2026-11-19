// Reusable native form dialog, following the evaluation dialog's focus/scroll pattern.
export default function recordFormDialog(initial) {
    return {
        submitting: false,
        navigating: false,
        returnedErrors: initial.hasErrors ?? initial.reopen,
        trigger: null,
        priorOverflow: null,
        baseline: '',
        dialog: null,

        init() {
            // $el is event-context dependent in Alpine; retain the native dialog.
            this.dialog = this.$el;
            this.pageShowListener = () => { this.submitting = false; this.navigating = false; };
            this.unloadListener = (event) => {
                if (this.dialog.open && !this.submitting && !this.navigating && this.isDirty()) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            window.addEventListener('pageshow', this.pageShowListener);
            window.addEventListener('beforeunload', this.unloadListener);
            this.$nextTick(() => {
                this.baseline = this.values();
                if (initial.reopen) this.open();
            });
        },
        values() {
            return JSON.stringify([...new FormData(this.$refs.form).entries()]);
        },
        isDirty() {
            return this.returnedErrors || this.values() !== this.baseline;
        },
        open(trigger = null) {
            if (this.dialog.open) return;
            this.trigger = trigger ?? document.getElementById(initial.triggerId) ?? document.querySelector(`[aria-controls="${this.dialog.id}"]`);
            this.priorOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            this.dialog.showModal();
            this.$nextTick(() => {
                (this.$refs.form.querySelector('[aria-invalid="true"]')
                    ?? this.$refs.form.querySelector('[data-dialog-errors]')
                    ?? this.$refs.form.querySelector('select, input:not([type="hidden"]), textarea'))?.focus();
            });
        },
        cancel() {
            if (this.submitting) return;
            if (this.isDirty() && !window.confirm('Discard the unsaved information in this form? No changes will be saved.')) return;
            const form = this.$refs.form;
            form.reset();
            for (const [name, value] of Object.entries(initial.defaults)) {
                const field = form.elements.namedItem(name);
                if (field) field.value = value ?? '';
            }
            form.querySelectorAll('[data-record-field-error], [data-dialog-errors]').forEach(error => error.remove());
            form.querySelectorAll('[aria-invalid="true"]').forEach(field => {
                field.removeAttribute('aria-invalid');
                field.removeAttribute('aria-describedby');
            });
            this.returnedErrors = false;
            this.baseline = this.values();
            // Keep dependent previews in sync after restoring the form's defaults.
            form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(field => {
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            });
            this.dialog.close();
        },
        afterClose() {
            if (this.priorOverflow !== null) document.body.style.overflow = this.priorOverflow;
            this.priorOverflow = null;
            if (initial.dismissQuery?.length) {
                const url = new URL(window.location.href);
                initial.dismissQuery.forEach(key => url.searchParams.delete(key));
                window.history.replaceState(window.history.state, '', url);
            }
            this.$nextTick(() => this.trigger?.focus());
        },
        submit(event) {
            if (event.defaultPrevented) return;
            if (this.submitting) {
                event.preventDefault();
                return;
            }
            // Native constraint validation runs first. Do not disable named inputs/CSRF.
            this.submitting = true;
        },
        trapFocus(event) {
            const fields = [...this.dialog.querySelectorAll('button, input:not([type="hidden"]), select, textarea, [tabindex="0"]')]
                .filter(field => !field.disabled && field.getClientRects().length);
            const first = fields[0], last = fields[fields.length - 1];
            if (!first) return;
            if (event.shiftKey && (document.activeElement === first || !fields.includes(document.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !fields.includes(document.activeElement))) {
                event.preventDefault(); first.focus();
            }
        },
        destroy() {
            window.removeEventListener('pageshow', this.pageShowListener);
            window.removeEventListener('beforeunload', this.unloadListener);
            if (this.priorOverflow !== null) document.body.style.overflow = this.priorOverflow;
        },
    };
}
