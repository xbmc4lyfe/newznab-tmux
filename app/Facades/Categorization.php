<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\Categorization\CategorizationPipeline;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array categorize(int|string $groupId, string $releaseName, ?string $poster = '', bool $debug = false)
 * @method static \Illuminate\Support\Collection getCategorizers()
 *
 * @see CategorizationPipeline
 */
class Categorization extends Facade // @phpstan-ignore missingType.iterableValue, missingType.generics
{
    protected static function getFacadeAccessor(): string
    {
        return CategorizationPipeline::class;
    }
}
