## Frontend

Blade + TailwindCSS v4 + Vite bundling. Run `npm run build` after changes.

- **Livewire 3**: Used only by the vendored Spatie Pulse dashboard views (`resources/views/vendor/pulse/`). All application pages (auth, profile, admin, browse, etc.) are plain Blade + Alpine.
- **Alpine.js**: CSP-safe build with component architecture in `resources/js/alpine/`
  - Core components loaded eagerly in `alpine/index.js`
  - Page-specific components lazy-loaded via `alpine/lazy-loader.js`
  - Lazy-loaded pages must declare an `x-data` name that matches a key in `alpine/lazy-loader.js`, or the component JS will never load; example: `resources/views/admin/content/index.blade.php` uses `x-data="contentToggle"` so delete/toggle handlers from `resources/js/alpine/components/content-toggle.js` are available
  - Stores in `alpine/stores/`, components in `alpine/components/`
- **CSS**: Main entry is `resources/css/app.css` (imports `csp-safe.css` for component styles)
- **Vite entry points**: `resources/js/app.js` and `resources/css/app.css`

This structure ensures Content Security Policy (CSP) compliance by using Alpine.js CSP-safe build and keeping scripts and styles in external files.
