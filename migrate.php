<?php

declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';

$migrator = $app['migrator'];
$applied = $migrator->migrate();

if ($applied === []) {
    echo "No new migrations.\n";
    exit(0);
}

foreach ($applied as $version => $file) {
    echo sprintf("Applied migration %d: %s\n", $version, basename($file));
}
