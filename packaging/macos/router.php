<?php

declare(strict_types=1);

// Router for "php -S": static files from public/ as they are, everything else through the Symfony front controller.

$root = realpath((string) $_SERVER['DOCUMENT_ROOT']);
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $root === false ? false : realpath($root . $path);

if (
    $file !== false
    && str_starts_with($file, $root . '/')
    && is_file($file)
    && !str_ends_with($file, '.php')
) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

require $root . '/index.php';
