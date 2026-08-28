<?php
/**
 * Booking settings — temporarily disabled (native booking coming later).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
redirect('/client/settings?notice=booking_coming_soon');
