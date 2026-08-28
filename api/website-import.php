<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/website-import.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(300);
@ignore_user_abort(true);

try {
    $user = require_login_json();
    security_require_api_csrf();

    $input = security_cached_json_body();
    if ($input === [] && $_POST !== []) {
        $input = $_POST;
    }

    $userId = (int) $user['id'];
    $action = trim((string) ($input['action'] ?? 'import'));
    $botId = (int) ($input['bot_id'] ?? 0);
    $url = trim((string) ($input['url'] ?? ''));

    if ($botId <= 0) {
        json_response(['success' => false, 'error' => 'Missing bot_id'], 400);
    }

    $owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
    if (!$owned) {
        json_response(['success' => false, 'error' => 'Invalid bot'], 403);
    }

    if ($action === 'clear') {
        $deleteProducts = !empty($input['delete_products']);
        $result = website_import_clear_bot($botId, $userId, $deleteProducts);
        json_response($result, $result['success'] ? 200 : 400);
    }

    if ($url === '') {
        json_response(['success' => false, 'error' => 'Missing website URL'], 400);
    }

    if ($action === 'preview') {
        @set_time_limit(120);
        $result = website_import_preview($url);
        json_response($result, $result['success'] ? 200 : 400);
    }

    if ($action === 'import_start') {
        @set_time_limit(180);
        $result = website_import_start_job($botId, $userId, $url);
        json_response($result, $result['success'] ? 200 : 400);
    }

    if ($action === 'import_batch') {
        $jobId = trim((string) ($input['job_id'] ?? ''));
        if ($jobId === '') {
            json_response(['success' => false, 'error' => 'Missing job_id'], 400);
        }
        $result = website_import_run_batch($jobId, $botId, $userId);
        json_response($result, $result['success'] ? 200 : 400);
    }

    if ($action === 'import_indolj_browser') {
        $menus = $input['menus'] ?? [];
        if (!is_array($menus) || $menus === []) {
            json_response(['success' => false, 'error' => 'Missing menu data from browser'], 400);
        }
        $result = website_import_indolj_browser($botId, $userId, $url, $menus);
        json_response($result, $result['success'] ? 200 : 400);
    }

    // Legacy single-request import (small catalogs).
    $result = website_import_sync($botId, $userId, $url);
    json_response($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('website-import.php: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
