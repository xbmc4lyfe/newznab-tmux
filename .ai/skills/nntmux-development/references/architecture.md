## Architecture

NNTmux scans Usenet servers, collects headers, organizes releases, and enriches with metadata. Data flow:

```
NNTP → NNTPService → BinariesRunner → ReleaseCreationService → ReleaseProcessingService → SearchService → API/Web
```

### Key Patterns

| Pattern | Location | Example |
|---------|----------|---------|
| **Service Layer** | `app/Services/` | 50+ services with facades (`Search::`, `Categorization::`, `TvProcessing::`, `Yenc::`) |
| **Pipeline** | `*/Pipes/` | `TvProcessingPipeline` (Parse→Local DB→TVDB→TVMaze→TMDB→Trakt; pipes only record findings, `TvLookupOutcomeWriter` persists them; lookup states in `App\Enums\TvLookupStatus`, due-work SQL in `TvProcessingCandidateQuery`), `CategorizationPipeline` (priority-driven; `Music` runs before `Book` for audiobook detection) |
| **Driver** | `Search/Drivers/` | Manticore via `SEARCH_DRIVER` env var |
| **Runners** | `Runners/` | `BinariesRunner`, `ReleasesRunner`, `BackfillRunner`, `PostProcessRunner` |
| **DTO** | `*/DTO/`, `app/Support/DTOs/`, `app/Data/` | Internal: `NameFixResult`, `ReleaseProcessingContext`, `ReleaseCreationResult`. API responses use Spatie Laravel Data in `app/Data/Api/` (`ReleaseData`, `CategoryData`, `DetailsData`) |
| **Enum** | `app/Enums/` | `UserRole`, `QueueType`, `FileCompletionStatus`, `SecondarySearchIndex`, `NzbImportStatus` |
| **Observer** | `app/Observers/`, `AppServiceProvider` | `ReleaseObserver`, `MovieInfoObserver`, `RolePromotionObserver` |
| **View Composer** | `app/View/Composers/`, `AppServiceProvider` | `GlobalDataComposer` shared across `layouts.*` and `admin.*` |
| **Status Probe** | `app/Services/StatusProbes/` | `ServiceProbeRegistry` aggregates `DatabaseProbe`, `DiskProbe`, `NntpProbe`, `QueueProbe`, `RedisProbe`, `SearchProbe` for `StatusPageController` (`/status`) and `DegradeWhenRedisUnreachable` middleware; tune via `config/status-probes.php` |
| **Monitoring** | `app/Services/Monitoring/`, `config/monitoring.php`, `docker/monitoring/`, `scripts/install-monitoring.sh` | Prometheus + Grafana. `monitoring:export-metrics` (every minute) runs tagged `MetricsCollector`s and PUTs to the Pushgateway; processing counts come from `TmuxMetricsSnapshot`, published by the tmux monitor loop. Grafana is proxied at `/grafana/` and trusts RS256 JWTs from `GrafanaJwtIssuer`, which only ever arrive as the `X-JWT-Assertion` header and never in a URL. With `GRAFANA_AUTH=proxy`, nginx `auth_request` calls `AdminMonitoringController::authorizeGrafana` on every request; with `cookie` (Apache), the web server copies the HttpOnly `nntmux_grafana_jwt` cookie into the header. See `docker/monitoring/README.md`. Alpine `adminMonitoring`/`grafanaPanels` render the embeds (Alpine's CSP build forbids directives on `<iframe>`). Dashboard UIDs/panel ids are a contract with `config/monitoring.php` (`GrafanaDashboardDefinitionsTest`). The Ubuntu installer reuses exporters it didn't install and never modifies them; Sail uses the `docker-compose.monitoring.yml` overlay, which the Justfile adds to every compose call (`just up`/`down`) while `MONITORING_ENABLED=true` and the Grafana key exists |
| **Passkey** | `app/Actions/Passkeys/`, `app/Http/Controllers/Auth/Passkey*` | Spatie Laravel Passkeys; ceremony actions (`GeneratePasskeyRegisterOptionsAction`, `FindPasskeyToAuthenticateAction`) wire into routes `passkeys.*` in `routes/web.php`. `GeneratePasskeyRegisterOptionsAction` overrides `authenticatorSelection()` (defaults: attachment=null, `residentKey=preferred`, `userVerification=preferred`) and injects WebAuthn L3 `hints` + `credProps` extension so Windows Hello / Touch ID / phone-via-QR / FIDO2 keys all appear in the browser picker on Windows domain machines. Tunable via `PASSKEY_AUTHENTICATOR_ATTACHMENT`, `PASSKEY_RESIDENT_KEY`, `PASSKEY_USER_VERIFICATION`, `PASSKEY_RELYING_PARTY_ID` (see `config/passkeys.php`) |

## Key Directories

| Path | Purpose |
|------|---------|
| `app/Services/TvProcessing/` | TV metadata pipeline |
| `app/Services/Search/` | Manticore/ES abstraction |
| `app/Services/NameFixing/` | Release name correction (see README.md there) |
| `app/Services/Tmux/` | Tmux orchestration |
| `app/Services/StatusProbes/` | Service health probes feeding `/status` and degrade middleware |
| `app/Facades/` | Static service accessors |

## External APIs

Requires `.env` keys: TMDB, TVDB, TVMaze, Trakt, OMDB (TV/Movies); IGDB, GiantBomb, Steam (Games); AniList, AniDB (Anime); NNTP credentials.
