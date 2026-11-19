import './bootstrap';

import Alpine from 'alpinejs';
import billingBatchFilter from './billing-batch-filter';
import initializeDataTables from './data-tables';
import evaluationWorkspace from './evaluation-workspace';
import primaryNavigation from './primary-navigation';
import recordFormDialog from './record-form-dialog';
import rosterScholarSelection from './roster-scholar-selection';

window.Alpine = Alpine;
Alpine.data('billingBatchFilter', billingBatchFilter);
Alpine.data('evaluationWorkspace', evaluationWorkspace);
Alpine.data('primaryNavigation', primaryNavigation);
Alpine.data('recordFormDialog', recordFormDialog);
Alpine.data('rosterScholarSelection', rosterScholarSelection);

Alpine.data('scrollableNavigation', () => ({
    canScrollLeft: false,
    canScrollRight: false,
    activeRevealed: false,
    resizeObserver: null,

    init() {
        this.$nextTick(() => {
            const navigation = this.$refs.navigation;
            this.resizeObserver = new ResizeObserver(() => this.updateScrollEdges());
            this.resizeObserver.observe(navigation);
            for (const item of navigation.children) {
                this.resizeObserver.observe(item);
            }
            this.updateScrollEdges();
        });
    },

    updateScrollEdges() {
        const navigation = this.$refs.navigation;
        if (!navigation.clientWidth) {
            this.canScrollLeft = false;
            this.canScrollRight = false;
            return;
        }

        if (!this.activeRevealed) {
            this.activeRevealed = true;
            const active = navigation.querySelector('[aria-current="page"], [data-active-navigation="true"]');
            if (active) {
                const bounds = navigation.getBoundingClientRect();
                const item = active.getBoundingClientRect();
                navigation.scrollLeft += item.left - bounds.left - (navigation.clientWidth - item.width) / 2;
            }
        }

        const remaining = navigation.scrollWidth - navigation.clientWidth - navigation.scrollLeft;
        this.canScrollLeft = navigation.scrollLeft > 1;
        this.canScrollRight = remaining > 1;
    },

    scrollNavigation(direction) {
        const navigation = this.$refs.navigation;
        navigation.scrollBy({
            left: direction * Math.max(120, navigation.clientWidth * 0.7),
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    },

    destroy() {
        this.resizeObserver?.disconnect();
    },
}));

initializeDataTables();
Alpine.start();
