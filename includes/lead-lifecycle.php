<?php
/**
 * Lead conversion lifecycle — business mode, qualified/booked status, owner alerts.
 */

require_once __DIR__ . '/db.php';

function ensure_lead_lifecycle_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn = db_connect();
    require_once __DIR__ . '/commerce-schema.php';
    commerce_ensure_column($conn, 'bots', 'business_mode', "VARCHAR(32) NOT NULL DEFAULT 'mixed'");
    commerce_ensure_column($conn, 'bots', 'conversion_goal', "VARCHAR(32) NOT NULL DEFAULT 'call_booked'");

    $done = true;
}

function bot_business_modes(): array
{
    return [
        'ecommerce' => 'E-commerce / product sales (COD, catalog, orders)',
        'services'  => 'Services / consulting (book a call)',
        'saas'      => 'SaaS / subscriptions (demo + trial)',
        'mixed'     => 'Mixed (products + services)',
    ];
}

function bot_conversion_goals(): array
{
    return [
        'order_placed'   => 'Customer places an order',
        'call_booked'    => 'Customer books a call / meeting',
        'trial_started'  => 'Customer starts trial or signup',
    ];
}

/**
 * @param array<string, mixed> $bot
 */
function bot_business_mode(array $bot): string
{
    ensure_lead_lifecycle_schema();
    $mode = strtolower(trim((string) ($bot['business_mode'] ?? 'mixed')));
    return array_key_exists($mode, bot_business_modes()) ? $mode : 'mixed';
}

/**
 * @param array<string, mixed> $bot
 */
function bot_conversion_goal(array $bot): string
{
    ensure_lead_lifecycle_schema();
    $goal = strtolower(trim((string) ($bot['conversion_goal'] ?? '')));
    if ($goal !== '' && array_key_exists($goal, bot_conversion_goals())) {
        return $goal;
    }

    return match (bot_business_mode($bot)) {
        'ecommerce' => 'order_placed',
        'saas'      => 'trial_started',
        default     => 'call_booked',
    };
}

/**
 * Infer business mode from knowledge document text.
 */
function lifecycle_infer_business_mode(string $document): string
{
    $d = mb_strtolower($document);
    $ecom = 0;
    $services = 0;
    $saas = 0;

    foreach (['product', 'catalog', 'shop', 'cod', 'delivery', 'sku', 'cart', 'perfume', 'order'] as $w) {
        if (str_contains($d, $w)) {
            $ecom++;
        }
    }
    foreach (['consultation', 'service', 'project', 'hourly', 'retainer', 'appointment', 'visit'] as $w) {
        if (str_contains($d, $w)) {
            $services++;
        }
    }
    foreach (['saas', 'subscription', 'monthly plan', 'free trial', 'per month', '/mo', 'software', 'platform', 'api'] as $w) {
        if (str_contains($d, $w)) {
            $saas++;
        }
    }

    if ($ecom >= 2 && ($services >= 1 || $saas >= 1)) {
        return 'mixed';
    }
    if ($ecom >= 2) {
        return 'ecommerce';
    }
    if ($saas >= 2) {
        return 'saas';
    }
    if ($services >= 2) {
        return 'services';
    }

    return 'mixed';
}

function lifecycle_conversion_goal_for_mode(string $mode): string
{
    return match ($mode) {
        'ecommerce' => 'order_placed',
        'saas'      => 'trial_started',
        'services'  => 'call_booked',
        default     => 'call_booked',
    };
}

function lead_mark_qualified(int $leadId, int $score = 85): void
{
    db_execute(
        'UPDATE leads SET status = \'qualified\', score = GREATEST(score, ?) WHERE id = ?',
        'ii',
        [$score, $leadId]
    );
    if (function_exists('ai_ceo_notify_outcome')) {
        ai_ceo_notify_outcome($leadId, 'qualified');
    } else {
        require_once __DIR__ . '/ai-ceo.php';
        ai_ceo_notify_outcome($leadId, 'qualified');
    }
}

function lead_mark_booked(int $leadId, string $reason = 'order'): void
{
    db_execute(
        'UPDATE leads SET status = \'booked\', score = GREATEST(score, 95) WHERE id = ?',
        'i',
        [$leadId]
    );

    $data = db_fetch('SELECT qualification_data FROM leads WHERE id = ?', 'i', [$leadId]);
    $json = [];
    if (!empty($data['qualification_data'])) {
        $json = json_decode($data['qualification_data'], true);
        if (!is_array($json)) {
            $json = [];
        }
    }
    $json['conversion'] = [
        'type'       => $reason,
        'converted_at' => date('c'),
    ];
    db_execute(
        'UPDATE leads SET qualification_data = ? WHERE id = ?',
        'si',
        [json_encode($json, JSON_UNESCAPED_UNICODE), $leadId]
    );

    require_once __DIR__ . '/ai-ceo.php';
    ai_ceo_notify_outcome($leadId, 'booked');
}

/**
 * Prompt block: how this business qualifies/converts leads.
 *
 * @param array<string, mixed> $bot
 */
function lifecycle_conversion_prompt_block(array $bot): string
{
    ensure_lead_lifecycle_schema();
    $mode = bot_business_mode($bot);
    $goal = bot_conversion_goal($bot);

    $lines = [
        '',
        '───── BUSINESS TYPE & CONVERSION ─────',
        'Mode: ' . ($mode === 'ecommerce' ? 'E-commerce / product sales' : ($mode === 'saas' ? 'SaaS / subscription' : ($mode === 'services' ? 'Services / consulting' : 'Mixed business'))),
    ];

    if ($goal === 'order_placed' || $mode === 'ecommerce' || $mode === 'mixed') {
        $lines[] = 'When customer completes a purchase: you MUST include [CREATE_ORDER] and [ORDER:Full Name|Phone|Address|yes] so the system records the order.';
        $lines[] = 'Never say "order confirmed" unless [CREATE_ORDER] is included and cart has items + delivery details + COD yes.';
        $lines[] = 'A placed order = lead is BOOKED — highest value conversion.';
    }

    if ($goal === 'call_booked' || $mode === 'services' || $mode === 'mixed') {
        $lines[] = 'When lead meets qualify trigger: include [BOOK_CALL] and share booking link in the same message.';
        $lines[] = 'Qualified lead = ready for human follow-up or appointment.';
    }

    if ($goal === 'trial_started' || $mode === 'saas') {
        $lines[] = 'When lead agrees to trial/demo/signup: include [BOOK_CALL] or send signup link from script.';
        $lines[] = 'Trial/demo booked = qualified lead.';
    }

    $lines[] = 'Qualify when: ' . trim((string) ($bot['qualify_trigger'] ?? 'clear fit per owner script'));

    if (qualification_flow_load()) {
        $lines[] = qualification_prompt_block($bot);
    }

    return implode("\n", $lines);
}

/**
 * Backfill orders + booked status when AI confirmed an order but no bot_orders row exists.
 *
 * @return array{repaired: bool, order_id: ?int}
 */
function lifecycle_repair_lead_conversion(int $leadId, int $botId, int $userId): array
{
    try {
        require_once __DIR__ . '/cart.php';

        $lead = db_fetch('SELECT * FROM leads WHERE id = ? AND bot_id = ?', 'ii', [$leadId, $botId]);
        if (!$lead) {
            return ['repaired' => false, 'order_id' => null];
        }

        if (cart_lead_has_open_order($leadId)) {
            if (($lead['status'] ?? '') !== 'booked') {
                lead_mark_booked($leadId, 'order');
            }
            return ['repaired' => true, 'order_id' => null];
        }

        if (($lead['status'] ?? '') === 'disqualified') {
            return ['repaired' => false, 'order_id' => null];
        }

        if (catalog_products_for_bot($botId) === []) {
            return ['repaired' => false, 'order_id' => null];
        }

        $messages = db_fetch_all(
            'SELECT role, message FROM conversations WHERE lead_id = ? ORDER BY id ASC',
            'i',
            [$leadId]
        );
        if ($messages === []) {
            return ['repaired' => false, 'order_id' => null];
        }

        $combined = '';
        $confirmationText = '';
        foreach (array_reverse($messages) as $row) {
            $combined = ($row['message'] ?? '') . "\n" . $combined;
            if (($row['role'] ?? '') === 'assistant' && cart_reply_implies_order_placed((string) ($row['message'] ?? ''))) {
                $confirmationText = (string) ($row['message'] ?? '');
                break;
            }
        }

        if ($confirmationText === '' || !cart_reply_implies_order_placed($confirmationText)) {
            return ['repaired' => false, 'order_id' => null];
        }

        cart_hydrate_checkout_from_history($leadId, $messages);
        cart_reconstruct_from_text($leadId, $botId, $confirmationText);
        if (cart_is_empty($leadId)) {
            cart_reconstruct_from_text($leadId, $botId, $combined);
        }
        cart_merge_hints_from_text($leadId, $combined, $confirmationText);

        if (!cart_ready_for_finalize($leadId, $combined, $confirmationText)) {
            $cart = cart_get($leadId);
            if ($cart['items'] !== [] && $cart['customer_name'] !== '' && $cart['shipping_address'] === '') {
                foreach ($messages as $row) {
                    if (($row['role'] ?? '') === 'user') {
                        cart_merge_hints_from_text($leadId, (string) ($row['message'] ?? ''));
                    }
                }
            }
        }

        if (!cart_ready_for_finalize($leadId, $combined, $confirmationText)) {
            return ['repaired' => false, 'order_id' => null];
        }

        $cart = cart_get($leadId);
        if (!$cart['cod_confirmed']) {
            cart_update_checkout($leadId, ['cod_confirmed' => true]);
        }
        cart_update_checkout($leadId, ['anything_else_done' => true, 'anything_else_offered' => true]);

        $orderId = cart_finalize_order($leadId, $botId, $userId, $lead, $confirmationText);

        return ['repaired' => (bool) $orderId, 'order_id' => $orderId ?: null];
    } catch (Throwable $e) {
        error_log('lifecycle_repair_lead_conversion #' . $leadId . ': ' . $e->getMessage());
        return ['repaired' => false, 'order_id' => null];
    }
}

/**
 * Repair recent in-progress leads that look like confirmed orders (dashboard sync).
 *
 * @return int Number of leads repaired
 */
function lifecycle_repair_stuck_orders_for_user(int $userId, int $limit = 25): int
{
    $limit = max(1, min(100, $limit));

    $rows = db_fetch_all(
        'SELECT DISTINCT l.id AS lead_id, l.bot_id, b.user_id
         FROM leads l
         JOIN bots b ON b.id = l.bot_id
         JOIN conversations c ON c.lead_id = l.id AND c.role = \'assistant\'
         WHERE b.user_id = ?
           AND l.status IN (\'new\', \'in_progress\', \'qualified\', \'booked\')
           AND NOT EXISTS (
                SELECT 1 FROM bot_orders o
                WHERE o.lead_id = l.id AND o.status <> \'cancelled\'
           )
           AND (
                c.message LIKE \'%order confirmation%\'
                OR c.message LIKE \'%order is confirm%\'
                OR c.message LIKE \'%order confirm%\'
                OR c.message LIKE \'%Order #% confirm%\'
                OR c.message LIKE \'%order details%\'
                OR c.message LIKE \'%Cash on Delivery%\'
           )
         ORDER BY l.id DESC
         LIMIT ?',
        'ii',
        [$userId, $limit]
    );

    if ($rows === []) {
        $rows = db_fetch_all(
            'SELECT l.id AS lead_id, l.bot_id, b.user_id
             FROM leads l
             JOIN bots b ON b.id = l.bot_id
             WHERE b.user_id = ?
               AND l.status IN (\'in_progress\', \'qualified\', \'booked\')
               AND NOT EXISTS (
                    SELECT 1 FROM bot_orders o
                    WHERE o.lead_id = l.id AND o.status <> \'cancelled\'
               )
             ORDER BY l.id DESC
             LIMIT ?',
            'ii',
            [$userId, $limit]
        );
    }

    $repaired = 0;
    foreach ($rows as $row) {
        $result = lifecycle_repair_lead_conversion(
            (int) $row['lead_id'],
            (int) $row['bot_id'],
            (int) $row['user_id']
        );
        if ($result['repaired']) {
            $repaired++;
        }
    }

    return $repaired;
}
