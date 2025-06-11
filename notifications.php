<?php
// notifications.php - Simple Notification & Reminder System for EduHive
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'mark_read':
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            markNotificationAsRead($notification_id, $user['id'], $db);
            break;
            
        case 'mark_all_read':
            markAllNotificationsAsRead($user['id'], $db);
            setMessage('All notifications marked as read!', 'success');
            break;
            
        case 'send_test_notification':
            createTestNotification($user['id'], $db);
            setMessage('Test notification created!', 'success');
            break;
    }
    
    header("Location: notifications.php");
    exit();
}

// Simple notification functions
function createTaskDueNotification($user_id, $task_title, $due_date, $db) {
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, 'task_due', NOW())
        ");
        $stmt->execute([
            $user_id,
            'Task Due Soon',
            "Your task '$task_title' is due on " . date('M d, Y', strtotime($due_date))
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

function createTestNotification($user_id, $db) {
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, 'system', NOW())
        ");
        $stmt->execute([
            $user_id,
            'Test Notification',
            'This is a test notification to check if the system is working properly.'
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function markNotificationAsRead($notification_id, $user_id, $db) {
    try {
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notification_id, $user_id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function markAllNotificationsAsRead($user_id, $db) {
    try {
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Check for due tasks and create notifications
function checkDueTasksAndNotify($user_id, $db) {
    try {
        // Get tasks due in next 24 hours that don't have notifications yet
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $stmt = $db->prepare("
            SELECT id, title, due_date 
            FROM tasks 
            WHERE user_id = ? 
            AND due_date = ? 
            AND status != 'completed'
            AND id NOT IN (
                SELECT DISTINCT SUBSTRING_INDEX(message, '''', 2) 
                FROM notifications 
                WHERE user_id = ? AND type = 'task_due' 
                AND DATE(created_at) = CURDATE()
            )
        ");
        $stmt->execute([$user_id, $tomorrow, $user_id]);
        $due_tasks = $stmt->fetchAll();
        
        foreach ($due_tasks as $task) {
            createTaskDueNotification($user_id, $task['title'], $task['due_date'], $db);
        }
        
        return count($due_tasks);
    } catch (Exception $e) {
        error_log("Error checking due tasks: " . $e->getMessage());
        return 0;
    }
}

// Auto-check for due tasks
$new_notifications = checkDueTasksAndNotify($user['id'], $db);

// Get user notifications
try {
    $stmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (Exception $e) {
    $notifications = [];
}

// Get unread count
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as unread_count 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$user['id']]);
    $unread_count = $stmt->fetch()['unread_count'];
} catch (Exception $e) {
    $unread_count = 0;
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - EduHive</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            flex: 1;
            padding: 30px;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .unread-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .action-btn.primary {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
        }

        .action-btn.secondary {
            background: #6c757d;
            color: white;
        }

        .action-btn:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }

        /* Message Styles */
        .message {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 12px;
            font-weight: 500;
            animation: slideDown 0.3s ease;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Notifications Container */
        .notifications-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .notifications-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            background: #f8f9fa;
        }

        .notifications-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .notifications-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 20px 25px;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
            position: relative;
        }

        .notification-item:hover {
            background-color: #f8f9fa;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }

        .notification-item.unread::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 25px;
            width: 8px;
            height: 8px;
            background: #dc3545;
            border-radius: 50%;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .notification-title {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .notification-time {
            font-size: 12px;
            color: #666;
        }

        .notification-message {
            color: #666;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .notification-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .notification-type.task_due {
            background-color: #fff3cd;
            color: #856404;
        }

        .notification-type.system {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .notification-type.achievement {
            background-color: #d4edda;
            color: #155724;
        }

        .notification-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .notification-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .notification-btn.mark-read {
            background-color: #28a745;
            color: white;
        }

        .notification-btn:hover {
            opacity: 0.8;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Notification Settings */
        .settings-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            padding: 25px;
        }

        .settings-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-info {
            flex: 1;
        }

        .setting-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .setting-description {
            font-size: 12px;
            color: #666;
        }

        .setting-toggle {
            position: relative;
            width: 50px;
            height: 24px;
            background: #ddd;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .setting-toggle.active {
            background: #28a745;
        }

        .setting-toggle::before {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            transition: transform 0.3s ease;
        }

        .setting-toggle.active::before {
            transform: translateX(26px);
        }

        /* Browser Notification Permission */
        .permission-section {
            background: #e8f4fd;
            border: 1px solid #bee5eb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .permission-title {
            font-weight: 600;
            color: #0c5460;
            margin-bottom: 10px;
        }

        .permission-text {
            color: #0c5460;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .permission-btn {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .header-actions {
                justify-content: center;
            }

            .notification-header {
                flex-direction: column;
                gap: 5px;
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                🔔 Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="unread-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </h1>
            <div class="header-actions">
                <?php if ($unread_count > 0): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="action-btn secondary">
                            ✅ Mark All Read
                        </button>
                    </form>
                <?php endif; ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="send_test_notification">
                    <button type="submit" class="action-btn primary">
                        🧪 Test Notification
                    </button>
                </form>
            </div>
        </div>

        <!-- Show Message -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Browser Notification Permission -->
        <div class="permission-section" id="permissionSection" style="display: none;">
            <div class="permission-title">🔔 Enable Browser Notifications</div>
            <div class="permission-text">
                Allow EduHive to send you browser notifications for task reminders and important updates.
            </div>
            <button class="permission-btn" onclick="requestNotificationPermission()">
                Enable Notifications
            </button>
        </div>

        <!-- Notification Settings -->
        <div class="settings-section">
            <h2 class="settings-title">⚙️ Notification Settings</h2>
            
            <div class="setting-item">
                <div class="setting-info">
                    <div class="setting-name">Task Due Reminders</div>
                    <div class="setting-description">Get notified 24 hours before tasks are due</div>
                </div>
                <div class="setting-toggle active" onclick="toggleSetting(this)"></div>
            </div>
            
            <div class="setting-item">
                <div class="setting-info">
                    <div class="setting-name">Browser Notifications</div>
                    <div class="setting-description">Show desktop notifications when browser is open</div>
                </div>
                <div class="setting-toggle active" onclick="toggleSetting(this)"></div>
            </div>
            
            <div class="setting-item">
                <div class="setting-info">
                    <div class="setting-name">Achievement Notifications</div>
                    <div class="setting-description">Get notified when you earn badges and rewards</div>
                </div>
                <div class="setting-toggle active" onclick="toggleSetting(this)"></div>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="notifications-container">
            <div class="notifications-header">
                <h3 class="notifications-title">Recent Notifications</h3>
            </div>
            
            <div class="notifications-list">
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                            <div class="notification-header">
                                <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                <div class="notification-time"><?php echo timeAgo($notification['created_at']); ?></div>
                            </div>
                            
                            <div class="notification-message">
                                <?php echo htmlspecialchars($notification['message']); ?>
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="notification-type <?php echo $notification['type']; ?>">
                                    <?php echo str_replace('_', ' ', $notification['type']); ?>
                                </span>
                                
                                <?php if (!$notification['is_read']): ?>
                                    <div class="notification-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" class="notification-btn mark-read">
                                                ✓ Mark Read
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔔</div>
                        <h3>No Notifications</h3>
                        <p>You're all caught up! Notifications will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Check notification permission status
        if ('Notification' in window) {
            if (Notification.permission === 'default') {
                document.getElementById('permissionSection').style.display = 'block';
            }
        }

        // Request notification permission
        function requestNotificationPermission() {
            if ('Notification' in window) {
                Notification.requestPermission().then(permission => {
                    if (permission === 'granted') {
                        document.getElementById('permissionSection').style.display = 'none';
                        showBrowserNotification('Notifications Enabled!', 'You will now receive task reminders.');
                    }
                });
            }
        }

        // Show browser notification
        function showBrowserNotification(title, message) {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(title, {
                    body: message,
                    icon: 'logoo.png',
                    badge: 'logoo.png'
                });
            }
        }

        // Toggle setting
        function toggleSetting(element) {
            element.classList.toggle('active');
            
            // Here you could save the setting to database
            const settingName = element.parentElement.querySelector('.setting-name').textContent;
            console.log('Setting toggled:', settingName, element.classList.contains('active'));
        }

        // Auto-hide success messages
        setTimeout(function() {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                setTimeout(() => successMessage.remove(), 300);
            }
        }, 3000);

        // Auto-check for new notifications every 30 seconds
        setInterval(function() {
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.new_notifications > 0) {
                        // Update unread badge
                        const badge = document.querySelector('.unread-badge');
                        if (badge) {
                            badge.textContent = data.unread_count;
                        }
                        
                        // Show browser notification
                        if (data.latest_notification) {
                            showBrowserNotification(
                                data.latest_notification.title,
                                data.latest_notification.message
                            );
                        }
                    }
                })
                .catch(err => console.log('Auto-check failed:', err));
        }, 30000);

        // Mark notification as read when clicked
        document.querySelectorAll('.notification-item.unread').forEach(item => {
            item.addEventListener('click', function() {
                if (!this.querySelector('.notification-btn')) {
                    // Auto-mark as read when clicked (if no explicit button)
                    this.classList.remove('unread');
                }
            });
        });

        // Simple notification sound (optional)
        function playNotificationSound() {
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRvIBAABXQVZFZm10IAAAAAAQAAABAB4ARAAoAQAAYAEAAAIAEABkYXRh1gEAAP//////////');
                audio.volume = 0.3;
                audio.play();
            } catch (e) {
                // Sound failed, ignore
            }
        }
    </script>
</body>
</html>

<?php
// Create notifications table if it doesn't exist
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('task_due', 'system', 'achievement', 'reminder') DEFAULT 'system',
            is_read BOOLEAN DEFAULT FALSE,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Error creating notifications table: " . $e->getMessage());
}
?>

<?php
// check_notifications.php - AJAX endpoint for checking new notifications
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    // Get unread count
    $stmt = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unread_count = $stmt->fetch()['unread_count'];
    
    // Get latest notification
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $latest = $stmt->fetch();
    
    echo json_encode([
        'unread_count' => $unread_count,
        'new_notifications' => 0, // Could implement logic to track truly new ones
        'latest_notification' => $latest
    ]);
    exit();
}
?>