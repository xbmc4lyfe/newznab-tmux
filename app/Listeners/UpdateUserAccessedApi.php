<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserAccessedApi;
use Illuminate\Support\Facades\DB;

class UpdateUserAccessedApi
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserAccessedApi $event): void
    {
        $updateData = ['apiaccess' => now()];

        if ($event->ip !== null) {
            $updateData['host'] = $event->ip;
        }

        DB::table('users')
            ->where('id', $event->user->id)
            ->update($updateData);
    }
}
