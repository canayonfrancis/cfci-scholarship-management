// Disclosure navigation: existing links/permissions are supplied by Blade.
export default function primaryNavigation() {
    return {
        mobileOpen: false,
        mobileGroup: null,
        profileOpen: false,
        openMenu: null,
        openMode: 'hover',
        keyboardMode: false,
        lastPointer: null,
        suppressFocus: false,
        closeTimer: null,
        root: null,
        popupStyle: { visibility: 'hidden' },

        init() { this.root = this.$el; },
        destroy() { this.cancelClose(); },
        get desktop() { return window.matchMedia('(min-width: 981px)').matches; },
        get hoverCapable() { return window.matchMedia('(hover: hover) and (pointer: fine)').matches; },
        trigger(key = this.openMenu) { return document.getElementById(`desktop-nav-${key}`); },
        panel(key = this.openMenu) { return document.getElementById(`desktop-dropdown-${key}`); },
        cancelClose() {
            if (this.closeTimer !== null) window.clearTimeout(this.closeTimer);
            this.closeTimer = null;
        },
        hoverParent(key, event) {
            if (this.desktop && this.hoverCapable && event.pointerType !== 'touch') this.openDropdown(key, 'hover');
        },
        openDropdown(key, mode) {
            if (!this.desktop) return;
            if (this.openMenu !== key && this.panel()?.contains(document.activeElement)) this.closeDropdown(true);
            this.cancelClose();
            if (this.openMenu !== key) this.popupStyle = { visibility: 'hidden' };
            this.openMenu = key;
            this.openMode = mode;
            this.profileOpen = false;
            this.$nextTick(() => this.positionDropdown());
        },
        clickParent(key, event) {
            if (event.detail === 0) {
                this.openDropdown(key, 'keyboard');
                this.$nextTick(() => this.focusChild(key));
            } else if (this.hoverCapable && this.lastPointer !== 'touch') {
                // Clicking a menu already opened by mouse hover must not collapse it.
                this.openDropdown(key, 'hover');
            } else if (this.openMenu === key) this.closeDropdown();
            else this.openDropdown(key, 'tap');
        },
        focusParent(key) {
            if (this.suppressFocus) return;
            if (this.keyboardMode) this.openDropdown(key, 'keyboard');
            else if (this.openMenu && this.openMenu !== key) this.closeDropdown();
        },
        scheduleClose(key) {
            this.cancelClose();
            this.closeTimer = window.setTimeout(() => {
                this.closeTimer = null;
                if (this.openMenu !== key) return;
                const trigger = this.trigger(key), panel = this.panel(key);
                const hovered = this.hoverCapable && (trigger?.matches(':hover') || panel?.matches(':hover'));
                const focused = trigger === document.activeElement || panel?.contains(document.activeElement);
                if (!hovered && (this.openMode === 'hover' || !focused)) this.closeDropdown();
            }, 200);
        },
        closeDropdown(restoreFocus = false) {
            const trigger = this.trigger();
            const childHadFocus = this.panel()?.contains(document.activeElement);
            this.cancelClose();
            this.openMenu = null;
            if ((restoreFocus || childHadFocus) && trigger) {
                this.suppressFocus = true;
                trigger.focus();
                this.suppressFocus = false;
            }
        },
        positionDropdown() {
            if (!this.openMenu) return;
            const trigger = this.trigger();
            if (!trigger?.getClientRects().length || !this.desktop) { this.closeDropdown(); return; }
            const bounds = trigger.getBoundingClientRect();
            const viewportWidth = document.documentElement.clientWidth;
            const width = Math.min(340, viewportWidth - 24);
            const top = bounds.bottom + 6;
            this.popupStyle = {
                position: 'fixed', width: `${width}px`, top: `${top}px`,
                left: `${Math.max(12, Math.min(bounds.left, viewportWidth - width - 12))}px`,
                maxHeight: `${Math.max(0, window.innerHeight - top - 12)}px`, visibility: 'visible',
            };
        },
        navigationScrolled() {
            if (!this.openMenu) return;
            const trigger = this.trigger()?.getBoundingClientRect();
            const viewport = this.root.querySelector('#primary-navigation')?.getBoundingClientRect();
            if (!trigger || !viewport || trigger.right <= viewport.left || trigger.left >= viewport.right) this.closeDropdown();
            else this.positionDropdown();
        },
        childLinks(key = this.openMenu) { return [...(this.panel(key)?.querySelectorAll('a[href]') ?? [])]; },
        focusChild(key, last = false) {
            const links = this.childLinks(key);
            (last ? links.at(-1) : links[0])?.focus();
        },
        parentKey(key, event) {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                this.openDropdown(key, 'keyboard');
                this.$nextTick(() => this.focusChild(key, event.key === 'ArrowUp'));
            } else if (event.key === 'Tab' && !event.shiftKey && this.openMenu === key) {
                event.preventDefault();
                this.openMode = 'keyboard';
                this.focusChild(key);
            }
        },
        rootKey(event) {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            const item = event.target.closest('[data-primary-item]');
            if (!item) return;
            event.preventDefault();
            this.movePrimary(item, event.key === 'ArrowRight' ? 1 : -1);
        },
        movePrimary(item, direction, wrap = true) {
            const items = [...this.root.querySelectorAll('[data-primary-item]')].filter(link => link.getClientRects().length);
            const position = items.indexOf(item) + direction;
            this.closeDropdown();
            this.keyboardMode = true;
            const next = wrap ? items[(position + items.length) % items.length] : items[position];
            (next ?? this.root.querySelector('[data-profile-trigger]'))?.focus();
        },
        panelKey(key, event) {
            const links = this.childLinks(key);
            const index = links.indexOf(document.activeElement);
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopPropagation(); this.closeDropdown(true);
            } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                this.openMode = 'keyboard';
                const position = event.key === 'Home' ? 0 : event.key === 'End' ? links.length - 1
                    : (index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
                links[position]?.focus();
            } else if (event.key === 'Tab' && event.shiftKey && index === 0) {
                event.preventDefault(); this.closeDropdown(true);
            } else if (event.key === 'Tab' && !event.shiftKey && index === links.length - 1) {
                event.preventDefault(); this.movePrimary(this.trigger(key), 1, false);
            }
        },
        pointerDown(event) {
            this.keyboardMode = false;
            this.lastPointer = event.pointerType;
            if (!this.root.contains(event.target) && !this.panel()?.contains(event.target)) this.closeAll();
        },
        focusChanged(event) {
            if (this.openMenu && event.target !== this.trigger() && !this.panel()?.contains(event.target)) this.closeDropdown();
            if (!this.root.contains(event.target) && !this.panel()?.contains(event.target)) {
                this.mobileOpen = false; this.mobileGroup = null; this.profileOpen = false;
            }
        },
        keyboardInput(event) {
            if (['Tab', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter', ' '].includes(event.key)) this.keyboardMode = true;
            if (event.key === 'Escape') this.escape(event);
        },
        escape(event) {
            if (this.openMenu) { event.preventDefault(); this.closeDropdown(true); }
            else if (this.mobileGroup) {
                const key = this.mobileGroup;
                this.mobileGroup = null;
                this.root.querySelector(`#mobile-nav-${key}`)?.focus();
            } else if (this.mobileOpen) {
                this.mobileOpen = false;
                this.root.querySelector('[data-mobile-trigger]')?.focus();
            } else if (this.profileOpen) {
                this.profileOpen = false;
                this.root.querySelector('[data-profile-trigger]')?.focus();
            }
        },
        toggleMobile() {
            this.closeDropdown(); this.profileOpen = false;
            this.mobileOpen = !this.mobileOpen;
            if (!this.mobileOpen) this.mobileGroup = null;
        },
        toggleMobileGroup(key) { this.mobileGroup = this.mobileGroup === key ? null : key; },
        toggleProfile() {
            this.closeDropdown(); this.mobileOpen = false; this.mobileGroup = null;
            this.profileOpen = !this.profileOpen;
        },
        closeAll() { this.closeDropdown(); this.mobileOpen = false; this.mobileGroup = null; this.profileOpen = false; },
    };
}
