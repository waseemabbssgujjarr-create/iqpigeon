<?php
/**
 * AI CEO — temporarily disabled.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
redirect('/client/dashboard?notice=ai_ceo_temp_disabled');
