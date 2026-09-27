<?php
require_once __DIR__ . '/../includes/application_document.php';
$document = application_document();
$user = $document['user'];
$id = (int)$document['application']['id'];
$url = app_url('public/document.php?app=' . $id . ($document['attachment_id']?'&attachment='.$document['attachment_id']:'&key='.rawurlencode($document['key'])));
$fallback = match ($user['role']) {
    'admin' => 'admin/applications.php',
    'secretary' => 'staff/applications.php',
    default => 'parishioner/application.php?id=' . $id,
};
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Application document</title></head>
<body class="document-viewer"><div class="document-toolbar">
<?= navigation_controls($fallback) ?>
<a class="return-link" href="<?= h($url . '&download=1') ?>">Download document</a>
</div><h1>Application #<?= $id ?> ? Document</h1>
<?php if ($document['mime'] === 'application/pdf'): ?>
<object class="document-preview" data="<?= h($url) ?>" type="application/pdf"><p>Preview is unavailable in this browser. <a href="<?= h($url) ?>">Open PDF</a> or use Download document above.</p></object>
<?php elseif (in_array($document['mime'], ['image/png','image/jpeg'], true)): ?>
<img class="document-image" src="<?= h($url) ?>" alt="Application document">
<?php else: ?><p>A preview is unavailable for this file. Use Download document above.</p><?php endif; ?>
</body></html>
