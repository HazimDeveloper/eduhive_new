<?php
// config/functions.php - Helper functions for the application
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Clean and sanitize input data
 */
function cleanInput($data)
{
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }

    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email address
 */
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate date format
 */
function isValidDate($date, $format = 'Y-m-d')
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'M d, Y')
{
    if (empty($date)) return '';

    try {
        $datetime = new DateTime($date);
        return $datetime->format($format);
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = 'M d, Y g:i A')
{
    if (empty($datetime)) return '';

    try {
        $dt = new DateTime($datetime);
        return $dt->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Time ago function
 */
function timeAgo($datetime)
{
    if (empty($datetime)) return '';

    try {
        $time = time() - strtotime($datetime);

        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time / 60) . ' minutes ago';
        if ($time < 86400) return floor($time / 3600) . ' hours ago';
        if ($time < 2592000) return floor($time / 86400) . ' days ago';
        if ($time < 31536000) return floor($time / 2592000) . ' months ago';
        return floor($time / 31536000) . ' years ago';
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Generate random string
 */
function generateRandomString($length = 10)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Format file size
 */
function formatFileSize($size)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }

    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Get priority color class
 */
function getPriorityColor($priority)
{
    switch (strtolower($priority)) {
        case 'high':
            return 'danger';
        case 'medium':
            return 'warning';
        case 'low':
            return 'success';
        default:
            return 'secondary';
    }
}

/**
 * Get status color class
 */
function getStatusColor($status)
{
    switch (strtolower($status)) {
        case 'completed':
            return 'success';
        case 'in_progress':
            return 'primary';
        case 'pending':
            return 'warning';
        case 'overdue':
            return 'danger';
        default:
            return 'secondary';
    }
}

/**
 * Check if task is overdue
 */
function isOverdue($due_date)
{
    if (empty($due_date)) return false;
    return strtotime($due_date) < strtotime('today');
}

/**
 * Days until due date
 */
function daysUntilDue($due_date)
{
    if (empty($due_date)) return null;

    $today = new DateTime('today');
    $due = new DateTime($due_date);
    $diff = $today->diff($due);

    if ($due < $today) {
        return -$diff->days; // Negative for overdue
    }

    return $diff->days;
}

/**
 * Get day of week name
 */
function getDayName($day_number)
{
    $days = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];

    return $days[$day_number] ?? '';
}

/**
 * Convert minutes to hours and minutes format
 */
function formatDuration($minutes)
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($mins == 0) {
        return $hours . ' hr';
    }

    return $hours . ' hr ' . $mins . ' min';
}

/**
 * Upload file with validation
 */
function uploadFile($file, $upload_dir = 'uploads/', $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'])
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid file parameters'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['success' => false, 'message' => 'No file sent'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['success' => false, 'message' => 'File too large'];
        default:
            return ['success' => false, 'message' => 'Unknown upload error'];
    }

    // Validate file size (5MB max)
    if ($file['size'] > 5000000) {
        return ['success' => false, 'message' => 'File too large (max 5MB)'];
    }

    // Validate file extension
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }

    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }

    return [
        'success' => true,
        'filename' => $new_filename,
        'path' => $upload_path,
        'original_name' => $file['name'],
        'size' => $file['size'],
        'type' => $file['type']
    ];
}

/**
 * Send email notification
 */
function sendEmail($to, $subject, $message, $from = 'noreply@eduhive.com')
{
    require 'vendor/autoload.php';

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'masyraaf886@gmail.com'; //your email address (change this part)
        $mail->Password = 'aosf bkyv cmnh guhz'; // your generated app password (change this part)
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        // Recipients
        $mail->setFrom($from, 'EduHive');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        echo "Message sent successfully!";
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        echo "Mailer Error: " . $mail->ErrorInfo;
        return false;
    }
}

function testTelegramBot()
{
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
        curl_close($ch);

        $result = json_decode($response, true);

        if ($http_code === 200 && isset($result['ok']) && $result['ok']) {
            return [
                'success' => true,
                'bot_name' => $result['result']['first_name'],
                'bot_username' => $result['result']['username']
            ];
        } else {
            return ['success' => false, 'error' => $response];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
/**
 * Calculate study statistics
 */
function calculateStudyStats($user_id, $database)
{
    try {
        $db = $database->getConnection();

        // Total tasks
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM tasks WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $total_tasks = $stmt->fetch()['total'];

        // Completed tasks
        $stmt = $db->prepare("SELECT COUNT(*) as completed FROM tasks WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$user_id]);
        $completed_tasks = $stmt->fetch()['completed'];

        // Total study time this week
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $stmt = $db->prepare("SELECT SUM(duration) as total_time FROM time_records WHERE user_id = ? AND date >= ?");
        $stmt->execute([$user_id, $week_start]);
        $weekly_time = $stmt->fetch()['total_time'] ?? 0;

        // Completion rate
        $completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

        return [
            'total_tasks' => $total_tasks,
            'completed_tasks' => $completed_tasks,
            'pending_tasks' => $total_tasks - $completed_tasks,
            'weekly_time' => $weekly_time,
            'completion_rate' => $completion_rate
        ];
    } catch (Exception $e) {
        error_log("Error calculating study stats: " . $e->getMessage());
        return [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'pending_tasks' => 0,
            'weekly_time' => 0,
            'completion_rate' => 0
        ];
    }
}

/**
 * Log activity for debugging
 */
function logActivity($message, $level = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$level] $message" . PHP_EOL;

    // Log to file (create logs directory if needed)
    $log_dir = 'logs/';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    file_put_contents($log_dir . 'app.log', $log_message, FILE_APPEND | LOCK_EX);
}

/**
 * Validate and process form data
 */
function validateFormData($data, $rules)
{
    $errors = [];

    foreach ($rules as $field => $rule_list) {
        $value = $data[$field] ?? '';

        foreach ($rule_list as $rule) {
            switch ($rule) {
                case 'required':
                    if (empty($value)) {
                        $errors[$field] = ucfirst($field) . ' is required';
                    }
                    break;
                case 'email':
                    if (!empty($value) && !isValidEmail($value)) {
                        $errors[$field] = 'Invalid email format';
                    }
                    break;
                case 'date':
                    if (!empty($value) && !isValidDate($value)) {
                        $errors[$field] = 'Invalid date format';
                    }
                    break;
            }
        }
    }

    return $errors;
}

function createNotification($user_id, $title, $message, $type = 'system', $database = null)
{
    try {
        if (!$database) {
            $database = new Database();
        }
        $db = $database->getConnection();

        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $title, $message, $type]);
        return true;
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notification count for user
 */
function getUnreadNotificationCount($user_id, $database = null)
{
    try {
        if (!$database) {
            $database = new Database();
        }
        $db = $database->getConnection();

        $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return $stmt->fetch()['count'];
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Check for tasks due soon and create notifications
 */
function checkAndCreateTaskReminders($user_id, $database = null)
{
    try {
        if (!$database) {
            $database = new Database();
        }
        $db = $database->getConnection();

        // Get tasks due tomorrow that don't have recent notifications
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $stmt = $db->prepare("
            SELECT id, title, due_date 
            FROM tasks 
            WHERE user_id = ? 
            AND due_date = ? 
            AND status != 'completed'
            AND id NOT IN (
                SELECT task_id FROM notifications 
                WHERE user_id = ? 
                AND type = 'task_due' 
                AND DATE(created_at) = CURDATE()
                AND task_id IS NOT NULL
            )
        ");
        $stmt->execute([$user_id, $tomorrow, $user_id]);
        $due_tasks = $stmt->fetchAll();

        $created_count = 0;
        foreach ($due_tasks as $task) {
            $title = 'Task Due Tomorrow';
            $message = "Your task '{$task['title']}' is due tomorrow (" . formatDate($task['due_date']) . ")";

            if (createNotification($user_id, $title, $message, 'task_due', $database)) {
                $created_count++;
            }
        }

        return $created_count;
    } catch (Exception $e) {
        error_log("Error checking task reminders: " . $e->getMessage());
        return 0;
    }
}

function sendMultiNotification($user_id, $title, $message, $type, $channels)
{
    $database = new Database();
    $db = $database->getConnection();

    // Get user info
    $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    $results = [];

    foreach ($channels as $channel) {
        switch ($channel) {
            case 'website':
                $results['website'] = createWebsiteNotification($user_id, $title, $message, $type, $db);
                break;
            case 'email':
                if (!empty($user['email'])) {
                    $results['email'] = sendEmailNotification($user['email'], $user['name'], $title, $message);
                } else {
                    $results['email'] = false;
                    error_log("Email not sent: User ID $user_id has no email address.");
                }
                // $results['email'] = sendEmailNotification($user['email'], $user['name'], $title, $message);
                break;
            case 'telegram':
                if ($user['telegram_chat_id']) {
                    $results['telegram'] = sendTelegramNotification($user['telegram_chat_id'], $title, $message);
                } else {
                    $results['telegram'] = false;
                }
                break;
        }
    }

    return $results;
}

/**
 * Create website notification
 */
function createWebsiteNotification($user_id, $title, $message, $type, $db)
{
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $title, $message, $type]);
        return true;
    } catch (Exception $e) {
        error_log("Website notification error: " . $e->getMessage());
        return false;
    }
}

function sendEmailNotification($email, $name, $title, $message)
{
    try {
        $subject = "EduHive: " . $title;

        $html_message = "
        <html>
        <head>
        <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: #C4A484; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #ffffff; }
        .footer { background: #f8f9fa; padding: 15px; text-align: center; color: #666; }
        .button { background: #C4A484; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 15px 0; }
        </style>
        </head>
        <body>
        <div class='container'>
        <div class='header'>
        <h2>🎓 EduHive Notification</h2>
        </div>
                <div class='content'>
                    <h3>Hi $name,</h3>
                    <h4>$title</h4>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                    <a href='http://localhost/eduhive/dashboard.php' class='button'>📚 Open EduHive</a>
                    </div>
                    <div class='footer'>
                    <p>This is an automated message from EduHive.<br>
                    <small>Sent at " . date('Y-m-d H:i:s') . "</small></p>
                    </div>
                    </div>
                    </body>
                    </html>";

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: EduHive System <noreply@eduhive.com>',
            'Reply-To: noreply@eduhive.com'
        ];
        return sendEmail($email, $subject, $html_message);
    } catch (Exception $e) {
        error_log("Email notification error: " . $e->getMessage());
        return false;
    }
}

function sendTelegramNotification($chat_id, $title, $message)
{
    try {
        // Your actual bot token
        $bot_token = "8122156077:AAEL_j6QN-vnPrfyqbOnam4mCfBQfIjId9k";
        $url = "https://api.telegram.org/bot$bot_token/sendMessage";

        // Format message dengan emoji dan markdown
        $text = "🎓 *EduHive Notification*\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "📌 *" . $title . "*\n\n";
        $text .= $message . "\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "🕐 _" . date('d M Y, H:i') . "_\n";
        $text .= "💻 [Open EduHive](http://localhost/eduhive/dashboard.php)";

        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For localhost testing

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
            return true;
        } else {
            error_log("Telegram API Error: " . $response);
            return false;
        }
    } catch (Exception $e) {
        error_log("Telegram notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check tasks due soon and send notifications
 */
function checkTasksAndSendReminders()
{
    try {
        $database = new Database();
        $db = $database->getConnection();

        // Get tasks due in 24 hours that haven't been reminded today
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $stmt = $db->prepare("
            SELECT t.*, u.name as user_name, u.email,
                   us.notification_email
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN user_settings us ON u.id = us.user_id
            WHERE t.due_date = ? 
            AND t.status != 'completed'
            AND t.id NOT IN (
                SELECT DISTINCT task_id FROM notifications 
                WHERE type = 'task_due_reminder' 
                AND DATE(created_at) = CURDATE()
                AND task_id IS NOT NULL
            )
        ");
        $stmt->execute([$tomorrow]);
        $due_tasks = $stmt->fetchAll();

        $sent_count = 0;
        foreach ($due_tasks as $task) {
            $title = "⏰ Task Due Tomorrow";
            $message = "Your task '{$task['title']}' is due tomorrow ({$task['due_date']}).\n\nPlease complete it on time!";

            // Determine which channels to use
            $channels = ['website']; // Always send website notification

            if ($task['notification_email']) {
                $channels[] = 'email';
            }

            if ($task['notification_telegram'] && $task['telegram_chat_id']) {
                $channels[] = 'telegram';
            }

            $results = sendMultiNotification($task['user_id'], $title, $message, 'task_due_reminder', $channels);

            if ($results['website']) {
                $sent_count++;
            }

            // Log results
            error_log("Task reminder sent for task {$task['id']}: " . json_encode($results));
        }

        return $sent_count;
    } catch (Exception $e) {
        error_log("Error checking task reminders: " . $e->getMessage());
        return 0;
    }
}

/**
 * Send task assignment notification
 */
function sendTaskAssignmentNotification($assigned_to_user_id, $task_title, $assigned_by_name, $due_date = null)
{
    $title = "📋 New Task Assigned";
    $message = "You have been assigned a new task: '$task_title'";
    $message .= "\nAssigned by: $assigned_by_name";

    if ($due_date) {
        $message .= "\nDue date: " . date('M d, Y', strtotime($due_date));
    }

    $channels = ['website', 'email', 'telegram'];
    return sendMultiNotification($assigned_to_user_id, $title, $message, 'task_assignment', $channels);
}
