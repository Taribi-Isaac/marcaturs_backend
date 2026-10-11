<?php

namespace Tests\Feature\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the stubbed scripts/smoke.sh harness so CI proves the post-deploy smoke
 * checks fail closed (including a WebSocket URL without its public Reverb key).
 * The harness uses a curl stub in a temporary directory; it never contacts a host.
 */
class SmokeScriptTest extends TestCase
{
    public function test_smoke_script_harness_passes(): void
    {
        $harness = dirname(__DIR__, 3).'/scripts/tests/smoke-test.sh';

        $process = new Process(['bash', $harness]);
        $process->setTimeout(300);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "smoke-test.sh failed:\n".$process->getOutput().$process->getErrorOutput(),
        );
        $this->assertMatchesRegularExpression('/\d+ passed, 0 failed/', $process->getOutput());
    }
}
