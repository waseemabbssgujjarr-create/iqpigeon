<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$user = get_user();
$role = $user['role'] ?? 'client';
logout();
redirect($role === 'admin' ? admin_login_url() : '/login.php');
