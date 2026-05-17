<?php

namespace App\Console\Commands;

use Database\Seeders\WeddingDemoSeeder;
use Illuminate\Console\Command;

class SeedDemoCommand extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Seed demo data (WeddingDemoSeeder) without migrate:fresh';

    public function handle(): int
    {
        $this->info('Seeding demo data...');

        $seeder = new WeddingDemoSeeder();
        $seeder->setCommand($this);
        $seeder->run();

        $this->info('Demo seed complete.');

        return self::SUCCESS;
    }
}
