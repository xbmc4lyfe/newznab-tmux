<?php

/*
|--------------------------------------------------------------------------
| Internal Routes
|--------------------------------------------------------------------------
|
| Endpoints for other NNTmux worker hosts on the private network. They carry
| no session, CSRF or API throttling; see AllowRemoteVerificationPublication.
|
*/

use App\Http\Controllers\ReleaseVerificationPublicationController;
use App\Http\Middleware\AllowRemoteVerificationPublication;

Route::post('release-verification/publish', ReleaseVerificationPublicationController::class)
    ->middleware(AllowRemoteVerificationPublication::class);
