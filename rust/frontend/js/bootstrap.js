import axios from 'axios';
import {
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
    platformAuthenticatorIsAvailable,
    startAuthentication,
    startRegistration,
} from '@simplewebauthn/browser';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}
window.browserSupportsWebAuthn = browserSupportsWebAuthn;
window.browserSupportsWebAuthnAutofill = browserSupportsWebAuthnAutofill;
window.platformAuthenticatorIsAvailable = platformAuthenticatorIsAvailable;
window.startAuthentication = startAuthentication;
window.startRegistration = startRegistration;
