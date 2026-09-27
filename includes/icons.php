<?php
// Attach the local SVG renderer to HTML responses only; JSON and downloads stay untouched.
require_once __DIR__.'/config.php';
function ui_icon(string $name): string {
    $paths = [
        'applications' => 'M9 5H5v16h14V5h-4M9 3h6v4H9zM8 12h8M8 16h6',
        'clock' => 'M21 12a9 9 0 11-18 0 9 9 0 0118 0M12 6v6l4 2',
        'check' => 'M5 12l4 4L19 6',
        'wallet' => 'M3 5h18v16H3V5M3 5l14-3v3M15 11h6v6h-6v-6M17 14h1',
        'plus' => 'M12 5v14M5 12h14',
        'mail' => 'M2 5h20v14H2V5M2 5l10 8L22 5',
        'file' => 'M14 2H4v20h16V8l-6-6v6h6M8 12h8M8 16h6',
        'calendar' => 'M3 5h18v16H3V5M3 10h18M7 2v6M17 2v6M7 14h3M14 14h3M7 18h3',
        'qr' => 'M3 3h6v6H3V3M15 3h6v6h-6V3M3 15h6v6H3v-6M15 15h2v2h-2v-2M19 15h2v6h-6v-2M12 3v3M3 12h6M12 9v6h-1M12 19v2',
    ];
    static $sharedPaths = null;
    $sharedPaths ??= json_decode(file_get_contents(__DIR__.'/icon_paths.json'), true);
    $paths += $sharedPaths;
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="ui-icon" data-icon="'.$name.'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1.1em" height="1.1em" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="'.$paths[$name].'"></path></svg>';
}
ob_start(function (string $output): string {
    if (!preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i', $output)) {
        return $output;
    }
    $source = htmlspecialchars(app_url('assets/js/icons.js'), ENT_QUOTES, 'UTF-8');
    return preg_replace('/<\/head>/i', '<script src="'.$source.'" defer></script></head>', $output, 1);
});
