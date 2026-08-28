<?php
/**
 * Load config.local.php safely — strips bare pasted secret lines that PHP would echo.
 */
declare(strict_types=1);

function config_load_local(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $src = file_get_contents($path);
    if ($src === false || $src === '') {
        return;
    }

    // Drop any plain text before <?php (would be echoed on require)
    if (preg_match('/<\?php/i', $src, $m, PREG_OFFSET_CAPTURE)) {
        $src = substr($src, (int) $m[0][1]);
    }

    // Remove lines that are only a long key (common paste mistake)
    $src = preg_replace('/^[ \t]*[A-Za-z0-9]{32,}[ \t]*;?[ \t]*(?:\r?\n|$)/m', '', $src) ?? $src;

    if (!preg_match('/^\s*<\?php/i', $src)) {
        $src = "<?php\n" . ltrim($src);
    }

    $cacheDir = dirname(__DIR__) . '/storage/security';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }

    // Always rebuild cache from sanitized source (content hash avoids stale bad cache)
    $hash = md5($src);
    $cacheFile = $cacheDir . '/config.local.' . $hash . '.php';
    if (!is_file($cacheFile)) {
        if (@file_put_contents($cacheFile, $src, LOCK_EX) === false) {
            require_once $path;
            return;
        }
    }

    try {
        require_once $cacheFile;
    } catch (Throwable $e) {
        @unlink($cacheFile);
        error_log('config.local cache invalid, loading source: ' . $e->getMessage());
        require_once $path;
    }
}
