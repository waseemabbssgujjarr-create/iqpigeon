<?php
/**
 * Stripe webhook handler.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/mailer.php';

$payload = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;

$event = stripe_verify_webhook($payload, $sigHeader);

if (!$event) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$type = $event['type'] ?? '';
$data = $event['data']['object'] ?? [];

switch ($type) {
    case 'customer.subscription.created':
    case 'customer.subscription.updated':
        $customerId = $data['customer'] ?? '';
        $subscriptionId = $data['id'] ?? '';
        $status = $data['status'] ?? 'active';
        $plan = $data['metadata']['plan'] ?? 'starter';

        $mappedStatus = 'active';
        switch ($status) {
            case 'trialing':
                $mappedStatus = 'trialing';
                break;
            case 'active':
                $mappedStatus = 'active';
                break;
            case 'past_due':
                $mappedStatus = 'past_due';
                break;
            case 'canceled':
            case 'unpaid':
                $mappedStatus = 'canceled';
                break;
        }

        db_execute(
            'UPDATE users SET stripe_subscription_id = ?, subscription_status = ?, subscription_plan = ?
             WHERE stripe_customer_id = ?',
            'ssss',
            [$subscriptionId, $mappedStatus, $plan, $customerId]
        );
        break;

    case 'customer.subscription.deleted':
        $customerId = $data['customer'] ?? '';
        db_execute(
            'UPDATE users SET subscription_status = \'canceled\', stripe_subscription_id = NULL WHERE stripe_customer_id = ?',
            's',
            [$customerId]
        );
        break;

    case 'invoice.payment_failed':
        $customerId = $data['customer'] ?? '';
        db_execute(
            'UPDATE users SET subscription_status = \'past_due\' WHERE stripe_customer_id = ?',
            's',
            [$customerId]
        );

        $user = db_fetch('SELECT email FROM users WHERE stripe_customer_id = ?', 's', [$customerId]);
        if ($user) {
            email_payment_failed($user['email']);
        }
        break;

    case 'checkout.session.completed':
        $customerId = $data['customer'] ?? '';
        $subscriptionId = $data['subscription'] ?? '';
        $plan = $data['metadata']['plan'] ?? 'starter';

        if ($customerId) {
            db_execute(
                'UPDATE users SET stripe_subscription_id = ?, subscription_status = \'active\', subscription_plan = ?
                 WHERE stripe_customer_id = ?',
                'sss',
                [$subscriptionId, $plan, $customerId]
            );
        }
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
