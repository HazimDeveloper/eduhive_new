<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Get current month and year
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
if ($current_month < 1 || $current_month > 12) $current_month = date('n');
if ($current_year < 2020 || $current_year > 2030) $current_year = date('Y');

// Get month info
$first_day = mktime(0, 0, 0, $current_month, 1, $current_year);
$days_in_month = date('t', $first_day);
$month_name = date('F Y', $first_day);
$first_day_of_week = date('w', $first_day);

// Get tasks for this month
try {
    $start_date = date('Y-m-01', $first_day);
    $end_date = date('Y-m-t', $first_day);
    
    $stmt = $db->prepare("
        SELECT id, title, due_date, priority, status, course
        FROM tasks 
        WHERE user_id = ? AND due_date BETWEEN ? AND ?
        ORDER BY due_date, priority DESC
    ");
    $stmt->execute([$user['id'], $start_date, $end_date]);
    $tasks = $stmt->fetchAll();
    
    // Group tasks by date
    $tasks_by_date = [];
    foreach ($tasks as $task) {
        $day = (int)date('j', strtotime($task['due_date']));
        if (!isset($tasks_by_date[$day])) {
            $tasks_by_date[$day] = [];
        }
        $tasks_by_date[$day][] = $task;
    }
} catch (Exception $e) {
    $tasks_by_date = [];
}

// Get classes for this month
try {
    $stmt = $db->prepare("
        SELECT id, subject, day_of_week, start_time, end_time, location, room, instructor
        FROM class_schedules 
        WHERE user_id = ?
        ORDER BY start_time
    ");
    $stmt->execute([$user['id']]);
    $classes = $stmt->fetchAll();
    
    // Group classes by day of week
    $classes_by_day = [];
    foreach ($classes as $class) {
        $day_name = $class['day_of_week'];
        if (!isset($classes_by_day[$day_name])) {
            $classes_by_day[$day_name] = [];
        }
        $classes_by_day[$day_name][] = $class;
    }
} catch (Exception $e) {
    $classes_by_day = [];
}

// Navigation
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar - EduHive</title>
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
        }

        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .nav-btn {
            padding: 10px 15px;
            background: #4A90A4;
            color: white;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .nav-btn:hover {
            background: #357A8C;
        }

        .month-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            min-width: 200px;
            text-align: center;
        }

        /* Calendar Styles */
        .calendar-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #4A90A4;
            color: white;
        }

        .calendar-header-day {
            padding: 15px;
            text-align: center;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 14px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border-collapse: collapse;
        }

        .calendar-day {
            min-height: 120px;
            border: 1px solid #e9ecef;
            padding: 8px;
            vertical-align: top;
            position: relative;
            background: white;
            transition: background-color 0.3s ease;
        }

        .calendar-day:hover {
            background-color: #f8f9fa;
        }

        .calendar-day.other-month {
            background-color: #f8f9fa;
            color: #6c757d;
        }

        .calendar-day.today {
            background-color: #fff3cd;
            border-color: #ffc107;
        }

        .day-number {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }

        .calendar-day.other-month .day-number {
            color: #adb5bd;
        }

        .day-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            height: calc(100% - 25px);
            overflow-y: auto;
        }

        .task-item {
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.3s ease;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .task-item:hover {
            opacity: 0.8;
        }

        .task-item.high {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 3px solid #dc3545;
        }

        .task-item.medium {
            background-color: #fff3cd;
            color: #856404;
            border-left: 3px solid #ffc107;
        }

        .task-item.low {
            background-color: #d4edda;
            color: #155724;
            border-left: 3px solid #28a745;
        }

        .task-item.completed {
            background-color: #e9ecef;
            color: #6c757d;
            text-decoration: line-through;
            border-left: 3px solid #6c757d;
        }

        .class-item {
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 3px solid #17a2b8;
            cursor: pointer;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .class-item:hover {
            opacity: 0.8;
        }

        /* Legend */
        .calendar-legend {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .legend-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }

        .legend-items {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border-left: 3px solid;
        }

        .legend-color.high {
            background-color: #f8d7da;
            border-left-color: #dc3545;
        }

        .legend-color.medium {
            background-color: #fff3cd;
            border-left-color: #ffc107;
        }

        .legend-color.low {
            background-color: #d4edda;
            border-left-color: #28a745;
        }

        .legend-color.class {
            background-color: #d1ecf1;
            border-left-color: #17a2b8;
        }

        .legend-color.completed {
            background-color: #e9ecef;
            border-left-color: #6c757d;
        }

        .legend-text {
            font-size: 14px;
            color: #333;
        }

        /* Task Detail Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-btn:hover {
            color: #333;
        }

        .task-details {
            line-height: 1.6;
        }

        .task-details h4 {
            color: #333;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .task-details p {
            color: #666;
            margin-bottom: 15px;
        }

        .task-meta-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .task-meta-item:last-child {
            border-bottom: none;
        }

        .meta-label {
            font-weight: 500;
            color: #333;
        }

        .meta-value {
            color: #666;
        }

        /* Quick Add */
        .quick-add {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .quick-add-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }

        .quick-add-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .quick-input {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .quick-input:focus {
            outline: none;
            border-color: #4A90A4;
        }

        .quick-input.title {
            min-width: 200px;
            flex: 1;
        }

        .quick-btn {
            padding: 8px 16px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .quick-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-1px);
        }

        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }

        .toggle-btn {
            padding: 8px 16px;
            border: 2px solid #4A90A4;
            background: white;
            color: #4A90A4;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .toggle-btn.active,
        .toggle-btn:hover {
            background: #4A90A4;
            color: white;
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

            .calendar-nav {
                flex-direction: column;
                gap: 15px;
            }

            .calendar-header-day {
                padding: 10px 5px;
                font-size: 12px;
            }

            .calendar-day {
                min-height: 80px;
                padding: 5px;
            }

            .task-item,
            .class-item {
                font-size: 10px;
                padding: 2px 4px;
            }

            .legend-items {
                flex-direction: column;
                gap: 10px;
            }

            .quick-add-form {
                flex-direction: column;
                align-items: stretch;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(30px);
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
            <h1 class="page-title">Calendar</h1>
            <div class="view-toggle">
                <a href="calendar.php" class="toggle-btn active">Month View</a>
                <a href="class_schedule.php" class="toggle-btn">Schedule View</a>
            </div>
        </div>

        <!-- Calendar Navigation -->
        <div class="calendar-nav">
            <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="nav-btn">
                ◀ Previous
            </a>
            <h2 class="month-title"><?php echo $month_name; ?></h2>
            <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="nav-btn">
                Next ▶
            </a>
            <a href="calendar.php" class="nav-btn">Today</a>
        </div>

        <!-- Quick Add Task -->
        <div class="quick-add">
            <h3 class="quick-add-title">Quick Add Task</h3>
            <form class="quick-add-form" action="task.php" method="POST">
                <input type="hidden" name="action" value="add_task">
                <input type="text" name="title" class="quick-input title" placeholder="Task title" required>
                <input type="date" name="due_date" class="quick-input" value="<?php echo date('Y-m-d'); ?>">
                <select name="priority" class="quick-input">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                </select>
                <button type="submit" class="quick-btn">Add Task</button>
            </form>
        </div>

        <!-- Calendar Legend -->
        <div class="calendar-legend">
            <h3 class="legend-title">Legend</h3>
            <div class="legend-items">
                <div class="legend-item">
                    <div class="legend-color high"></div>
                    <span class="legend-text">High Priority Task</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color medium"></div>
                    <span class="legend-text">Medium Priority Task</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color low"></div>
                    <span class="legend-text">Low Priority Task</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color class"></div>
                    <span class="legend-text">Class/Lecture</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color completed"></div>
                    <span class="legend-text">Completed Task</span>
                </div>
            </div>
        </div>

        <!-- Calendar -->
        <div class="calendar-container">
            <!-- Calendar Header -->
            <div class="calendar-header">
                <div class="calendar-header-day">Sunday</div>
                <div class="calendar-header-day">Monday</div>
                <div class="calendar-header-day">Tuesday</div>
                <div class="calendar-header-day">Wednesday</div>
                <div class="calendar-header-day">Thursday</div>
                <div class="calendar-header-day">Friday</div>
                <div class="calendar-header-day">Saturday</div>
            </div>

            <!-- Calendar Grid -->
            <div class="calendar-grid">
                <?php
                // Calculate the start date (may include days from previous month)
                $start_date = $first_day - ($first_day_of_week * 86400);
                
                // Generate 42 days (6 weeks)
                for ($i = 0; $i < 42; $i++) {
                    $current_date = $start_date + ($i * 86400);
                    $day_number = date('j', $current_date);
                    $day_month = date('n', $current_date);
                    $day_year = date('Y', $current_date);
                    $day_name = date('l', $current_date);
                    $is_today = (date('Y-m-d', $current_date) === date('Y-m-d'));
                    $is_current_month = ($day_month == $current_month && $day_year == $current_year);
                    
                    $class_names = ['calendar-day'];
                    if (!$is_current_month) $class_names[] = 'other-month';
                    if ($is_today) $class_names[] = 'today';
                    ?>
                    <div class="<?php echo implode(' ', $class_names); ?>">
                        <div class="day-number"><?php echo $day_number; ?></div>
                        <div class="day-content">
                            <?php if ($is_current_month): ?>
                                <!-- Show tasks for this day -->
                                <?php if (isset($tasks_by_date[$day_number])): ?>
                                    <?php foreach ($tasks_by_date[$day_number] as $task): ?>
                                        <div class="task-item <?php echo $task['priority']; ?> <?php echo $task['status']; ?>" 
                                             onclick="showTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)"
                                             title="<?php echo htmlspecialchars($task['title']); ?>">
                                            <?php echo htmlspecialchars(substr($task['title'], 0, 20)); ?>
                                            <?php if (strlen($task['title']) > 20) echo '...'; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <!-- Show classes for this day -->
                                <?php if (isset($classes_by_day[$day_name])): ?>
                                    <?php foreach ($classes_by_day[$day_name] as $class): ?>
                                        <div class="class-item" 
                                             onclick="showClassDetails(<?php echo htmlspecialchars(json_encode($class)); ?>)"
                                             title="<?php echo htmlspecialchars($class['subject']); ?>">
                                            <?php echo htmlspecialchars(substr($class['subject'], 0, 15)); ?>
                                            <?php if (strlen($class['subject']) > 15) echo '...'; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Task Details Modal -->
    <div id="taskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Task Details</h2>
                <button class="close-btn" onclick="closeModal('taskModal')">&times;</button>
            </div>
            <div id="taskDetails" class="task-details">
                <!-- Task details will be populated here -->
            </div>
        </div>
    </div>

    <!-- Class Details Modal -->
    <div id="classModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Class Details</h2>
                <button class="close-btn" onclick="closeModal('classModal')">&times;</button>
            </div>
            <div id="classDetails" class="task-details">
                <!-- Class details will be populated here -->
            </div>
        </div>
    </div>

    <script>
        function showTaskDetails(task) {
            const modal = document.getElementById('taskModal');
            const details = document.getElementById('taskDetails');
            
            const priorityColors = {
                'high': '#dc3545',
                'medium': '#ffc107',
                'low': '#28a745'
            };
            
            const statusLabels = {
                'pending': 'Pending',
                'in_progress': 'In Progress',
                'completed': 'Completed'
            };
            
            details.innerHTML = `
                <h4>${task.title}</h4>
                ${task.description ? `<p>${task.description}</p>` : ''}
                <div class="task-meta-item">
                    <span class="meta-label">Course:</span>
                    <span class="meta-value">${task.course || 'No course specified'}</span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Priority:</span>
                    <span class="meta-value" style="color: ${priorityColors[task.priority]}; font-weight: bold;">
                        ${task.priority.charAt(0).toUpperCase() + task.priority.slice(1)}
                    </span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Status:</span>
                    <span class="meta-value">${statusLabels[task.status]}</span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Due Date:</span>
                    <span class="meta-value">${formatDate(task.due_date)}</span>
                </div>
                <div style="margin-top: 20px; text-align: center;">
                    <a href="task.php" style="display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #C4A484, #B8956A); color: white; text-decoration: none; border-radius: 8px; font-weight: 500;">
                        Manage Tasks
                    </a>
                </div>
            `;
            
            openModal('taskModal');
        }
        
        function showClassDetails(classItem) {
            const modal = document.getElementById('classModal');
            const details = document.getElementById('classDetails');
            
            details.innerHTML = `
                <h4>${classItem.subject}</h4>
                <div class="task-meta-item">
                    <span class="meta-label">Course Code:</span>
                    <span class="meta-value">${classItem.course_code || 'N/A'}</span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Instructor:</span>
                    <span class="meta-value">${classItem.instructor || 'Not specified'}</span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Day:</span>
                    <span class="meta-value">${classItem.day_of_week}</span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Time:</span>
                    <span class="meta-value">${formatTime(classItem.start_time)} - ${formatTime(classItem.end_time)}</span>
                </div>
                <div class="task-meta-item">
                    <span class="meta-label">Location:</span>
                    <span class="meta-value">${classItem.location || 'No location specified'}</span>
                </div>
                ${classItem.room ? `
                <div class="task-meta-item">
                    <span class="meta-label">Room:</span>
                    <span class="meta-value">${classItem.room}</span>
                </div>
                ` : ''}
                <div style="margin-top: 20px; text-align: center;">
                    <a href="class_schedule.php" style="display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #C4A484, #B8956A); color: white; text-decoration: none; border-radius: 8px; font-weight: 500;">
                        Manage Schedule
                    </a>
                </div>
            `;
            
            openModal('classModal');
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
        
        function formatTime(timeString) {
            const time = new Date('2000-01-01 ' + timeString);
            return time.toLocaleTimeString('en-US', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    closeModal(activeModal.id);
                }
            }
        });
    </script>
</body>
</html>