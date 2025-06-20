<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            
            case 'add_task_with_notifications':
                $title = cleanInput($_POST['title'] ?? '');
                $description = cleanInput($_POST['description'] ?? '');
                $priority = cleanInput($_POST['priority'] ?? 'medium');
                $due_date = cleanInput($_POST['due_date'] ?? '');
                $assign_to = cleanInput($_POST['assign_to'] ?? '');
                
                // Notification preferences
                $notify_email = isset($_POST['notify_email']);
                
                if (empty($title)) {
                    setMessage('Task title is required.', 'error');
                } else {
                    try {
                        // Create task
                        $assigned_user_id = $assign_to ?: $user['id'];
                        
                        $stmt = $db->prepare("
                            INSERT INTO tasks (user_id, title, description, priority, status, due_date, created_at) 
                            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
                        ");
                        $stmt->execute([$assigned_user_id, $title, $description, $priority, $due_date ?: null]);
                        $task_id = $db->lastInsertId();
                        
                        // Send assignment notification if assigning to someone else
                        if ($assign_to != $user['id']) {
                            $channels = ['website'];
                            if ($notify_email) $channels[] = 'email';
                            
                            $assignment_title = "📋 New Task Assigned";
                            $assignment_message = "You have been assigned: '$title'";
                            $assignment_message .= "\nAssigned by: {$user['name']}";
                            if ($due_date) {
                                $assignment_message .= "\nDue: " . date('M d, Y', strtotime($due_date));
                            }
                            
                            sendMultiNotification($assign_to, $assignment_title, $assignment_message, 'task_assignment', $channels);
                        }
                        
                        setMessage('Task created successfully!', 'success');
                    } catch (Exception $e) {
                        error_log('Error creating task: ' . $e->getMessage());
                        setMessage('Error creating task: ' . $e->getMessage(), 'error');
                    }
                }
                break;
            
            case 'run_reminder_check':
                $reminder_count = checkTasksAndSendReminders();
                setMessage("Checked tasks. Sent $reminder_count reminders.", 'info');
                break;

            case 'add_task':
                $title = cleanInput($_POST['title'] ?? '');
                $description = cleanInput($_POST['description'] ?? '');
                $priority = cleanInput($_POST['priority'] ?? 'medium');
                $due_date = cleanInput($_POST['due_date'] ?? '');
                $course = cleanInput($_POST['course'] ?? '');
                
                if (empty($title)) {
                    setMessage('Task title is required.', 'error');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO tasks (user_id, title, description, priority, status, due_date, course, created_at) 
                            VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                        ");
                        $stmt->execute([$user['id'], $title, $description, $priority, $due_date ?: null, $course]);
                        setMessage('Task added successfully!', 'success');
                    } catch (Exception $e) {
                        error_log('Database error: ' . $e->getMessage());
                        setMessage('Error: ' . $e->getMessage(), 'error');
                    }
                }
                break;
                
            case 'update_status':
                $task_id = (int)($_POST['task_id'] ?? 0);
                $status = cleanInput($_POST['status'] ?? '');
                
                try {
                    // Get task details first
                    $stmt = $db->prepare("SELECT title FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $user['id']]);
                    $task = $stmt->fetch();
                    
                    if ($task) {
                        $stmt = $db->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                        $stmt->execute([$status, $task_id, $user['id']]);
                        
                        // Award points and create notification for completed tasks
                        if ($status === 'completed') {
                            $stmt = $db->prepare("
                                INSERT INTO rewards (user_id, badge_name, badge_type, points, description, earned_date) 
                                VALUES (?, 'Task Completed', 'achievement', 10, 'Completed a task', CURRENT_DATE)
                            ");
                            $stmt->execute([$user['id']]);
                            
                            // Create completion notification
                            createNotification(
                                $user['id'], 
                                'Task Completed! 🎉', 
                                "Great job completing '{$task['title']}'! You earned 10 points.", 
                                'achievement'
                            );
                        }
                        
                        setMessage('Task status updated!', 'success');
                    } else {
                        setMessage('Task not found.', 'error');
                    }
                } catch (Exception $e) {
                    error_log('Error updating task: ' . $e->getMessage());
                    setMessage('Error updating task status.', 'error');
                }
                break;
                
            case 'delete_task':
                $task_id = (int)($_POST['task_id'] ?? 0);
                
                try {
                    $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $user['id']]);
                    
                    if ($stmt->rowCount() > 0) {
                        setMessage('Task deleted successfully!', 'success');
                    } else {
                        setMessage('Task not found or already deleted.', 'error');
                    }
                } catch (Exception $e) {
                    error_log('Error deleting task: ' . $e->getMessage());
                    setMessage('Error deleting task.', 'error');
                }
                break;
        }
    }
    
    header("Location: task.php");
    exit();
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$search = cleanInput($_GET['search'] ?? '');

// Build query with proper WHERE conditions
$where_conditions = ["user_id = ?"];
$params = [$user['id']];

if ($filter_status !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $filter_status;
}

if ($filter_priority !== 'all') {
    $where_conditions[] = "priority = ?";
    $params[] = $filter_priority;
}

if (!empty($search)) {
    $where_conditions[] = "(title LIKE ? OR description LIKE ? OR course LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// Get tasks with FIXED ORDER BY clause
try {
    $stmt = $db->prepare("
        SELECT * FROM tasks 
        WHERE $where_clause 
        ORDER BY 
            CASE WHEN status = 'completed' THEN 1 ELSE 0 END,
            CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END,
            due_date ASC,
            created_at DESC
    ");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
    
    // Debug: Log task count
    error_log("Found " . count($tasks) . " tasks for user " . $user['id']);
    
} catch (Exception $e) {
    error_log('Error loading tasks: ' . $e->getMessage());
    $tasks = [];
    setMessage('Error loading tasks: ' . $e->getMessage(), 'error');
}

$message = getMessage();

// Debug: Check if table exists and has data
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM tasks");
    $stmt->execute();
    $total_tasks = $stmt->fetch()['total'];
    error_log("Total tasks in database: " . $total_tasks);
    
    $stmt = $db->prepare("SELECT COUNT(*) as user_tasks FROM tasks WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $user_task_count = $stmt->fetch()['user_tasks'];
    error_log("Tasks for user {$user['id']}: " . $user_task_count);
    
} catch (Exception $e) {
    error_log("Debug query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks - EduHive</title>
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

        .add-task-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 25px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .add-task-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(196, 164, 132, 0.4);
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

        /* Debug Panel */
        .debug-panel {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 12px;
        }

        .debug-panel h4 {
            color: #495057;
            margin-bottom: 10px;
            font-family: 'Segoe UI', sans-serif;
        }

        /* Filters */
        .filters {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-group label {
            font-weight: 500;
            color: #333;
        }

        .filter-select,
        .search-input {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .filter-select:focus,
        .search-input:focus {
            outline: none;
            border-color: #4A90A4;
        }

        .search-input {
            width: 250px;
        }

        .filter-btn {
            padding: 8px 16px;
            background: #4A90A4;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }

        .filter-btn:hover {
            background: #357A8C;
        }

        /* Task Grid */
        .task-grid {
            display: grid;
            gap: 20px;
        }

        .task-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 5px solid;
            transition: all 0.3s ease;
            position: relative;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }

        .task-card.high {
            border-left-color: #dc3545;
        }

        .task-card.medium {
            border-left-color: #ffc107;
        }

        .task-card.low {
            border-left-color: #28a745;
        }

        .task-card.completed {
            opacity: 0.7;
            background-color: #f8f9fa;
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .task-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .task-course {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .task-priority {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .task-priority.high {
            background-color: #f8d7da;
            color: #721c24;
        }

        .task-priority.medium {
            background-color: #fff3cd;
            color: #856404;
        }

        .task-priority.low {
            background-color: #d4edda;
            color: #155724;
        }

        .task-description {
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        .task-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 14px;
            color: #666;
        }

        .task-due {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .task-status {
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .task-status.pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .task-status.in_progress {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .task-status.completed {
            background-color: #d4edda;
            color: #155724;
        }

        .task-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .task-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .task-btn.primary {
            background-color: #4A90A4;
            color: white;
        }

        .task-btn.success {
            background-color: #28a745;
            color: white;
        }

        .task-btn.warning {
            background-color: #ffc107;
            color: #333;
        }

        .task-btn.danger {
            background-color: #dc3545;
            color: white;
        }

        .task-btn:hover {
            opacity: 0.8;
            transform: translateY(-1px);
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
            padding: 60px 20px;
            color: #666;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #333;
        }

        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }

        /* Responsive Design */
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

            .filters {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
                gap: 5px;
            }

            .search-input {
                width: 100%;
            }

            .task-actions {
                justify-content: center;
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
            <h1 class="page-title">Task Management</h1>
            <button class="add-task-btn" onclick="openModal('addTaskModal')">
                ➕ Add New Task
            </button>
        </div>

        <!-- Show Message if exists -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Debug Information (Remove in production) -->
        <div class="debug-panel">
            <h4>🔍 Debug Information:</h4>
            <div>User ID: <?php echo $user['id']; ?></div>
            <div>Total tasks found: <?php echo count($tasks); ?></div>
            <div>Current filters: Status=<?php echo $filter_status; ?>, Priority=<?php echo $filter_priority; ?>, Search='<?php echo htmlspecialchars($search); ?>'</div>
            <div>Database connection: <?php echo $db ? '✅ Connected' : '❌ Failed'; ?></div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap; width: 100%;">
                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status" class="filter-select">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="priority">Priority:</label>
                    <select name="priority" id="priority" class="filter-select">
                        <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="search">Search:</label>
                    <input type="text" name="search" id="search" class="search-input" 
                           placeholder="Search tasks..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <button type="submit" class="filter-btn">Filter</button>
                <a href="task.php" class="filter-btn" style="text-decoration: none; background: #6c757d;">Clear</a>
            </form>
        </div>

        <!-- Task Grid -->
        <div class="task-grid">
            <?php if (!empty($tasks)): ?>
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card <?php echo $task['priority']; ?> <?php echo $task['status']; ?>">
                        <div class="task-header">
                            <div>
                                <h3 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h3>
                                <?php if (!empty($task['course'])): ?>
                                    <div class="task-course"><?php echo htmlspecialchars($task['course']); ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="task-priority <?php echo $task['priority']; ?>">
                                <?php echo ucfirst($task['priority']); ?>
                            </span>
                        </div>

                        <?php if (!empty($task['description'])): ?>
                            <div class="task-description">
                                <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                            </div>
                        <?php endif; ?>

                        <div class="task-meta">
                            <div class="task-due">
                                <?php if ($task['due_date']): ?>
                                    📅 Due: <?php echo formatDate($task['due_date']); ?>
                                    <?php 
                                    $days_until = daysUntilDue($task['due_date']);
                                    if ($days_until < 0): ?>
                                        <span style="color: #dc3545; font-weight: bold;">(Overdue)</span>
                                    <?php elseif ($days_until == 0): ?>
                                        <span style="color: #ffc107; font-weight: bold;">(Due Today)</span>
                                    <?php elseif ($days_until <= 3): ?>
                                        <span style="color: #fd7e14; font-weight: bold;">(Due Soon)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    📅 No due date
                                <?php endif; ?>
                            </div>
                            <span class="task-status <?php echo $task['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                            </span>
                        </div>

                        <div class="task-actions">
                            <?php if ($task['status'] !== 'completed'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="status" value="in_progress">
                                    <?php if ($task['status'] !== 'in_progress'): ?>
                                        <button type="submit" class="task-btn warning">▶️ Start</button>
                                    <?php endif; ?>
                                </form>

                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="task-btn success">✅ Complete</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="status" value="pending">
                                    <button type="submit" class="task-btn primary">↩️ Reopen</button>
                                </form>
                            <?php endif; ?>

                            <a href="record_time.php?task_id=<?php echo $task['id']; ?>" class="task-btn primary">
                                ⏱️ Track Time
                            </a>

                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to delete this task?');">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                <button type="submit" class="task-btn danger">🗑️ Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <h3>No tasks found</h3>
                    <p>
                        <?php if ($filter_status !== 'all' || $filter_priority !== 'all' || !empty($search)): ?>
                            No tasks match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            Start by creating your first task to stay organized!
                        <?php endif; ?>
                    </p>
                    <button class="add-task-btn" onclick="openModal('addTaskModal')">
                        ➕ Add Your First Task
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Task Modal -->
    <div id="addTaskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add New Task</h2>
                <button class="close-btn" onclick="closeModal('addTaskModal')">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add_task">

                <div class="form-group">
                    <label for="title" class="form-label">Task Title *</label>
                    <input type="text" id="title" name="title" class="form-input" 
                           placeholder="Enter task title" required>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-textarea" 
                              placeholder="Enter task description (optional)"></textarea>
                </div>

                <div class="form-group">
                    <label for="course" class="form-label">Course/Subject</label>
                    <input type="text" id="course" name="course" class="form-input" 
                           placeholder="e.g., Mathematics, Physics">
                </div>

                <div class="form-group">
                    <label for="priority" class="form-label">Priority</label>
                    <select id="priority" name="priority" class="form-select">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="due_date" class="form-label">Due Date</label>
                    <input type="date" id="due_date" name="due_date" class="form-input" 
                           min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('addTaskModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn primary">Add Task</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
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

        // Auto-submit filter form on change
        document.getElementById('status').addEventListener('change', function() {
            this.form.submit();
        });

        document.getElementById('priority').addEventListener('change', function() {
            this.form.submit();
        });

        // Focus first input when modal opens
        document.getElementById('addTaskModal').addEventListener('transitionend', function(e) {
            if (e.target === this && this.classList.contains('active')) {
                document.getElementById('title').focus();
            }
        });
    </script>
</body>
</html>