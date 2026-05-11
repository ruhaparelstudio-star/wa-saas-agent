<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make {name : Nama module (PascalCase)}';

    protected $description = 'Buat struktur folder module baru';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error("Nama module harus PascalCase. Contoh: AgentCore");
            return self::FAILURE;
        }

        $basePath = app_path("Modules/{$name}");

        if (File::isDirectory($basePath)) {
            $this->error("Module {$name} sudah ada di {$basePath}");
            return self::FAILURE;
        }

        $subdirs = [
            'Actions',
            'DTOs',
            'Enums',
            'Events',
            'Jobs',
            'Models',
            'Repositories',
            'Services',
            'Tests',
        ];

        foreach ($subdirs as $dir) {
            File::makeDirectory("{$basePath}/{$dir}", 0755, true);
            File::put("{$basePath}/{$dir}/.gitkeep", '');
        }

        File::put("{$basePath}/routes.php", $this->routeTemplate($name));
        File::put("{$basePath}/{$name}ServiceProvider.php", $this->providerTemplate($name));

        $this->info("Module {$name} created successfully.");
        $this->line("  Path: {$basePath}");

        return self::SUCCESS;
    }

    private function routeTemplate(string $name): string
    {
        return <<<PHP
<?php

use Illuminate\Support\Facades\Route;

// Routes for {$name} module

PHP;
    }

    private function providerTemplate(string $name): string
    {
        $namespace = "App\\Modules\\{$name}";

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Support\ServiceProvider;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}

PHP;
    }
}
