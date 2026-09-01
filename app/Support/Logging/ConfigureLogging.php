<?php

namespace App\Support\Logging;

use Monolog\Logger;

final class ConfigureLogging
{
    public function __invoke(Logger $logger): void
    {
        $processor = new RedactSensitiveContextProcessor;

        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor($processor);
        }

        $logger->pushProcessor($processor);
    }
}
