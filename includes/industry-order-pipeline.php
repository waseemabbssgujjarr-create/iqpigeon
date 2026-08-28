<?php
/**
 * Order kanban pipeline — labels & behavior synced with Training industry (bots.industry_key).
 */
declare(strict_types=1);

require_once __DIR__ . '/industry-templates.php';
require_once __DIR__ . '/db.php';

/**
 * @return array{
 *   industry_key: string,
 *   industry_label: string,
 *   page_subtitle: string,
 *   sync_hint: string,
 *   show_courier: bool,
 *   show_catalog: bool,
 *   requires_shipment: bool,
 *   status_order: list<string>,
 *   columns: array<string, array{label: string, icon: string, color: string, action_label?: string}>,
 *   whatsapp: array<string, string>
 * }
 */
function industry_order_pipeline(?string $industryKey = null): array
{
    $key = trim((string) $industryKey);
    if ($key === '') {
        $key = 'default';
    }

    $tpl = industry_template($key);
    $industryLabel = $tpl['label'] ?? 'General';

    $pipelines = [
        'restaurant' => [
            'page_subtitle'    => 'Food orders · COD · drag to update',
            'sync_hint'        => 'Missing a WhatsApp food order?',
            'show_courier'     => false,
            'show_catalog'     => true,
            'requires_shipment'=> false,
            'columns'          => [
                'new'       => ['label' => 'New order', 'icon' => 'receipt_long', 'color' => 'text-on-surface-variant', 'action_label' => 'Start preparing'],
                'confirmed' => ['label' => 'Preparing', 'icon' => 'skillet', 'color' => 'text-primary', 'action_label' => 'Out for delivery'],
                'shipped'   => ['label' => 'Out for delivery', 'icon' => 'two_wheeler', 'color' => 'text-secondary', 'action_label' => 'Mark delivered'],
                'delivered' => ['label' => 'Delivered', 'icon' => 'check_circle', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Order #{id} confirmed!\nTotal: {total} (COD)\nWe're preparing your food now. 🍕",
                'shipped'   => "🛵 Order #{id} is out for delivery!\nTotal: {total}\nETA: 30–50 minutes.",
                'delivered' => "📦 Order #{id} delivered.\nThank you for ordering with us!",
            ],
        ],
        'ecommerce' => [
            'page_subtitle'     => 'COD pipeline · drag to update',
            'sync_hint'         => 'Missing a WhatsApp order?',
            'show_courier'      => true,
            'show_catalog'      => true,
            'requires_shipment' => true,
            'columns'           => [
                'new'       => ['label' => 'New', 'icon' => 'fiber_new', 'color' => 'text-on-surface-variant', 'action_label' => 'Confirm order'],
                'confirmed' => ['label' => 'Confirmed', 'icon' => 'check_circle', 'color' => 'text-primary', 'action_label' => 'Mark shipped'],
                'shipped'   => ['label' => 'Shipped', 'icon' => 'local_shipping', 'color' => 'text-secondary', 'action_label' => 'Mark delivered'],
                'delivered' => ['label' => 'Delivered', 'icon' => 'inventory_2', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Order #{id} confirmed!\nTotal: {total} (COD)\nWe'll prepare it soon.",
                'shipped'   => "🚚 Order #{id} has shipped!\nTotal: {total}\nIt should arrive soon.",
                'delivered' => "📦 Order #{id} delivered.\nThank you for shopping with us!",
            ],
        ],
        'services' => [
            'page_subtitle'     => 'Bookings & projects · drag to update',
            'sync_hint'         => 'Missing a WhatsApp booking?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New lead', 'icon' => 'inbox', 'color' => 'text-on-surface-variant', 'action_label' => 'Confirm booking'],
                'confirmed' => ['label' => 'Booked', 'icon' => 'event_available', 'color' => 'text-primary', 'action_label' => 'In progress'],
                'shipped'   => ['label' => 'In progress', 'icon' => 'engineering', 'color' => 'text-secondary', 'action_label' => 'Complete'],
                'delivered' => ['label' => 'Completed', 'icon' => 'task_alt', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Booking confirmed (#{id}).\nEstimated value: {total}\nWe'll follow up with next steps.",
                'shipped'   => "🔧 Your project #{id} is in progress.\nWe'll update you when it's ready.",
                'delivered' => "✨ Project #{id} completed.\nThank you for working with us!",
            ],
        ],
        'saas' => [
            'page_subtitle'     => 'Trials & subscriptions · drag to update',
            'sync_hint'         => 'Missing a WhatsApp signup?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New trial', 'icon' => 'science', 'color' => 'text-on-surface-variant', 'action_label' => 'Activate trial'],
                'confirmed' => ['label' => 'Active trial', 'icon' => 'play_circle', 'color' => 'text-primary', 'action_label' => 'Onboarding'],
                'shipped'   => ['label' => 'Onboarding', 'icon' => 'rocket_launch', 'color' => 'text-secondary', 'action_label' => 'Subscribed'],
                'delivered' => ['label' => 'Subscribed', 'icon' => 'verified', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "🎉 Trial activated (#{id}).\nPlan value: {total}\nCheck your email for login details.",
                'shipped'   => "🚀 Onboarding started for #{id}.\nReply here if you need setup help.",
                'delivered' => "✅ Subscription active (#{id}).\nWelcome aboard!",
            ],
        ],
        'local' => [
            'page_subtitle'     => 'Walk-ins & appointments · drag to update',
            'sync_hint'         => 'Missing a WhatsApp request?',
            'show_courier'      => false,
            'show_catalog'      => true,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New request', 'icon' => 'storefront', 'color' => 'text-on-surface-variant', 'action_label' => 'Confirm'],
                'confirmed' => ['label' => 'Scheduled', 'icon' => 'schedule', 'color' => 'text-primary', 'action_label' => 'In service'],
                'shipped'   => ['label' => 'In service', 'icon' => 'handyman', 'color' => 'text-secondary', 'action_label' => 'Done'],
                'delivered' => ['label' => 'Completed', 'icon' => 'done_all', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Appointment confirmed (#{id}).\nSee you soon!",
                'shipped'   => "We're serving your request now (#{id}).",
                'delivered' => "Thanks for visiting us! (#{id})",
            ],
        ],
        'health' => [
            'page_subtitle'     => 'Appointments · drag to update',
            'sync_hint'         => 'Missing a WhatsApp appointment?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New request', 'icon' => 'medical_services', 'color' => 'text-on-surface-variant', 'action_label' => 'Confirm appt'],
                'confirmed' => ['label' => 'Confirmed', 'icon' => 'event_available', 'color' => 'text-primary', 'action_label' => 'In clinic'],
                'shipped'   => ['label' => 'In progress', 'icon' => 'healing', 'color' => 'text-secondary', 'action_label' => 'Complete'],
                'delivered' => ['label' => 'Completed', 'icon' => 'check_circle', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Appointment #{id} confirmed.\nFee: {total}\nPlease arrive 10 minutes early.",
                'shipped'   => "Your visit #{id} is in progress.",
                'delivered' => "Visit #{id} completed. Take care!",
            ],
        ],
        'education' => [
            'page_subtitle'     => 'Enrollments · drag to update',
            'sync_hint'         => 'Missing a WhatsApp enrollment?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New inquiry', 'icon' => 'school', 'color' => 'text-on-surface-variant', 'action_label' => 'Enroll'],
                'confirmed' => ['label' => 'Enrolled', 'icon' => 'how_to_reg', 'color' => 'text-primary', 'action_label' => 'In session'],
                'shipped'   => ['label' => 'In session', 'icon' => 'menu_book', 'color' => 'text-secondary', 'action_label' => 'Graduate'],
                'delivered' => ['label' => 'Completed', 'icon' => 'emoji_events', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Enrollment #{id} confirmed.\nFee: {total}\nWelcome to the program!",
                'shipped'   => "Your sessions for #{id} are underway.",
                'delivered' => "Program #{id} completed. Congratulations!",
            ],
        ],
        'realestate' => [
            'page_subtitle'     => 'Property pipeline · drag to update',
            'sync_hint'         => 'Missing a WhatsApp inquiry?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New inquiry', 'icon' => 'home', 'color' => 'text-on-surface-variant', 'action_label' => 'Visit booked'],
                'confirmed' => ['label' => 'Visit booked', 'icon' => 'calendar_month', 'color' => 'text-primary', 'action_label' => 'Under offer'],
                'shipped'   => ['label' => 'Under offer', 'icon' => 'gavel', 'color' => 'text-secondary', 'action_label' => 'Closed'],
                'delivered' => ['label' => 'Closed', 'icon' => 'key', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Property visit confirmed (#{id}).\nBudget: {total}\nAgent will meet you on site.",
                'shipped'   => "Offer in progress for inquiry #{id}.",
                'delivered' => "Deal #{id} closed. Congratulations!",
            ],
        ],
        'automotive' => [
            'page_subtitle'     => 'Sales & service · drag to update',
            'sync_hint'         => 'Missing a WhatsApp inquiry?',
            'show_courier'      => false,
            'show_catalog'      => true,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New inquiry', 'icon' => 'directions_car', 'color' => 'text-on-surface-variant', 'action_label' => 'Book test drive'],
                'confirmed' => ['label' => 'Test drive', 'icon' => 'event', 'color' => 'text-primary', 'action_label' => 'Processing'],
                'shipped'   => ['label' => 'Processing', 'icon' => 'build', 'color' => 'text-secondary', 'action_label' => 'Delivered'],
                'delivered' => ['label' => 'Delivered', 'icon' => 'key', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Test drive booked (#{id}).\nValue: {total}\nSee you at the showroom!",
                'shipped'   => "Your vehicle/service #{id} is being processed.",
                'delivered' => "Vehicle #{id} delivered. Drive safe!",
            ],
        ],
        'travel' => [
            'page_subtitle'     => 'Bookings · drag to update',
            'sync_hint'         => 'Missing a WhatsApp booking?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New inquiry', 'icon' => 'flight', 'color' => 'text-on-surface-variant', 'action_label' => 'Confirm booking'],
                'confirmed' => ['label' => 'Booked', 'icon' => 'confirmation_number', 'color' => 'text-primary', 'action_label' => 'Documents sent'],
                'shipped'   => ['label' => 'Documents sent', 'icon' => 'description', 'color' => 'text-secondary', 'action_label' => 'Trip complete'],
                'delivered' => ['label' => 'Completed', 'icon' => 'luggage', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Travel booking #{id} confirmed.\nPackage: {total}\nItinerary coming shortly.",
                'shipped'   => "Travel documents for #{id} have been sent.",
                'delivered' => "Trip #{id} completed. Safe travels!",
            ],
        ],
        'b2b' => [
            'page_subtitle'     => 'Wholesale orders · COD · drag to update',
            'sync_hint'         => 'Missing a WhatsApp order?',
            'show_courier'      => true,
            'show_catalog'      => true,
            'requires_shipment' => true,
            'columns'           => [
                'new'       => ['label' => 'New PO', 'icon' => 'fiber_new', 'color' => 'text-on-surface-variant', 'action_label' => 'Confirm PO'],
                'confirmed' => ['label' => 'Confirmed', 'icon' => 'check_circle', 'color' => 'text-primary', 'action_label' => 'Dispatch'],
                'shipped'   => ['label' => 'Dispatched', 'icon' => 'local_shipping', 'color' => 'text-secondary', 'action_label' => 'Delivered'],
                'delivered' => ['label' => 'Delivered', 'icon' => 'inventory_2', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Purchase order #{id} confirmed.\nTotal: {total} (COD/terms as agreed)",
                'shipped'   => "🚚 PO #{id} dispatched.\nTotal: {total}",
                'delivered' => "📦 PO #{id} delivered. Thank you for your business.",
            ],
        ],
        'freelancer' => [
            'page_subtitle'     => 'Projects · drag to update',
            'sync_hint'         => 'Missing a WhatsApp project?',
            'show_courier'      => false,
            'show_catalog'      => false,
            'requires_shipment' => false,
            'columns'           => [
                'new'       => ['label' => 'New brief', 'icon' => 'inbox', 'color' => 'text-on-surface-variant', 'action_label' => 'Accept project'],
                'confirmed' => ['label' => 'Accepted', 'icon' => 'thumb_up', 'color' => 'text-primary', 'action_label' => 'In progress'],
                'shipped'   => ['label' => 'In progress', 'icon' => 'edit_note', 'color' => 'text-secondary', 'action_label' => 'Delivered'],
                'delivered' => ['label' => 'Delivered', 'icon' => 'send', 'color' => 'text-tertiary'],
            ],
            'whatsapp' => [
                'confirmed' => "✅ Project #{id} accepted.\nQuote: {total}\nI'll share the first draft soon.",
                'shipped'   => "🎨 Working on project #{id} now.",
                'delivered' => "✨ Project #{id} delivered. Let me know if you need revisions!",
            ],
        ],
    ];

    $config = $pipelines[$key] ?? $pipelines['ecommerce'];

    if ($key === 'default') {
        $config = $pipelines['ecommerce'];
        $industryLabel = 'All industries';
    }

    return [
        'industry_key'      => $key,
        'industry_label'    => $industryLabel,
        'page_subtitle'     => $config['page_subtitle'],
        'sync_hint'         => $config['sync_hint'],
        'show_courier'      => $config['show_courier'],
        'show_catalog'      => $config['show_catalog'],
        'requires_shipment' => $config['requires_shipment'],
        'status_order'      => ['new', 'confirmed', 'shipped', 'delivered'],
        'columns'           => $config['columns'],
        'whatsapp'          => $config['whatsapp'],
    ];
}

/**
 * @param array<string, mixed>|null $bot
 * @return array<string, mixed>
 */
function industry_order_pipeline_for_bot(?array $bot): array
{
    if (!$bot) {
        return industry_order_pipeline('default');
    }

    $key = trim((string) ($bot['industry_key'] ?? ''));
    if ($key === '') {
        $mode = (string) ($bot['business_mode'] ?? 'mixed');
        $key = match ($mode) {
            'ecommerce' => 'ecommerce',
            'services'  => 'services',
            'saas'      => 'saas',
            default     => 'ecommerce',
        };
    }

    return industry_order_pipeline($key);
}

function industry_order_status_label(string $status, ?string $industryKey = null): string
{
    $pipeline = industry_order_pipeline($industryKey);
    $col = $pipeline['columns'][$status] ?? null;

    if (is_array($col)) {
        return (string) $col['label'];
    }

    require_once __DIR__ . '/cart.php';

    return catalog_order_status_label($status);
}

function industry_order_next_action_label(string $currentStatus, ?string $industryKey = null): ?string
{
    require_once __DIR__ . '/cart.php';

    $next = catalog_order_next_status($currentStatus);
    if ($next === null) {
        return null;
    }

    $pipeline = industry_order_pipeline($industryKey);
    $col = $pipeline['columns'][$next] ?? null;

    if (is_array($col) && !empty($col['action_label'])) {
        return (string) $col['action_label'];
    }

    return industry_order_status_label($next, $industryKey);
}

function industry_order_requires_shipment(string $status, ?string $industryKey = null): bool
{
    if ($status !== 'shipped') {
        return false;
    }

    return industry_order_pipeline($industryKey)['requires_shipment'];
}

/**
 * @param array<string, mixed> $order
 */
function industry_order_whatsapp_message(string $newStatus, array $order, ?string $industryKey = null): string
{
    $pipeline = industry_order_pipeline($industryKey);
    $template = $pipeline['whatsapp'][$newStatus] ?? '';
    $label = industry_order_status_label($newStatus, $industryKey);

    if ($template === '') {
        return 'Order #' . (int) $order['id'] . ' — ' . $label;
    }

    require_once __DIR__ . '/catalog.php';
    require_once __DIR__ . '/cart.php';
    $total = catalog_format_price((float) $order['total_amount'], (string) ($order['currency'] ?? 'PKR'));
    $customerName = trim((string) ($order['customer_name'] ?? ''));
    if ($customerName === '') {
        $customerName = 'there';
    }
    $itemsLine = catalog_order_items_line((int) $order['id']);

    if (!str_contains($template, '{label}')) {
        $template = "📋 {label}\n\n" . $template;
    }
    if ($itemsLine !== '' && !str_contains($template, '{items}')) {
        $template .= "\n\n🛒 {items}";
    }

    return str_replace(
        ['{id}', '{total}', '{label}', '{customer_name}', '{items}'],
        [(string) (int) $order['id'], $total, $label, $customerName, $itemsLine],
        $template
    );
}

/**
 * Resolve industry key for an order row.
 */
function industry_order_key_for_order(array $order): string
{
    $botId = (int) ($order['bot_id'] ?? 0);
    if ($botId <= 0) {
        return 'default';
    }

    $bot = db_fetch('SELECT industry_key, business_mode FROM bots WHERE id = ?', 'i', [$botId]);

    return industry_order_pipeline_for_bot($bot ?: null)['industry_key'];
}
