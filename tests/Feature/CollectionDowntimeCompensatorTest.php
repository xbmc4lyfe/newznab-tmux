<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\CollectionDowntimeCompensator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CollectionDowntimeCompensatorTest extends TestCase
{
    private string $marker = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = sys_get_temp_dir().'/nntmux-last-activity-'.uniqid('', true).'/marker';
        Schema::dropIfExists('collections');
        Schema::create('collections', static function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('dateadded')->nullable();
        });
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('collections');
        @unlink($this->marker);
        @rmdir(\dirname($this->marker));

        parent::tearDown();
    }

    public function test_a_long_outage_extends_only_collections_that_were_still_waiting(): void
    {
        // The indexer stopped at 11:00 and starts again at 12:00, with a 2 hour delay.
        $this->lastActive(Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 10:30:00');   // 30 min into its 2 h wait at 11:00
        $expired = $this->collection('2026-10-11 08:30:00');   // already past its 2 h wait at 11:00
        $newer = $this->collection('2026-10-11 11:50:00');     // written after the last recorded activity

        $moved = $this->compensator()->compensate(2);

        $this->assertSame(2, $moved);
        $this->assertSame('2026-10-11 12:00:00', $this->dateAdded($newer), 'Gets back only the 10 minutes it waited.');
        $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting), 'Pushed back by the hour of downtime.');
        $this->assertSame('2026-10-11 08:30:00', $this->dateAdded($expired), 'Already due when the indexer stopped.');
    }

    public function test_a_short_restart_changes_nothing(): void
    {
        $this->lastActive(Carbon::parse('2026-10-11 11:58:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 11:00:00');

        $this->assertSame(0, $this->compensator()->compensate(2));
        $this->assertSame('2026-10-11 11:00:00', $this->dateAdded($waiting));
    }

    public function test_a_long_outage_restores_the_full_downtime(): void
    {
        $this->lastActive(Carbon::parse('2026-10-11 02:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 01:30:00');

        $this->compensator()->compensate(2);

        $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting), 'Ten hours down: resumes 30 minutes into its wait, not stale.');
    }

    public function test_unknown_downtime_changes_nothing_and_starts_recording(): void
    {
        $waiting = $this->collection('2026-10-11 09:00:00');

        $this->assertSame(0, $this->compensator()->compensate(2));
        $this->assertSame('2026-10-11 09:00:00', $this->dateAdded($waiting));
        $this->assertSame(Carbon::now()->getTimestamp(), $this->compensator()->lastActivity());
    }

    public function test_a_second_start_right_after_compensating_changes_nothing(): void
    {
        $this->lastActive(Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 10:30:00');
        $compensator = $this->compensator();

        $compensator->compensate(2);
        $this->travel(30)->seconds();
        $compensator->compensate(2);

        $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting), 'tmux:start and tmux:monitor both compensate on start; only the first may move it.');
    }

    public function test_record_activity_stores_the_current_time(): void
    {
        $this->compensator()->recordActivity();

        $this->assertSame(Carbon::now()->getTimestamp(), $this->compensator()->lastActivity());
    }

    public function test_work_follows_the_waiting_window_not_the_id_range(): void
    {
        $this->lastActive(Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        DB::table('collections')->insert(['id' => 2_000_000_001, 'dateadded' => '2026-10-11 10:30:00']);
        $updates = 0;
        DB::listen(static function ($query) use (&$updates): void {
            $updates += preg_match('/^\s*update\b/i', $query->sql);
        });

        $this->assertSame(1, $this->compensator()->compensate(2));
        $this->assertSame(1, $updates, 'Only the occupied window, however high the ids or long the outage.');
    }

    public function test_rows_crossing_into_a_later_window_are_shifted_once(): void
    {
        $this->lastActive(Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $early = $this->collection('2026-10-11 09:00:30');
        $late = $this->collection('2026-10-11 10:59:30');

        // Down 10 minutes: each moves 10 minutes, landing in windows already processed.
        $this->travelTo(Carbon::parse('2026-10-11 11:10:00'));
        $this->compensator()->compensate(2);

        $this->assertSame('2026-10-11 09:10:30', $this->dateAdded($early));
        $this->assertSame('2026-10-11 11:09:30', $this->dateAdded($late));
    }

    public function test_the_marker_survives_a_cache_flush(): void
    {
        $this->compensator()->recordActivity();
        Cache::flush();

        $this->assertSame(Carbon::now()->getTimestamp(), $this->compensator()->lastActivity());
    }

    public function test_an_interrupted_start_never_shifts_rows_twice(): void
    {
        $this->lastActive(Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 10:30:00');
        $this->compensator()->compensate(2);

        // A retry a minute later (the first start died after its updates).
        $this->travel(1)->minutes();
        $this->compensator()->compensate(2);

        $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting));
    }

    public function test_works_in_a_timezone_west_of_utc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
            $this->lastActive(Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
            $waiting = $this->collection('2026-10-11 10:30:00');

            $this->assertSame(1, $this->compensator()->compensate(2));
            $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_a_dst_fall_back_never_moves_rows_backwards(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            // 01:50 EDT to 01:10 EST on 2026-11-01 is 20 minutes, though the clock reads earlier.
            $lastActive = Carbon::parse('2026-11-01 05:50:00', 'UTC')->getTimestamp();
            Carbon::setTestNow(Carbon::createFromTimestamp($lastActive + 1200, 'UTC'));
            $this->lastActive($lastActive);
            $waiting = $this->collection('2026-11-01 01:30:00');
            $ambiguous = $this->collection('2026-11-01 01:45:00'); // 01:45 EDT plus 20 minutes reads 01:05 EST

            $this->compensator()->compensate(2);

            $this->assertSame('2026-11-01 01:50:00', $this->dateAdded($waiting), 'Moved forward by the 20 real minutes.');
            $this->assertSame('2026-11-01 01:05:00', $this->dateAdded($ambiguous), 'Moved once, though its wall clock reads earlier.');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_a_dst_spring_forward_keeps_the_real_elapsed_wait(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            // 01:50 EST to 03:10 EDT on 2026-03-08 is 20 real minutes.
            $lastActive = Carbon::parse('2026-03-08 06:50:00', 'UTC')->getTimestamp();
            Carbon::setTestNow(Carbon::createFromTimestamp($lastActive + 1200, 'UTC'));
            $this->lastActive($lastActive);
            $waiting = $this->collection('2026-03-08 01:45:00');

            $this->compensator()->compensate(2);

            $this->assertSame('2026-03-08 03:05:00', $this->dateAdded($waiting), '01:45 EST plus 20 minutes is 03:05 EDT.');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_collections_from_the_repeated_fall_back_hour_are_included(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            // Shut down 02:50 EST, restart 03:20 EST on 2026-11-01; added at 01:10 EST.
            $lastActive = Carbon::parse('2026-11-01 07:50:00', 'UTC')->getTimestamp();
            Carbon::setTestNow(Carbon::createFromTimestamp($lastActive + 1800, 'UTC'));
            $this->lastActive($lastActive);
            $repeatedHour = $this->collection('2026-11-01 01:10:00');
            $lateInRepeatedHour = $this->collection('2026-11-01 01:45:00');

            $this->compensator()->compensate(2);

            $this->assertSame('2026-11-01 01:40:00', $this->dateAdded($repeatedHour), 'Gets the 30 minutes back.');
            $this->assertSame('2026-11-01 02:15:00', $this->dateAdded($lateInRepeatedHour), 'Read as 01:45 EST, the occurrence before the shutdown.');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    private function compensator(): CollectionDowntimeCompensator
    {
        return new CollectionDowntimeCompensator($this->marker);
    }

    private function lastActive(int $timestamp): void
    {
        @mkdir(\dirname($this->marker), 0775, true);
        file_put_contents($this->marker, (string) $timestamp);
    }

    private function collection(string $dateAdded): int
    {
        return DB::table('collections')->insertGetId(['dateadded' => $dateAdded]);
    }

    private function dateAdded(int $id): string
    {
        return (string) DB::table('collections')->where('id', $id)->value('dateadded');
    }
}
