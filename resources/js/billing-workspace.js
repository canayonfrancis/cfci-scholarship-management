// The same URL-backed, keyboard-accessible tab pattern as the Evaluation workspace.
export default function billingWorkspace(initialTab, enrollment = {}) {
    return {
        activeTab: initialTab,
        tabs: ['roster', 'items', 'submission'],
        root: null,
        enrollmentRows: enrollment.rows ?? [],
        enrollmentSelected: [],
        enrollmentOutcome: enrollment.outcome ?? '',
        init() {
            this.root = this.$el;
            const available = new Set(this.enrollmentRows.map(row => row.id));
            this.enrollmentSelected = Array.isArray(enrollment.selected) ? [...new Set(enrollment.selected.map(String))].filter(id => available.has(id)) : [];
            this.popstateListener = () => {
                const tab = new URL(window.location.href).searchParams.get('tab');
                this.activeTab = this.tabs.includes(tab) ? tab : initialTab;
            };
            window.addEventListener('popstate', this.popstateListener);
            this.updateUrl(false);
        },
        selectTab(tab, focus = false) {
            if (!this.tabs.includes(tab)) return;
            this.activeTab = tab;
            this.updateUrl(true);
            if (focus) this.$nextTick(() => this.root.querySelector(`#billing-tab-${tab}`)?.focus());
        },
        get enrollmentNeedsCorrection() {
            return this.enrollmentRows.some(row => this.enrollmentSelected.includes(row.id) && row.correction);
        },
        openEnrollment(id = null, trigger = null) {
            this.enrollmentSelected = id === null ? [] : this.enrollmentRows.filter(row => row.id === String(id)).map(row => row.id);
            this.$dispatch('open-record-form-dialog', { id: 'verify-enrollment', trigger });
        },
        resetEnrollment() {
            this.enrollmentSelected = [];
            this.enrollmentOutcome = '';
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
        destroy() {
            window.removeEventListener('popstate', this.popstateListener);
        },
    };
}
