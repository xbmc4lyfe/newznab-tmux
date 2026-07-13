import axios from 'axios';
import {
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
    startAuthentication,
    startRegistration,
} from '@simplewebauthn/browser';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.browserSupportsWebAuthn = browserSupportsWebAuthn;
window.browserSupportsWebAuthnAutofill = browserSupportsWebAuthnAutofill;
window.startAuthentication = startAuthentication;
window.startRegistration = startRegistration;
