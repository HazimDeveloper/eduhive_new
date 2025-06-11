<?php
// class_schedule.php - Class Schedule Management System

// Include required files (session will be started in session.php)
require_once 'config/session.php';
require_once 'config/database.php';
require_once 'config/functions.php';

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
            case 'add_schedule':
                $result = addClassSchedule($_POST, $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
                
            case 'update_schedule':
                $result = updateClassSchedule($_POST, $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
                
            case 'delete_schedule':
                $result = deleteClassSchedule($_POST['schedule_id'], $db, $user_id);
                $message = $result['message'];
                $message_type = $result['type'];
                break;
        }
    }
}

// Get class schedules for current user
$schedules = getClassSchedules($db, $user_id);

// Functions for class schedule operations
function addClassSchedule($data, $db, $user_id) {
    try {
        // Validate input
        $errors = validateScheduleData($data);
        if (!empty($errors)) {
            return ['message' => implode(', ', $errors), 'type' => 'error'];
        }
        
        // Check for time conflicts
        if (hasTimeConflict($data, $db, $user_id)) {
            return ['message' => 'Time conflict detected with existing class!', 'type' => 'error'];
        }
        
        $stmt = $db->prepare("INSERT INTO class_schedules (user_id, subject, course_code, instructor, day_of_week, start_time, end_time, location, room, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $user_id,
            cleanInput($data['subject']),
            cleanInput($data['course_code']),
            cleanInput($data['instructor']),
            cleanInput($data['day_of_week']),
            cleanInput($data['start_time']),
            cleanInput($data['end_time']),
            cleanInput($data['location']),
            cleanInput($data['room']),
            cleanInput($data['semester'])
        ]);
        
        return ['message' => 'Class schedule added successfully!', 'type' => 'success'];
    } catch (Exception $e) {
        error_log("Error adding class schedule: " . $e->getMessage());
        return ['message' => 'Error adding class schedule. Please try again.', 'type' => 'error'];
    }
}

function updateClassSchedule($data, $db, $user_id) {
    try {
        $schedule_id = intval($data['schedule_id']);
        
        // Verify ownership
        $stmt = $db->prepare("SELECT id FROM class_schedules WHERE id = ? AND user_id = ?");
        $stmt->execute([$schedule_id, $user_id]);
        if (!$stmt->fetch()) {
            return ['message' => 'Schedule not found or access denied.', 'type' => 'error'];
        }
        
        // Validate input
        $errors = validateScheduleData($data);
        if (!empty($errors)) {
            return ['message' => implode(', ', $errors), 'type' => 'error'];
        }
        
        // Check for time conflicts (excluding current schedule)
        if (hasTimeConflict($data, $db, $user_id, $schedule_id)) {
            return ['message' => 'Time conflict detected with existing class!', 'type' => 'error'];
        }
        
        $stmt = $db->prepare("UPDATE class_schedules SET subject = ?, course_code = ?, instructor = ?, day_of_week = ?, start_time = ?, end_time = ?, location = ?, room = ?, semester = ? WHERE id = ? AND user_id = ?");
        
        $stmt->execute([
            cleanInput($data['subject']),
            cleanInput($data['course_code']),
            cleanInput($data['instructor']),
            cleanInput($data['day_of_week']),
            cleanInput($data['start_time']),
            cleanInput($data['end_time']),
            cleanInput($data['location']),
            cleanInput($data['room']),
            cleanInput($data['semester']),
            $schedule_id,
            $user_id
        ]);
        
        return ['message' => 'Class schedule updated successfully!', 'type' => 'success'];
    } catch (Exception $e) {
        error_log("Error updating class schedule: " . $e->getMessage());
        return ['message' => 'Error updating class schedule. Please try again.', 'type' => 'error'];
    }
}

function deleteClassSchedule($schedule_id, $db, $user_id) {
    try {
        $schedule_id = intval($schedule_id);
        
        $stmt = $db->prepare("DELETE FROM class_schedules WHERE id = ? AND user_id = ?");
        $stmt->execute([$schedule_id, $user_id]);
        
        if ($stmt->rowCount() > 0) {
            return ['message' => 'Class schedule deleted successfully!', 'type' => 'success'];
        } else {
            return ['message' => 'Schedule not found or already deleted.', 'type' => 'error'];
        }
    } catch (Exception $e) {
        error_log("Error deleting class schedule: " . $e->getMessage());
        return ['message' => 'Error deleting class schedule. Please try again.', 'type' => 'error'];
    }
}

function getClassSchedules($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM class_schedules WHERE user_id = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error fetching class schedules: " . $e->getMessage());
        return [];
    }
}

function validateScheduleData($data) {
    $errors = [];
    
    if (empty(trim($data['subject']))) {
        $errors[] = 'Subject is required';
    }
    
    if (empty(trim($data['day_of_week']))) {
        $errors[] = 'Day of week is required';
    }
    
    if (empty(trim($data['start_time']))) {
        $errors[] = 'Start time is required';
    }
    
    if (empty(trim($data['end_time']))) {
        $errors[] = 'End time is required';
    }
    
    // Validate time format and logic
    if (!empty($data['start_time']) && !empty($data['end_time'])) {
        if (strtotime($data['start_time']) >= strtotime($data['end_time'])) {
            $errors[] = 'Start time must be before end time';
        }
    }
    
    return $errors;
}

function hasTimeConflict($data, $db, $user_id, $exclude_id = null) {
    try {
        $sql = "SELECT id FROM class_schedules WHERE user_id = ? AND day_of_week = ? AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?) OR (start_time >= ? AND end_time <= ?))";
        $params = [
            $user_id,
            $data['day_of_week'],
            $data['end_time'],
            $data['start_time'],
            $data['start_time'],
            $data['start_time'],
            $data['start_time'],
            $data['end_time']
        ];
        
        if ($exclude_id !== null) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error checking time conflict: " . $e->getMessage());
        return false;
    }
}

// Generate timetable array
function generateTimetable($schedules) {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $timetable = [];
    
    foreach ($schedules as $schedule) {
        $day = $schedule['day_of_week'];
        $start_time = date('H:i', strtotime($schedule['start_time']));
        $timetable[$day][$start_time] = $schedule;
    }
    
    return $timetable;
}

$timetable = generateTimetable($schedules);

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
    <title>Class Schedule - EduHive</title>
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

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: #8B4513;
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .sidebar-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .sidebar-header h2 {
            font-size: 24px;
            font-weight: 600;
        }

        .nav-menu {
            list-style: none;
            padding: 20px 0;
        }

        .nav-item {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .nav-item a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .nav-item:hover a,
        .nav-item.active a {
            background: rgba(255, 255, 255, 0.1);
        }

        .menu-icon {
            margin-right: 10px;
            font-size: 16px;
            width: 20px;
            display: inline-block;
        }

        /* Main Content */
        .main-content {
            margin-left: 250px;
            flex: 1;
            padding: 30px;
            min-height: 100vh;
        }

        .class-schedule-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .schedule-title {
            color: #333;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 2px;
            margin: 0;
        }

        .add-schedule-btn {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .add-schedule-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Timetable Styles */
        .timetable-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .timetable {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .timetable th,
        .timetable td {
            padding: 15px 10px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .timetable th {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .time-slot {
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
            width: 100px;
        }

        .class-cell {
            background: #fff;
            min-height: 60px;
            position: relative;
            vertical-align: top;
        }

        .class-item {
            background: linear-gradient(135deg, #E3F2FD, #BBDEFB);
            border-left: 4px solid #2196F3;
            border-radius: 8px;
            padding: 8px;
            margin: 2px;
            text-align: left;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .class-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
        }

        .class-subject {
            font-weight: 600;
            color: #1976D2;
            font-size: 12px;
            margin-bottom: 2px;
        }

        .class-code {
            font-size: 10px;
            color: #666;
            margin-bottom: 2px;
        }

        .class-location {
            font-size: 10px;
            color: #888;
        }

        .class-actions {
            position: absolute;
            top: 2px;
            right: 2px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .class-item:hover .class-actions {
            opacity: 1;
        }

        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            margin: 0 1px;
            border-radius: 3px;
            font-size: 12px;
        }

        .edit-btn {
            color: #4CAF50;
        }

        .delete-btn {
            color: #f44336;
        }

        .action-btn:hover {
            background: rgba(255,255,255,0.8);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            overflow-y: auto;
            padding: 20px 0;
        }

        .modal-content {
            background-color: #f8f9fa;
            margin: 20px auto;
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
            position: relative;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .modal-title {
            color: #333;
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #666;
            padding: 5px;
            line-height: 1;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            color: #333;
            background: #e9ecef;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 600;
            font-size: 13px;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: white;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: #4A90A4;
            box-shadow: 0 0 0 2px rgba(74, 144, 164, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
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

        /* Schedule List */
        .schedule-list {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            padding: 25px;
            margin-top: 30px;
        }

        .schedule-list h3 {
            color: #333;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .schedule-item {
            padding: 15px;
            border-left: 4px solid #2196F3;
            background: #f8f9fa;
            margin-bottom: 10px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .schedule-info {
            flex: 1;
        }

        .schedule-subject {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .schedule-details {
            font-size: 14px;
            color: #666;
        }

        .schedule-actions {
            display: flex;
            gap: 10px;
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
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .class-schedule-container {
                padding: 10px;
            }

            .schedule-header {
                flex-direction: column;
                align-items: stretch;
            }

            .schedule-title {
                font-size: 20px;
                text-align: center;
            }

            .timetable-container {
                overflow-x: auto;
            }

            .modal {
                padding: 10px 0;
            }

            .modal-content {
                margin: 10px auto;
                padding: 20px;
                width: 95%;
                max-width: none;
                max-height: 90vh;
                border-radius: 10px;
            }

            .modal-title {
                font-size: 16px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .form-actions {
                flex-direction: column;
                gap: 8px;
            }

            .btn {
                width: 100%;
                padding: 12px;
            }

            .schedule-item {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .schedule-actions {
                justify-content: center;
            }
        }

        @media (max-height: 600px) {
            .modal-content {
                max-height: 95vh;
                margin: 10px auto;
            }
            
            .form-group {
                margin-bottom: 12px;
            }
            
            .form-actions {
                margin-top: 15px;
                padding-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include_once 'sidebar.php'; ?>
        
        <main class="main-content">
            <div class="class-schedule-container">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="schedule-header">
                    <h1 class="schedule-title">CLASS SCHEDULE</h1>
                    <button class="add-schedule-btn" onclick="openModal()">
                        + Add New Class
                    </button>
                </div>

                <!-- Timetable View -->
                <div class="timetable-container">
                    <table class="timetable">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Mon</th>
                                <th>Tue</th>
                                <th>Wed</th>
                                <th>Thu</th>
                                <th>Fri</th>
                                <th>Sat</th>
                                <th>Sun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $time_slots = [
                                '08:00' => '09:00',
                                '09:00' => '10:00', 
                                '10:00' => '11:00',
                                '11:00' => '12:00',
                                '12:00' => '13:00',
                                '13:00' => '14:00',
                                '14:00' => '15:00',
                                '15:00' => '16:00',
                                '16:00' => '17:00',
                                '17:00' => '18:00',
                                '18:00' => '19:00'
                            ];
                            
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            
                            foreach ($time_slots as $start => $end):
                            ?>
                            <tr>
                                <td class="time-slot"><?php echo $start; ?><br><small><?php echo $end; ?></small></td>
                                <?php foreach ($days as $day): ?>
                                <td class="class-cell">
                                    <?php
                                    if (isset($timetable[$day])) {
                                        foreach ($timetable[$day] as $time => $class) {
                                            $class_start = date('H:i', strtotime($class['start_time']));
                                            $class_end = date('H:i', strtotime($class['end_time']));
                                            
                                            // Check if this class falls within current time slot
                                            if ($class_start <= $start && $class_end > $start) {
                                                echo '<div class="class-item" onclick="editSchedule(' . $class['id'] . ')">';
                                                echo '<div class="class-subject">' . htmlspecialchars($class['subject']) . '</div>';
                                                echo '<div class="class-code">' . htmlspecialchars($class['course_code']) . '</div>';
                                                echo '<div class="class-location">' . htmlspecialchars($class['location']) . '</div>';
                                                echo '<div class="class-actions">';
                                                echo '<button class="action-btn edit-btn" onclick="event.stopPropagation(); editSchedule(' . $class['id'] . ')" title="Edit">✏️</button>';
                                                echo '<button class="action-btn delete-btn" onclick="event.stopPropagation(); deleteSchedule(' . $class['id'] . ')" title="Delete">🗑️</button>';
                                                echo '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Schedule List View -->
                <div class="schedule-list">
                    <h3>All Classes</h3>
                    <?php if (empty($schedules)): ?>
                        <p style="text-align: center; color: #666; padding: 40px;">
                            No classes scheduled yet. Click "Add New Class" to get started!
                        </p>
                    <?php else: ?>
                        <?php foreach ($schedules as $schedule): ?>
                        <div class="schedule-item">
                            <div class="schedule-info">
                                <div class="schedule-subject">
                                    <?php echo htmlspecialchars($schedule['subject']); ?>
                                    <?php if ($schedule['course_code']): ?>
                                        <span style="color: #666;">- <?php echo htmlspecialchars($schedule['course_code']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="schedule-details">
                                    <?php echo htmlspecialchars($schedule['day_of_week']); ?> 
                                    <?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
                                    <?php if ($schedule['location']): ?>
                                        | <?php echo htmlspecialchars($schedule['location']); ?>
                                    <?php endif; ?>
                                    <?php if ($schedule['instructor']): ?>
                                        | <?php echo htmlspecialchars($schedule['instructor']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="schedule-actions">
                                <button class="btn btn-primary" onclick="editSchedule(<?php echo $schedule['id']; ?>)" style="padding: 8px 15px; font-size: 12px;">
                                    Edit
                                </button>
                                <button class="btn btn-secondary" onclick="deleteSchedule(<?php echo $schedule['id']; ?>)" style="padding: 8px 15px; font-size: 12px; background: #dc3545;">
                                    Delete
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Schedule Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add New Class</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <form id="scheduleForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="formAction" value="add_schedule">
                <input type="hidden" name="schedule_id" id="scheduleId" value="">
                
                <div class="form-group">
                    <label class="form-label">Subject *</label>
                    <input type="text" name="subject" id="subject" class="form-input" required maxlength="100">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Course Code</label>
                        <input type="text" name="course_code" id="course_code" class="form-input" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Instructor</label>
                        <input type="text" name="instructor" id="instructor" class="form-input" maxlength="100">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Day of Week *</label>
                    <select name="day_of_week" id="day_of_week" class="form-select" required>
                        <option value="">Select Day</option>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time *</label>
                        <input type="time" name="start_time" id="start_time" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Time *</label>
                        <input type="time" name="end_time" id="end_time" class="form-input" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" id="location" class="form-input" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Room</label>
                        <input type="text" name="room" id="room" class="form-input" maxlength="50">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Semester</label>
                    <input type="text" name="semester" id="semester" class="form-input" maxlength="20" placeholder="e.g., Fall 2024">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Add Class</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal() {
            const modal = document.getElementById('scheduleModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Reset form
            document.getElementById('modalTitle').textContent = 'Add New Class';
            document.getElementById('formAction').value = 'add_schedule';
            document.getElementById('submitBtn').textContent = 'Add Class';
            document.getElementById('scheduleForm').reset();
            document.getElementById('scheduleId').value = '';
            
            // Focus on first input after modal animation
            setTimeout(() => {
                const firstInput = modal.querySelector('input[type="text"]');
                if (firstInput) firstInput.focus();
            }, 300);
            
            // Ensure modal is scrolled to top
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent) modalContent.scrollTop = 0;
        }

        function closeModal() {
            document.getElementById('scheduleModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function editSchedule(scheduleId) {
            // Get schedule data via AJAX or from existing data
            const schedules = <?php echo json_encode($schedules); ?>;
            const schedule = schedules.find(s => s.id == scheduleId);
            
            if (schedule) {
                const modal = document.getElementById('scheduleModal');
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                
                document.getElementById('modalTitle').textContent = 'Edit Class';
                document.getElementById('formAction').value = 'update_schedule';
                document.getElementById('submitBtn').textContent = 'Update Class';
                document.getElementById('scheduleId').value = schedule.id;
                
                // Fill form fields
                document.getElementById('subject').value = schedule.subject || '';
                document.getElementById('course_code').value = schedule.course_code || '';
                document.getElementById('instructor').value = schedule.instructor || '';
                document.getElementById('day_of_week').value = schedule.day_of_week || '';
                document.getElementById('start_time').value = schedule.start_time || '';
                document.getElementById('end_time').value = schedule.end_time || '';
                document.getElementById('location').value = schedule.location || '';
                document.getElementById('room').value = schedule.room || '';
                document.getElementById('semester').value = schedule.semester || '';
                
                // Ensure modal is scrolled to top
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) modalContent.scrollTop = 0;
                
                // Focus on first input
                setTimeout(() => {
                    document.getElementById('subject').focus();
                }, 300);
            }
        }

        function deleteSchedule(scheduleId) {
            if (confirm('Are you sure you want to delete this class schedule?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="delete_schedule">
                    <input type="hidden" name="schedule_id" value="${scheduleId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('scheduleModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Handle keyboard navigation
        document.addEventListener('keydown', function(event) {
            const modal = document.getElementById('scheduleModal');
            if (modal.style.display === 'block') {
                if (event.key === 'Escape') {
                    closeModal();
                }
            }
        });

        // Form validation
        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            if (startTime && endTime && startTime >= endTime) {
                e.preventDefault();
                alert('Start time must be before end time');
                return false;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';
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
    </script>
</body>
</html>