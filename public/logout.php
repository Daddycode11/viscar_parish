<?php
require_once __DIR__ . '/../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    logoutUser();
    header('Location: ' . app_url('public/login.php'));
    exit;
}
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign Out</title></head><body><?= navigation_controls() ?>
<form method="post"><?= csrf_field() ?><button>Sign Out</button></form>
</body></html>
