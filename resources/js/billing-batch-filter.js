export default (endpoint, optionsCount, configuration = {}) => ({
    optionsCount,
    selected: '',
    loading: false,
    loadError: '',
    requestNumber: 0,

    init() {
        this.selected = this.$refs.batch.value;
    },

    async refresh() {
        const requestNumber = ++this.requestNumber;
        const fields = this.$refs.batch.form.elements;
        const parameters = new URLSearchParams();
        for (const [parameter, field] of Object.entries({
            program_id: configuration.programField ?? 'program_id',
            academic_year: 'academic_year',
            academic_period_id: 'academic_period_id',
            status: 'status',
        })) {
            if (fields[field]?.value) parameters.set(parameter, fields[field].value);
        }
        this.loading = true;
        this.loadError = '';

        try {
            const response = await fetch(`${endpoint}?${parameters}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Unable to load billing batches.');
            const { options } = await response.json();
            if (requestNumber !== this.requestNumber) return;

            this.optionsCount = options.length;
            if (!options.some(option => String(option.id) === this.selected)) {
                this.selected = '';
            }
            this.$refs.batch.replaceChildren(
                new Option(options.length ? (configuration.placeholder ?? 'All matching batches') : 'No matching billing batches', ''),
                ...options.map(option => new Option(option.label, String(option.id))),
            );
            this.$refs.batch.value = this.selected;
        } catch {
            if (requestNumber !== this.requestNumber) return;
            this.loadError = 'Unable to load matching billing batches. Retry or change the filters.';
        } finally {
            if (requestNumber === this.requestNumber) this.loading = false;
        }
    },
});
