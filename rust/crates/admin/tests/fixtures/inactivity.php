<?php
// Golden cases for UserInactivityEvaluator::shouldPurge. From the repository root:
// php rust/crates/admin/tests/fixtures/inactivity.php <php-final checkout> > rust/crates/admin/tests/fixtures/inactivity.json
// The evaluator only calls lt() on its dates, so a small CarbonInterface stands in for Carbon.
namespace Carbon {
    interface CarbonInterface {}
}
namespace {
    use Carbon\CarbonInterface;

    final class At implements CarbonInterface
    {
        public function __construct(public readonly DateTimeImmutable $at) {}
        public function lt(CarbonInterface $other): bool { return $this->at < $other->at; }
    }

    require $argv[1].'/app/Services/UserInactivityEvaluator.php';

    $threshold = new DateTimeImmutable('2025-09-12 12:00:00');
    $times = [
        'none' => null,
        'stale' => '2025-03-15 08:00:00',
        'edge' => '2025-09-12 12:00:00',
        'fresh' => '2026-03-11 10:00:00',
    ];
    $at = static fn (?string $value): ?At => $value === null ? null : new At(new DateTimeImmutable($value));
    $evaluator = new App\Services\UserInactivityEvaluator;
    $rows = [];
    // Each case: [created_at, updated_at, lastlogin, apiaccess, lastdownload, grabs, purge],
    // with the dates as keys of `times`.
    foreach ($times as $c => $created) {
        foreach ($times as $u => $updated) {
            foreach ($times as $l => $login) {
                foreach ($times as $a => $api) {
                    foreach ($times as $d => $download) {
                        foreach ([0, 3] as $grabs) {
                            $rows[] = [$c, $u, $l, $a, $d, $grabs, $evaluator->shouldPurge(
                                    createdAt: $at($created),
                                    updatedAt: $at($updated),
                                    lastLoginAt: $at($login),
                                    apiAccessAt: $at($api),
                                    lastDownloadAt: $at($download),
                                    grabs: $grabs,
                                    threshold: new At($threshold),
                            )];
                        }
                    }
                }
            }
        }
    }
    echo "{\"threshold\": \"", $threshold->format('Y-m-d H:i:s'), "\",\n\"times\": ", json_encode($times), ",\n\"cases\": [\n";
    echo implode(",\n", array_map('json_encode', $rows)), "\n]}\n";
}
