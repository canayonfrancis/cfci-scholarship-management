export default function evaluationWorkspace(initial) {
    return {
        activeTab: initial.activeTab,
        tabs: initial.tabs,
        applicantType: initial.applicantType,
        requiredFacts: initial.requiredFacts,
        program: initial.program,
        formRevision: 0,
        dirtyForms: [],
        submitting: false,
        dialogTrigger: null,
        priorOverflow: null,
        unloadListener: null,
        popstateListener: null,

        init() {
            this.unloadListener = (event) => {
                if (this.dirtyForms.length && !this.submitting) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            this.popstateListener = () => {
                this.closeOpenDialogs();
                const tab = new URL(window.location.href).searchParams.get('tab');
                this.activeTab = this.tabs.includes(tab) ? tab : 'overview';
            };
            window.addEventListener('beforeunload', this.unloadListener);
            window.addEventListener('popstate', this.popstateListener);
            this.updateUrl(false);
            this.$nextTick(() => {
                this.formRevision++;
                this.$el.querySelectorAll('details[data-has-errors="true"]').forEach(group => { group.open = true; });
                if (initial.hasErrors && initial.failedForm !== 'activate') {
                    const failed = [...this.$el.querySelectorAll('form[data-workspace-form]')].find(form =>
                        form.dataset.workspaceForm === initial.failedForm && (initial.failedForm !== 'review'
                            || form.querySelector('[name="_workspace_document"]')?.value === initial.reviewDocument));
                    if (failed) this.dirtyForms.push(failed.id);
                }
                if (initial.evidenceErrors) this.openDialog('evidenceDialog', this.$el.querySelector('#evaluation-tab-supporting-evidence'));
                if (initial.hasErrors) this.$nextTick(() => this.focusFirstError());
            });
        },
        destroy() {
            window.removeEventListener('beforeunload', this.unloadListener);
            window.removeEventListener('popstate', this.popstateListener);
            if (this.priorOverflow !== null) document.body.style.overflow = this.priorOverflow;
        },
        selectTab(tab, focus = false) {
            if (!this.tabs.includes(tab)) return;
            if (tab !== this.activeTab) this.closeOpenDialogs();
            this.activeTab = tab;
            this.updateUrl(true);
            if (focus) this.$nextTick(() => this.$el.querySelector(`#evaluation-tab-${tab}`)?.focus());
        },
        updateUrl(push) {
            const url = new URL(window.location.href);
            if (url.searchParams.get('tab') === this.activeTab) return;
            url.searchParams.set('tab', this.activeTab);
            window.history[push ? 'pushState' : 'replaceState'](window.history.state, '', url);
        },
        tabKey(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const index = this.tabs.indexOf(this.activeTab);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? this.tabs.length - 1
                : (index + (event.key === 'ArrowRight' ? 1 : -1) + this.tabs.length) % this.tabs.length;
            this.selectTab(this.tabs[next], true);
        },
        changed(event) {
            const form = event.target.closest('form[data-workspace-form]');
            if (!form) return;
            this.formRevision++;
            if (!this.dirtyForms.includes(form.id)) this.dirtyForms.push(form.id);
        },
        submit(event) {
            const form = event.target;
            if (!form.matches('form[data-workspace-form]')) return;
            if (this.dirtyForms.some(id => id !== form.id)
                && !window.confirm('Other forms in this workspace contain unsaved changes. Submitting this form reloads the page and discards those other changes. Continue?')) {
                event.preventDefault();
                return;
            }
            this.submitting = true;
        },
        revealInvalid(event) {
            const form = event.target.closest('form[data-workspace-form]');
            if (!form) return;
            this.selectTab(form.dataset.tab);
            let group = event.target.closest('details');
            while (group) { group.open = true; group = group.parentElement.closest('details'); }
        },
        focusFirstError() {
            const dialog = this.$refs.evidenceDialog?.open ? this.$refs.evidenceDialog : null;
            const panel = dialog ?? this.$el.querySelector(`#evaluation-panel-${this.activeTab}`);
            const field = panel?.querySelector('[aria-invalid="true"]');
            if (field) {
                let group = field.closest('details');
                while (group) { group.open = true; group = group.parentElement.closest('details'); }
                this.$nextTick(() => field.focus());
            } else panel?.querySelector('[data-workspace-errors]')?.focus();
        },
        applicableFact(key) {
            if (initial.errorKeys.some(error => error === `facts.${key}` || error === `fact_sources.${key}`)) return true;
            return !this.requiredFacts || (this.requiredFacts[this.applicantType] ?? []).includes(key);
        },
        applicableCondition(key) {
            if (initial.errorKeys.includes(key)) return true;
            if (!this.requiredFacts) return true; // No approved applicability mapping: retain fields.
            if (['legacy_pnsl_transfer', 'claiming_pwd'].includes(key)) return this.program === 'TES';
            if (key === 'smart_same_semester') return this.program === 'TDP';
            return true;
        },
        get nonApplicableFacts() {
            return initial.factKeys.filter(key => !this.applicableFact(key));
        },
        groupStatus(id) {
            this.formRevision; // Recompute from actual form values after input/change.
            const group = this.$el.querySelector(`#${id}`);
            if (!group) return 'Pending review';
            if (group.dataset.hasErrors === 'true') return 'Validation errors';
            const facts = [...group.querySelectorAll('[data-fact]')].filter(row => this.applicableFact(row.dataset.fact));
            const conditions = [...group.querySelectorAll('[data-condition]')].filter(row => this.applicableCondition(row.dataset.condition));
            if (!facts.length && !conditions.length) return 'No applicable fields';
            const enteredFacts = facts.every(row => row.querySelector('select').value === 'yes' && row.querySelector('input[type="text"]').value.trim() !== '');
            const enteredConditions = conditions.every(row => {
                const control = row.querySelector('select, textarea, input');
                if (!control) return false;
                if (control.type === 'checkbox') return control.checked;
                const value = control.value.trim();
                if (row.dataset.condition === 'special_case') return value === 'none';
                if (row.dataset.condition === 'duration_review') return ['within_limit', 'approved_exception'].includes(value);
                if (row.dataset.condition === 'smart_same_semester') return value === 'no';
                if (['returning', 'transferee', 'legacy_pnsl_transfer', 'claiming_pwd'].includes(row.dataset.condition)) return ['yes', 'no'].includes(value);
                return value !== '' && !['unverified', 'no'].includes(value);
            });
            return enteredFacts && enteredConditions ? 'Entered — evaluate to verify' : 'Pending review';
        },
        openDialog(ref, trigger = null) {
            const dialog = this.$refs[ref];
            if (!dialog || dialog.open) return;
            this.dialogTrigger = trigger ?? document.activeElement;
            this.priorOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            dialog.showModal();
            this.$nextTick(() => (dialog.querySelector('[autofocus]') ?? dialog.querySelector('button, select, input:not([type="hidden"])'))?.focus());
        },
        closeDialog(ref) {
            this.$refs[ref]?.close(); // Mounted inputs/files are retained when closing this dialog.
            if (this.priorOverflow !== null) document.body.style.overflow = this.priorOverflow;
            this.priorOverflow = null;
            this.$nextTick(() => {
                if (this.dialogTrigger?.isConnected && this.dialogTrigger.getClientRects().length) this.dialogTrigger.focus();
                else this.$el.querySelector(`#evaluation-tab-${this.activeTab}`)?.focus();
            });
        },
        closeOpenDialogs() {
            for (const ref of ['evidenceDialog', 'activationDialog']) {
                if (this.$refs[ref]?.open) this.closeDialog(ref);
            }
        },
        trapFocus(event) {
            const dialog = event.currentTarget;
            const elements = [...dialog.querySelectorAll('button, input:not([type="hidden"]), textarea, select, [tabindex="0"]')]
                .filter(element => !element.disabled && element.getClientRects().length);
            if (!elements.length) return;
            const first = elements[0], last = elements[elements.length - 1];
            if (event.shiftKey && (document.activeElement === first || !elements.includes(document.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !elements.includes(document.activeElement))) {
                event.preventDefault(); first.focus();
            }
        },
    };
}
