## Testing

PHPUnit only (no Pest). Create tests: `php artisan make:test --phpunit {name}`

- In-memory SQLite (`DB_CONNECTION=testing`)
- App boot can hit `Settings::settingValue()` via `CategorizationPipeline` (`app/Providers/CategorizationServiceProvider.php` → `app/Services/Categorization/CategorizationPipeline.php`), even in focused controller tests
- For isolated tests that bypass the normal app test DB setup, seed a minimal `settings` table before app bootstrap; `categorizeforeign` and `catwebdl` are the minimum keys needed for this path, and `tests/Feature/AdminContentControllerTest.php` shows the file-backed SQLite workaround when `php artisan test` would otherwise fail during startup
- Feature tests that render shared layouts or admin pages may need to clear `App\View\Composers\GlobalDataComposer::$resolvedData`; see `resetGlobalComposerState()` helpers in `tests/Feature/AdminContentControllerTest.php`, `AdminGroupControllerTest.php`, and `NzbAndRssAccessTest.php`
- All HTTP mocked - no real API calls
- Suites: `Install`, `Unit`, `Feature` (also `tests/Integration/` for live API tests, not in CI)
- Use model factories; check for custom states first
- Mocks in `tests/Fixtures/`, `tests/mock_data/`
- Test harnesses in `tests/Support/` (e.g., `DatabaseTestCase`, `TestBinariesHarness`)
- PHPUnit 12 — use `#[Test]` attributes or `test` prefix naming
