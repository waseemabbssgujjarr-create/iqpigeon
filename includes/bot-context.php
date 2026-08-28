<?php
/**
 * Lightweight bot snapshot for cross-page sync (Training ↔ Orders ↔ Catalog ↔ Dashboard).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bot-knowledge.php';
require_once __DIR__ . '/lead-lifecycle.php';
require_once __DIR__ . '/commerce-schema.php';

/**
 * @return array<string, mixed>
 */
function bot_context_snapshot(int $botId, int $userId): array
{
    if ($botId <= 0 || $userId <= 0) {
        return ['bot_id' => 0, 'version' => ''];
    }

    try {
        ensure_bots_schema();
        ensure_lead_lifecycle_schema();
    } catch (Throwable $e) {
        error_log('bot_context_snapshot schema: ' . $e->getMessage());
    }

    try {
        $bot = db_fetch(
            'SELECT * FROM bots WHERE id = ? AND user_id = ?',
            'ii',
            [$botId, $userId]
        );
    } catch (Throwable $e) {
        error_log('bot_context_snapshot bot fetch: ' . $e->getMessage());

        return ['bot_id' => $botId, 'version' => ''];
    }

    if (!$bot) {
        return ['bot_id' => $botId, 'version' => ''];
    }

    try {
        ensure_commerce_schema();
    } catch (Throwable $e) {
        error_log('bot_context_snapshot commerce schema: ' . $e->getMessage());
    }

    $productCount = 0;
    try {
        $productCount = (int) (db_fetch(
            'SELECT COUNT(*) AS c FROM bot_products WHERE bot_id = ? AND user_id = ?',
            'ii',
            [$botId, $userId]
        )['c'] ?? 0);
    } catch (Throwable $e) {
        error_log('bot_context_snapshot products: ' . $e->getMessage());
    }

    $orderCounts = ['new' => 0, 'confirmed' => 0, 'shipped' => 0, 'delivered' => 0];
    try {
        $rows = db_fetch_all(
            'SELECT status, COUNT(*) AS c FROM bot_orders
             WHERE bot_id = ? AND user_id = ? AND status <> \'cancelled\'
             GROUP BY status',
            'ii',
            [$botId, $userId]
        );
        foreach ($rows as $row) {
            $st = (string) ($row['status'] ?? '');
            if (isset($orderCounts[$st])) {
                $orderCounts[$st] = (int) ($row['c'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('bot_context_snapshot orders: ' . $e->getMessage());
    }

    $leadCount = 0;
    try {
        $leadCount = (int) (db_fetch(
            'SELECT COUNT(*) AS c FROM leads WHERE bot_id = ?',
            'i',
            [$botId]
        )['c'] ?? 0);
    } catch (Throwable $e) {
        error_log('bot_context_snapshot leads: ' . $e->getMessage());
    }

    $industryKey = trim((string) ($bot['industry_key'] ?? ''));

    $version = md5(implode('|', [
        (string) $botId,
        $industryKey,
        (string) ($bot['business_mode'] ?? ''),
        (string) ($bot['knowledge_updated_at'] ?? ''),
        (string) ($bot['updated_at'] ?? ''),
        (string) ($bot['qualify_trigger'] ?? ''),
        (string) $productCount,
        json_encode($orderCounts),
        (string) $leadCount,
    ]));

    $pipeline = null;
    if ($industryKey !== '' && is_file(__DIR__ . '/industry-order-pipeline.php')) {
        require_once __DIR__ . '/industry-order-pipeline.php';
        $pipeline = industry_order_pipeline_for_bot($bot);
    }

    return [
        'bot_id'               => $botId,
        'bot_name'             => (string) ($bot['name'] ?? ''),
        'industry_key'         => $industryKey,
        'business_mode'        => (string) ($bot['business_mode'] ?? ''),
        'conversion_goal'      => (string) ($bot['conversion_goal'] ?? ''),
        'knowledge_updated_at' => (string) ($bot['knowledge_updated_at'] ?? ''),
        'trained'              => trim((string) ($bot['qualify_trigger'] ?? '')) !== '',
        'product_count'        => $productCount,
        'order_counts'         => $orderCounts,
        'lead_count'           => $leadCount,
        'version'              => $version,
        'pipeline'             => $pipeline ? [
            'industry_key'      => $pipeline['industry_key'],
            'industry_label'    => $pipeline['industry_label'],
            'page_subtitle'     => $pipeline['page_subtitle'],
            'requires_shipment' => $pipeline['requires_shipment'],
            'show_courier'      => $pipeline['show_courier'],
            'columns'           => $pipeline['columns'],
        ] : null,
    ];
}

/**
 * Attach context to API JSON responses after bot mutations.
 *
 * @return array<string, mixed>
 */
function bot_context_api_envelope(int $botId, int $userId, array $extra = []): array
{
    try {
        return array_merge(['context' => bot_context_snapshot($botId, $userId)], $extra);
    } catch (Throwable $e) {
        error_log('bot_context_api_envelope: ' . $e->getMessage());

        return $extra;
    }
}
