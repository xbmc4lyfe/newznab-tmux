## Tmux Processing Engine

Multi-pane terminal orchestrator at `app/Services/Tmux/`. Components: `TmuxSessionManager`, `TmuxLayoutBuilder`, `TmuxPaneManager`, `TmuxTaskRunner`, `TmuxMonitorService`.

**Sequential Modes** (`Settings::settingValue('sequential')`):
- Mode 0: Full (3 windows, parallel panes)
- Mode 1: Basic (reduced)
- Mode 2: Stripped (minimal)

**Commands**: `tmux:start`, `tmux:stop`, `tmux:attach`, `tmux:monitor`, `tmux:health-check`

**Config**: `config/tmux.php` + database `settings` table

**Post-process panes** (window 2: panes 2.0–2.3) run `php artisan multiprocessing:postprocess <type>`, which fans out work as multiple `postprocess:guid <type> <char>` child processes via `App\Services\Runners\PostProcessRunner`. Types: `add`/`nfo` (pane 2.0), `tv`/`ani` (2.1), `ama` (2.2 — books+music+console+games), `mov` (2.3). Per-type aliases: `boo`, `mus`, `con`, `gam`.

- **Live tmux output**: set `STREAM_FORK_OUTPUT=true` in `.env` (`config('nntmux.stream_fork_output')`). When false (default), child output is buffered per batch and the pane may look idle until a batch completes.
- **Parallelism settings** (all default to `1` in `database/seeders/SettingsTableSeeder.php`; raise via Admin UI or DB): `postthreads` (additional), `nfothreads` (NFO when `post=3`), `postthreadsnon` (TV/anime/movies), `postthreadsamazon` (books/music/console/games and `ama` fan-out). Raising `nfothreads` opens that many parallel NNTP sessions for NFO children.
- **Batch sizing**: up to 16 distinct first-character GUID buckets per type per cycle (`LIMIT 16` in `PostProcessRunner`); each bucket processes its slice sequentially inside `postprocess:guid`. Additional processing also respects `maxaddprocessed` (default 25) per bucket.
- **Direct CLI**: `update:postprocess <type>` remains available for single-process runs outside tmux; tmux panes use the multiprocessing command only.
