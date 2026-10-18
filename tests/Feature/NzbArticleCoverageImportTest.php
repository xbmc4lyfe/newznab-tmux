<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NzbArticleCoverageImportTest extends TestCase
{
    public static function exhaustedProofBounds(): array
    {
        return ['candidate count' => [false], 'decoded byte budget' => [true]];
    }

    #[DataProvider('exhaustedProofBounds')]
    public function test_exhausted_fingerprint_proof_skips_metadata_nomination_queries(bool $bytes): void
    {
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            foreach (['guid', 'name', 'searchname', 'fromname'] as $column) {
                $table->string($column)->default('');
            }
            $table->integer('size')->default(0);
            $table->integer('predb_id')->default(0);
            $table->integer('nzbstatus')->default(1);
            $table->char('article_fingerprint', 40)->nullable();
        });
        $fingerprint = str_repeat('a', 40);
        $reads = $bytes ? 2 : 128;
        for ($i = 1; $i <= $reads; $i++) {
            DB::table('releases')->insert(['guid' => 'fingerprint-'.$i, 'article_fingerprint' => $fingerprint]);
        }
        // Overlapping metadata nominations would run all three eager queries.
        for ($i = 1; $i <= 127; $i++) {
            DB::table('releases')->insert(['guid' => 'metadata-'.$i, 'name' => 'fixture', 'searchname' => 'fixture', 'predb_id' => 42]);
        }
        $nzb = $this->getMockBuilder(NzbService::class)->disableOriginalConstructor()->onlyMethods(['readNzbContentsBounded'])->getMock();
        $nzb->expects(self::exactly($reads))->method('readNzbContentsBounded')->willReturnCallback(static function (string $guid, int $compressed, int $decoded, int &$used) use ($bytes): false {
            $used = $bytes ? $decoded : 0;

            return false;
        });
        $incoming = simplexml_load_string('<nzb><file><segments><segment bytes="1" number="1">incoming@example.test</segment></segments></file></nzb>');
        self::assertNotFalse($incoming);
        $connection = DB::connection();
        $connection->enableQueryLog();
        try {
            $coverage = new NzbArticleCoverage($nzb, new ReleaseDuplicateFinder);
            self::assertNull($coverage->findCoveredUpload(0, $fingerprint, $incoming, 'fixture', 'fixture', 42));
            $metadataQueries = array_filter($connection->getQueryLog(), static fn (array $query): bool => preg_match('/where "(?:predb_id|searchname|name)" =/', $query['query']) === 1);
            self::assertCount(0, $metadataQueries, 'Exhausted proof bounds must skip eager metadata SQL.');
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    public function test_precomputed_required_ids_replace_the_incoming_documents_own_inventory(): void
    {
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            foreach (['guid', 'name', 'searchname', 'fromname'] as $column) {
                $table->string($column)->default('');
            }
            $table->integer('size')->default(0);
            $table->integer('predb_id')->default(0);
            $table->integer('nzbstatus')->default(1);
            $table->char('article_fingerprint', 40)->nullable();
        });
        $fingerprint = str_repeat('c', 40);
        DB::table('releases')->insert(['guid' => 'retained', 'article_fingerprint' => $fingerprint]);
        $retained = '<nzb><file><segments><segment bytes="1" number="1">retained@example.test</segment></segments></file></nzb>';
        $nzb = $this->getMockBuilder(NzbService::class)->disableOriginalConstructor()->onlyMethods(['readNzbContentsBounded'])->getMock();
        $nzb->method('readNzbContentsBounded')->willReturnCallback(static function (string $guid, int $compressed, int $decoded, int &$used) use ($retained): string {
            $used = strlen($retained);

            return $retained;
        });
        $coverage = new NzbArticleCoverage($nzb, new ReleaseDuplicateFinder);
        $incoming = simplexml_load_string('<nzb><file><segments><segment bytes="1" number="1">incoming@example.test</segment></segments></file></nzb>');
        $inventory = simplexml_load_string($retained);
        self::assertNotFalse($incoming);
        self::assertNotFalse($inventory);

        self::assertSame(["\0incoming@example.test" => true], $coverage->coverageIds($incoming));
        self::assertNull($coverage->coverageIds(simplexml_load_string('<nzb/>')));
        self::assertNull($coverage->findCoveredUpload(0, $fingerprint, $incoming, publishedOnly: true));
        // The caller's ID set decides coverage; the document only has to be present.
        self::assertSame('retained', $coverage->findCoveredUpload(0, $fingerprint, $incoming, publishedOnly: true,
            requiredIds: $coverage->coverageIds($inventory))?->guid);
        self::assertSame($coverage->findCoveredUpload(0, $fingerprint, $inventory, publishedOnly: true)?->id,
            $coverage->findCoveredUpload(0, $fingerprint, $inventory, publishedOnly: true, requiredIds: $coverage->coverageIds($inventory))?->id);
        self::assertNull($coverage->findCoveredUpload(0, $fingerprint, null, publishedOnly: true, requiredIds: $coverage->coverageIds($inventory)));
    }

    /** @return array<string, array{list<string>, bool, int, NzbImportStatus, bool, string}> */
    public static function articleSetCases(): array
    {
        $cases = [
            'ordered equivalent' => [['a2@x', 'a1@x'], true, 100, NzbImportStatus::Duplicate],
            'subset' => [['a1@x'], true, 100, NzbImportStatus::Duplicate],
            'superset adds new ID' => [['a1@x', 'a2@x', 'b3@x'], true, 100, NzbImportStatus::Inserted],
            'repeated reference' => [['a1@x', 'a2@x', '<a1@x>'], true, 100, NzbImportStatus::Duplicate],
            'case differs' => [['A1@x', 'a2@x'], true, 100, NzbImportStatus::Inserted],
            'unsupported incoming ID' => [['a1@x', 'bad id'], true, 100, NzbImportStatus::Inserted],
            'different articles' => [['b1@x', 'b2@x'], true, 100, NzbImportStatus::Inserted],
            'overlapping unequal set' => [['a1@x', 'b2@x'], true, 100, NzbImportStatus::Inserted],
            'legacy changed byte annotation' => [['a1@x', 'a2@x'], false, 150, NzbImportStatus::Duplicate],
        ];
        foreach (['missing', 'corrupt', 'truncated', 'concatenated', 'trailing', 'oversized', 'incomplete', 'nested', 'nested_prefixed', 'nested_default', 'invalid', 'foreign_container', 'compressed_oversized', 'budget', 'saturation', 'proof_before_saturation', 'guid_replacement', 'incoming_incomplete'] as $mode) {
            $cases[$mode] = [['a1@x', 'a2@x'], true, 100, NzbImportStatus::Inserted, false, $mode];
        }
        unset($cases['proof_before_saturation']);
        $cases['proof before saturation'] = [['a1@x', 'a2@x'], true, 100, NzbImportStatus::Duplicate, false, 'proof_before_saturation'];
        $cases['schema absent'] = [['a1@x'], false, 100, NzbImportStatus::Duplicate, false, 'schema_absent'];
        $cases['prefixed incoming'] = [['a1@x', 'a2@x'], true, 100, NzbImportStatus::Duplicate, false, 'prefixed_incoming'];
        $cases['prefixed stored'] = [['a1@x', 'a2@x'], true, 100, NzbImportStatus::Duplicate, false, 'prefixed_stored'];
        $cases['prefixed both'] = [['a1@x', 'a2@x'], true, 100, NzbImportStatus::Duplicate, false, 'prefixed_both'];
        $cases['prefixed subset'] = [['a1@x'], true, 100, NzbImportStatus::Duplicate, false, 'prefixed_subset'];
        $cases['prefixed adds new articles'] = [['a1@x', 'a2@x', 'b3@x'], true, 100, NzbImportStatus::Inserted, false, 'prefixed_added'];
        $result = [];
        foreach ($cases as $label => $case) {
            foreach ([false, true] as $enabled) {
                $result[$label.' metadata '.($enabled ? 'enabled' : 'disabled')] = [$case[0], $case[1], $case[2], $case[3], $enabled, $case[5] ?? 'valid'];
            }
        }

        return $result;
    }

    /** @param list<string> $ids */
    #[DataProvider('articleSetCases')]
    public function test_public_import_requires_actual_article_coverage(array $ids, bool $persisted, int $bytes, NzbImportStatus $expected, bool $enabled, string $mode): void
    {
        config(['release_verification.required' => false, 'logging.default' => 'stderr', 'nntmux.release_dedupe_enabled' => $enabled, 'nntmux.release_dedupe_lock_store' => 'array', 'nntmux.echocli' => false]);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            foreach (['guid', 'name', 'searchname', 'fromname', 'leftguid', 'postdate', 'adddate'] as $column) {
                $table->string($column)->default('');
            }
            $table->char('article_fingerprint', 40)->nullable();
            foreach (['predb_id', 'size', 'totalpart', 'groups_id', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized'] as $column) {
                $table->integer($column)->default(0);
            }
        });
        Schema::create('predb', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
            $table->string('filename')->default('');
        });
        (require database_path('migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        DB::table('predb')->insert(['id' => 42, 'title' => 'Same.Release-GRP']);
        $this->insertCategory(7010, 'Other', 1);
        $xml = static function (array $messageIds, int $size): string {
            $segments = '';
            foreach ($messageIds as $i => $id) {
                $segments .= '<segment bytes="'.$size.'" number="'.($i + 1).'">'.htmlspecialchars($id).'</segment>';
            }

            return '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head><meta type="category">7010</meta></head><file poster="poster" date="1" subject="Same.Release-GRP"><groups><group>alt.test</group></groups><segments>'.$segments.'</segments></file></nzb>';
        };
        $prefix = static function (string $contents): string {
            $contents = preg_replace('~(<\\/?)(nzb|head|meta|file|groups|group|segments|segment)(?=[\\s>/])~', '$1p:$2', $contents);

            return str_replace('xmlns="http://www.newzbin.com/DTD/2003/nzb"', 'xmlns:p="http://www.newzbin.com/DTD/2003/nzb"', $contents);
        };
        $original = $xml(['a1@x', 'a2@x'], 100);
        if (in_array($mode, ['prefixed_stored', 'prefixed_both'], true)) {
            $original = $prefix($original);
        }
        DB::table('releases')->insert(['guid' => 'original', 'name' => 'Same.Release-GRP', 'searchname' => 'Same.Release-GRP', 'predb_id' => 42, 'size' => 200, 'article_fingerprint' => $persisted ? NzbArticleFingerprint::fromContents($original) : null]);
        $directory = sys_get_temp_dir().'/article-review-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $storedXml = match ($mode) {
            'oversized', 'budget' => str_replace('</nzb>', '<!--'.str_repeat('x', 16_777_216).'--></nzb>', $original),
            'incomplete' => str_replace('</nzb>', '<file><segments /></file></nzb>', $original),
            'nested' => str_replace('a1@x', 'a1@x<hidden>other</hidden>', $original),
            'nested_prefixed' => str_replace('a1@x', 'a1@x<foreign:hidden xmlns:foreign="urn:foreign">other</foreign:hidden>', $original),
            'nested_default' => str_replace('a1@x', 'a1@x<hidden xmlns="urn:foreign">other</hidden>', $original),
            'invalid' => str_replace('a1@x', '', $original),
            'foreign_container' => str_replace('</file>', '<foreign:segments xmlns:foreign="urn:other"><foreign:segment>extra</foreign:segment></foreign:segments></file>', $original),
            default => $original,
        };
        $compressed = gzencode($storedXml);
        self::assertIsString($compressed);
        $compressed = match ($mode) {
            'compressed_oversized' => $compressed.str_repeat('x', 4_194_304),
            'corrupt' => substr($compressed, 0, -8).str_repeat('x', 8),
            'truncated' => substr($compressed, 0, -8),
            'concatenated' => $compressed.$compressed,
            'trailing' => $compressed.'garbage'.substr($compressed, -8),
            default => $compressed,
        };
        if ($mode !== 'missing') {
            file_put_contents($directory.'/original.nzb.gz', $compressed);
        }
        if ($mode === 'saturation') {
            file_put_contents($directory.'/original.nzb.gz', gzencode($xml(['other@x'], 100)));
        }
        if (in_array($mode, ['budget', 'saturation', 'proof_before_saturation'], true)) {
            $additional = $mode === 'budget' ? 2 : 128;
            for ($index = 1; $index <= $additional; $index++) {
                $guid = 'candidate-'.$index;
                DB::table('releases')->insert(['guid' => $guid, 'searchname' => 'Same.Release-GRP', 'predb_id' => 42, 'size' => 200, 'article_fingerprint' => NzbArticleFingerprint::fromContents($original)]);
                file_put_contents($directory.'/'.$guid.'.nzb.gz', $mode === 'budget' && $index === 1 ? $compressed : gzencode($index === $additional ? $original : $xml(['other@x'], 100)));
            }
        }
        $existingRows = DB::table('releases')->count();
        if ($mode === 'schema_absent') {
            Schema::table('releases', static fn (Blueprint $table) => $table->dropColumn('article_fingerprint'));
        }
        $incoming = $directory.'/Same.Release-GRP.nzb';
        $incomingXml = $xml($ids, $bytes);
        if (in_array($mode, ['prefixed_incoming', 'prefixed_both', 'prefixed_subset', 'prefixed_added'], true)) {
            $incomingXml = $prefix($incomingXml);
        }
        if ($mode === 'incoming_incomplete') {
            $incomingXml = str_replace('</nzb>', '<file poster="poster" date="1" subject="empty"><groups><group>alt.test</group></groups><segments /></file></nzb>', $incomingXml);
        }
        file_put_contents($incoming, $incomingXml);
        $nzb = $this->getStubBuilder(NzbService::class)->disableOriginalConstructor()->onlyMethods(['buildNzbPath', 'getNzbPath', 'nzbPath'])->getStub();
        $nzb->method('buildNzbPath')->willReturn($directory.DIRECTORY_SEPARATOR);
        $nzb->method('getNzbPath')->willReturnCallback(static fn (string $guid): string => $directory.'/'.$guid.'.nzb.gz');
        $nzb->method('nzbPath')->willReturnCallback(static function (string $guid) use ($directory, $mode): string|false {
            if ($mode === 'guid_replacement' && $guid === 'original') {
                DB::table('releases')->where('id', 1)->update(['guid' => 'ORIGINAL']);
            }

            return is_file($directory.'/'.$guid.'.nzb.gz') ? $directory.'/'.$guid.'.nzb.gz' : false;
        });
        $this->app->instance(NzbService::class, $nzb);
        Search::shouldReceive('matchPredbExact')->andReturn(['id' => 42, 'title' => 'Same.Release-GRP']);
        Search::shouldReceive('updateRelease')->andReturnNull();
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn(false);
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);
        $service = new class($blacklist) extends NzbImportService
        {
            public function __construct(BlacklistService $blacklist)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
        $statuses = [];
        try {
            $report = $service->beginImport([$incoming], useNzbName: true, resultCallback: static function (array $result) use (&$statuses): void {
                $statuses[] = $result['status'];
            });
            self::assertIsString($report);
            self::assertSame([$expected], $statuses);
            self::assertSame($existingRows + ($expected === NzbImportStatus::Inserted ? 1 : 0), DB::table('releases')->count());
            self::assertSame($existingRows + ($expected === NzbImportStatus::Inserted ? 1 : 0), DB::table('releases')->where('predb_id', 42)->count());
        } finally {
            foreach (glob($directory.'/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_bounded_nzb_read_accounts_for_exact_byte_limits_and_bad_framing(): void
    {
        $contents = '<nzb />';
        $compressed = gzencode($contents);
        self::assertIsString($compressed);
        $path = tempnam(sys_get_temp_dir(), 'bounded-nzb');
        self::assertIsString($path);
        $service = $this->getStubBuilder(NzbService::class)->disableOriginalConstructor()->onlyMethods(['nzbPath'])->getStub();
        $service->method('nzbPath')->willReturn($path);
        try {
            file_put_contents($path, $compressed);
            $used = 0;
            self::assertSame($contents, $service->readNzbContentsBounded('guid', strlen($compressed), strlen($contents), $used));
            self::assertSame(strlen($contents), $used);
            self::assertFalse($service->readNzbContentsBounded('guid', strlen($compressed) - 1, strlen($contents), $used));
            self::assertSame(strlen($contents), $used);
            self::assertFalse($service->readNzbContentsBounded('guid', strlen($compressed), strlen($contents) - 1, $used));
            self::assertSame(strlen($contents) - 1, $used);
            file_put_contents($path, $compressed.$compressed);
            self::assertFalse($service->readNzbContentsBounded('guid', strlen($compressed) * 2, strlen($contents) * 2, $used));
            self::assertSame(strlen($contents) * 2, $used);
        } finally {
            unlink($path);
        }
    }

    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-article-coverage-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        new PDO('sqlite:'.$this->databasePath);

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        Schema::create('categories', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
            $table->integer('status');
        });
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    private function insertCategory(int $id, string $title, int $status): void
    {
        DB::table('categories')->insert([
            'id' => $id,
            'title' => $title,
            'status' => $status,
        ]);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
