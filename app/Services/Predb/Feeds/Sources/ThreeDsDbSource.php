<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

final class ThreeDsDbSource extends CompleteXmlCatalogueSource
{
    public function key(): string
    {
        return '3dsdb';
    }

    protected function sourceLabel(): string
    {
        return '3dsdb.com';
    }
}
