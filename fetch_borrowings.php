<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit;
}

require_once 'config.php';

$stmt = $pdo->query("
    SELECT b.borrow_date, e.name AS equipment_name, e.image, u.username
    FROM borrowings b
    JOIN equipment e ON b.equipment_id = e.id
    JOIN users u ON b.user_id = u.id
    WHERE b.status = 'borrowed'
");
$borrowings = $stmt->fetchAll();

$events = [];
foreach ($borrowings as $borrowing) {
    $events[] = [
        'title' => 'Equipment: ' . htmlspecialchars($borrowing['equipment_name']),
        'start' => $borrowing['borrow_date'],
        'color' => '#722ff9', // Purple to match theme
        'extendedProps' => [
            'username' => htmlspecialchars($borrowing['username']),
            'image' => $borrowing['image'] ? htmlspecialchars($borrowing['image']) : 'default.jpg'
        ]
    ];
}

header('Content-Type: application/json');
echo json_encode($events);
?>
