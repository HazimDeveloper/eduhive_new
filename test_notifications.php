<?php
// test_notifications.php - Complete fixed version for EduHive
// Make sure to place this file in your project root directory

// Prevent any output before JSON
ob_start();

// Error handling - disable display for production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Include required files
    require_once 'config/database.php';
    require_once 'config/session.php';
    require_once 'config/functions.php';

    // Clean any previous output
    ob_clean();

    // Check if user is logged in
    if (!isLoggedIn()) {
        echo json_encode([
            'success' => false, 
            'message' => 'Please log in first'
        ]);
        exit();
    }

    // Get current user
    $user = getCurrentUser();
    $database = new Database();
    $db = $database->getConnection();

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'success' => false, 
            'message' => 'Only POST requests allowed'
        ]);
        exit();
    }

    // Get action
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'test_email':
            $title = "🧪 Test Email Notification";
            $message = "This is a test email from EduHive notification system.\n\nTime: " . date('Y-m-d H:i:s') . "\n\nIf you receive this email, your email notifications are working correctly!";
            
            $result = testSendEmail($user['email'], $user['name'], $title, $message);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Test email sent successfully! Check your inbox.' : 'Failed to send email. Check server mail configuration.'
            ]);
            break;

        case 'test_telegram':
            // Get chat ID from POST or database
            $chat_id = $_POST['chat_id'] ?? '';
            
            if (empty($chat_id)) {
                // Try to get from user database
                try {
                    $stmt = $db->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    $user_data = $stmt->fetch();
                    $chat_id = $user_data['telegram_chat_id'] ?? '';
                } catch (Exception $e) {
                    // Ignore database error, will handle empty chat_id below
                }
            }
            
            if (empty($chat_id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No Telegram Chat ID found. Please message @eduhive_notifications_bot first and get your Chat ID.'
                ]);
                break;
            }
            
            $title = "🧪 Test Telegram Notification";
            $message = "Hello from EduHive!\n\nThis is a test notification to verify your Telegram setup.\n\nTime: " . date('Y-m-d H:i:s') . "\n\nIf you see this message, your Telegram notifications are working perfectly! 🎉";
            
            $result = testSendTelegram($chat_id, $title, $message);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Test message sent to Telegram! Check your chat.' : 'Failed to send Telegram message. Check your Chat ID or bot configuration.'
            ]);
            break;

        case 'test_website':
            $title = "🧪 Website Test Notification";
            $message = "This is a test website notification created at " . date('Y-m-d H:i:s') . ". If you see this in your notifications page, website notifications are working!";
            
            $result = testCreateWebsiteNotification($user['id'], $title, $message, $db);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Website notification created! Check your notifications page.' : 'Failed to create website notification.'
            ]);
            break;

        case 'test_notifications':
            $title = "🧪 Multi-Channel Test";
            $message = "Testing all notification channels from EduHive system.\n\nTime: " . date('Y-m-d H:i:s') . "\n\nThis tests website, email, and Telegram notifications.";
            
            $results = testAllNotifications($user['id'], $user['email'], $user['name'], $title, $message, $db);
            
            $success_channels = array_keys(array_filter($results));
            $failed_channels = array_keys(array_filter($results, function($v) { return !$v; }));
            
            $success = !empty($success_channels);
            $message = $success 
                ? 'Success: ' . implode(', ', $success_channels) . (empty($failed_channels) ? '' : '. Failed: ' . implode(', ', $failed_channels))
                : 'All channels failed: ' . implode(', ', $failed_channels);
            
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'details' => $results
            ]);
            break;

        case 'check_due_tasks':
            $reminder_count = checkAndSendTaskReminders($user['id'], $db);
            
            echo json_encode([
                'success' => true,
                'message' => "Checked due tasks. Sent $reminder_count reminders.",
                'reminders_sent' => $reminder_count
            ]);
            break;

        case 'get_telegram_info':
            $bot_info = getTelegramBotInfo();
            
            echo json_encode([
                'success' => $bot_info['success'],
                'message' => $bot_info['success'] ? 'Bot is working' : 'Bot connection failed',
                'bot_info' => $bot_info
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Unknown action: ' . $action
            ]);
            break;
    }

} catch (Exception $e) {
    // Log error
    error_log("Test notification error: " . $e->getMessage());
    
    // Clean output and return error
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'System error occurred. Please try again.',
        'error' => $e->getMessage() // Remove this in production
    ]);
}

exit();

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function testSendEmail($email, $name, $title, $message) {
    try {
        $subject = "EduHive: " . $title;
        
        $html_message = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; }
                .header { background: linear-gradient(135deg, #C4A484, #B8956A); color: white; padding: 30px 20px; text-align: center; }
                .content { padding: 30px; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; border-top: 1px solid #eee; }
                .button { background: #C4A484; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 15px 0; }
                .highlight { background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎓 EduHive</h1>
                    <p style="margin: 0; opacity: 0.9;">Student Task Management System</p>
                </div>
                <div class="content">
                    <h2>Hi ' . htmlspecialchars($name) . ',</h2>
                    <h3>' . htmlspecialchars($title) . '</h3>
                    <div class="highlight">
                        <p>' . nl2br(htmlspecialchars($message)) . '</p>
                    </div>
                    <p>You can access your dashboard using the button below:</p>
                    <a href="http://localhost/eduhive/dashboard.php" class="button">📚 Open EduHive Dashboard</a>
                </div>
                <div class="footer">
                    <p><strong>EduHive Notification System</strong></p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p><small>Sent at ' . date('Y-m-d H:i:s') . '</small></p>
                </div>
            </div>
        </body>
        </html>';
        
        $headers = array(
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: EduHive System <noreply@eduhive.com>',
            'Reply-To: noreply@eduhive.com',
            'X-Mailer: PHP/' . phpversion()
        );
        
        return mail($email, $subject, $html_message, implode("\r\n", $headers));
        
    } catch (Exception $e) {
        error_log("Email test error: " . $e->getMessage());
        return false;
    }
}

function testSendTelegram($chat_id, $title, $message) {
    try {
        // Your bot token
        $bot_token = "8122156077:AAEL_j6QN-vnPrfyqbOnam4mCfBQfIjId9k";
        $url = "https://api.telegram.org/bot$bot_token/sendMessage";
        
        // Format message with emoji and markdown
        $text = "🎓 *EduHive Notification*\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "📌 *" . $title . "*\n\n";
        $text .= $message . "\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "🕐 _" . date('d M Y, H:i') . "_\n";
        $text .= "💻 [Open EduHive](http://localhost/eduhive/dashboard.php)";
        
        $data = array(
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For localhost testing
        curl_setopt($ch, CURLOPT_USERAGENT, 'EduHive Bot 1.0');
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            error_log("Telegram CURL Error: " . $curl_error);
            return false;
        }
        
        $result = json_decode($response, true);
        
        if ($http_code === 200 && isset($result['ok']) && $result['ok']) {
            error_log("Telegram message sent successfully to chat_id: $chat_id");
            return true;
        } else {
            error_log("Telegram API Error (HTTP $http_code): " . $response);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Telegram test error: " . $e->getMessage());
        return false;
    }
}

function testCreateWebsiteNotification($user_id, $title, $message, $db) {
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, 'test', NOW())
        ");
        $stmt->execute([$user_id, $title, $message]);
        return true;
    } catch (Exception $e) {
        error_log("Website notification test error: " . $e->getMessage());
        return false;
    }
}

function testAllNotifications($user_id, $email, $name, $title, $message, $db) {
    $results = array();
    
    // Test website notification
    $results['website'] = testCreateWebsiteNotification($user_id, $title, $message, $db);
    
    // Test email notification
    $results['email'] = testSendEmail($email, $name, $title, $message);
    
    // Test telegram notification (get chat_id from database)
    try {
        $stmt = $db->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch();
        $chat_id = $user_data['telegram_chat_id'] ?? '';
        
        if (!empty($chat_id)) {
            $results['telegram'] = testSendTelegram($chat_id, $title, $message);
        } else {
            $results['telegram'] = false;
        }
    } catch (Exception $e) {
        $results['telegram'] = false;
    }
    
    return $results;
}

function checkAndSendTaskReminders($user_id, $db) {
    try {
        // Get tasks due tomorrow that haven't been reminded today
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $stmt = $db->prepare("
            SELECT id, title, due_date 
            FROM tasks 
            WHERE user_id = ? 
            AND due_date = ? 
            AND status != 'completed'
            AND id NOT IN (
                SELECT DISTINCT task_id FROM notifications 
                WHERE user_id = ? 
                AND type = 'task_due_reminder' 
                AND DATE(created_at) = CURDATE()
                AND task_id IS NOT NULL
            )
        ");
        $stmt->execute([$user_id, $tomorrow, $user_id]);
        $due_tasks = $stmt->fetchAll();
        
        $sent_count = 0;
        foreach ($due_tasks as $task) {
            $title = "⏰ Task Due Tomorrow";
            $message = "Your task '{$task['title']}' is due tomorrow (" . date('M d, Y', strtotime($task['due_date'])) . ").\n\nPlease complete it on time!";
            
            // Create website notification
            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, title, message, type, task_id, created_at) 
                VALUES (?, ?, ?, 'task_due_reminder', ?, NOW())
            ");
            $stmt->execute([$user_id, $title, $message, $task['id']]);
            $sent_count++;
        }
        
        return $sent_count;
        
    } catch (Exception $e) {
        error_log("Task reminder check error: " . $e->getMessage());
        return 0;
    }
}

function getTelegramBotInfo() {
    try {
        $bot_token = "8122156077:AAEL_j6QN-vnPrfyqbOnam4mCfBQfIjId9k";
        $url = "https://api.telegram.org/bot$bot_token/getMe";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            return array('success' => false, 'error' => $curl_error);
        }
        
        $result = json_decode($response, true);
        
        if ($http_code === 200 && isset($result['ok']) && $result['ok']) {
            return array(
                'success' => true,
                'bot_name' => $result['result']['first_name'],
                'bot_username' => $result['result']['username']
            );
        } else {
            return array('success' => false, 'error' => $response);
        }
        
    } catch (Exception $e) {
        return array('success' => false, 'error' => $e->getMessage());
    }
}
?>