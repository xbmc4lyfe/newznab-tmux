<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\UpdateBackfill;
use App\Enums\ConfigurationDomain;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

final class UpdateBackfillCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Cache::flush();

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('first_record')->default(0);
            $table->unsignedBigInteger('last_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
            $table->integer('backfill_target')->default(1);
            $table->boolean('active')->default(true);
            $table->boolean('backfill')->default(true);
        });

        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, []);
        config(['nntmux.echocli' => false]);
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function validGroupNames(): array
    {
        return [
            'standard alt.binaries group without quantity' => ['alt.binaries.ath', null],
            'standard alt.binaries group with quantity' => ['alt.binaries.ath', 200000],
            'prefixed ab.alt.binaries group' => ['ab.alt.binaries.moovee', null],
            'regional dk.binaer group' => ['dk.binaer.film', 100000],
            'numeric prefix 4u.alt group' => ['4u.alt.movies', 50000],
        ];
    }

    #[DataProvider('validGroupNames')]
    public function test_backfills_any_valid_dotted_usenet_group_name(string $groupName, ?int $quantity): void
    {
        $queriedGroups = [];
        DB::listen(static function (QueryExecuted $event) use (&$queriedGroups): void {
            if (str_contains($event->sql, 'usenet_groups') && $event->bindings !== []) {
                $queriedGroups[] = (string) $event->bindings[0];
            }
        });

        $this->bindCommandWithNntp($this->mock(NNTPService::class));

        $arguments = ['mode' => $groupName];
        if ($quantity !== null) {
            $arguments['quantity'] = (string) $quantity;
        }

        $this->artisan('update:backfill', $arguments)->assertSuccessful();
        $this->assertContains($groupName, $queriedGroups);
    }

    public function test_rejects_unknown_non_group_mode_arguments(): void
    {
        $this->bindCommandWithNntp($this->mock(NNTPService::class));

        $this->artisan('update:backfill', ['mode' => 'invalidmode'])
            ->expectsOutputToContain('Wrong set of arguments.')
            ->assertFailed();
    }

    private function bindCommandWithNntp(NNTPService $nntp): void
    {
        $command = new class($nntp) extends UpdateBackfill
        {
            public function __construct(
                private readonly NNTPService $mockNntp,
            ) {
                parent::__construct();
            }

            protected function getNntp(): NNTPService
            {
                return $this->mockNntp;
            }
        };

        $this->app->instance(UpdateBackfill::class, $command);
    }
}
