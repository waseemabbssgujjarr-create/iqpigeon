<?php
/**
 * Business outcome / conversion model — tenant-configured, customer-need-aware.
 */
declare(strict_types=1);

/** @return list<string> */
function agent_business_outcome_catalog(): array
{
    return [
        'sell_product', 'sell_service', 'book_appointment', 'book_consultation',
        'book_demo', 'capture_lead', 'request_quote', 'request_estimate',
        'property_viewing', 'service_visit', 'table_reservation', 'class_registration',
        'course_enrollment', 'admission_inquiry', 'job_application', 'callback_request',
        'human_handoff', 'resolve_support', 'track_order', 'process_return',
        'process_refund', 'start_trial', 'event_invitation', 'event_registration',
        'collect_documents', 'qualify_lead', 'provide_information', 'recommend_product',
        'complete_conversation', 'none',
    ];
}

/**
 * @param array<string, mixed> $bot
 * @return array{primary: string, secondary: list<string>, mode: string, goal_key: string}
 */
function business_outcome_profile_for_bot(array $bot): array
{
    require_once dirname(__DIR__) . '/lead-lifecycle.php';
    $mode = bot_business_mode($bot);
    $goalKey = bot_conversion_goal($bot);

    $primary = match ($goalKey) {
        'order_placed'  => 'sell_product',
        'trial_started' => 'start_trial',
        default         => 'book_appointment',
    };

    $secondary = match ($mode) {
        'ecommerce' => ['recommend_product', 'capture_lead'],
        'services'  => ['book_consultation', 'capture_lead'],
        'saas'      => ['book_demo', 'start_trial'],
        default     => ['capture_lead', 'provide_information'],
    };

    return [
        'primary'    => $primary,
        'secondary'  => $secondary,
        'mode'       => $mode,
        'goal_key'   => $goalKey,
    ];
}

/**
 * Map customer need to aligned business outcome opportunity (never overrides support/stop).
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $bot
 */
function agent_core_business_outcome_for_turn(array $plan, array $bot): string
{
    $need = trim((string) ($plan['customer_need'] ?? ''));
    $goal = trim((string) ($plan['customer_goal'] ?? ''));
    $profile = business_outcome_profile_for_bot($bot);

    if ($goal === 'stop' || $need === 'social') {
        return 'complete_conversation';
    }
    if (in_array($need, ['resolve_delivery', 'obtain_refund', 'personal_support', 'resolve_payment', 'resolve_refund'], true)) {
        return 'resolve_support';
    }
    if ($need === 'invite_speaker') {
        return 'event_invitation';
    }
    if ($need === 'book_appointment') {
        return 'book_appointment';
    }
    if ($need === 'learn_pricing') {
        return in_array($profile['primary'], ['sell_product', 'sell_service'], true)
            ? 'request_quote'
            : 'provide_information';
    }
    if ($need === 'complete_purchase' || $need === 'find_product') {
        return 'sell_product';
    }
    if ($need === 'speak_to_human') {
        return 'human_handoff';
    }

    return $profile['primary'];
}
