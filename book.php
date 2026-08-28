<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/booking.php';

$slug = trim($_GET['slug'] ?? '');
$bot = $slug !== '' ? booking_bot_by_slug($slug) : null;
$message = '';
$error = '';
$booked = false;

if ($bot && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $slotIdx = (int) ($_POST['slot'] ?? -1);
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '' || $phone === '') {
        $error = 'Name and phone are required.';
    } else {
        $slots = booking_next_slots((int) $bot['id'], 12);
        if (!isset($slots[$slotIdx])) {
            $error = 'Please pick a valid time slot.';
        } else {
            $slot = $slots[$slotIdx];
            booking_create_public_appointment(
                (int) $bot['id'],
                (int) $bot['user_id'],
                $slot['start'],
                $slot['end'],
                $name,
                $phone,
                $notes !== '' ? $notes : 'Booked via public page'
            );
            $booked = true;
            $message = 'Booked for ' . $slot['label'] . '. We will confirm on WhatsApp if connected.';
        }
    }
}

$slots = $bot ? booking_next_slots((int) $bot['id'], 12) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head($bot ? 'Book with ' . ($bot['name'] ?? APP_NAME) : 'Booking') ?>
<?= marketing_assets() ?>
</head>
<body class="marketing-page marketing-page--hippo marketing-page--v2 font-body v2-mkt-page">
<div class="v2-mkt-page__layers" aria-hidden="true"><div class="v2-section__gradient"></div></div>
<main class="v2-mkt-page__main">
    <?php if (!$bot): ?>
    <div class="v2-mkt-success fade-up">
        <span class="material-symbols-outlined text-5xl text-outline mb-md">event_busy</span>
        <h1 class="v2-mkt-hero__title">Booking not available</h1>
        <p class="v2-mkt-hero__lead">This booking link is invalid or scheduling is disabled.</p>
        <a href="/" class="v2-btn-pill v2-btn-pill--ghost hip-btn hip-btn--outline mt-lg">← Home</a>
    </div>
    <?php elseif ($booked): ?>
    <div class="v2-card v2-mkt-success fade-up">
        <span class="material-symbols-outlined text-5xl text-primary mb-md">event_available</span>
        <h1 class="v2-section-title hip-title">You're booked!</h1>
        <p class="v2-section-lead hip-lead hip-lead--center"><?= sanitize($message) ?></p>
    </div>
    <?php else: ?>
    <div class="text-center mb-lg fade-up">
        <span class="v2-eyebrow">Booking</span>
        <h1 class="v2-mkt-hero__title">Book with <?= sanitize($bot['name']) ?></h1>
        <p class="v2-mkt-hero__lead"><?= (int) (booking_settings_for_bot((int) $bot['id'])['slot_duration_min'] ?? 30) ?> min slot — pick a time below.</p>
    </div>

    <?php if ($error): ?><p class="v2-mkt-alert v2-mkt-alert--error text-center"><?= sanitize($error) ?></p><?php endif; ?>

    <?php if ($slots === []): ?>
    <p class="text-center v2-section-lead hip-lead">No slots available right now. Try again later.</p>
    <?php else: ?>
    <form method="POST" class="v2-card v2-mkt-form mkt-form fade-up">
        <div class="mkt-form__group">
            <label class="mkt-form__label" for="book-name">Your name</label>
            <input id="book-name" name="name" required class="mkt-input"/>
        </div>
        <div class="mkt-form__group">
            <label class="mkt-form__label" for="book-phone">Phone / WhatsApp</label>
            <input id="book-phone" name="phone" type="tel" required placeholder="03XXXXXXXXX" class="mkt-input"/>
        </div>
        <div class="mkt-form__group">
            <label class="mkt-form__label" for="book-notes">Notes (optional)</label>
            <textarea id="book-notes" name="notes" rows="2" class="mkt-textarea"></textarea>
        </div>
        <fieldset class="mkt-form__group">
            <legend class="mkt-form__label">Available times</legend>
            <div class="space-y-xs max-h-64 overflow-y-auto">
                <?php foreach ($slots as $i => $slot): ?>
                <label class="flex items-center gap-sm p-sm rounded-xl border border-outline-variant cursor-pointer">
                    <input type="radio" name="slot" value="<?= $i ?>" required class="text-primary"/>
                    <span class="text-body-md"><?= sanitize($slot['label']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button type="submit" class="v2-btn-pill v2-btn-pill--primary hip-btn hip-btn--primary hip-btn--block">Confirm booking</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
