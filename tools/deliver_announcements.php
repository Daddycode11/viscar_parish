<?php
/** Run every minute from the hosting scheduler; only pending deliveries are claimed. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/announcement_delivery.php';
$lock = fopen(private_path('announcement-worker.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit; }
try {
    $ids = $conn->query("SELECT DISTINCT announcement_id FROM announcement_deliveries WHERE status='queued' ORDER BY announcement_id LIMIT 10");
    foreach ($ids as $row) dispatch_announcement((int) $row['announcement_id'], 20);
    $counts = $conn->query("SELECT status,COUNT(*) total FROM announcement_deliveries GROUP BY status")->fetch_all(MYSQLI_ASSOC);
    echo json_encode($counts) . PHP_EOL;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
