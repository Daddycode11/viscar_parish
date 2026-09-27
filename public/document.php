<?php
require_once __DIR__ . '/../includes/application_document.php';
$document = application_document();
$path = $document['path'];
$name = $document['name'];
$mime = $document['mime'];
$inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline && !isset($_GET['download']) ? 'inline' : 'attachment') . '; filename="document.' . preg_replace('/[^a-z0-9]/i', '', pathinfo($name, PATHINFO_EXTENSION)) . '"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');readfile($path);
