<?php

declare(strict_types=1);

namespace Tests\Unit\Nzb;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Nzb\NzbImportService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\NzbManifestReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class NzbImportNamespaceContractTest extends TestCase
{
    public function test_scan_and_real_verification_admission_preserve_metadata_identity_and_category_across_namespaces(): void
    {
        $originalContainer = Container::getInstance();
        $originalResolver = Model::getConnectionResolver();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['release_verification' => ['required' => true]]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->bootEloquent();
        $capsule->getConnection()->getSchemaBuilder()->create('categories', function ($table): void {
            $table->integer('id')->primary();
            $table->string('title');
            $table->integer('status');
        });
        $capsule->getConnection()->table('categories')->insert([
            ['id' => 3010, 'title' => 'Contract Category', 'status' => 1],
            ['id' => 3020, 'title' => 'Extension Category', 'status' => 1],
        ]);
        $queue = new class
        {
            /** @var list<array<string, mixed>> */
            private array $items = [];

            /** @param array<string, mixed> $manifest */
            public function enqueue(array $manifest): void
            {
                $this->items[] = $manifest;
            }

            public function clear(): void
            {
                $this->items = [];
            }

            /** @return list<array<string, mixed>> */
            public function manifests(): array
            {
                return $this->items;
            }
        };
        $container->instance(CandidateRepository::class, $queue);
        $container->bind(NzbManifestReader::class, fn () => new NzbManifestReader);
        $service = new class extends NzbImportService
        {
            /** @var array<string, mixed> */
            public array $scanned = [];

            public function __construct()
            {
                $this->allGroups = ['alt.test.contract' => 73];
                $this->blacklistService = new class extends BlacklistService
                {
                    public function isBlackListed(array $msg, string $groupName): bool
                    {
                        return false;
                    }

                    public function getAndClearIdsToUpdate(): array
                    {
                        return [];
                    }

                    public function updateBlacklistUsage(array $ids): void {}
                };
            }

            public function scan(string $text): NzbImportStatus
            {
                $xml = simplexml_load_string($text);

                return $this->scanNZBFile($xml);
            }

            protected function insertNZB(mixed $details): NzbImportStatus
            {
                $this->scanned = $details;

                return parent::insertNZB($details);
            }

            protected function echoOut(string $message): void {}
        };
        try {
            $baseline = null;
            foreach ($this->fixtures() as $kind => $xml) {
                $queue->clear();
                self::assertSame(NzbImportStatus::Queued, $service->scan($xml), $kind);
                $manifests = $queue->manifests();
                self::assertCount(1, $manifests, $kind);
                self::assertArrayHasKey(0, $manifests, $kind);
                $details = $service->scanned;
                $manifest = $manifests[0];
                self::assertSame('Contract Sample yEnc (1/1)', $details['subject'], $kind);
                self::assertSame('contract@example.invalid', $details['from'], $kind);
                self::assertSame(date('Y-m-d H:i:s', 946684800), $details['postDate'], $kind);
                self::assertSame(73, $details['groups_id'], $kind);
                self::assertSame(100, $details['totalSize'], $kind);
                self::assertSame(1, $details['totalFiles'], $kind);
                self::assertSame(3010, $details['nzbCategoryId'], $kind);
                self::assertSame(NzbArticleFingerprint::fromMessageIds(['contract-1@example.invalid']), $details['articleFingerprint'], $kind);
                self::assertSame($details['from'], $manifest['fromname'], $kind);
                self::assertSame($details['postDate'], $manifest['date'], $kind);
                self::assertSame(73, $manifest['groups_id'], $kind);
                self::assertSame(3010, $manifest['categories_id'], $kind);
                self::assertSame(['alt.test.contract'], $manifest['groups'], $kind);
                self::assertSame([['number' => 1, 'bytes' => 100, 'messageid' => 'contract-1@example.invalid']], $manifest['files'][0]['segments'], $kind);
                unset($details['verificationXml'], $details['articleXml']);
                $result = [$details, $manifest];
                $baseline ??= $result;
                self::assertSame($baseline, $result, $kind);
            }
        } finally {
            Container::setInstance($originalContainer);
            if ($originalResolver === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($originalResolver);
            }
            $capsule->getConnection()->disconnect();
        }
    }

    public function test_fingerprinting_rejects_foreign_namespace_and_wrong_root(): void
    {
        foreach (['<nzb xmlns="urn:contract:extension">', '<extension>'] as $root) {
            $end = str_starts_with($root, '<nzb') ? '</nzb>' : '</extension>';
            self::assertNull(NzbArticleFingerprint::fromContents($root.'<file><segments><segment>contract-1@example.invalid</segment></segments></file>'.$end));
        }
    }

    public function test_prefixed_scans_preserve_blacklist_and_missing_group_rejection(): void
    {
        foreach ([true, false] as $hasCanonicalGroup) {
            $blacklist = $this->createMock(BlacklistService::class);
            $blacklist->expects(self::once())->method('getAndClearIdsToUpdate')->willReturn([]);
            $blacklist->expects(self::once())->method('updateBlacklistUsage')->with([]);
            if ($hasCanonicalGroup) {
                $blacklist->expects(self::once())->method('isBlackListed')->with(
                    ['Subject' => 'Contract Sample', 'From' => 'contract@example.invalid', 'Message-ID' => ''],
                    'alt.test.contract'
                )->willReturn(true);
            } else {
                $blacklist->expects(self::never())->method('isBlackListed');
            }
            $service = new class($blacklist) extends NzbImportService
            {
                public function __construct(BlacklistService $blacklist)
                {
                    $this->allGroups = ['alt.test.contract' => 73];
                    $this->blacklistService = $blacklist;
                }

                public function scan(\SimpleXMLElement $xml): NzbImportStatus
                {
                    return $this->scanNZBFile($xml);
                }

                protected function insertNZB(mixed $details): NzbImportStatus
                {
                    throw new \LogicException('Rejected imports must not reach admission.');
                }

                protected function echoOut(string $message): void {}
            };
            $groups = '<e:groups><e:group>alt.extension</e:group></e:groups>';
            if ($hasCanonicalGroup) {
                $groups .= '<n:groups><n:group>alt.test.contract</n:group></n:groups>';
            }
            $xml = simplexml_load_string('<n:nzb xmlns:n="http://www.newzbin.com/DTD/2003/nzb" xmlns:e="urn:extension"><n:file subject="Contract Sample" poster="contract@example.invalid" date="946684800">'.$groups.'<n:segments><n:segment bytes="100" number="1">contract@example.invalid</n:segment></n:segments></n:file></n:nzb>');
            self::assertNotFalse($xml);
            self::assertSame($hasCanonicalGroup ? NzbImportStatus::Blacklisted : NzbImportStatus::NoGroup, $service->scan($xml));
        }
    }

    /** @return array<string, string> */
    private function fixtures(): array
    {
        $uri = 'http://www.newzbin.com/DTD/2003/nzb';
        $fixtures = [];
        foreach (['legacy', 'default', 'prefixed', 'aliased'] as $kind) {
            $p = in_array($kind, ['prefixed', 'aliased']) ? 'n:' : '';
            $decl = $kind === 'legacy' ? '' : ($p ? ' xmlns:n="'.$uri.'"' : ' xmlns="'.$uri.'"');
            $q = $kind === 'aliased' ? 'x:' : $p;
            $decl .= $kind === 'aliased' ? ' xmlns:x="'.$uri.'"' : '';
            $fixtures[$kind] = '<'.$p.'nzb'.$decl.'><'.$p.'file subject="Contract Sample yEnc (1/1)" poster="contract@example.invalid" date="946684800"><'.$q.'groups><'.$q.'group>alt.test.contract</'.$q.'group></'.$q.'groups><'.$q.'segments><'.$q.'segment bytes="100" number="1">contract-1@example.invalid</'.$q.'segment></'.$q.'segments></'.$p.'file></'.$p.'nzb>';
        }
        $base = $fixtures['prefixed'];
        $fixtures['prefixed_foreign'] = str_replace(['xmlns:n="'.$uri.'"', '<n:groups>', '<n:segments>', '</n:nzb>'], ['xmlns:n="'.$uri.'" xmlns:e="urn:contract:extension"', '<e:groups><e:group>alt.extension</e:group></e:groups><n:groups><e:group>alt.extension</e:group>', '<e:segments><e:segment bytes="900" number="2">extension@example.invalid</e:segment></e:segments><n:segments><e:segment bytes="900" number="2">extension@example.invalid</e:segment>', '<e:file subject="Extension"><e:groups/><e:segments/></e:file></n:nzb>'], $base);
        $fixtures['prefixed_second_containers'] = str_replace(['</n:groups>', '</n:segments>'], ['</n:groups><n:groups><n:group>alt.extension</n:group></n:groups>', '</n:segments><n:segments><n:segment bytes="900" number="2">extension@example.invalid</n:segment></n:segments>'], $base);
        $fixtures['legacy_foreign_default'] = str_replace(['<groups>', '<segments>', '</nzb>'], ['<groups><group xmlns="urn:contract:extension">alt.extension</group>', '<segments><segment xmlns="urn:contract:extension" bytes="900" number="2">extension@example.invalid</segment>', '<file xmlns="urn:contract:extension" subject="Extension"><groups/><segments/></file></nzb>'], $fixtures['legacy']);
        $fixtures['default_reset'] = str_replace(['<groups>', '<segments>', '</nzb>'], ['<groups><group xmlns="">alt.extension</group>', '<segments><segment xmlns="" bytes="900" number="2">extension@example.invalid</segment>', '<file xmlns="" subject="Extension"><groups/><segments/></file></nzb>'], $fixtures['default']);
        $fixtures['legacy_second_containers'] = str_replace(['</groups>', '</segments>'], ['</groups><groups><group>alt.extension</group></groups>', '</segments><segments><segment bytes="900" number="2">extension@example.invalid</segment></segments>'], $fixtures['legacy']);
        foreach ($fixtures as $kind => &$xml) {
            $p = str_starts_with($kind, 'prefixed') || $kind === 'aliased' ? 'n:' : '';
            $head = '<'.$p.'head><'.$p.'meta type="category">3010</'.$p.'meta></'.$p.'head>';
            if ($kind === 'prefixed_foreign') {
                $head = '<e:head><e:meta type="category">3020</e:meta></e:head><n:head><e:meta type="category">3020</e:meta><n:meta type="category">3010</n:meta></n:head><n:head><n:meta type="category">3020</n:meta></n:head>';
            }
            $rootEnd = strpos($xml, '>') + 1;
            $xml = substr($xml, 0, $rootEnd).$head.substr($xml, $rootEnd);
        }

        return $fixtures;
    }
}
