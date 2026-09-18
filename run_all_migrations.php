<?php

require 'c:/Users/y/Documents/new/PatchOps-DevSecOps/vendor/autoload.php';
$app = require_once 'c:/Users/y/Documents/new/PatchOps-DevSecOps/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$migrations = [
    '2026_08_31_000001_create_incident_evidences_table' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_08_31_000001_create_incident_evidences_table.php',
    '2026_08_31_000002_create_sandboxes_table' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_08_31_000002_create_sandboxes_table.php',
    '2026_08_31_000003_add_correlation_ids_to_sandbox_tables' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_08_31_000003_add_correlation_ids_to_sandbox_tables.php',
    '2026_09_18_220000_create_quality_gate_persistence_tables' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_09_18_220000_create_quality_gate_persistence_tables.php',
    '2026_09_18_230000_create_approval_and_pr_tables' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_09_18_230000_create_approval_and_pr_tables.php',
    '2026_09_18_240000_create_remediation_runs_and_verification_checks_tables' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_09_18_240000_create_remediation_runs_and_verification_checks_tables.php',
    '2026_09_18_250000_create_observability_and_audit_tables' => 'c:/Users/y/Documents/new/PatchOps-DevSecOps/database/migrations/2026_09_18_250000_create_observability_and_audit_tables.php',
];

$maxBatch = DB::table('migrations')->max('batch') ?? 1;

foreach ($migrations as $name => $path) {
    $exists = DB::table('migrations')->where('migration', $name)->exists();
    if ($exists) {
        echo "Already migrated: {$name}\n";

        continue;
    }

    echo "Running migration: {$name}... ";
    try {
        $mig = require $path;
        $mig->up();
        $maxBatch++;
        DB::table('migrations')->insert([
            'migration' => $name,
            'batch' => $maxBatch,
        ]);
        echo "OK!\n";
    } catch (Throwable $e) {
        echo 'FAIL: '.$e->getMessage()."\n";
        echo 'LINE: '.$e->getFile().':'.$e->getLine()."\n";
        break;
    }
}

echo "\n--- Seeding Database ---\n";
try {
    $seeder = new DatabaseSeeder;
    $seeder->run();
    echo "DATABASE SEEDED SUCCESSFULLY!\n";
} catch (Throwable $e) {
    echo 'SEED FAIL: '.$e->getMessage()."\n";
    echo 'LINE: '.$e->getFile().':'.$e->getLine()."\n";
}
