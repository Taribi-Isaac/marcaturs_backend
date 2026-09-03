<?php

namespace App\Providers;

use App\Notifications\Channels\IdempotentDatabaseChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the MarcatursHub notification infrastructure.
 *
 * Replaces the default database notification channel with
 * {@see IdempotentDatabaseChannel} to enforce idempotency.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DatabaseChannel::class, IdempotentDatabaseChannel::class);
    }
}
