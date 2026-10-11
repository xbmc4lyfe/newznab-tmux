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
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
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

        parent::tearDown();
    }

    public function test_a_long_outage_extends_only_collections_that_were_still_waiting(): void
    {
        // The indexer stopped at 11:00 and starts again at 12:00, with a 2 hour delay.
        Cache::forever(CollectionDowntimeCompensator::LAST_ACTIVE_KEY, Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 10:30:00');   // 30 min into its 2 h wait at 11:00
        $expired = $this->collection('2026-10-11 08:30:00');   // already past its 2 h wait at 11:00
        $newer = $this->collection('2026-10-11 11:50:00');     // added after activity stopped being recorded

        $moved = (new CollectionDowntimeCompensator)->compensate(2);

        $this->assertSame(1, $moved);
        $this->assertSame('2026-10-11 11:50:00', $this->dateAdded($newer), 'It did not wait through the downtime.');
        $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting), 'Pushed back by the hour of downtime.');
        $this->assertSame('2026-10-11 08:30:00', $this->dateAdded($expired), 'Already due when the indexer stopped.');
    }

    public function test_a_short_restart_changes_nothing(): void
    {
        Cache::forever(CollectionDowntimeCompensator::LAST_ACTIVE_KEY, Carbon::parse('2026-10-11 11:58:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 11:00:00');

        $this->assertSame(0, (new CollectionDowntimeCompensator)->compensate(2));
        $this->assertSame('2026-10-11 11:00:00', $this->dateAdded($waiting));
    }

    public function test_the_extension_never_exceeds_one_full_delay(): void
    {
        Cache::forever(CollectionDowntimeCompensator::LAST_ACTIVE_KEY, Carbon::parse('2026-10-11 02:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 01:00:00');

        (new CollectionDowntimeCompensator)->compensate(2);

        $this->assertSame('2026-10-11 03:00:00', $this->dateAdded($waiting), 'Ten hours down, but at most two hours added.');
    }

    public function test_unknown_downtime_changes_nothing_and_starts_recording(): void
    {
        $waiting = $this->collection('2026-10-11 09:00:00');

        $this->assertSame(0, (new CollectionDowntimeCompensator)->compensate(2));
        $this->assertSame('2026-10-11 09:00:00', $this->dateAdded($waiting));
        $this->assertSame(Carbon::now()->getTimestamp(), Cache::get(CollectionDowntimeCompensator::LAST_ACTIVE_KEY));
    }

    public function test_a_second_start_right_after_compensating_changes_nothing(): void
    {
        Cache::forever(CollectionDowntimeCompensator::LAST_ACTIVE_KEY, Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $waiting = $this->collection('2026-10-11 10:30:00');
        $compensator = new CollectionDowntimeCompensator;

        $compensator->compensate(2);
        $this->travel(30)->seconds();
        $compensator->compensate(2);

        $this->assertSame('2026-10-11 11:30:00', $this->dateAdded($waiting), 'tmux:start and tmux:monitor both compensate on start; only the first may move it.');
    }

    public function test_record_activity_stores_the_current_time(): void
    {
        (new CollectionDowntimeCompensator)->recordActivity();

        $this->assertSame(Carbon::now()->getTimestamp(), Cache::get(CollectionDowntimeCompensator::LAST_ACTIVE_KEY));
    }

    public function test_work_follows_the_waiting_window_not_the_id_range(): void
    {
        Cache::forever(CollectionDowntimeCompensator::LAST_ACTIVE_KEY, Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        DB::table('collections')->insert(['id' => 2_000_000_001, 'dateadded' => '2026-10-11 10:30:00']);
        $updates = 0;
        DB::listen(static function ($query) use (&$updates): void {
            $updates += preg_match('/^\s*update\b/i', $query->sql);
        });

        $this->assertSame(1, (new CollectionDowntimeCompensator)->compensate(2));
        $this->assertSame(120, $updates, 'One per minute of the two-hour window, however high the ids.');
    }

    public function test_rows_crossing_into_a_later_window_are_shifted_once(): void
    {
        Cache::forever(CollectionDowntimeCompensator::LAST_ACTIVE_KEY, Carbon::parse('2026-10-11 11:00:00')->getTimestamp());
        $early = $this->collection('2026-10-11 09:00:30');
        $late = $this->collection('2026-10-11 10:59:30');

        // Down 10 minutes: each moves 10 minutes, landing in windows already processed.
        $this->travelTo(Carbon::parse('2026-10-11 11:10:00'));
        (new CollectionDowntimeCompensator)->compensate(2);

        $this->assertSame('2026-10-11 09:10:30', $this->dateAdded($early));
        $this->assertSame('2026-10-11 11:09:30', $this->dateAdded($late));
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
