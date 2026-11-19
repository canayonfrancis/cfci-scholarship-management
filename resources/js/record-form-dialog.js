// Reusable native form dialog, following the evaluation dialog's focus/scroll pattern.
export default function recordFormDialog(initial) {
    return {
        submitting: false,
        returnedErrors: initial.reopen,
        trigger: null,
        priorOverflow: null,
        baseline: '',

        init() {
            this.pageShowListener = () => { this.submitting = false; };
            this.unloadListener = (event) => {
                if (this.$el.open && !this.submitting && this.isDirty()) {
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
            if (this.$el.open) return;
            this.trigger = trigger ?? document.querySelector(`[aria-controls="${this.$el.id}"]`);
            this.priorOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            this.$el.showModal();
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
            this.$el.close();
        },
        afterClose() {
            if (this.priorOverflow !== null) document.body.style.overflow = this.priorOverflow;
            this.priorOverflow = null;
            this.$nextTick(() => this.trigger?.focus());
        },
        submit(event) {
            if (this.submitting) {
                event.preventDefault();
                return;
            }
            // Native constraint validation runs first. Do not disable named inputs/CSRF.
            this.submitting = true;
        },
        trapFocus(event) {
            const fields = [...this.$el.querySelectorAll('button, input:not([type="hidden"]), select, textarea, [tabindex="0"]')]
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
