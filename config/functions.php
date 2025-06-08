<?php
// config/functions.php - Helper functions for the application

/**
 * Clean and sanitize input data
 */
function cleanInput($data) {
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
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate date format
 */
function isValidDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'M d, Y') {
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
function formatDateTime($datetime, $format = 'M d, Y g:i A') {
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
function timeAgo($datetime) {
    if (empty($datetime)) return '';
    
    try {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time/60) . ' minutes ago';
        if ($time < 86400) return floor($time/3600) . ' hours ago';
        if ($time < 2592000) return floor($time/86400) . ' days ago';
        if ($time < 31536000) return floor($time/2592000) . ' months ago';
        return floor($time/31536000) . ' years ago';
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Generate random string
 */
function generateRandomString($length = 10) {
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
function formatFileSize($size) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Get priority color class
 */
function getPriorityColor($priority) {
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
function getStatusColor($status) {
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
function isOverdue($due_date) {
    if (empty($due_date)) return false;
    return strtotime($due_date) < strtotime('today');
}

/**
 * Days until due date
 */
function daysUntilDue($due_date) {
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
function getDayName($day_number) {
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
function formatDuration($minutes) {
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
function uploadFile($file, $upload_dir = 'uploads/', $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']) {
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
function sendEmail($to, $subject, $message, $from = null) {
    if (empty($from)) {
        $from = 'noreply@eduhive.com';
    }
    
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * Calculate study statistics
 */
function calculateStudyStats($user_id, $database) {
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
function logActivity($message, $level = 'INFO') {
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
function validateFormData($data, $rules) {
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
?>