/**
 * Alpine.data('verifyUser') - Admin user verification confirmation modal.
 */
import Alpine from '@alpinejs/csp';

Alpine.data('verifyUser', () => ({
    open: false,
    _form: null,

    show(form) {
        this._form = form;
        this.open = true;
    },

    hide() {
        this.open = false;
        this._form = null;
    },

    submit() {
        if (this._form) {
            this._form.submit();
        }

        this.hide();
    },

    init() {
        window.showVerifyModal = (event, form) => {
            event.preventDefault();
            this.show(form);
        };
        window.hideVerifyModal = () => this.hide();
        window.submitVerifyForm = () => this.submit();
    },
}));
