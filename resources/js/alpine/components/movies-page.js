/**
 * Alpine.data('moviesPage') - Movies page layout toggle
 * Switches between 1 and 2 column layouts instantly via CSS cascade,
 * persisting the preference to the server in the background.
 */
import Alpine from '@alpinejs/csp';

Alpine.data('moviesPage', () => ({
    layout: 2,

    init() {
        const layoutAttr = this.$el.dataset.movieLayout;
        if (layoutAttr) {
            this.layout = parseInt(layoutAttr, 10) || 2;
        }
    },

    get layoutLabel() {
        return this.layout === 1 ? 'Comfortable list' : 'Compact grid';
    },

    get layoutIcon() {
        return this.layout === 1 ? 'fas fa-list' : 'fas fa-table-cells-large';
    },

    toggleLayout() {
        this.layout = this.layout === 1 ? 2 : 1;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf) {
            fetch('/movies/update-layout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ layout: this.layout }),
            }).catch(() => {});
        }
    },
}));
