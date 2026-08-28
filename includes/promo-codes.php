<?php
/**
 * WhatsApp shop promo / discount codes.
 */

require_once __DIR__ . '/phase6-schema.php';
require_once __DIR__ . '/cart.php';

/**
 * @return array<int, array<string, mixed>>
 */
function promo_codes_for_bot(int $botId, int $userId): array
{
    ensure_phase6_schema();
    return db_fetch_all(
        'SELECT * FROM bot_promo_codes WHERE bot_id = ? AND user_id = ? ORDER BY created_at DESC',
        'ii',
        [$botId, $userId]
    );
}

function promo_code_save(int $botId, int $userId, array $data, ?int $promoId = null): int
{
    ensure_phase6_schema();

    $code = strtoupper(preg_replace('/\s+/', '', trim((string) ($data['code'] ?? ''))));
    if ($code === '' || strlen($code) < 3) {
        throw new InvalidArgumentException('Promo code must be at least 3 characters.');
    }

    $type = ($data['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    $value = max(0, (float) ($data['discount_value'] ?? 0));
    if ($type === 'percent') {
        $value = min(100, $value);
    }
    $minOrder = max(0, (float) ($data['min_order'] ?? 0));
    $maxUses = ($data['max_uses'] ?? '') === '' ? null : max(1, (int) $data['max_uses']);
    $expires = trim((string) ($data['expires_at'] ?? ''));
    $expiresAt = $expires !== '' ? date('Y-m-d H:i:s', strtotime($expires)) : null;
    $active = !empty($data['is_active']) ? 1 : 0;

    if ($promoId) {
        db_execute(
            'UPDATE bot_promo_codes SET code=?, discount_type=?, discount_value=?, min_order=?, max_uses=?, expires_at=?, is_active=?
             WHERE id=? AND bot_id=? AND user_id=?',
            'ssddisiiii',
            [$code, $type, $value, $minOrder, $maxUses, $expiresAt, $active, $promoId, $botId, $userId]
        );
        return $promoId;
    }

    return db_insert(
        'INSERT INTO bot_promo_codes (bot_id, user_id, code, discount_type, discount_value, min_order, max_uses, expires_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iissddisi',
        [$botId, $userId, $code, $type, $value, $minOrder, $maxUses, $expiresAt, $active]
    );
}

function promo_code_delete(int $promoId, int $botId, int $userId): void
{
    ensure_phase6_schema();
    db_execute(
        'DELETE FROM bot_promo_codes WHERE id = ? AND bot_id = ? AND user_id = ?',
        'iii',
        [$promoId, $botId, $userId]
    );
}

function promo_find_active(int $botId, string $code): ?array
{
    ensure_phase6_schema();
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $row = db_fetch(
        'SELECT * FROM bot_promo_codes WHERE bot_id = ? AND code = ? AND is_active = 1',
        'is',
        [$botId, $code]
    );
    if (!$row) {
        return null;
    }

    if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
        return null;
    }

    $maxUses = $row['max_uses'] ?? null;
    if ($maxUses !== null && (int) $row['used_count'] >= (int) $maxUses) {
        return null;
    }

    return $row;
}

function promo_calculate_discount(array $promo, float $subtotal): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }

    $minOrder = (float) ($promo['min_order'] ?? 0);
    if ($subtotal < $minOrder) {
        return 0.0;
    }

    if (($promo['discount_type'] ?? '') === 'fixed') {
        return min($subtotal, (float) ($promo['discount_value'] ?? 0));
    }

    $pct = min(100, max(0, (float) ($promo['discount_value'] ?? 0)));
    return round($subtotal * ($pct / 100), 2);
}

function promo_apply_to_cart(int $leadId, int $botId, string $code): string
{
    $promo = promo_find_active($botId, $code);
    if (!$promo) {
        return 'Invalid or expired promo code. Try again or type *cart* to continue.';
    }

    $cart = cart_get($leadId);
    if ($cart['items'] === []) {
        return 'Add items to your cart first, then apply a promo code.';
    }

    $subtotal = cart_subtotal($cart);
    $discount = promo_calculate_discount($promo, $subtotal);
    if ($discount <= 0) {
        $min = catalog_format_price((float) $promo['min_order'], cart_currency($cart));
        return 'This code needs a minimum order of ' . $min . '.';
    }

    $cart['promo_code'] = (string) $promo['code'];
    $cart['discount_amount'] = $discount;
    cart_save($leadId, $cart);

    return 'Promo *' . $promo['code'] . '* applied! You save ' . catalog_format_price($discount, cart_currency($cart)) . ".\n\n" . cart_format_summary($leadId);
}

function promo_increment_usage(string $code, int $botId): void
{
    ensure_phase6_schema();
    db_execute(
        'UPDATE bot_promo_codes SET used_count = used_count + 1 WHERE bot_id = ? AND code = ?',
        'is',
        [$botId, strtoupper(trim($code))]
    );
}
