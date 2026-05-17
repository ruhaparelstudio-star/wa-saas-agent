<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTestingDisksWritable();
    }

    // Ensure storage/framework/testing/disks subdirs are writable.
    // When tests run inside the Docker container as root, subdirs (local/, r2/) can end up
    // owned by root with restricted permissions. @chmod fails for root-owned dirs.
    // If disks/ itself is writable (the common case), we rename root-owned subdirs out of
    // the way and recreate them — rename only needs write permission on the parent.
    // Backup dirs (-root-bak suffix) are skipped to prevent cascading renames.
    //
    // If disks/ itself is root-owned and unwritable, this method cannot self-heal.
    // Manual fix: docker exec <app-container> chmod -R 777 /var/www/html/storage/framework/testing/disks/
    private function ensureTestingDisksWritable(): void
    {
        $disksPath = storage_path('framework/testing/disks');

        if (!is_dir($disksPath) || !is_writable($disksPath)) {
            return;
        }

        foreach (glob($disksPath . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
            if (str_contains(basename($subdir), '-root-bak')) {
                continue;
            }

            if (!is_writable($subdir)) {
                if (rename($subdir, $subdir . '-root-bak')) {
                    mkdir($subdir, 0777, true);
                }
            }
        }
    }
}
