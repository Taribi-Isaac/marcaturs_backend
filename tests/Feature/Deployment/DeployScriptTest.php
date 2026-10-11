<?php

namespace Tests\Feature\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the stubbed deploy.sh / deploy-ci.sh harness so CI exercises release,
 * rollback and workflow recovery logic. The harness only touches a temporary
 * directory and stub binaries; it never contacts a server.
 */
class DeployScriptTest extends TestCase
{
    public function test_deployment_recovery_harness_passes(): void
    {
        $harness = dirname(__DIR__, 3).'/infra/lightsail/tests/deploy-test.sh';

        $process = new Process(['bash', $harness]);
        $process->setTimeout(300);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "deploy-test.sh failed:\n".$process->getOutput().$process->getErrorOutput(),
        );
        $this->assertMatchesRegularExpression('/\d+ passed, 0 failed/', $process->getOutput());
    }
}
