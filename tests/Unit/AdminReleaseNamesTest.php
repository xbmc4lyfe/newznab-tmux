<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AdminReleaseNames;
use DOMDocument;
use Tests\TestCase;

class AdminReleaseNamesTest extends TestCase
{
    public function test_titles_link_to_public_details_and_keep_distinct_original_names(): void
    {
        $releases = [
            (object) ['guid' => 'abc123', 'searchname' => 'Recovered.Movie', 'name' => '[1/2] - "original.rar" yEnc'],
            (object) ['guid' => 'def456', 'searchname' => '[1/2] "second.enc"', 'name' => null],
        ];
        $html = (new AdminReleaseNames)->render($releases, false);
        $this->assertCount(2, $html);
        $document = new DOMDocument;
        $document->loadHTML('<html><body>'.$html[0].$html[1].'</body></html>');
        $links = $document->getElementsByTagName('a');
        $this->assertSame(route('details', ['guid' => 'abc123']), $links[0]->getAttribute('href'));
        $this->assertSame('Recovered.Movie', $links[0]->textContent);
        $this->assertSame('original.rar', $document->getElementsByTagName('div')[0]->textContent);
        $this->assertSame(route('details', ['guid' => 'def456']), $links[1]->getAttribute('href'));
        $this->assertSame('second.enc', $links[1]->textContent);
        $this->assertSame('[1/2] - "original.rar" yEnc', $releases[0]->name);
    }

    public function test_untrusted_names_cannot_add_elements_or_attributes(): void
    {
        $html = (new AdminReleaseNames)->render([(object) [
            'guid' => 'abc123',
            'searchname' => '" onmouseover="alert(1)"><script>alert(2)</script>&',
            'name' => '<img src=x onerror=alert(3)>',
        ]], false)[0];
        $document = new DOMDocument;
        $document->loadHTML('<html><body>'.$html.'</body></html>');
        $this->assertCount(0, $document->getElementsByTagName('script'));
        $this->assertCount(0, $document->getElementsByTagName('img'));
        $link = $document->getElementsByTagName('a')[0];
        $this->assertFalse($link->hasAttribute('onmouseover'));
        $this->assertSame('" onmouseover="alert(1)"><script>alert(2)</script>&', $link->textContent);
    }

    public function test_empty_batches_and_unavailable_native_scope_keep_functional_links(): void
    {
        $renderer = new AdminReleaseNames;
        $this->assertSame([], $renderer->render([]));
        $rows = [(object) ['guid' => 'abc123', 'searchname' => 'Movie', 'name' => null]];
        $this->assertSame((string) $renderer->render($rows, false)[0], (string) $renderer->render($rows)[0]);
    }
}
