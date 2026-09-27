<?php
function t(string $text): string
{
    static $filipino;
    $filipino ??= require __DIR__ . '/translations/fil.php';
    $language = $_SESSION['user']['language'] ?? $_SESSION['language'] ?? 'en';
    return $language === 'fil' ? ($filipino[$text] ?? $text) : $text;
}
