<?php
// layout_open.php
// Define base URL for assets (adjust if your project folder changes)
$base_url = '/parish-platform';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'Admin') ?> — Apostolic Vicariate</title>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

<!-- Admin CSS -->
<link rel="stylesheet" href="<?= $base_url ?>/loads/assets/css/admin.css">

<?php if (!empty($extra_css)) echo $extra_css; ?>
</head>
<body>

<div class="overlay" id="overlay"></div>

<?php 
// Include PHP components using server paths
require_once __DIR__ . '/sidebar.php'; 
require_once __DIR__ . '/topbar.php';  
?>

<main class="main">
<div class="pg">

<!-- Your page content goes here -->

</div>
</main>

<!-- Admin JS -->
<script src="<?= $base_url ?>/loads/assets/js/admin.js"></script>

<?php if (!empty($extra_js)) echo $extra_js; ?>
</body>
</html>