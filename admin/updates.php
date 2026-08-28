<?php
/**
 * Legacy "Publish Update" page — superseded by /admin/announcements, which
 * publishes system updates (same publish_system_update pipeline) plus richer
 * audience/channel/scheduling controls. Kept as a redirect so old links resolve.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
redirect('/admin/announcements');
