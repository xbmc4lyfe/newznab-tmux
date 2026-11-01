<?php

use App\Providers\AdditionalProcessingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CategorizationServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ProcessingServiceProvider;
use App\Providers\SearchServiceProvider;
use App\Providers\TvProcessingServiceProvider;
use App\Providers\UserServiceProvider;

return [
    AdditionalProcessingServiceProvider::class,
    AppServiceProvider::class,
    CategorizationServiceProvider::class,
    HorizonServiceProvider::class,
    ProcessingServiceProvider::class,
    SearchServiceProvider::class,
    TvProcessingServiceProvider::class,
    UserServiceProvider::class,
];
