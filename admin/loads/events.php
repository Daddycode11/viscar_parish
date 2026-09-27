<?php
require_once __DIR__ . '/../../includes/access.php';
require_once __DIR__ . '/../../includes/workflow_routes.php';

// Returns all parish events as JSON for calendar
include '../../includes/db.php';
header('Content-Type: application/json');
$events = [];
$r = $conn->query("SELECT id, title, start, end, description FROM events ORDER BY start ASC");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $events[] = [
      'id' => $row['id'],
      'title' => $row['title'],
      'start' => $row['start'],
      'end' => $row['end'],
      'description' => $row['description']
    ];
  }
}
echo json_encode($events);
