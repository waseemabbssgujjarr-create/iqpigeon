<?php
/**
 * Team Inbox — merged into Leads (assign & priority live on each conversation).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/client/leads' . ($query !== '' ? '?' . $query : '');
redirect($target);
