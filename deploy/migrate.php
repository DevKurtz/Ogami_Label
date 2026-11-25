<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(ConsoleKernel::class);
$database = $app->make('db')->connection();
$migrationLockId = 73190426011;

// Prevent concurrent Vercel cold starts from applying the same migration together.
$database->select('SELECT pg_advisory_lock(?)', [$migrationLockId]);

try {
    $status = $kernel->call('migrate', ['--force' => true]);
    fwrite(STDOUT, $kernel->output());
} finally {
    $database->select('SELECT pg_advisory_unlock(?)', [$migrationLockId]);
}

exit($status);
