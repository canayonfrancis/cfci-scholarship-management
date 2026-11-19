// Page-local preview only; the existing billing action authorizes every addition.
export default function billingRosterSelection(rows, previous = []) {
    return {
        rows,
        selected: [],
        review: false,
        confirmed: false,
        saving: false,
        init() {
            const ids = new Set(rows.map(row => row.id));
            this.selected = Array.isArray(previous) ? [...new Set(previous.map(String))].filter(id => ids.has(id)) : [];
            this.pageShowListener = () => { this.saving = false; };
            window.addEventListener('pageshow', this.pageShowListener);
        },
        get selectedRows() {
            return this.rows.filter(row => this.selected.includes(row.id));
        },
        get total() {
            const cents = this.selectedRows.reduce((sum, row) => {
                const [whole, fraction = ''] = row.amount.split('.');
                return sum + Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
            }, 0);
            return (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        selectDisplayed() {
            this.selected = this.rows.map(row => row.id);
            this.review = false;
            this.confirmed = false;
        },
        clearSelection() {
            this.selected = [];
            this.review = false;
            this.confirmed = false;
        },
        reviewSelection() {
            if (!this.selected.length) return;
            this.review = true;
            this.confirmed = false;
            this.$nextTick(() => this.$refs.reviewPanel.focus());
        },
        submitSelection(event) {
            if (this.saving || !this.review || !this.confirmed || !this.selected.length) {
                event.preventDefault();
                return;
            }
            this.saving = true;
        },
        destroy() {
            window.removeEventListener('pageshow', this.pageShowListener);
        },
    };
}
