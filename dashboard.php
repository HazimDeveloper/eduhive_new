<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
// requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Get user statistics
$stats = calculateStudyStats($user['id'], $database);
$unread_notifications = getUnreadNotificationCount($user['id'], $database);
// Get recent tasks (next 5 upcoming tasks)
try {
    $stmt = $db->prepare("
        SELECT * FROM tasks 
        WHERE user_id = ? AND status != 'completed' 
        ORDER BY due_date ASC, priority DESC 
        LIMIT 5
    ");
    $stmt->execute([$user['id']]);
    $recent_tasks = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_tasks = [];
}

// Get today's classes
try {
    $today = date('l'); // Full day name
    $stmt = $db->prepare("
        SELECT * FROM class_schedules 
        WHERE user_id = ? AND day_of_week = ? 
        ORDER BY start_time ASC
    ");
    $stmt->execute([$user['id'], $today]);
    $today_classes = $stmt->fetchAll();
} catch (Exception $e) {
    $today_classes = [];
}

// Get recent rewards
try {
    $stmt = $db->prepare("
        SELECT * FROM rewards 
        WHERE user_id = ? 
        ORDER BY earned_date DESC 
        LIMIT 3
    ");
    $stmt->execute([$user['id']]);
    $recent_rewards = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_rewards = [];
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - EduHive</title>
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
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .page-subtitle {
            color: #666;
            font-size: 16px;
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

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 5px solid;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card.tasks {
            border-left-color: #4A90A4;
        }

        .stat-card.completed {
            border-left-color: #28a745;
        }

        .stat-card.time {
            border-left-color: #C4A484;
        }

        .stat-card.progress {
            border-left-color: #ffc107;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-icon {
            font-size: 24px;
            opacity: 0.7;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
        }

        .stat-subtitle {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .card-body {
            padding: 25px;
        }

        /* Task List */
        .task-list {
            list-style: none;
        }

        .task-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.3s ease;
        }

        .task-item:hover {
            background-color: #f8f9fa;
            margin: 0 -25px;
            padding-left: 25px;
            padding-right: 25px;
        }

        .task-item:last-child {
            border-bottom: none;
        }

        .task-priority {
            width: 4px;
            height: 30px;
            border-radius: 2px;
            margin-right: 15px;
        }

        .task-priority.high {
            background-color: #dc3545;
        }

        .task-priority.medium {
            background-color: #ffc107;
        }

        .task-priority.low {
            background-color: #28a745;
        }

        .task-content {
            flex: 1;
        }

        .task-title {
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }

        .task-meta {
            font-size: 12px;
            color: #666;
        }

        .task-due {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 500;
        }

        .task-due.today {
            background-color: #fff3cd;
            color: #856404;
        }

        .task-due.overdue {
            background-color: #f8d7da;
            color: #721c24;
        }

        .task-due.upcoming {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        /* Class Schedule */
        .class-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .class-item:last-child {
            border-bottom: none;
        }

        .class-time {
            width: 80px;
            font-size: 14px;
            font-weight: 600;
            color: #4A90A4;
        }

        .class-details {
            flex: 1;
        }

        .class-subject {
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .class-location {
            font-size: 12px;
            color: #666;
        }

        /* Rewards */
        .reward-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .reward-item:last-child {
            border-bottom: none;
        }

        .reward-icon {
            font-size: 24px;
            margin-right: 15px;
        }

        .reward-details {
            flex: 1;
        }

        .reward-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .reward-date {
            font-size: 12px;
            color: #666;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .quick-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .quick-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(196, 164, 132, 0.4);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            color: #666;
            padding: 40px 20px;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .page-title {
                font-size: 24px;
            }

            .quick-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 15px;
            }

            .card-body {
                padding: 20px;
            }
        }

        .notification-badge {
    background: #dc3545;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 600;
    margin-left: 5px;
}

.nav-item a {
    position: relative;
}

.nav-item a .notification-badge {
    position: absolute;
    top: 10px;
    right: 10px;
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
            <h1 class="page-title">Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</h1>
            <p class="page-subtitle">Here's what's happening with your studies today</p>
        </div>

        <!-- Show Message if exists -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="task.php" class="quick-btn">➕ Add Task</a>
            <a href="calendar.php" class="quick-btn">📅 View Calendar</a>
            <a href="record_time.php" class="quick-btn">⏱️ Track Time</a>
                    
<div class="notification-indicator quick-btn">
    <a href="notifications.php" style="text-decoration: none;color: white;">
        🔔 Notifications
        <?php if ($unread_notifications > 0): ?>
            <span class="badge"><?php echo $unread_notifications; ?></span>
        <?php endif; ?>
    </a>
</div>
        </div>

        

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card tasks">
                <div class="stat-header">
                    <span class="stat-title">Total Tasks</span>
                    <span class="stat-icon">📋</span>
                </div>
                <div class="stat-value"><?php echo $stats['total_tasks']; ?></div>
                <div class="stat-subtitle"><?php echo $stats['pending_tasks']; ?> pending</div>
            </div>

            <div class="stat-card completed">
                <div class="stat-header">
                    <span class="stat-title">Completed</span>
                    <span class="stat-icon">✅</span>
                </div>
                <div class="stat-value"><?php echo $stats['completed_tasks']; ?></div>
                <div class="stat-subtitle"><?php echo $stats['completion_rate']; ?>% completion rate</div>
            </div>

            <div class="stat-card time">
                <div class="stat-header">
                    <span class="stat-title">This Week</span>
                    <span class="stat-icon">⏰</span>
                </div>
                <div class="stat-value"><?php echo formatDuration($stats['weekly_time']); ?></div>
                <div class="stat-subtitle">Study time logged</div>
            </div>

            <div class="stat-card progress">
                <div class="stat-header">
                    <span class="stat-title">Progress</span>
                    <span class="stat-icon">📈</span>
                </div>
                <div class="stat-value"><?php echo $stats['completion_rate']; ?>%</div>
                <div class="stat-subtitle">Overall completion</div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Upcoming Tasks -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Upcoming Tasks</h3>
                    <a href="task.php" style="color: #4A90A4; text-decoration: none; font-size: 14px;">View All</a>
                </div>
                <div class="card-body">
                    <?php if (!empty($recent_tasks)): ?>
                        <ul class="task-list">
                            <?php foreach ($recent_tasks as $task): ?>
                                <li class="task-item">
                                    <div class="task-priority <?php echo $task['priority']; ?>"></div>
                                    <div class="task-content">
                                        <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <div class="task-meta">
                                            <?php if ($task['course']): ?>
                                                <?php echo htmlspecialchars($task['course']); ?> •
                                            <?php endif; ?>
                                            Priority: <?php echo ucfirst($task['priority']); ?>
                                        </div>
                                    </div>
                                    <?php if ($task['due_date']): ?>
                                        <?php 
                                        $days_until = daysUntilDue($task['due_date']);
                                        $due_class = 'upcoming';
                                        if ($days_until < 0) $due_class = 'overdue';
                                        elseif ($days_until == 0) $due_class = 'today';
                                        ?>
                                        <span class="task-due <?php echo $due_class; ?>">
                                            <?php 
                                            if ($days_until < 0) echo 'Overdue';
                                            elseif ($days_until == 0) echo 'Due Today';
                                            else echo 'Due in ' . $days_until . ' day' . ($days_until > 1 ? 's' : '');
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📝</div>
                            <p>No upcoming tasks. Great job!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar Content -->
            <div>
                <!-- Today's Classes -->
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header">
                        <h3 class="card-title">Today's Classes</h3>
                        <a href="class_schedule.php" style="color: #4A90A4; text-decoration: none; font-size: 14px;">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($today_classes)): ?>
                            <?php foreach ($today_classes as $class): ?>
                                <div class="class-item">
                                    <div class="class-time">
                                        <?php echo date('g:i A', strtotime($class['start_time'])); ?>
                                    </div>
                                    <div class="class-details">
                                        <div class="class-subject"><?php echo htmlspecialchars($class['subject']); ?></div>
                                        <div class="class-location">
                                            <?php echo htmlspecialchars($class['location'] ?? 'No location'); ?>
                                            <?php if ($class['room']): ?>
                                                - <?php echo htmlspecialchars($class['room']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🎓</div>
                                <p>No classes today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Rewards -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Achievements</h3>
                        <a href="reward.php" style="color: #4A90A4; text-decoration: none; font-size: 14px;">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_rewards)): ?>
                            <?php foreach ($recent_rewards as $reward): ?>
                                <div class="reward-item">
                                    <span class="reward-icon">🏆</span>
                                    <div class="reward-details">
                                        <div class="reward-name"><?php echo htmlspecialchars($reward['badge_name']); ?></div>
                                        <div class="reward-date"><?php echo formatDate($reward['earned_date']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🌟</div>
                                <p>Complete tasks to earn rewards!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh stats every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);

        // Mobile sidebar toggle (if needed)
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Auto-hide success messages after 5 seconds
        setTimeout(function() {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                setTimeout(function() {
                    successMessage.style.display = 'none';
                }, 300);
            }
        }, 5000);
    </script>
</body>
</html>