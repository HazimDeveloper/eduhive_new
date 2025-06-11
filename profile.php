<?php
// profile.php - User Profile Management System
session_start();

// Include required files
require_once 'config/database.php';
require_once 'config/functions.php';
require_once 'config/session.php';

// Require login
requireLogin();

// Initialize database
$database = new Database();
$db = $database->getConnection();

// Get current user
$current_user = getCurrentUser();
$user_id = $current_user['id'];

// Handle form submissions
$message = null;
$message_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Security token mismatch. Please try again.';
        $message_type = 'error';
    } else {
        $action = cleanInput($_POST['action']);
        
        switch ($action) {
            case 'update_profile':
                $result = updateProfile($_POST, $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
                
            case 'change_password':
                $result = changePassword($_POST, $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
                
            case 'upload_avatar':
                $result = uploadAvatar($_FILES, $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
                
            case 'update_settings':
                $result = updateSettings($_POST, $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
        }
    }
}

// Get user profile data
$user_profile = getUserProfile($db, $user_id);
$user_settings = getUserSettings($db, $user_id);
$user_stats = calculateStudyStats($user_id, $database);

// Profile management functions
function updateProfile($data, $db, $user_id) {
    try {
        // Validate input
        $errors = validateProfileData($data);
        if (!empty($errors)) {
            return ['message' => implode(', ', $errors), 'type' => 'error'];
        }
        
        // Check if email is already taken by another user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([cleanInput($data['email']), $user_id]);
        if ($stmt->fetch()) {
            return ['message' => 'Email address is already in use by another account.', 'type' => 'error'];
        }
        
        // Update user profile
        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([
            cleanInput($data['name']),
            cleanInput($data['email']),
            $user_id
        ]);
        
        // Update session variables
        $_SESSION['user_name'] = cleanInput($data['name']);
        $_SESSION['user_email'] = cleanInput($data['email']);
        
        return ['message' => 'Profile updated successfully!', 'type' => 'success'];
    } catch (Exception $e) {
        error_log("Error updating profile: " . $e->getMessage());
        return ['message' => 'Error updating profile. Please try again.', 'type' => 'error'];
    }
}

function changePassword($data, $db, $user_id) {
    try {
        // Validate input
        if (empty($data['current_password']) || empty($data['new_password']) || empty($data['confirm_password'])) {
            return ['message' => 'All password fields are required.', 'type' => 'error'];
        }
        
        if ($data['new_password'] !== $data['confirm_password']) {
            return ['message' => 'New passwords do not match.', 'type' => 'error'];
        }
        
        if (strlen($data['new_password']) < 6) {
            return ['message' => 'New password must be at least 6 characters long.', 'type' => 'error'];
        }
        
        // Verify current password
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($data['current_password'], $user['password'])) {
            return ['message' => 'Current password is incorrect.', 'type' => 'error'];
        }
        
        // Update password
        $new_password_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_password_hash, $user_id]);
        
        return ['message' => 'Password changed successfully!', 'type' => 'success'];
    } catch (Exception $e) {
        error_log("Error changing password: " . $e->getMessage());
        return ['message' => 'Error changing password. Please try again.', 'type' => 'error'];
    }
}

function uploadAvatar($files, $db, $user_id) {
    try {
        if (!isset($files['avatar']) || $files['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
            return ['message' => 'Please select an image file.', 'type' => 'error'];
        }
        
        // Upload file
        $upload_result = uploadFile($files['avatar'], 'uploads/avatars/', ['jpg', 'jpeg', 'png', 'gif']);
        
        if (!$upload_result['success']) {
            return ['message' => $upload_result['message'], 'type' => 'error'];
        }
        
        // Get old avatar to delete it
        $stmt = $db->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $old_avatar = $stmt->fetch()['avatar'];
        
        // Update avatar in database
        $stmt = $db->prepare("UPDATE users SET avatar = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$upload_result['filename'], $user_id]);
        
        // Delete old avatar file if it exists
        if ($old_avatar && file_exists('uploads/avatars/' . $old_avatar)) {
            unlink('uploads/avatars/' . $old_avatar);
        }
        
        return ['message' => 'Profile picture updated successfully!', 'type' => 'success'];
    } catch (Exception $e) {
        error_log("Error uploading avatar: " . $e->getMessage());
        return ['message' => 'Error uploading profile picture. Please try again.', 'type' => 'error'];
    }
}

function updateSettings($data, $db, $user_id) {
    try {
        // Prepare settings data
        $notification_email = isset($data['notification_email']) ? 1 : 0;
        $notification_browser = isset($data['notification_browser']) ? 1 : 0;
        $reminder_time = intval($data['reminder_time'] ?? 24);
        $theme = cleanInput($data['theme'] ?? 'light');
        $timezone = cleanInput($data['timezone'] ?? 'UTC');
        
        // Check if settings exist
        $stmt = $db->prepare("SELECT id FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        if ($stmt->fetch()) {
            // Update existing settings
            $stmt = $db->prepare("UPDATE user_settings SET notification_email = ?, notification_browser = ?, reminder_time = ?, theme = ?, timezone = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
            $stmt->execute([$notification_email, $notification_browser, $reminder_time, $theme, $timezone, $user_id]);
        } else {
            // Create new settings
            $stmt = $db->prepare("INSERT INTO user_settings (user_id, notification_email, notification_browser, reminder_time, theme, timezone) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $notification_email, $notification_browser, $reminder_time, $theme, $timezone]);
        }
        
        return ['message' => 'Settings updated successfully!', 'type' => 'success'];
    } catch (Exception $e) {
        error_log("Error updating settings: " . $e->getMessage());
        return ['message' => 'Error updating settings. Please try again.', 'type' => 'error'];
    }
}

function getUserProfile($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error fetching user profile: " . $e->getMessage());
        return null;
    }
}

function getUserSettings($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $settings = $stmt->fetch();
        
        // Return default settings if none exist
        if (!$settings) {
            return [
                'notification_email' => 1,
                'notification_browser' => 1,
                'reminder_time' => 24,
                'theme' => 'light',
                'timezone' => 'UTC'
            ];
        }
        
        return $settings;
    } catch (Exception $e) {
        error_log("Error fetching user settings: " . $e->getMessage());
        return [
            'notification_email' => 1,
            'notification_browser' => 1,
            'reminder_time' => 24,
            'theme' => 'light',
            'timezone' => 'UTC'
        ];
    }
}

function validateProfileData($data) {
    $errors = [];
    
    if (empty(trim($data['name']))) {
        $errors[] = 'Name is required';
    }
    
    if (empty(trim($data['email']))) {
        $errors[] = 'Email is required';
    } elseif (!isValidEmail($data['email'])) {
        $errors[] = 'Invalid email format';
    }
    
    return $errors;
}

// Get session message
$session_message = getMessage();
if ($session_message) {
    $message = $session_message['text'];
    $message_type = $session_message['type'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - EduHive</title>
    <style>
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .profile-title {
            color: #333;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 2px;
            margin: 0;
        }

        .profile-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
            height: fit-content;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-avatar-placeholder {
            font-size: 48px;
            color: white;
        }

        .avatar-upload {
            margin-top: 15px;
        }

        .avatar-upload input[type="file"] {
            display: none;
        }

        .avatar-upload-btn {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .avatar-upload-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        }

        .profile-name {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .profile-email {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            display: block;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        /* Tabs */
        .profile-tabs {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .tab-navigation {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }

        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            transition: all 0.3s ease;
        }

        .tab-button.active {
            background: white;
            color: #333;
            border-bottom: 3px solid #C4A484;
        }

        .tab-content {
            padding: 30px;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Styles */
        .form-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 15px 20px;
            border: 3px solid #333;
            border-radius: 50px;
            font-size: 16px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: white;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: #4A90A4;
            box-shadow: 0 0 0 3px rgba(74, 144, 164, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Checkbox styles */
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .checkbox-input {
            margin-right: 10px;
            transform: scale(1.2);
        }

        .checkbox-label {
            color: #333;
            font-weight: 500;
            cursor: pointer;
        }

        /* Account Info */
        .account-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #333;
        }

        .info-value {
            color: #666;
        }

        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-container {
                padding: 10px;
            }

            .profile-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .profile-title {
                font-size: 20px;
            }

            .tab-navigation {
                flex-direction: column;
            }

            .tab-content {
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .profile-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .profile-avatar {
                width: 100px;
                height: 100px;
            }

            .profile-avatar-placeholder {
                font-size: 36px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include_once 'sidebar.php'; ?>
        
        <main class="main-content">
            <div class="profile-container">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="profile-header">
                    <h1 class="profile-title">MY PROFILE</h1>
                </div>

                <div class="profile-content">
                    <!-- Profile Card -->
                    <div class="profile-card">
                        <div class="profile-avatar">
                            <?php if ($user_profile['avatar'] && file_exists('uploads/avatars/' . $user_profile['avatar'])): ?>
                                <img src="uploads/avatars/<?php echo htmlspecialchars($user_profile['avatar']); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder">👤</div>
                            <?php endif; ?>
                        </div>

                        <div class="avatar-upload">
                            <form method="POST" enctype="multipart/form-data" id="avatarForm">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="upload_avatar">
                                <input type="file" id="avatarInput" name="avatar" accept="image/*" onchange="uploadAvatar()">
                                <button type="button" class="avatar-upload-btn" onclick="document.getElementById('avatarInput').click()">
                                    Change Photo
                                </button>
                            </form>
                        </div>

                        <div class="profile-name"><?php echo htmlspecialchars($user_profile['name']); ?></div>
                        <div class="profile-email"><?php echo htmlspecialchars($user_profile['email']); ?></div>

                        <div class="profile-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo $user_stats['total_tasks']; ?></span>
                                <div class="stat-label">Total Tasks</div>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo $user_stats['completed_tasks']; ?></span>
                                <div class="stat-label">Completed</div>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo formatDuration($user_stats['weekly_time']); ?></span>
                                <div class="stat-label">This Week</div>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo $user_stats['completion_rate']; ?>%</span>
                                <div class="stat-label">Completion Rate</div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Tabs -->
                    <div class="profile-tabs">
                        <div class="tab-navigation">
                            <button class="tab-button active" onclick="switchTab('personal-info')">Personal Info</button>
                            <button class="tab-button" onclick="switchTab('security')">Security</button>
                            <button class="tab-button" onclick="switchTab('preferences')">Preferences</button>
                            <button class="tab-button" onclick="switchTab('account-info')">Account Info</button>
                        </div>

                        <!-- Personal Information Tab -->
                        <div id="personal-info" class="tab-content active">
                            <div class="form-section">
                                <h3 class="section-title">Personal Information</h3>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Full Name *</label>
                                        <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($user_profile['name']); ?>" required maxlength="100">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Email Address *</label>
                                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user_profile['email']); ?>" required maxlength="100">
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">Update Profile</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="form-group">
    <label class="form-label">Telegram Chat ID (Optional)</label>
    <input type="text" name="telegram_chat_id" class="form-input" 
           value="<?php echo htmlspecialchars($user_profile['telegram_chat_id'] ?? ''); ?>"
           placeholder="Your Telegram Chat ID">
    <small class="form-help">Get this from @YourEduHiveBot after sending /start</small>
</div>

<div class="checkbox-group">
    <input type="checkbox" name="notification_telegram" id="notification_telegram" 
           <?php echo $user_settings['notification_telegram'] ? 'checked' : ''; ?>>
    <label for="notification_telegram">Enable Telegram Notifications</label>
</div>

                        <!-- Security Tab -->
                        <div id="security" class="tab-content">
                            <div class="form-section">
                                <h3 class="section-title">Change Password</h3>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Current Password *</label>
                                        <input type="password" name="current_password" class="form-input" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">New Password *</label>
                                        <input type="password" name="new_password" class="form-input" required minlength="6">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Confirm New Password *</label>
                                        <input type="password" name="confirm_password" class="form-input" required minlength="6">
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">Change Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Preferences Tab -->
                        <div id="preferences" class="tab-content">
                            <div class="form-section">
                                <h3 class="section-title">Notification Preferences</h3>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="update_settings">
                                    
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="email_notifications" name="notification_email" class="checkbox-input" <?php echo $user_settings['notification_email'] ? 'checked' : ''; ?>>
                                        <label for="email_notifications" class="checkbox-label">Email Notifications</label>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="browser_notifications" name="notification_browser" class="checkbox-input" <?php echo $user_settings['notification_browser'] ? 'checked' : ''; ?>>
                                        <label for="browser_notifications" class="checkbox-label">Browser Push Notifications</label>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Default Reminder Time</label>
                                        <select name="reminder_time" class="form-select">
                                            <option value="1" <?php echo $user_settings['reminder_time'] == 1 ? 'selected' : ''; ?>>1 hour before</option>
                                            <option value="24" <?php echo $user_settings['reminder_time'] == 24 ? 'selected' : ''; ?>>1 day before</option>
                                            <option value="48" <?php echo $user_settings['reminder_time'] == 48 ? 'selected' : ''; ?>>2 days before</option>
                                            <option value="72" <?php echo $user_settings['reminder_time'] == 72 ? 'selected' : ''; ?>>3 days before</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Theme Preference</label>
                                        <select name="theme" class="form-select">
                                            <option value="light" <?php echo $user_settings['theme'] == 'light' ? 'selected' : ''; ?>>Light Theme</option>
                                            <option value="dark" <?php echo $user_settings['theme'] == 'dark' ? 'selected' : ''; ?>>Dark Theme</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Timezone</label>
                                        <select name="timezone" class="form-select">
                                            <option value="UTC" <?php echo $user_settings['timezone'] == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                            <option value="Asia/Kuala_Lumpur" <?php echo $user_settings['timezone'] == 'Asia/Kuala_Lumpur' ? 'selected' : ''; ?>>Asia/Kuala Lumpur</option>
                                            <option value="Asia/Singapore" <?php echo $user_settings['timezone'] == 'Asia/Singapore' ? 'selected' : ''; ?>>Asia/Singapore</option>
                                            <option value="Asia/Bangkok" <?php echo $user_settings['timezone'] == 'Asia/Bangkok' ? 'selected' : ''; ?>>Asia/Bangkok</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">Save Preferences</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Account Info Tab -->
                        <div id="account-info" class="tab-content">
                            <div class="form-section">
                                <h3 class="section-title">Account Information</h3>
                                <div class="account-info">
                                    <div class="info-row">
                                        <span class="info-label">User ID:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($user_profile['id']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Account Created:</span>
                                        <span class="info-value"><?php echo formatDate($user_profile['created_at']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Last Login:</span>
                                        <span class="info-value"><?php echo $user_profile['last_login'] ? formatDateTime($user_profile['last_login']) : 'Never'; ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Last Updated:</span>
                                        <span class="info-value"><?php echo formatDateTime($user_profile['updated_at']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Account Status:</span>
                                        <span class="info-value" style="color: <?php echo $user_profile['status'] === 'active' ? '#28a745' : '#dc3545'; ?>">
                                            <?php echo ucfirst($user_profile['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Tab switching functionality
        function switchTab(tabId) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Avatar upload functionality
        function uploadAvatar() {
            const form = document.getElementById('avatarForm');
            const fileInput = document.getElementById('avatarInput');
            
            if (fileInput.files[0]) {
                // Check file size (5MB max)
                if (fileInput.files[0].size > 5000000) {
                    alert('File size must be less than 5MB');
                    fileInput.value = '';
                    return;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(fileInput.files[0].type)) {
                    alert('Please select a valid image file (JPG, PNG, GIF)');
                    fileInput.value = '';
                    return;
                }
                
                form.submit();
            }
        }

        // Password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const passwordForm = document.querySelector('form[action*="change_password"], form input[name="action"][value="change_password"]').closest('form');
            
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    const newPassword = document.querySelector('input[name="new_password"]').value;
                    const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match');
                        return false;
                    }
                    
                    if (newPassword.length < 6) {
                        e.preventDefault();
                        alert('Password must be at least 6 characters long');
                        return false;
                    }
                });
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            const browserNotificationCheckbox = document.getElementById('browser_notifications');
            if (browserNotificationCheckbox && browserNotificationCheckbox.checked) {
                Notification.requestPermission();
            }
        }
    </script>
</body>
</html>