<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = [$root . '/route.php'];
foreach (['app', 'tests', 'scripts'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . '/' . $directory,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $targets[] = $file->getPathname();
        }
    }
}

$failed = false;
foreach (array_unique($targets) as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    exec($command, $output, $status);
    echo implode(PHP_EOL, $output) . PHP_EOL;
    $output = [];
    $failed = $failed || $status !== 0;
}

exit($failed ? 1 : 0);
