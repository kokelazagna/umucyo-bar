<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/app',
    FilesystemIterator::SKIP_DOTS
)));
$files[] = new SplFileInfo($root . '/route.php');

function exact_relative_file_exists(string $base, string $relative): bool
{
    $current = $base;
    foreach (explode('/', str_replace('\\', '/', $relative)) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            $current = dirname($current);
            continue;
        }
        $matches = array_filter(scandir($current) ?: [], static fn (string $entry): bool => $entry === $part);
        if ($matches === []) {
            return false;
        }
        $current .= '/' . $part;
    }
    return is_file($current);
}

$errors = [];
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $content = file_get_contents($file->getPathname()) ?: '';
    $patterns = [
        ['/(?:require|require_once|include|include_once)\s+__DIR__\s*\.\s*[\'\"]([^\'\"]+\.php)[\'\"]/m', dirname($file->getPathname())],
        ['/(?:require|require_once|include|include_once)\s+APP_PATH\s*\.\s*[\'\"]([^\'\"]+\.php)[\'\"]/m', $root . '/app'],
        ['/(?:require|require_once|include|include_once)\s+BASE_PATH\s*\.\s*[\'\"]([^\'\"]+\.php)[\'\"]/m', $root],
    ];
    foreach ($patterns as [$pattern, $base]) {
        preg_match_all($pattern, $content, $matches);
        foreach ($matches[1] as $relative) {
            if (!exact_relative_file_exists($base, $relative)) {
                $errors[] = $file->getPathname() . ': path casing does not match: ' . $relative;
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "Case-sensitive import check passed.\n";
