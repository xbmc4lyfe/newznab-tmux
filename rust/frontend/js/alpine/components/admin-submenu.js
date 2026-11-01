/**
 * Alpine.data('adminSubmenu') - Admin sidebar submenu toggle (CSP-safe)
 */
import Alpine from '@alpinejs/csp';

Alpine.data('adminSubmenu', () => ({
    open: false,

    init() {
        this.open = Array.from(this.$el.querySelectorAll('a[href]')).some((link) => {
            try {
                var linkUrl = new URL(link.href, window.location.origin);
                return linkUrl.origin === window.location.origin && linkUrl.pathname === window.location.pathname;
            } catch (_error) {
                return false;
            }
        });
    },

    toggle() {
        this.open = !this.open;
    }
}));

