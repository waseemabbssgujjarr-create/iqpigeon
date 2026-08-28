<?php
/**
 * Team management lives in Settings → Team Members.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
redirect('/client/settings?tab=team');
