<?php
/**
 * Extract plain text from uploaded business documents (TXT, DOCX, PDF).
 */

/**
 * @return array{success: bool, text?: string, error?: string}
 */
function extract_document_text(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed. Please try again.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return ['success' => false, 'error' => 'The file is empty.'];
    }
    if ($size > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File is too large (max 10 MB).'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }

    $name = (string) ($file['name'] ?? 'document');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $text = match ($ext) {
        'txt'  => extract_txt_text($tmp),
        'docx' => extract_docx_text($tmp),
        'doc'  => extract_doc_text($tmp),
        'pdf'  => extract_pdf_text($tmp),
        default => '',
    };

    if ($text === '') {
        return [
            'success' => false,
            'error' => 'Unsupported or unreadable file. Use PDF, Word (.docx), or plain text (.txt).',
        ];
    }

    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($text) < 40) {
        return [
            'success' => false,
            'error' => 'Could not read enough text from this file. Try a text-based PDF or paste the content manually.',
        ];
    }

    if (mb_strlen($text) > 20000) {
        $text = mb_substr($text, 0, 20000);
    }

    return ['success' => true, 'text' => $text];
}

/**
 * Map MIME type or filename to a document extractor extension.
 */
function document_extension_from_mime(string $mime, string $filename = ''): string
{
    $filename = trim($filename);
    $ext = $filename !== '' ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
    if (in_array($ext, ['txt', 'pdf', 'doc', 'docx'], true)) {
        return $ext;
    }

    $mime = strtolower(trim($mime));

    return match ($mime) {
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        default => $ext,
    };
}

/**
 * Extract text from a file already stored on disk (e.g. after move_uploaded_file).
 *
 * @return array{success: bool, text?: string, error?: string}
 */
function extract_document_text_from_path(string $path, string $filenameOrExt, int $minChars = 40): array
{
    if ($path === '' || !is_readable($path)) {
        return ['success' => false, 'error' => 'File not readable.'];
    }

    $size = (int) (@filesize($path) ?: 0);
    if ($size <= 0) {
        return ['success' => false, 'error' => 'The file is empty.'];
    }
    if ($size > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File is too large (max 10 MB).'];
    }

    $ext = strtolower(pathinfo($filenameOrExt, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = strtolower($filenameOrExt);
    }
    if (!in_array($ext, ['txt', 'pdf', 'doc', 'docx'], true)) {
        $ext = document_extension_from_mime($filenameOrExt, $filenameOrExt);
    }

    $text = match ($ext) {
        'txt'  => extract_txt_text($path),
        'docx' => extract_docx_text($path),
        'doc'  => extract_doc_text($path),
        'pdf'  => extract_pdf_text($path),
        default => '',
    };

    if ($text === '') {
        return [
            'success' => false,
            'error' => 'Unsupported or unreadable file. Use PDF, Word (.docx), or plain text (.txt).',
        ];
    }

    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    $minChars = max(1, $minChars);
    if (mb_strlen($text) < $minChars) {
        return [
            'success' => false,
            'error' => 'Could not read enough text from this file. Try a text-based PDF or paste the content manually.',
        ];
    }

    if (mb_strlen($text) > 20000) {
        $text = mb_substr($text, 0, 20000);
    }

    return ['success' => true, 'text' => $text];
}

function extract_txt_text(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return '';
    }

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (is_string($converted)) {
            $raw = $converted;
        }
    }

    return trim($raw);
}

function extract_docx_text(string $path): string
{
    if (!class_exists(ZipArchive::class)) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if (!is_string($xml) || $xml === '') {
        return '';
    }

    $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>', '<w:cr/>'], ["\n", "\t", "\n", "\n"], $xml);
    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}

function extract_doc_text(string $path): string
{
    $docx = extract_docx_text($path);
    if ($docx !== '') {
        return $docx;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return '';
    }

    if (preg_match_all('/[\x20-\x7E\xC0-\xFF]{4,}/u', $raw, $matches)) {
        $chunks = array_filter($matches[0], static fn ($chunk) => !str_contains($chunk, 'Microsoft') && !str_contains($chunk, 'Word'));
        return trim(implode(' ', $chunks));
    }

    return '';
}

function extract_pdf_text(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return '';
    }

    $text = '';

    if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|TJ)/s', $content, $matches)) {
        foreach ($matches[0] as $match) {
            if (preg_match('/\((.*)\)\s*(?:Tj|TJ)/s', $match, $inner)) {
                $text .= pdf_decode_literal($inner[1]) . ' ';
            }
        }
    }

    if (trim($text) === '' && preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if (is_string($decoded) && $decoded !== '') {
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $decoded, $innerMatches)) {
                    foreach ($innerMatches[0] as $literal) {
                        $text .= pdf_decode_literal(trim($literal, '()')) . ' ';
                    }
                }
            }
        }
    }

    if (trim($text) === '') {
        if (preg_match_all('/[\x20-\x7E]{5,}/', $content, $runs)) {
            $text = implode(' ', $runs[0]);
        }
    }

    return trim(preg_replace('/\s+/u', ' ', $text));
}

function pdf_decode_literal(string $value): string
{
    $value = preg_replace('/\\\\([nrtbf()\\\\])/', static function (array $m): string {
        return match ($m[1]) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\x0C",
            '(' => '(',
            ')' => ')',
            '\\' => '\\',
            default => $m[1],
        };
    }, $value) ?? $value;

    return trim($value);
}
