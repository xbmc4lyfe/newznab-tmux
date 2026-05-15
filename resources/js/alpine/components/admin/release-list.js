/**
 * Alpine.data('adminReleaseList') - Admin release list bulk selection and category change
 */
import Alpine from '@alpinejs/csp';

Alpine.data('adminReleaseList', () => ({
    allChecked: false,
    selectedCount: 0,
    rootEl: null,

    init() {
        this.rootEl = this.$root;
        this.syncSelectionState();
    },

    componentRoot() {
        return this.rootEl || this.$root;
    },

    releaseCheckboxes() {
        const root = this.componentRoot();

        return root ? [...root.querySelectorAll('.release-checkbox')] : [];
    },

    setAllSelection(checked) {
        const boxes = this.releaseCheckboxes();
        boxes.forEach(cb => {
            cb.checked = checked;
        });
        this.allChecked = checked && boxes.length > 0;
        this.selectedCount = checked ? boxes.length : 0;
    },

    selectAll() {
        this.setAllSelection(true);
    },

    clearSelection() {
        this.setAllSelection(false);
    },

    syncSelectionState() {
        const boxes = this.releaseCheckboxes();
        const checkedCount = boxes.filter(cb => cb.checked).length;
        this.selectedCount = checkedCount;
        this.allChecked = boxes.length > 0 && checkedCount === boxes.length;
    },

    onCheckboxChange() {
        this.syncSelectionState();
    },

    showModal(options) {
        if (typeof window.showConfirm === 'function') {
            window.showConfirm(options);

            return;
        }

        if (options.onConfirm) {
            options.onConfirm();
        }
    },

    validateBulkAction(e) {
        e.preventDefault();

        const form = e.target;
        const root = this.componentRoot();
        const categorySelect = root?.querySelector('select[name="categories_id"]');
        const categoryId = categorySelect?.value;
        const categoryName = categorySelect?.selectedOptions?.[0]?.text?.trim() || '';
        const checkedCount = this.releaseCheckboxes().filter(cb => cb.checked).length;

        if (!categoryId || categoryId === '-1') {
            this.showModal({
                title: 'Category required',
                message: 'Please select a category to assign to the selected releases.',
                type: 'warning',
                confirmText: 'OK',
                cancelText: 'Close',
                onConfirm: function() {},
            });

            return;
        }

        if (checkedCount === 0) {
            this.showModal({
                title: 'No releases selected',
                message: 'Please select at least one release before changing its category.',
                type: 'warning',
                confirmText: 'OK',
                cancelText: 'Close',
                onConfirm: function() {},
            });

            return;
        }

        const self = this;
        this.showModal({
            title: 'Change category',
            message: 'Change category for ' + checkedCount + ' release(s)?',
            details: 'Assign to: ' + categoryName + '. The database and search index will be updated.',
            type: 'warning',
            confirmText: 'Change category',
            cancelText: 'Cancel',
            onConfirm: function() {
                form.submit();
            },
        });
    },
}));
