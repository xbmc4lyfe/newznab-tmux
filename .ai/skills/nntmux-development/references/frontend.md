## Frontend

Server-rendered askama templates (`rust/crates/webkit/templates/` and `rust/crates/site/templates/`), with TailwindCSS v4, Alpine.js and Vite for the assets.

- The source is `rust/frontend/` (`js/app.js`, `css/app.css`). `just frontend` runs `npm ci && npm run build` and writes `rust/frontend/public/build`. The Dockerfile builds it in its own stage, and `nntmux-web` reads the Vite manifest for its asset tags.
- **Alpine.js** is the CSP-safe build (`@alpinejs/csp`), with components under `rust/frontend/js/alpine/`. The CSP build forbids inline expressions, so put logic in components and register them. `rust/crates/webkit/src/headers.rs` sets the `Content-Security-Policy` header with a per-request nonce.
- **CSS**: the entry is `rust/frontend/css/app.css`, which imports `csp-safe.css` for component styles.
- Keep scripts and styles in external files so the CSP holds. Run `just frontend` after changes under `rust/frontend`.
