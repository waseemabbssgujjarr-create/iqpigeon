<?php
/**
 * One-time: set DEFAULT_CALENDLY_LINK on all bots missing a booking URL.
 * Run once via browser, then delete this file.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: text/plain; charset=utf-8');

if (!defined('DEFAULT_CALENDLY_LINK') || DEFAULT_CALENDLY_LINK === '') {
    echo "DEFAULT_CALENDLY_LINK is not set in config.php\n";
    exit(1);
}

$link = trim(DEFAULT_CALENDLY_LINK);
$updated = db_execute(
    "UPDATE bots SET calendly_link = ? WHERE calendly_link IS NULL OR TRIM(calendly_link) = ''",
    's',
    [$link]
);

$count = db_fetch('SELECT COUNT(*) AS c FROM bots WHERE calendly_link = ?', 's', [$link]);
echo "Updated bots with empty Calendly link.\n";
echo "Booking URL: {$link}\n";
echo "Bots now using this link: " . (int) ($count['c'] ?? 0) . "\n";
echo "\nDelete sync-calendly.php after running.\n";
