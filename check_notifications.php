<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

try {
    // Check for new task reminders
    $new_reminders = checkAndCreateTaskReminders($user['id'], $database);
    
    // Get current unread count
    $unread_count = getUnreadNotificationCount($user['id'], $database);
    
    // Get latest notification
    $stmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $latest = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'new_reminders' => $new_reminders,
        'latest_notification' => $latest
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Failed to check notifications']);
}
?>