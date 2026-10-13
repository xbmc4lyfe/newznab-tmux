<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Facades\Search;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PredbFeedImporterMissingDetailsTest extends TestCase
{
    private Capsule $database;

    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    private ?ConnectionResolverInterface $previousResolver;

    private ?Dispatcher $previousDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository([]));
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $this->database = new Capsule($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $app->instance('db', $this->database->getDatabaseManager());
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT UNIQUE, source TEXT, category TEXT, size TEXT, files TEXT, predate TEXT, nuked INTEGER, nukereason TEXT, filename TEXT)');
        Search::swap(new class
        {
            /** @param array<string, mixed> $document */
            public function updatePreDb(array $document): void {}
        });
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('nonemptyDetails')]
    public function nonempty_zero_values_are_preserved_and_preview_matches_writes(string $column, string $replacement): void
    {
        DB::table('predb')->insert(['id' => 1, 'title' => 'Exact.Release.2026-GRP', 'source' => 'original-publisher', $column => '0', 'nuked' => 0, 'filename' => '']);
        $before = (array) DB::table('predb')->where('id', 1)->first();
        $entry = $this->detailEntry($column, $replacement);

        $preview = (new PredbFeedImporter)->import([$entry], true);
        $written = (new PredbFeedImporter)->import([$entry]);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $preview);
        $this->assertSame($preview, $written);
        $this->assertSame($before, (array) DB::table('predb')->where('id', 1)->first());
    }

    /** @return array<string, array{string, string}> */
    public static function nonemptyDetails(): array
    {
        return ['category label' => ['category', 'PUBLISHED-CATEGORY'], 'size literal' => ['size', '67MB'], 'file count literal' => ['files', '12']];
    }

    #[Test]
    #[DataProvider('missingDetails')]
    public function null_and_empty_details_can_be_enriched_without_changing_provenance(string $column, ?string $missing, string $replacement): void
    {
        DB::table('predb')->insert(['id' => 1, 'title' => 'Exact.Release.2026-GRP', 'source' => 'original-publisher', $column => $missing, 'nuked' => 0, 'filename' => '']);
        $entry = $this->detailEntry($column, $replacement);

        $preview = (new PredbFeedImporter)->import([$entry], true);
        $written = (new PredbFeedImporter)->import([$entry]);

        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0], $preview);
        $this->assertSame($preview, $written);
        $this->assertSame($replacement, DB::table('predb')->where('id', 1)->value($column));
        $this->assertSame('original-publisher', DB::table('predb')->where('id', 1)->value('source'));
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function missingDetails(): array
    {
        return [
            'null category' => ['category', null, 'PUBLISHED-CATEGORY'],
            'empty category' => ['category', '', 'PUBLISHED-CATEGORY'],
            'null size' => ['size', null, '67MB'],
            'empty size' => ['size', '', '67MB'],
            'null files' => ['files', null, '12'],
            'empty files' => ['files', '', '12'],
        ];
    }

    private function detailEntry(string $column, string $replacement): PredbFeedEntry
    {
        return new PredbFeedEntry(
            title: 'Exact.Release.2026-GRP',
            source: 'second-publisher',
            category: $column === 'category' ? $replacement : null,
            size: $column === 'size' ? $replacement : null,
            files: $column === 'files' ? $replacement : null,
        );
    }
}
