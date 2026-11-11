import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

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
            const active = navigation.querySelector('[aria-current="page"]');
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

    destroy() {
        this.resizeObserver?.disconnect();
    },
}));

Alpine.start();
