<?php
/**
 * Universal business capability registry — industry-independent, tenant-scoped.
 * Distinguishes: configured vs available vs tool-backed execution.
 */
declare(strict_types=1);

/** @return array<string, array{label: string, tools: list<string>}> */
function agent_capability_registry(): array
{
    return [
        'catalog'           => ['label' => 'Product catalog', 'tools' => ['catalog.search', 'catalog.get_product']],
        'cart'              => ['label' => 'Cart / checkout', 'tools' => ['cart.view']],
        'ordering'          => ['label' => 'Order placement', 'tools' => ['order.place']],
        'pricing'           => ['label' => 'Verified pricing', 'tools' => []],
        'quotation'         => ['label' => 'Quote requests', 'tools' => []],
        'booking'           => ['label' => 'Native appointment booking', 'tools' => ['booking.availability', 'booking.offer', 'booking.create']],
        'scheduling'        => ['label' => 'Scheduling / slots', 'tools' => ['booking.availability', 'booking.offer']],
        'order_tracking'    => ['label' => 'Order tracking', 'tools' => ['orders.read']],
        'returns'           => ['label' => 'Returns / refunds', 'tools' => []],
        'lead_capture'      => ['label' => 'Lead capture', 'tools' => []],
        'qualification'     => ['label' => 'Qualification', 'tools' => ['qualification.update']],
        'human_handoff'     => ['label' => 'Human handoff', 'tools' => ['human_handoff.create']],
        'event_invitation'  => ['label' => 'Event / speaker invitations', 'tools' => []],
        'event_registration'=> ['label' => 'Event registration', 'tools' => []],
        'document_collection' => ['label' => 'Document collection', 'tools' => []],
        'live_web'          => ['label' => 'Live web evidence', 'tools' => ['live_web.search']],
        'hours'             => ['label' => 'Business hours', 'tools' => ['hours.read']],
    ];
}

/**
 * Capability states: available | unavailable | configured | not_configured | requires_human | disabled
 *
 * @param array<string, mixed> $bot
 * @return array<string, string>
 */
function business_capability_states_for_bot(array $bot): array
{
    $botId = (int) ($bot['id'] ?? 0);
    $states = [];
    foreach (array_keys(agent_capability_registry()) as $cap) {
        $states[$cap] = 'unavailable';
    }

    try {
        require_once dirname(__DIR__) . '/bot-knowledge.php';
        require_once dirname(__DIR__) . '/lead-lifecycle.php';
    } catch (Throwable $e) {
    }

    $skipDb = !empty($GLOBALS['agent_core_no_network']);

    if (!$skipDb && $botId > 0 && is_file(dirname(__DIR__) . '/catalog.php')) {
        try {
            require_once dirname(__DIR__) . '/catalog.php';
            if (function_exists('catalog_bot_has_products') && catalog_bot_has_products($botId)) {
                $states['catalog'] = 'available';
                $states['cart'] = 'available';
                $states['ordering'] = 'configured';
                $states['pricing'] = 'available';
            }
        } catch (Throwable $e) {
        }
    }
    if (!$skipDb && function_exists('bot_uses_shop_catalog') && bot_uses_shop_catalog($bot)) {
        $states['catalog'] = 'available';
        $states['cart'] = 'available';
        $states['ordering'] = 'configured';
    }

    if (!$skipDb && $botId > 0 && is_file(dirname(__DIR__) . '/booking.php')) {
        try {
            require_once dirname(__DIR__) . '/booking.php';
            $settings = booking_settings_for_bot($botId);
            if (!empty($settings['enabled'])) {
                $states['booking'] = 'available';
                $states['scheduling'] = 'available';
            } else {
                $states['booking'] = 'not_configured';
                $states['scheduling'] = 'not_configured';
            }
        } catch (Throwable $e) {
            $states['booking'] = 'not_configured';
            $states['scheduling'] = 'not_configured';
        }
    } elseif ($botId > 0) {
        $states['booking'] = 'not_configured';
        $states['scheduling'] = 'not_configured';
    }

    $meta = function_exists('bot_training_meta') ? bot_training_meta($bot) : [];
    if (!empty($meta['human_handoff']) || trim((string) ($bot['qualify_trigger'] ?? '')) !== '') {
        $states['human_handoff'] = 'configured';
    }
    if (!empty($meta['event_invitations']) || !empty($meta['speaker_invitations'])) {
        $states['event_invitation'] = 'configured';
    }

    $kb = trim((string) ($bot['bot_knowledge'] ?? '') . "\n" . (string) ($bot['business_model'] ?? ''));
    if ($kb !== '') {
        if (preg_match('/\b(invite|speaker|keynote|event invitation)\b/iu', $kb)) {
            $states['event_invitation'] = $states['event_invitation'] === 'unavailable' ? 'configured' : $states['event_invitation'];
        }
        if (preg_match('/\$\s*\d|rate\s*[:\-]|pricing|per hour|starting from/iu', $kb)) {
            $states['pricing'] = $states['pricing'] === 'unavailable' ? 'configured' : $states['pricing'];
        }
    }

    $states['live_web'] = 'available';
    $states['hours'] = 'configured';
    $states['lead_capture'] = 'available';
    $states['order_tracking'] = $states['catalog'] === 'available' ? 'configured' : 'not_configured';

    return $states;
}

/**
 * Flat list of capabilities the Agent may rely on for action selection.
 *
 * @param array<string, mixed> $bot
 * @return list<string>
 */
function business_capabilities_for_bot(array $bot): array
{
    $available = [];
    foreach (business_capability_states_for_bot($bot) as $cap => $state) {
        if (in_array($state, ['available', 'configured'], true)) {
            $available[] = $cap;
        }
    }

    return array_values(array_unique($available));
}

/**
 * @param array<string, mixed> $bot
 */
function business_capability_has(array $bot, string $capability): bool
{
    return in_array($capability, business_capabilities_for_bot($bot), true);
}
