<?php

namespace App\Console\Commands;

use Database\Seeders\DevelopmentScenarioSeeder;
use Illuminate\Console\Command;

class SeedDevelopmentScenarioCommand extends Command
{
    protected $signature = 'marcaturs:seed-demo
                            {--force : Allow staging when ALLOW_DEMO_SEED=true}';

    protected $description = 'Seed the deterministic development/UAT scenario (blocked in production)';

    public function handle(): int
    {
        $seeder = (new DevelopmentScenarioSeeder)->force((bool) $this->option('force'));
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
