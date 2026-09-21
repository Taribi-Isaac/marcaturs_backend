<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger as MonologLogger;

/**
 * Redacts credentials, tokens, and identity-sensitive keys from log context.
 *
 * Laravel 13 invokes log taps with {@see IlluminateLogger}. Accept both that
 * wrapper and a raw Monolog logger so channel configuration does not fall back
 * to the emergency logger (which always writes to laravel.log).
 */
final class ConfigureLogging
{
    public function __invoke(IlluminateLogger|MonologLogger $logger): void
    {
        $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $processor = new RedactSensitiveContextProcessor;

        foreach ($monolog->getHandlers() as $handler) {
            $handler->pushProcessor($processor);
        }

        $monolog->pushProcessor($processor);
    }
}
