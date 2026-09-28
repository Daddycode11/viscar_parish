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
    $isDashboard = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php';
    $escape = fn ($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    return '<link rel="stylesheet" href="' . $escape(app_url('assets/css/navigation.css')) . '">'
        . '<script defer src="' . $escape(app_url('assets/js/navigation.js')) . '"></script>'
        . ($isDashboard ? '' : '<nav class="return-navigation no-print" aria-label="Return navigation">'
        . '<a class="return-link" data-app-back aria-label="Go back" title="Go back" href="' . $escape(app_url($fallback)) . '">&larr;</a></nav>');
}
