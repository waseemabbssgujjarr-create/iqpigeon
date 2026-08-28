<?php
/**
 * One-off: strip .php from href/action in user-facing PHP templates.
 * Run: php scripts/strip-url-php.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = [$root, $root . '/admin', $root . '/client', $root . '/includes'];
$skipFiles = ['strip-url-php.php', 'config.php', 'config.local.php'];

function strip_links(string $content): string
{
    return (string) preg_replace_callback(
        '/\b(href|action)\s*=\s*(["\'])(\/(?!api\/)[^"\']*?)\.php(\?[^"\']*)?\2/i',
        static fn(array $m): string => $m[1] . '=' . $m[2] . $m[3] . ($m[4] ?? '') . $m[2],
        $content
    );
}

$changed = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        if (in_array($file->getFilename(), $skipFiles, true)) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $original = file_get_contents($path);
        if ($original === false) {
            continue;
        }
        $updated = strip_links($original);
        if ($updated !== $original) {
            file_put_contents($path, $updated);
            $changed++;
            echo 'Updated: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path) . PHP_EOL;
        }
    }
}

echo "Done. {$changed} files updated." . PHP_EOL;
