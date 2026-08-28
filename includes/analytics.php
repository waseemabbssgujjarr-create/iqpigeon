<?php
/**
 * Business analytics — leads, orders, bookings, broadcasts.
 */

require_once __DIR__ . '/commerce-schema.php';
require_once __DIR__ . '/payment-schema.php';

/**
 * @return array<string, mixed>
 */
function analytics_overview(int $userId, ?int $botId = null): array
{
    ensure_commerce_schema();

    $botSql = $botId ? ' AND b.id = ?' : '';
    $botTypes = $botId ? 'i' : '';
    $botParams = $botId ? [$botId] : [];

    $leads = db_fetch(
        'SELECT
            COUNT(l.id) AS total,
            SUM(l.status = \'qualified\') AS qualified,
            SUM(l.status = \'booked\') AS booked,
            SUM(l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS week_new
         FROM leads l JOIN bots b ON b.id = l.bot_id
         WHERE b.user_id = ?' . $botSql,
        'i' . $botTypes,
        array_merge([$userId], $botParams)
    ) ?: [];

    $orders = db_fetch(
        'SELECT
            COUNT(o.id) AS total,
            SUM(o.status = \'new\') AS status_new,
            SUM(o.status = \'confirmed\') AS status_confirmed,
            SUM(o.status = \'shipped\') AS status_shipped,
            SUM(o.status = \'delivered\') AS status_delivered,
            COALESCE(SUM(o.total_amount), 0) AS revenue,
            SUM(o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS month_orders
         FROM bot_orders o JOIN bots b ON b.id = o.bot_id
         WHERE o.user_id = ?' . ($botId ? ' AND o.bot_id = ?' : ''),
        'i' . $botTypes,
        array_merge([$userId], $botParams)
    ) ?: [];

    $appointments = db_fetch(
        'SELECT
            COUNT(a.id) AS total,
            SUM(a.slot_start >= NOW() AND a.status = \'confirmed\') AS upcoming
         FROM bot_appointments a JOIN bots b ON b.id = a.bot_id
         WHERE a.user_id = ?' . ($botId ? ' AND a.bot_id = ?' : ''),
        'i' . $botTypes,
        array_merge([$userId], $botParams)
    ) ?: [];

    $broadcasts = db_fetch(
        'SELECT
            COUNT(bc.id) AS campaigns,
            COALESCE(SUM(bc.sent_count), 0) AS messages_sent
         FROM broadcasts bc
         WHERE bc.user_id = ?' . ($botId ? ' AND bc.bot_id = ?' : ''),
        'i' . $botTypes,
        array_merge([$userId], $botParams)
    ) ?: [];

    $products = db_fetch(
        'SELECT COUNT(p.id) AS total FROM bot_products p
         WHERE p.user_id = ?' . ($botId ? ' AND p.bot_id = ?' : ''),
        'i' . $botTypes,
        array_merge([$userId], $botParams)
    ) ?: [];

    $totalLeads = (int) ($leads['total'] ?? 0);
    $qualified = (int) ($leads['qualified'] ?? 0);
    $booked = (int) ($leads['booked'] ?? 0);
    $totalOrders = (int) ($orders['total'] ?? 0);

    return [
        'leads_total'       => $totalLeads,
        'leads_qualified'   => $qualified,
        'leads_booked'      => $booked,
        'leads_week'        => (int) ($leads['week_new'] ?? 0),
        'qualify_rate'      => $totalLeads > 0 ? round(($qualified / $totalLeads) * 100) : 0,
        'book_rate'         => $totalLeads > 0 ? round(($booked / $totalLeads) * 100) : 0,
        'orders_total'      => $totalOrders,
        'orders_new'        => (int) ($orders['status_new'] ?? 0),
        'orders_confirmed'  => (int) ($orders['status_confirmed'] ?? 0),
        'orders_shipped'    => (int) ($orders['status_shipped'] ?? 0),
        'orders_delivered'  => (int) ($orders['status_delivered'] ?? 0),
        'orders_month'      => (int) ($orders['month_orders'] ?? 0),
        'revenue_total'     => (float) ($orders['revenue'] ?? 0),
        'order_rate'        => $totalLeads > 0 ? round(($totalOrders / $totalLeads) * 100) : 0,
        'appointments_total'=> (int) ($appointments['total'] ?? 0),
        'appointments_upcoming' => (int) ($appointments['upcoming'] ?? 0),
        'broadcasts'        => (int) ($broadcasts['campaigns'] ?? 0),
        'broadcasts_sent'   => (int) ($broadcasts['messages_sent'] ?? 0),
        'products'          => (int) ($products['total'] ?? 0),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function analytics_top_products(int $userId, ?int $botId = null, int $limit = 5): array
{
    ensure_commerce_schema();
    $sql = 'SELECT oi.product_name, SUM(oi.quantity) AS qty, SUM(oi.quantity * oi.unit_price) AS revenue
            FROM bot_order_items oi
            JOIN bot_orders o ON o.id = oi.order_id
            WHERE o.user_id = ?';
    $types = 'i';
    $params = [$userId];
    if ($botId) {
        $sql .= ' AND o.bot_id = ?';
        $types .= 'i';
        $params[] = $botId;
    }
    $sql .= ' GROUP BY oi.product_name ORDER BY qty DESC LIMIT ' . max(1, min(20, $limit));
    return db_fetch_all($sql, $types, $params);
}

/**
 * @return array<int, array<string, mixed>>
 */
function analytics_revenue_by_day(int $userId, int $days = 14, ?int $botId = null): array
{
    ensure_commerce_schema();
    $sql = 'SELECT DATE(o.created_at) AS day, COUNT(o.id) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue
            FROM bot_orders o
            WHERE o.user_id = ? AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    $types = 'ii';
    $params = [$userId, $days];
    if ($botId) {
        $sql .= ' AND o.bot_id = ?';
        $types .= 'i';
        $params[] = $botId;
    }
    $sql .= ' GROUP BY DATE(o.created_at) ORDER BY day ASC';
    return db_fetch_all($sql, $types, $params);
}
