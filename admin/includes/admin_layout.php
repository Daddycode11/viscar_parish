<?php
// Single file that loads head, sidebar, topbar
// Usage: set $page_title, $page_sub, $page_css before including
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'Admin') ?> — Apostolic Vicariate</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/admin.css">
<?php if(!empty($page_css)) echo $page_css; ?>
</head>
<body>
<div class="overlay" id="overlay"></div>
<?php require_once 'sidebar.php'; ?>
<?php require_once 'topbar.php'; ?>
<main class="main">
<div class="page-content"></div>