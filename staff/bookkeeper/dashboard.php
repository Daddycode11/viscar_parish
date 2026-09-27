<?php
require_once __DIR__ . '/../../includes/access.php';
if ($user['role'] !== 'bookkeeper') { fail_request('Bookkeeper access required.'); }
header('Location: ' . app_url('staff/dashboard.php'));
exit;
