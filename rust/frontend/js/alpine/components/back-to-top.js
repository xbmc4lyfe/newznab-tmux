/**
 * Alpine.data('backToTop') - Back to top button, appears on scroll
 * Works with both document scroll and overflow-y-auto containers (main, #app).
 */
import Alpine from '@alpinejs/csp';

Alpine.data('backToTop', (scrollContainerSelector = '[data-scroll-container]') => ({
    visible: false,
    scrollThreshold: 300,
    scrollContainer: null,
    handleScroll: null,

    init() {
        this.scrollContainer = document.querySelector(scrollContainerSelector) || window;
        const scrollTarget = this.scrollContainer === window ? window : this.scrollContainer;

        this.handleScroll = () => {
            const scrollTop = this.scrollContainer === window
                ? window.scrollY || document.documentElement.scrollTop
                : this.scrollContainer.scrollTop;
            this.visible = scrollTop > this.scrollThreshold;
        };

        scrollTarget.addEventListener('scroll', this.handleScroll, { passive: true });
        this.handleScroll(); // Initial check
    },

    destroy() {
        this.scrollContainer?.removeEventListener('scroll', this.handleScroll);
    },

    scrollToTop() {
        const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'instant' : 'smooth';
        this.scrollContainer.scrollTo({ top: 0, behavior });
    },
}));
