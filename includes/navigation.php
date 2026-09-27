<?php
require_once __DIR__ . '/config.php';

/** Render a normal link first; JS enhances it only when safe history is available. */
function navigation_controls(?string $fallback = null): string
{
    $role = $_SESSION['user']['role'] ?? '';
    $fallback ??= match ($role) {
        'admin' => 'admin/dashboard.php',
        'secretary', 'bookkeeper' => 'staff/dashboard.php',
        'parishioner' => 'parishioner/dashboard.php',
        default => 'index.php',
    };
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php') $fallback = 'index.php';
    $escape = fn ($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    return '<link rel="stylesheet" href="' . $escape(app_url('assets/css/navigation.css')) . '">'
        . '<script defer src="' . $escape(app_url('assets/js/navigation.js')) . '"></script>'
        . '<nav class="return-navigation no-print" aria-label="Return navigation">'
        . '<a class="return-link" data-app-back href="' . $escape(app_url($fallback)) . '">&larr; Back</a></nav>';
}
