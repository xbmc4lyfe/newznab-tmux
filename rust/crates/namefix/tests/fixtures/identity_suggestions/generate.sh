#!/usr/bin/env bash
# Regenerates golden.jsonl from the PHP reference's releases:identity-suggestions.
# Needs: docker compose -f rust/compose.test.yml up -d, and rust/porting/php-ref-setup.sh run once.
# Usage: generate.sh [path to the php-final checkout]
set -euo pipefail

ref="${1:-$HOME/git.xbmc4lyfe/newznab-tmux-php-ref}"
here="$(cd "$(dirname "$0")" && pwd)"
sql() { docker exec -i nntmux-rust-test-mariadb-1 mariadb -uroot -proot nntmux -e "$1"; }

ids="990001,990002,990003,990004,990005,990006,990007"
trap 'sql "DELETE FROM predb WHERE id IN ($ids)"' EXIT
sql "DELETE FROM predb WHERE id IN ($ids);
INSERT INTO predb (id, title, source) VALUES
 (990001, 'Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR', 'golden'),
 (990002, 'Zmora.S01E05.720p.AMZN.WEB-DL.DDP5.1.H.264-RAWR', 'golden'),
 (990003, 'Some.Film.2021.1080p.BluRay.x264-GRP', 'golden'),
 (990004, 'Some.Film.2021.2160p.BluRay.x265-OTHER', 'golden'),
 (990005, 'Unrelated.Show.S02E01.1080p.WEB.h264-NOPE', 'golden'),
 (990006, 'The.Wandering.Stars.Over.Quiet.Harbor.2021.1080p.BluRay.x264-GRP', 'golden'),
 (990007, 'The.Wandering.Stars.Over.Quiet.Harbor.2021.2160p.BluRay.x265-OTHER', 'golden')"

ask() {
    (cd "$ref" && php artisan --no-ansi releases:identity-suggestions "$@" 2>/dev/null) | tail -n 1
}
{
    ask "zmora s01e05 1080p amzn web-dl ddp5.1 h.264-rawr" --candidate-id=990001 --candidate-id=990002
    ask "Zmora.S01E05.1080p" --candidate-id=990001 --candidate-id=990002
    ask "Zmora.S01E05" --candidate-id=990001 --candidate-id=990002
    ask "Some.Film.2021" --candidate-id=990003 --candidate-id=990004
    ask "Some.Film.2021.1080p.BluRay.x264-GRP.mkv" --candidate-id=990003
    ask "Some/Film.2021.1080p" --candidate-id=990003
    ask "Nothing.Like.It.1999" --candidate-id=990005
    ask "Zmora.S01E05" --candidate-id=999999
    ask "The.Wandering.Stars.Over.Quiet.Harbor.2021.1080p.BluRay.x264-GRP" --candidate-id=990006 --candidate-id=990007
    ask "the wandering stars over quiet harbor 2021 1080p bluray x264-grp" --candidate-id=990006
    ask "The Wandering Stars Over Quiet Harbor 2021" --candidate-id=990006
    ask "The Wandering Stars Over Quiet Harbor 2021" --candidate-id=990006 --candidate-id=990007
    ask "The Wandering Stars Over Quiet Harbor 1080p" --candidate-id=990006 --candidate-id=990007
    ask "The/Wandering Stars Over Quiet Harbor 2021 1080p BluRay" --candidate-id=990006
} >"$here/golden.jsonl"
