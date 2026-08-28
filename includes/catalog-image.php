<?php
/**
 * Catalog product image uploads — validation, compression, temp originals.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/domain.php';

const CATALOG_IMAGE_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
const CATALOG_IMAGE_TARGET_BYTES = 100 * 1024;
const CATALOG_IMAGE_MAX_DIMENSION = 1600;
const CATALOG_IMAGE_MIN_DIMENSION = 200;
const CATALOG_IMAGE_ORIGINAL_TTL_SEC = 1800;

function catalog_product_uploads_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/catalog';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n");
    }

    return $dir;
}

function catalog_product_originals_dir(): string
{
    $dir = catalog_product_uploads_dir() . '/originals';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    return $dir;
}

function catalog_product_image_public_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return app_url('/uploads/catalog/' . $relativePath);
}

/**
 * @return array<string, string>
 */
function catalog_product_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/pjpeg'=> 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/x-webp' => 'webp',
    ];
}

function catalog_image_normalize_mime(string $mime): string
{
    $mime = strtolower(trim($mime));
    $map = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/x-webp' => 'image/webp',
    ];

    return $map[$mime] ?? $mime;
}

function catalog_image_has_gd(): bool
{
    return extension_loaded('gd')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagejpeg');
}

function catalog_image_has_imagick(): bool
{
    return extension_loaded('imagick') && class_exists('Imagick');
}

/**
 * @return array{success: bool, url?: string, error?: string, compressed?: bool}
 */
function catalog_process_product_image(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'No image selected.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed (code ' . (int) ($file['error'] ?? 0) . ').'];
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }

    $uploadSize = (int) ($file['size'] ?? 0);
    if ($uploadSize > CATALOG_IMAGE_MAX_UPLOAD_BYTES) {
        return ['success' => false, 'error' => 'Image must be under 10 MB.'];
    }

    if (!catalog_image_has_gd() && !catalog_image_has_imagick()) {
        return ['success' => false, 'error' => 'Server image processing is unavailable. Contact support or use Image URL instead.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = catalog_image_normalize_mime($finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '');
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = catalog_product_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['success' => false, 'error' => 'Use JPG, PNG, or WebP.'];
    }

    $userDir = catalog_product_uploads_dir() . '/' . $userId;
    if (!is_dir($userDir)) {
        @mkdir($userDir, 0755, true);
    }

    $baseName = 'prod_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $ext = $allowed[$mime];

    if ($uploadSize <= CATALOG_IMAGE_TARGET_BYTES) {
        $filename = $baseName . '.' . $ext;
        $dest = $userDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['success' => false, 'error' => 'Could not save image.'];
        }

        return [
            'success'    => true,
            'url'        => catalog_product_image_public_url($userId . '/' . $filename),
            'compressed' => false,
        ];
    }

    $origDir = catalog_product_originals_dir();
    $origFilename = $baseName . '_orig.' . $ext;
    $origPath = $origDir . '/' . $origFilename;
    if (!@copy($file['tmp_name'], $origPath)) {
        return ['success' => false, 'error' => 'Could not store original image.'];
    }
    @file_put_contents($origPath . '.expires', (string) (time() + CATALOG_IMAGE_ORIGINAL_TTL_SEC));

    $compress = catalog_compress_product_image($file['tmp_name'], $mime, $userDir, $baseName);
    @unlink($file['tmp_name']);

    if (!$compress['success']) {
        @unlink($origPath);
        @unlink($origPath . '.expires');

        return [
            'success' => false,
            'error'   => $compress['error'] ?? 'Could not compress image.',
        ];
    }

    return [
        'success'    => true,
        'url'        => catalog_product_image_public_url($userId . '/' . ($compress['filename'] ?? '')),
        'compressed' => true,
    ];
}

/**
 * @return array{success: bool, filename?: string, error?: string}
 */
function catalog_compress_product_image(string $srcPath, string $mime, string $destDir, string $baseName): array
{
    $filename = $baseName . '.jpg';
    $destPath = $destDir . '/' . $filename;
    $lastError = 'Could not compress image. Try a smaller JPG/PNG or paste an Image URL.';

    if (catalog_image_has_gd()) {
        $gd = catalog_compress_with_gd($srcPath, $mime, $destPath);
        if ($gd['success']) {
            return ['success' => true, 'filename' => $filename];
        }
        $lastError = $gd['error'] ?? $lastError;
    }

    if (catalog_image_has_imagick()) {
        $im = catalog_compress_with_imagick($srcPath, $destPath);
        if ($im['success']) {
            return ['success' => true, 'filename' => $filename];
        }
        $lastError = $im['error'] ?? $lastError;
    }

    return [
        'success' => false,
        'error'   => $lastError,
    ];
}

/**
 * @return array{success: bool, error?: string}
 */
function catalog_compress_with_gd(string $srcPath, string $mime, string $destPath): array
{
    $img = catalog_gd_load_image($srcPath, $mime);
    if ($img === null) {
        return ['success' => false, 'error' => 'Could not read image file. Try JPG or PNG.'];
    }

    $img = catalog_gd_fix_orientation($img, $srcPath, $mime);

    $width = imagesx($img);
    $height = imagesy($img);
    if ($width < 1 || $height < 1) {
        imagedestroy($img);

        return ['success' => false, 'error' => 'Invalid image dimensions.'];
    }

    $img = catalog_gd_resize_if_needed($img, $width, $height, CATALOG_IMAGE_MAX_DIMENSION);
    if ($img === null) {
        return ['success' => false, 'error' => 'Could not resize image.'];
    }

    $img = catalog_gd_flatten_for_jpeg($img);

    $saved = catalog_gd_save_under_size($img, $destPath, CATALOG_IMAGE_TARGET_BYTES);
    imagedestroy($img);

    if (!$saved['success']) {
        return ['success' => false, 'error' => $saved['error'] ?? 'Could not compress image.'];
    }

    return ['success' => true];
}

/**
 * @return array{success: bool, error?: string}
 */
function catalog_compress_with_imagick(string $srcPath, string $destPath): array
{
    try {
        $image = new Imagick($srcPath);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Could not read image file.'];
    }

    try {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $maxDim = CATALOG_IMAGE_MAX_DIMENSION;

        if ($width > $maxDim || $height > $maxDim) {
            $image->thumbnailImage($maxDim, $maxDim, true);
        }

        $image->setImageFormat('jpeg');
        $image->setImageBackgroundColor('white');
        if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }
        $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        $bestPath = $destPath . '.best';
        $bestSize = PHP_INT_MAX;
        $target = CATALOG_IMAGE_TARGET_BYTES;

        $sizes = [1600, 1200, 900, 720, 540, 420, 320, 260, CATALOG_IMAGE_MIN_DIMENSION];
        $qualities = [85, 75, 65, 55, 45, 35, 25, 18];

        foreach ($sizes as $dim) {
            $trial = clone $image;
            $tw = $trial->getImageWidth();
            $th = $trial->getImageHeight();
            if ($tw > $dim || $th > $dim) {
                $trial->thumbnailImage($dim, $dim, true);
            }

            foreach ($qualities as $quality) {
                $trial->setImageCompressionQuality($quality);
                $trial->stripImage();
                if (!$trial->writeImage($destPath)) {
                    continue;
                }
                $size = (int) @filesize($destPath);
                if ($size > 0 && $size < $bestSize) {
                    @copy($destPath, $bestPath);
                    $bestSize = $size;
                }
                if ($size > 0 && $size <= $target) {
                    $trial->clear();
                    @unlink($bestPath);

                    return ['success' => true];
                }
            }
            $trial->clear();
        }

        if ($bestSize < PHP_INT_MAX && is_file($bestPath)) {
            @rename($bestPath, $destPath);
            $image->clear();

            return ['success' => true];
        }

        @unlink($bestPath);
        $image->clear();

        return ['success' => false, 'error' => 'Could not compress image under 100 KB. Try a smaller photo.'];
    } catch (Throwable $e) {
        try {
            $image->clear();
        } catch (Throwable $ignored) {
        }

        return ['success' => false, 'error' => 'Image compression failed.'];
    }
}

/**
 * @return \GdImage|resource|null
 */
function catalog_gd_load_image(string $path, string $mime)
{
    $mime = catalog_image_normalize_mime($mime);

    $img = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };

    if ($img !== false && $img !== null) {
        return $img;
    }

    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return null;
    }

    return @imagecreatefromstring($bytes) ?: null;
}

/**
 * @param \GdImage|resource $img
 * @return \GdImage|resource
 */
function catalog_gd_fix_orientation($img, string $path, string $mime)
{
    if (!function_exists('exif_read_data') || catalog_image_normalize_mime($mime) !== 'image/jpeg') {
        return $img;
    }

    $exif = @exif_read_data($path);
    if (!is_array($exif) || empty($exif['Orientation'])) {
        return $img;
    }

    $orientation = (int) $exif['Orientation'];
    $rotated = match ($orientation) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => null,
    };

    if ($rotated !== null && $rotated !== false) {
        imagedestroy($img);

        return $rotated;
    }

    return $img;
}

/**
 * @param \GdImage|resource $img
 * @return \GdImage|resource|null
 */
function catalog_gd_resize_if_needed($img, int $width, int $height, int $maxDim)
{
    if ($width <= $maxDim && $height <= $maxDim) {
        return $img;
    }

    $scale = min($maxDim / $width, $maxDim / $height);
    $newW = max(1, (int) round($width * $scale));
    $newH = max(1, (int) round($height * $scale));

    $resized = imagecreatetruecolor($newW, $newH);
    if ($resized === false) {
        imagedestroy($img);

        return null;
    }

    imagealphablending($resized, true);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($img);

    return $resized;
}

/**
 * Flatten alpha onto white for JPEG output.
 *
 * @param \GdImage|resource $img
 * @return \GdImage|resource
 */
function catalog_gd_flatten_for_jpeg($img)
{
    $width = imagesx($img);
    $height = imagesy($img);
    $flatten = imagecreatetruecolor($width, $height);
    if ($flatten === false) {
        return $img;
    }

    $white = imagecolorallocate($flatten, 255, 255, 255);
    imagefill($flatten, 0, 0, $white);
    imagecopy($flatten, $img, 0, 0, 0, 0, $width, $height);
    imagedestroy($img);

    return $flatten;
}

/**
 * @param \GdImage|resource $img
 * @return array{success: bool, error?: string}
 */
function catalog_gd_save_under_size($img, string $destPath, int $targetBytes): array
{
    $bestPath = $destPath . '.best';
    $bestSize = PHP_INT_MAX;
    $width = imagesx($img);
    $height = imagesy($img);

    $maxDims = [1600, 1200, 900, 720, 540, 420, 320, 260, CATALOG_IMAGE_MIN_DIMENSION];
    $qualities = [85, 78, 72, 65, 58, 50, 42, 35, 28, 22, 16];

    foreach ($maxDims as $maxDim) {
        $working = $img;
        $w = imagesx($working);
        $h = imagesy($working);

        if ($w > $maxDim || $h > $maxDim) {
            $scale = min($maxDim / $w, $maxDim / $h);
            $newW = max(1, (int) round($w * $scale));
            $newH = max(1, (int) round($h * $scale));
            $resized = imagecreatetruecolor($newW, $newH);
            if ($resized === false) {
                continue;
            }
            imagecopyresampled($resized, $working, 0, 0, 0, 0, $newW, $newH, $w, $h);
            if ($working !== $img) {
                imagedestroy($working);
            }
            $working = $resized;
        }

        foreach ($qualities as $quality) {
            if (!@imagejpeg($working, $destPath, $quality)) {
                continue;
            }
            $size = (int) @filesize($destPath);
            if ($size <= 0) {
                continue;
            }
            if ($size < $bestSize) {
                @copy($destPath, $bestPath);
                $bestSize = $size;
            }
            if ($size <= $targetBytes) {
                if ($working !== $img) {
                    imagedestroy($working);
                }
                @unlink($bestPath);

                return ['success' => true];
            }
        }

        if ($working !== $img) {
            imagedestroy($working);
        }
    }

    if ($bestSize < PHP_INT_MAX && is_file($bestPath)) {
        @rename($bestPath, $destPath);
        @unlink($destPath . '.best');

        return ['success' => true];
    }

    @unlink($bestPath);

    return [
        'success' => false,
        'error'   => 'Could not compress image under 100 KB. Try a smaller photo or use Image URL.',
    ];
}

/**
 * @return array{deleted: int}
 */
function catalog_purge_expired_originals(): array
{
    $dir = catalog_product_originals_dir();
    $deleted = 0;

    foreach (glob($dir . '/*.expires') ?: [] as $expiresFile) {
        $expiresAt = (int) (@file_get_contents($expiresFile) ?: 0);
        if ($expiresAt > time()) {
            continue;
        }

        $originalPath = substr($expiresFile, 0, -strlen('.expires'));
        if ($originalPath !== '' && is_file($originalPath)) {
            @unlink($originalPath);
        }
        @unlink($expiresFile);
        $deleted++;
    }

    foreach (glob($dir . '/*.best') ?: [] as $stale) {
        @unlink($stale);
    }

    return ['deleted' => $deleted];
}
