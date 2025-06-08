<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Get task ID if provided
$selected_task_id = (int)($_GET['task_id'] ?? 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_time_record':
                $task_id = (int)($_POST['task_id'] ?? 0);
                $activity_name = cleanInput($_POST['activity_name'] ?? '');
                $duration = (int)($_POST['duration'] ?? 0);
                $date = cleanInput($_POST['date'] ?? '');
                $notes = cleanInput($_POST['notes'] ?? '');
                
                if (empty($activity_name) || $duration <= 0 || empty($date)) {
                    setMessage('Activity name, duration, and date are required.', 'error');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO time_records (user_id, task_id, activity_name, duration, date, notes, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $user['id'], 
                            $task_id ?: null, 
                            $activity_name, 
                            $duration, 
                            $date, 
                            $notes
                        ]);
                        setMessage('Time record added successfully!', 'success');
                    } catch (Exception $e) {
                        setMessage('Error adding time record. Please try again.', 'error');
                    }
                }
                break;
                
            case 'delete_record':
                $record_id = (int)($_POST['record_id'] ?? 0);
                
                try {
                    $stmt = $db->prepare("DELETE FROM time_records WHERE id = ? AND user_id = ?");
                    $stmt->execute([$record_id, $user['id']]);
                    setMessage('Time record deleted successfully!', 'success');
                } catch (Exception $e) {
                    setMessage('Error deleting time record.', 'error');
                }
                break;
        }
    }
    
    header("Location: record_time.php" . ($selected_task_id ? "?task_id=$selected_task_id" : ""));
    exit();
}

// Get user's tasks for dropdown
try {
    $stmt = $db->prepare("
        SELECT id, title, course FROM tasks 
        WHERE user_id = ? AND status != 'completed' 
        ORDER BY title ASC
    ");
    $stmt->execute([$user['id']]);
    $user_tasks = $stmt->fetchAll();
} catch (Exception $e) {
    $user_tasks = [];
}

// Get time records for current week
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));

try {
    $stmt = $db->prepare("
        SELECT tr.*, t.title as task_title, t.course 
        FROM time_records tr
        LEFT JOIN tasks t ON tr.task_id = t.id
        WHERE tr.user_id = ? AND tr.date BETWEEN ? AND ?
        ORDER BY tr.date DESC, tr.created_at DESC
    ");
    $stmt->execute([$user['id'], $week_start, $week_end]);
    $week_records = $stmt->fetchAll();
} catch (Exception $e) {
    $week_records = [];
}

// Get today's total time
$today = date('Y-m-d');
$today_total = 0;
foreach ($week_records as $record) {
    if ($record['date'] === $today) {
        $today_total += $record['duration'];
    }
}

// Get week total time
$week_total = array_sum(array_column($week_records, 'duration'));

// Group records by date
$records_by_date = [];
foreach ($week_records as $record) {
    $date = $record['date'];
    if (!isset($records_by_date[$date])) {
        $records_by_date[$date] = [];
    }
    $records_by_date[$date][] = $record;
}

// Calculate daily totals
$daily_totals = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($week_start . " +$i days"));
    $daily_total = 0;
    if (isset($records_by_date[$date])) {
        foreach ($records_by_date[$date] as $record) {
            $daily_total += $record['duration'];
        }
    }
    $daily_totals[$date] = $daily_total;
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Tracking - EduHive</title>
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

        .quick-timer {
            display: flex;
            align-items: center;
            gap: 15px;
            background: white;
            padding: 15px 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .timer-display {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            min-width: 100px;
            text-align: center;
        }

        .timer-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .timer-btn.start {
            background: #28a745;
            color: white;
        }

        .timer-btn.stop {
            background: #dc3545;
            color: white;
        }

        .timer-btn.reset {
            background: #6c757d;
            color: white;
        }

        .timer-btn:hover {
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

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Stats Grid */
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

        .stat-card.today {
            border-left-color: #4A90A4;
        }

        .stat-card.week {
            border-left-color: #C4A484;
        }

        .stat-card.records {
            border-left-color: #28a745;
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
            grid-template-columns: 1fr 1fr;
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

        .add-record-btn {
            padding: 8px 16px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
        }

        .add-record-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-1px);
        }

        /* Time Records */
        .time-record {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .time-record:last-child {
            border-bottom: none;
        }

        .record-info {
            flex: 1;
        }

        .record-activity {
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }

        .record-meta {
            font-size: 12px;
            color: #666;
        }

        .record-duration {
            font-size: 18px;
            font-weight: 600;
            color: #4A90A4;
            margin-right: 15px;
        }

        .record-actions {
            display: flex;
            gap: 5px;
        }

        .record-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .record-btn.danger {
            background-color: #dc3545;
            color: white;
        }

        .record-btn:hover {
            opacity: 0.8;
        }

        /* Daily Chart */
        .daily-chart {
            height: 200px;
            display: flex;
            align-items: end;
            justify-content: space-around;
            background: linear-gradient(to top, #f8f9fa, white);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-bar {
            flex: 1;
            margin: 0 2px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .bar {
            width: 100%;
            background: linear-gradient(to top, #C4A484, #B8956A);
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            transition: all 0.3s ease;
            position: relative;
        }

        .bar:hover {
            background: linear-gradient(to top, #B8956A, #A6845C);
        }

        .bar-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .bar-value {
            font-size: 10px;
            color: #333;
            font-weight: 600;
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

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #4A90A4;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .duration-input {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .duration-input input {
            width: 80px;
        }

        .duration-input span {
            color: #666;
            font-size: 14px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn.primary {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
        }

        .btn.secondary {
            background: #6c757d;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
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

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .quick-timer {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .duration-input {
                flex-direction: column;
                align-items: stretch;
                gap: 5px;
            }

            .daily-chart {
                height: 150px;
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 15px;
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
            <h1 class="page-title">Time Tracking</h1>
            <div class="quick-timer">
                <div class="timer-display" id="timerDisplay">00:00:00</div>
                <button class="timer-btn start" id="startBtn" onclick="startTimer()">▶️ Start</button>
                <button class="timer-btn stop" id="stopBtn" onclick="stopTimer()" style="display: none;">⏸️ Stop</button>
                <button class="timer-btn reset" onclick="resetTimer()">🔄 Reset</button>
            </div>
        </div>

        <!-- Show Message if exists -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card today">
                <div class="stat-header">
                    <span class="stat-title">Today</span>
                    <span class="stat-icon">📅</span>
                </div>
                <div class="stat-value"><?php echo formatDuration($today_total); ?></div>
                <div class="stat-subtitle">Study time logged</div>
            </div>

            <div class="stat-card week">
                <div class="stat-header">
                    <span class="stat-title">This Week</span>
                    <span class="stat-icon">📊</span>
                </div>
                <div class="stat-value"><?php echo formatDuration($week_total); ?></div>
                <div class="stat-subtitle">Total time tracked</div>
            </div>

            <div class="stat-card records">
                <div class="stat-header">
                    <span class="stat-title">Records</span>
                    <span class="stat-icon">📋</span>
                </div>
                <div class="stat-value"><?php echo count($week_records); ?></div>
                <div class="stat-subtitle">Entries this week</div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Daily Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">This Week's Progress</h3>
                </div>
                <div class="card-body">
                    <div class="daily-chart">
                        <?php
                        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                        $max_time = max(array_values($daily_totals)) ?: 60; // Minimum 1 hour for scale
                        
                        for ($i = 0; $i < 7; $i++) {
                            $date = date('Y-m-d', strtotime($week_start . " +$i days"));
                            $time = $daily_totals[$date];
                            $height = $max_time > 0 ? ($time / $max_time) * 150 : 0;
                            ?>
                            <div class="chart-bar">
                                <div class="bar" style="height: <?php echo $height; ?>px;" 
                                     title="<?php echo formatDuration($time); ?>"></div>
                                <div class="bar-value"><?php echo $time > 0 ? formatDuration($time) : '0m'; ?></div>
                                <div class="bar-label"><?php echo $days[$i]; ?></div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Recent Records -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent Time Records</h3>
                    <button class="add-record-btn" onclick="openModal('addRecordModal')">
                        ➕ Add Record
                    </button>
                </div>
                <div class="card-body">
                    <?php if (!empty($week_records)): ?>
                        <?php foreach (array_slice($week_records, 0, 5) as $record): ?>
                            <div class="time-record">
                                <div class="record-info">
                                    <div class="record-activity"><?php echo htmlspecialchars($record['activity_name']); ?></div>
                                    <div class="record-meta">
                                        <?php if ($record['task_title']): ?>
                                            Task: <?php echo htmlspecialchars($record['task_title']); ?> • 
                                        <?php endif; ?>
                                        <?php echo formatDate($record['date']); ?>
                                        <?php if ($record['notes']): ?>
                                            • <?php echo htmlspecialchars(substr($record['notes'], 0, 30)); ?>
                                            <?php if (strlen($record['notes']) > 30) echo '...'; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="record-duration"><?php echo formatDuration($record['duration']); ?></div>
                                <div class="record-actions">
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this record?');">
                                        <input type="hidden" name="action" value="delete_record">
                                        <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                        <button type="submit" class="record-btn danger">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">⏱️</div>
                            <p>No time records yet. Start tracking your study time!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Record Modal -->
    <div id="addRecordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add Time Record</h2>
                <button class="close-btn" onclick="closeModal('addRecordModal')">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add_time_record">

                <div class="form-group">
                    <label for="activity_name" class="form-label">Activity Name *</label>
                    <input type="text" id="activity_name" name="activity_name" class="form-input" 
                           placeholder="e.g., Studying Math, Reading Assignment" required>
                </div>

                <div class="form-group">
                    <label for="task_id" class="form-label">Related Task (Optional)</label>
                    <select id="task_id" name="task_id" class="form-select">
                        <option value="">No specific task</option>
                        <?php foreach ($user_tasks as $task): ?>
                            <option value="<?php echo $task['id']; ?>" 
                                    <?php echo ($task['id'] == $selected_task_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($task['title']); ?>
                                <?php if ($task['course']): ?>
                                    (<?php echo htmlspecialchars($task['course']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="duration_hours" class="form-label">Duration *</label>
                        <div class="duration-input">
                            <input type="number" id="duration_hours" min="0" max="23" value="0" placeholder="Hours">
                            <span>hours</span>
                            <input type="number" id="duration_minutes" min="0" max="59" value="30" placeholder="Minutes">
                            <span>minutes</span>
                        </div>
                        <input type="hidden" id="duration" name="duration" required>
                    </div>
                    <div class="form-group">
                        <label for="date" class="form-label">Date *</label>
                        <input type="date" id="date" name="date" class="form-input" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" class="form-textarea" 
                              placeholder="Optional notes about this study session"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('addRecordModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn primary">Add Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Timer Modal -->
    <div id="timerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Save Timer Session</h2>
                <button class="close-btn" onclick="closeModal('timerModal')">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add_time_record">
                <input type="hidden" id="timer_duration" name="duration">

                <div class="form-group">
                    <label for="timer_activity" class="form-label">What did you work on? *</label>
                    <input type="text" id="timer_activity" name="activity_name" class="form-input" 
                           placeholder="e.g., Studying Math, Reading Assignment" required>
                </div>

                <div class="form-group">
                    <label for="timer_task" class="form-label">Related Task (Optional)</label>
                    <select id="timer_task" name="task_id" class="form-select">
                        <option value="">No specific task</option>
                        <?php foreach ($user_tasks as $task): ?>
                            <option value="<?php echo $task['id']; ?>">
                                <?php echo htmlspecialchars($task['title']); ?>
                                <?php if ($task['course']): ?>
                                    (<?php echo htmlspecialchars($task['course']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="timer_date" class="form-label">Date</label>
                    <input type="date" id="timer_date" name="date" class="form-input" 
                           value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="timer_notes" class="form-label">Notes</label>
                    <textarea id="timer_notes" name="notes" class="form-textarea" 
                              placeholder="Optional notes about this study session"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('timerModal'); resetTimer();">
                        Discard
                    </button>
                    <button type="submit" class="btn primary">Save Session</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let timerInterval = null;
        let timerSeconds = 0;
        let isRunning = false;

        function updateDisplay() {
            const hours = Math.floor(timerSeconds / 3600);
            const minutes = Math.floor((timerSeconds % 3600) / 60);
            const seconds = timerSeconds % 60;
            
            const display = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            document.getElementById('timerDisplay').textContent = display;
        }

        function startTimer() {
            if (!isRunning) {
                isRunning = true;
                timerInterval = setInterval(() => {
                    timerSeconds++;
                    updateDisplay();
                }, 1000);
                
                document.getElementById('startBtn').style.display = 'none';
                document.getElementById('stopBtn').style.display = 'inline-block';
            }
        }

        function stopTimer() {
            if (isRunning) {
                isRunning = false;
                clearInterval(timerInterval);
                
                document.getElementById('startBtn').style.display = 'inline-block';
                document.getElementById('stopBtn').style.display = 'none';
                
                // If timer has recorded time, show save modal
                if (timerSeconds > 0) {
                    const minutes = Math.round(timerSeconds / 60);
                    document.getElementById('timer_duration').value = minutes;
                    openModal('timerModal');
                }
            }
        }

        function resetTimer() {
            isRunning = false;
            clearInterval(timerInterval);
            timerSeconds = 0;
            updateDisplay();
            
            document.getElementById('startBtn').style.display = 'inline-block';
            document.getElementById('stopBtn').style.display = 'none';
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Update duration field when hours/minutes change
        function updateDuration() {
            const hours = parseInt(document.getElementById('duration_hours').value) || 0;
            const minutes = parseInt(document.getElementById('duration_minutes').value) || 0;
            const totalMinutes = (hours * 60) + minutes;
            document.getElementById('duration').value = totalMinutes;
        }

        document.getElementById('duration_hours').addEventListener('input', updateDuration);
        document.getElementById('duration_minutes').addEventListener('input', updateDuration);

        // Initialize duration on page load
        updateDuration();

        // Auto-hide success messages
        setTimeout(function() {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                setTimeout(function() {
                    successMessage.style.display = 'none';
                }, 300);
            }
        }, 3000);

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    closeModal(activeModal.id);
                }
            }
            
            // Space bar to start/stop timer (when no modal is open)
            if (event.code === 'Space' && !document.querySelector('.modal.active')) {
                event.preventDefault();
                if (isRunning) {
                    stopTimer();
                } else {
                    startTimer();
                }
            }
        });

        // Prevent timer from running when page is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden && isRunning) {
                // Optional: pause timer when tab is not active
                // stopTimer();
            }
        });

        // Auto-focus activity name when modal opens
        document.getElementById('addRecordModal').addEventListener('transitionend', function(e) {
            if (e.target === this && this.classList.contains('active')) {
                document.getElementById('activity_name').focus();
            }
        });

        // Validate form before submission
        document.querySelector('#addRecordModal form').addEventListener('submit', function(e) {
            const duration = parseInt(document.getElementById('duration').value);
            if (duration <= 0) {
                e.preventDefault();
                alert('Please enter a valid duration (greater than 0 minutes).');
                document.getElementById('duration_minutes').focus();
            }
        });

        // Pre-fill timer activity based on selected task
        document.getElementById('timer_task').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value && !document.getElementById('timer_activity').value) {
                const taskTitle = selectedOption.textContent.split('(')[0].trim();
                document.getElementById('timer_activity').value = `Working on: ${taskTitle}`;
            }
        });

        // Auto-save timer session on page unload (if timer is running)
        window.addEventListener('beforeunload', function(e) {
            if (isRunning && timerSeconds > 60) { // Only if more than 1 minute
                e.preventDefault();
                e.returnValue = 'You have an active timer session. Are you sure you want to leave?';
            }
        });

        // Initialize display
        updateDisplay();
    </script>
</body>
</html>