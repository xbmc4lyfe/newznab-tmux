<?php

use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\FingerprintProviderBreaker;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\YencService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\CompactFingerprintCollectorTest as Fixture;
use Tests\Fixtures\ArchiveParsingBenchmarkFixtures;
use Tests\Fixtures\YencArticles;

// Run from the repository root after cargo build. Offline fixtures only; never accesses NNTP or DB.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'nntmux_nntp.server' => 'fixture.invalid', 'nntmux_nntp.port' => '563', 'nntmux_nntp.ssl' => true, 'nntmux_nntp.alternate_server' => '', 'nntmux.fingerprint_alternate_percent' => 0]);

function fixture($name, ...$args)
{
    return (new ReflectionMethod(Fixture::class, $name))->invoke(null, ...$args);
}
function normalize(&$r)
{
    unset($r['data']['collector']['engine']);
    foreach ($r['data']['providers'] as &$p) {
        unset($p['retrieval_ms']);
    }
    foreach ($r['data']['articles'] as &$a) {
        foreach ($a['provider_results'] as &$p) {
            unset($p['ms']);
        }
    }
}
function difference($a, $b, $path = '')
{
    if (is_array($a) && is_array($b)) {
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
            if (! array_key_exists($k, $a) || ! array_key_exists($k, $b)) {
                return $path.'.'.$k.' missing';
            }
            $d = difference($a[$k], $b[$k], $path.'.'.$k);
            if ($d !== null) {
                return $d;
            }
        }

        return null;
    }

    return $a === $b ? null : $path.' '.json_encode($a).' != '.json_encode($b);
}
$cases = [];
foreach ([[3, 1, false], [3, 2, false], [120, 5, true], [300, 5, true]] as [$files,$segments,$par]) {
    $cases['valid-'.$files.'-'.$segments] = [fixture('nzb', $files, $segments, $par), fn ($id) => fixture('segmentArticle', $id, $par && preg_match('/^f[01]-/', $id) ? 1 : $segments)];
}
foreach (Fixture::unverifiableSinglePartBodies() as $name => [$corrupt]) {
    $cases['single-'.$name] = [fixture('nzb', 3, 1, false), fn ($id) => str_starts_with($id, 'f1-') ? ['body' => $corrupt(fixture('article', $id)['body'])] + fixture('article', $id) : fixture('article', $id)];
}
foreach (Fixture::invalidMultipartBodies() as $name => [$corrupt]) {
    $cases['multipart-'.$name] = [fixture('nzb', 3, 2, false), fn ($id) => $id === 'f1-s1@fixture' ? ['body' => $corrupt(fixture('segmentArticle', $id, 2)['body'])] + fixture('segmentArticle', $id, 2) : fixture('segmentArticle', $id, 2)];
}
foreach ([1, 2] as $segments) {
    foreach (['line', 'size', 'name'] as $field) {
        $cases['missing-ybegin-'.$field.'-'.$segments] = [fixture('nzb', 3, $segments, false), function ($id) use ($segments, $field) {
            $a = fixture('segmentArticle', $id, $segments);
            if ($id === 'f1-s1@fixture') {
                $a['body'] = preg_replace_callback('/^=ybegin [^\r\n]*/m', static fn ($m) => preg_replace('/(?:^| )'.$field.'=[^ ]*/', '', $m[0]), $a['body']);
            }

            return $a;
        }];
    }
}
$cases['empty'] = [fixture('nzb', 3, 1, false), fn ($id) => ['body' => YencArticles::article('')] + fixture('article', $id)];
$cases['par2'] = [fixture('par2Nzb', ['offline-0000.bin']), fn ($id) => ['body' => YencArticles::article($id === 'index@fixture' ? ArchiveParsingBenchmarkFixtures::par2(1) : 'payload')] + fixture('article', $id)];
foreach (Fixture::invalidEmptyFileHeaders() as $name => [$header]) {
    $cases['empty-'.$name] = [fixture('nzb', 3, 1, false), fn ($id) => ['body' => str_replace('line=128', $header, YencArticles::article(''))] + fixture('article', $id)];
}
foreach (Fixture::unprovenTrailerlessParts() as $name => [$corrupt]) {
    $cases['trailerless-'.$name] = [fixture('nzb', 3, 2, false), function ($id) use ($corrupt) {
        $a = fixture('segmentArticle', $id, 2);
        $a['body'] = preg_replace('/(=yend size=16) part=\\d+/', '$1', $a['body']);
        if ($id === 'f1-s1@fixture') {
            $a['body'] = $corrupt($a['body']);
        }

        return $a;
    }];
}
$cases['trailerless-valid'] = [fixture('nzb', 3, 2, false), function ($id) {
    $a = fixture('segmentArticle', $id, 2);
    $a['body'] = preg_replace('/(=yend size=16) part=\\d+/', '$1', $a['body']);

    return $a;
}];
foreach (['missing-header', 'wrong-header', 'wrong-response', 'case-sensitive-id'] as $name) {
    $cases[$name] = [fixture('nzb', 3, 1, false), function ($id) use ($name) {
        $a = fixture('article', $id);
        if ($id === 'f1-s1@fixture') {
            if ($name === 'missing-header') {
                array_shift($a['headers']);
            }if ($name === 'wrong-header') {
                $a['headers'][0] = 'Message-ID: <wrong@fixture>';
            }if ($name === 'case-sensitive-id') {
                $a['headers'][0] = 'Message-ID: <'.strtoupper($id).'>';
            }if ($name === 'wrong-response') {
                $a['article_response']['response_text'] = '220 0 <wrong@fixture> follows';
            }
        }

        return $a;
    }];
}
$cases['duplicate-message-id'] = [fixture('nzb', 3, 1, false), function ($id) {
    $a = fixture('article', $id);
    if ($id === 'f1-s1@fixture') {
        $a['headers'][] = 'Message-ID: <'.$id.'>';
    }

    return $a;
}];
$cases['missing-article'] = [fixture('nzb', 3, 1, false), function ($id) {
    if ($id === 'f1-s1@fixture') {
        throw new NntpArticleMissingException('fixture missing');
    }

    return fixture('article', $id);
}];
$cases['alternate-valid'] = [fixture('nzb', 3, 1, false), fn ($id) => fixture('article', $id), 'valid'];
$cases['alternate-unavailable'] = [fixture('nzb', 3, 1, false), fn ($id) => fixture('article', $id), 'unavailable'];
$cases['alternate-skipped'] = [fixture('nzb', 3, 1, false), fn ($id) => fixture('article', $id), 'skipped'];
$cases['unicode-and-long-names'] = [str_replace(['poster@example', 'alt.binaries.test', 'movie.part01.rar'], [str_repeat('投稿者', 60), str_repeat('群', 60), str_repeat('影片', 60).'.rar'], fixture('nzb', 3, 1, false)), fn ($id) => fixture('article', $id)];
$cases['ascii-extension-boundary'] = [str_replace('movie.part01.rar', 'file.raré', fixture('nzb', 3, 1, false)), fn ($id) => fixture('article', $id)];
foreach (['negative-bytes', 'missing-bytes', 'duplicate-numbers', 'duplicate-message-ids', 'nonsequential-numbers', 'empty-message-id'] as $name) {
    $xml = fixture('nzb', 3, 2, false);
    $xml = match ($name) {
        'negative-bytes' => preg_replace('/bytes="[0-9]+"/', 'bytes="-1"', $xml, 1),
        'missing-bytes' => preg_replace('/bytes="[0-9]+" /', '', $xml, 1),
        'duplicate-numbers' => str_replace('number="2">f0-s2', 'number="1">f0-s2', $xml),
        'duplicate-message-ids' => str_replace('f0-s2@fixture', 'f0-s1@fixture', $xml),
        'nonsequential-numbers' => str_replace('number="2">f0-s2', 'number="3">f0-s2', $xml),
        'empty-message-id' => str_replace('f0-s1@fixture', '', $xml),
    };
    $cases['manifest-'.$name] = [$xml, fn ($id) => fixture('segmentArticle', $id, 2)];
}
$failed = [];
foreach ($cases as $name => $case) {
    [$xml,$article] = $case;
    $alternate = $case[2] ?? null;
    Cache::flush();
    config(['nntmux_nntp.alternate_server' => $alternate ? 'fixture.alt.invalid' : '', 'nntmux_nntp.alternate_server_port' => '563', 'nntmux_nntp.alternate_server_ssl' => true, 'nntmux.fingerprint_alternate_percent' => $alternate ? 100 : 0]);
    $analyzer = new NzbFingerprintAnalyzer(new YencService);
    $files = $analyzer->analyzeManifest($xml)['files'];
    $samples = CompactFingerprintCollector::selectSamples($files);
    $entries = [];
    $phparticles = [];
    foreach ($samples as $s) {
        try {
            $a = $article($s['messageid']);
            $phparticles[$s['messageid']] = $a;
            $entries[$s['messageid']] = ['Ok' => ['headers' => $a['headers'], 'body' => array_values(unpack('C*', $a['body'])), 'response' => $a['article_response']['response_text'], 'ms' => 0]];
        } catch (NntpArticleMissingException$e) {
            $phparticles[$s['messageid']] = $e;
            $entries[$s['messageid']] = ['Err' => 'NntpArticleMissingException'];
        }
    }
    $nntp = Mockery::mock(NNTPService::class);
    $nntp->shouldReceive('getFingerprintServerProfile')->andReturnUsing(function ($isAlternate) use ($alternate) {
        if ($isAlternate && $alternate === 'unavailable') {
            throw new NntpProviderUnavailableException('fixture unavailable');
        }

        return ['capabilities_response_code' => 101, 'capabilities_raw' => [], 'overview_format_response_code' => null, 'implementation' => null];
    });
    $nntp->shouldReceive('getFingerprintFullArticleByMessageID')->andReturnUsing(function ($id, $body, $header, $isAlternate) use ($alternate, $phparticles) {
        if ($isAlternate && $alternate === 'unavailable') {
            throw new NntpProviderUnavailableException('fixture unavailable');
        }$value = $phparticles[$id];
        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value;
    });
    $app->instance(NNTPService::class, $nntp);
    if ($alternate === 'skipped') {
        (new FingerprintProviderBreaker)->recordUnavailable(['name' => 'fixture.alt.invalid', 'port' => '563', 'tls' => true]);
    }
    $collector = new CompactFingerprintCollector(Mockery::mock(NzbService::class), $analyzer, new Par2MetadataParser);
    $php = $collector->collectFromNzb($xml, 7);
    $providers = [['account' => 'primary', 'host' => 'fixture.invalid', 'port' => '563', 'tls' => true, 'implementation' => null, 'profile' => 'ok', 'articles' => $entries]];
    if ($alternate) {
        $altEntries = $entries;
        if (in_array($alternate, ['unavailable', 'skipped'])) {
            $altEntries = array_fill_keys(array_keys($entries), ['Err' => 'NntpProviderUnavailableException']);
        }
        $providers[] = ['account' => 'alternate', 'host' => 'fixture.alt.invalid', 'port' => '563', 'tls' => true, 'implementation' => null, 'profile' => in_array($alternate, ['unavailable', 'skipped']) ? 'NntpProviderUnavailableException' : 'ok', 'skipped_reason' => $alternate === 'skipped' ? 'recent_provider_failure' : null, 'articles' => $altEntries];
    }
    $input = ['nzb' => $xml, 'providers' => $providers];
    $p = proc_open([getcwd().'/rust/fingerprint-worker/target/debug/nntmux-fingerprint-worker', '--analyze-stdin'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($p);
    if ($exit !== 0) {
        $failed[$name] = $error;

        continue;
    }
    $rust = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    normalize($php);
    normalize($rust);
    $d = difference($php, $rust);
    if ($d !== null) {
        $failed[$name] = $d;
    }
    Mockery::close();
}
echo json_encode(['cases' => count($cases), 'passed' => count($cases) - count($failed), 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failed ? 1 : 0);
