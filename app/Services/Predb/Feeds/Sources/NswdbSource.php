<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

final class NswdbSource extends CompleteXmlCatalogueSource
{
    public function key(): string
    {
        return 'nswdb';
    }

    protected function sourceLabel(): string
    {
        return 'nswdb.com';
    }
}
