<?php

declare(strict_types=1);

/**
 * Copies Homebrew PHP and PostgreSQL into a self-contained runtime folder:
 *
 *   runtime/php/bin/php
 *   runtime/pgsql/{bin,share,lib}
 *   runtime/lib/*.dylib   every non-system library they need, rewritten to @loader_path
 *
 * Usage: php bundle-runtime.php <runtime-dir> [<homebrew-prefix>]
 */

const SYSTEM_PREFIXES = ['/usr/lib/', '/System/'];
const PG_BINARIES     = ['postgres', 'initdb', 'pg_ctl', 'pg_dump'];

$runtime = $argv[1] ?? null;
$brew    = rtrim($argv[2] ?? '/opt/homebrew', '/');

if ($runtime === null) {
    fwrite(STDERR, "Usage: php bundle-runtime.php <runtime-dir> [<homebrew-prefix>]\n");
    exit(1);
}

$php = realpath($brew . '/opt/php/bin/php');
$pg  = realpath($brew . '/opt/postgresql@16');

if ($php === false || $pg === false) {
    fwrite(STDERR, "Install php and postgresql@16 with Homebrew first.\n");
    exit(1);
}

run(['rm', '-rf', $runtime]);
mkdir($runtime . '/php/bin', 0o755, true);
mkdir($runtime . '/pgsql/bin', 0o755, true);
mkdir($runtime . '/lib', 0o755, true);

copyFile($php, $runtime . '/php/bin/php');

foreach (PG_BINARIES as $binary) {
    copyFile($pg . '/bin/' . $binary, $runtime . '/pgsql/bin/' . $binary);
}

run(['cp', '-RL', trim(capture([$pg . '/bin/pg_config', '--sharedir'])), $runtime . '/pgsql/share']);
run(['cp', '-RL', trim(capture([$pg . '/bin/pg_config', '--pkglibdir'])), $runtime . '/pgsql/lib']);
run(['rm', '-rf', $runtime . '/pgsql/lib/pgxs']);
run(['chmod', '-R', 'u+w', $runtime]);

$certificates = $brew . '/etc/ca-certificates/cert.pem';

if (is_file($certificates)) {
    copyFile($certificates, $runtime . '/cert.pem');
}

$queue = array_merge(
    [$runtime . '/php/bin/php'],
    array_map(static fn (string $binary): string => $runtime . '/pgsql/bin/' . $binary, PG_BINARIES),
    glob($runtime . '/pgsql/lib/*.{so,dylib}', GLOB_BRACE) ?: [],
);
$origins = array_fill_keys($queue, null);
$origins[$runtime . '/php/bin/php'] = $php;

foreach (PG_BINARIES as $binary) {
    $origins[$runtime . '/pgsql/bin/' . $binary] = realpath($pg . '/bin/' . $binary);
}

foreach (glob($runtime . '/pgsql/lib/*.{so,dylib}', GLOB_BRACE) ?: [] as $module) {
    $origins[$module] = realpath(trim(capture([$pg . '/bin/pg_config', '--pkglibdir'])) . '/' . basename($module));
}

$bundled = [];

while ($queue !== []) {
    $file   = array_shift($queue);
    $origin = $origins[$file] ?? $file;

    foreach (dependencies($file) as $dependency) {
        $resolved = resolve($dependency, (string) $origin);

        if ($resolved === null) {
            continue;
        }

        $name   = basename($dependency);
        $target = $runtime . '/lib/' . $name;

        if (!isset($bundled[$name])) {
            copyFile($resolved, $target);
            run(['install_name_tool', '-id', '@rpath/' . $name, $target]);
            $bundled[$name]   = true;
            $origins[$target] = $resolved;
            $queue[]          = $target;
        }

        run([
            'install_name_tool',
            '-change',
            $dependency,
            '@loader_path/' . relativePath(dirname($file), $runtime . '/lib') . $name,
            $file,
        ]);
    }
}

$signed = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS)) as $item) {
    if ($item->isFile() && isMachO($item->getPathname())) {
        run(['codesign', '--force', '--sign', '-', $item->getPathname()]);
        ++$signed;
    }
}

printf("Runtime ready: %d libraries bundled, %d binaries signed.\n", count($bundled), $signed);

/**
 * @return list<string> non-system load commands of a Mach-O file
 */
function dependencies(string $file): array
{
    $lines = array_slice(explode("\n", trim(capture(['otool', '-L', $file]))), 1);
    $deps  = [];

    foreach ($lines as $line) {
        $path = trim(explode(' (', trim($line))[0]);

        if ($path === '' || isSystem($path)) {
            continue;
        }

        $deps[] = $path;
    }

    return $deps;
}

function resolve(string $dependency, string $origin): ?string
{
    if (str_starts_with($dependency, '@rpath/')) {
        foreach (rpaths($origin) as $rpath) {
            $candidate = realpath(str_replace('@loader_path', dirname($origin), $rpath) . '/' . substr($dependency, 7));

            if ($candidate !== false) {
                return $candidate;
            }
        }

        $sibling = realpath(dirname($origin) . '/' . substr($dependency, 7));

        return $sibling === false ? null : $sibling;
    }

    if (str_starts_with($dependency, '@loader_path/')) {
        $sibling = realpath(dirname($origin) . '/' . substr($dependency, 13));

        return $sibling === false ? null : $sibling;
    }

    $real = realpath($dependency);

    return $real === false || isSystem($real) ? null : $real;
}

/**
 * @return list<string>
 */
function rpaths(string $file): array
{
    preg_match_all('/cmd LC_RPATH\n\s+cmdsize \d+\n\s+path (\S+)/', capture(['otool', '-l', $file]), $matches);

    return $matches[1];
}

function relativePath(string $from, string $to): string
{
    $from   = explode('/', trim((string) realpath($from), '/'));
    $to     = explode('/', trim((string) realpath($to), '/'));
    $common = 0;

    while (isset($from[$common], $to[$common]) && $from[$common] === $to[$common]) {
        ++$common;
    }

    $up   = str_repeat('../', count($from) - $common);
    $down = implode('/', array_slice($to, $common));

    return $up . ($down === '' ? '' : $down . '/');
}

function isSystem(string $path): bool
{
    foreach (SYSTEM_PREFIXES as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

function isMachO(string $file): bool
{
    $handle = fopen($file, 'rb');
    $magic  = $handle === false ? '' : (string) fread($handle, 4);

    if ($handle !== false) {
        fclose($handle);
    }

    return in_array(bin2hex($magic), ['cffaedfe', 'cefaedfe', 'cafebabe'], true);
}

function copyFile(string $from, string $to): void
{
    if (!copy($from, $to)) {
        throw new RuntimeException(sprintf('Cannot copy %s to %s', $from, $to));
    }

    chmod($to, fileperms($from) | 0o200);
}

/**
 * @param list<string> $command
 */
function run(array $command): void
{
    capture($command);
}

/**
 * @param list<string> $command
 */
function capture(array $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . $command[0]);
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error  = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new RuntimeException(sprintf("%s failed:\n%s", implode(' ', $command), $error));
    }

    return $output;
}
