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
            
            case 'add_task_with_files':
                $title = cleanInput($_POST['title'] ?? '');
                $description = cleanInput($_POST['description'] ?? '');
                $priority = cleanInput($_POST['priority'] ?? 'medium');
                $due_date = cleanInput($_POST['due_date'] ?? '');
                $course = cleanInput($_POST['course'] ?? '');
                
                if (empty($title)) {
                    setMessage('Task title is required.', 'error');
                } else {
                    try {
                        // Create task first
                        $stmt = $db->prepare("
                            INSERT INTO tasks (user_id, title, description, priority, status, due_date, course, created_at) 
                            VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                        ");
                        $stmt->execute([$user['id'], $title, $description, $priority, $due_date ?: null, $course]);
                        $task_id = $db->lastInsertId();
                        
                        // Handle file uploads if any
                        $uploaded_files = [];
                        if (isset($_FILES['task_files']) && !empty($_FILES['task_files']['name'][0])) {
                            $uploaded_files = handleTaskFileUploads($_FILES['task_files'], $task_id, $user['id'], $db);
                        }
                        
                        $message = 'Task created successfully!';
                        if (!empty($uploaded_files)) {
                            $message .= ' ' . count($uploaded_files) . ' file(s) attached.';
                        }
                        setMessage($message, 'success');
                        
                    } catch (Exception $e) {
                        error_log('Error creating task: ' . $e->getMessage());
                        setMessage('Error creating task: ' . $e->getMessage(), 'error');
                    }
                }
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

            case 'attach_file':
                $task_id = (int)($_POST['task_id'] ?? 0);
                
                if ($task_id <= 0) {
                    setMessage('Invalid task ID.', 'error');
                } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    setMessage('Please select a file to upload.', 'error');
                } else {
                    // Verify task ownership
                    $stmt = $db->prepare("SELECT id FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $user['id']]);
                    if (!$stmt->fetch()) {
                        setMessage('Task not found or access denied.', 'error');
                    } else {
                        $result = uploadFileToTask($_FILES['file'], $task_id, $user['id'], $db);
                        setMessage($result['message'], $result['type']);
                    }
                }
                break;

            case 'delete_file':
                $file_id = (int)($_POST['file_id'] ?? 0);
                $result = deleteTaskFile($file_id, $user['id'], $db);
                setMessage($result['message'], $result['type']);
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
                    // Delete associated files first
                    deleteAllTaskFiles($task_id, $user['id'], $db);
                    
                    // Delete task
                    $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $user['id']]);
                    
                    if ($stmt->rowCount() > 0) {
                        setMessage('Task and all associated files deleted successfully!', 'success');
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

// File handling functions
function handleTaskFileUploads($files, $task_id, $user_id, $db) {
    $uploaded_files = [];
    $upload_dir = 'uploads/task_files/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_count = count($files['name']);
    
    for ($i = 0; $i < $file_count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $result = uploadSingleTaskFile([
                'name' => $files['name'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'size' => $files['size'][$i],
                'type' => $files['type'][$i],
                'error' => $files['error'][$i]
            ], $task_id, $user_id, $db);
            
            if ($result['success']) {
                $uploaded_files[] = $result['filename'];
            }
        }
    }
    
    return $uploaded_files;
}

function uploadSingleTaskFile($file, $task_id, $user_id, $db) {
    try {
        $upload_dir = 'uploads/task_files/';
        
        // Validate file
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            return ['success' => false, 'message' => 'File type not allowed for: ' . $file['name']];
        }
        
        if ($file['size'] > 10000000) { // 10MB limit
            return ['success' => false, 'message' => 'File too large: ' . $file['name']];
        }
        
        // Generate unique filename
        $new_filename = time() . '_' . $task_id . '_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;
        
        // Move file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Save to database
            $stmt = $db->prepare("
                INSERT INTO files (user_id, task_id, filename, original_name, file_path, file_size, file_type, upload_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                $task_id,
                $new_filename,
                $file['name'],
                $upload_path,
                $file['size'],
                $file['type']
            ]);
            
            return ['success' => true, 'filename' => $new_filename];
        } else {
            return ['success' => false, 'message' => 'Failed to upload: ' . $file['name']];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Upload error: ' . $e->getMessage()];
    }
}

function uploadFileToTask($file, $task_id, $user_id, $db) {
    try {
        $upload_dir = 'uploads/task_files/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Validate file
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            return ['message' => 'File type not allowed. Allowed: PDF, DOC, DOCX, TXT, JPG, PNG, GIF, ZIP, RAR', 'type' => 'error'];
        }
        
        if ($file['size'] > 10000000) { // 10MB limit
            return ['message' => 'File too large. Maximum size: 10MB', 'type' => 'error'];
        }
        
        // Generate unique filename
        $new_filename = time() . '_' . $task_id . '_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;
        
        // Move file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Save to database
            $stmt = $db->prepare("
                INSERT INTO files (user_id, task_id, filename, original_name, file_path, file_size, file_type, upload_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                $task_id,
                $new_filename,
                $file['name'],
                $upload_path,
                $file['size'],
                $file['type']
            ]);
            
            return ['message' => 'File attached successfully!', 'type' => 'success'];
        } else {
            return ['message' => 'Failed to upload file.', 'type' => 'error'];
        }
        
    } catch (Exception $e) {
        return ['message' => 'Error uploading file: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function deleteTaskFile($file_id, $user_id, $db) {
    try {
        // Get file info and verify ownership through task
        $stmt = $db->prepare("
            SELECT f.*, t.user_id as task_owner 
            FROM files f 
            JOIN tasks t ON f.task_id = t.id 
            WHERE f.id = ? AND t.user_id = ?
        ");
        $stmt->execute([$file_id, $user_id]);
        $file = $stmt->fetch();
        
        if (!$file) {
            return ['message' => 'File not found or access denied.', 'type' => 'error'];
        }
        
        // Delete physical file
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }
        
        // Delete from database
        $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
        $stmt->execute([$file_id]);
        
        return ['message' => 'File deleted successfully!', 'type' => 'success'];
        
    } catch (Exception $e) {
        return ['message' => 'Error deleting file.', 'type' => 'error'];
    }
}

function deleteAllTaskFiles($task_id, $user_id, $db) {
    try {
        // Get all files for this task
        $stmt = $db->prepare("
            SELECT f.* FROM files f 
            JOIN tasks t ON f.task_id = t.id 
            WHERE f.task_id = ? AND t.user_id = ?
        ");
        $stmt->execute([$task_id, $user_id]);
        $files = $stmt->fetchAll();
        
        // Delete physical files
        foreach ($files as $file) {
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
        }
        
        // Delete from database
        $stmt = $db->prepare("DELETE FROM files WHERE task_id = ?");
        $stmt->execute([$task_id]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error deleting task files: " . $e->getMessage());
        return false;
    }
}

function getTaskFiles($task_id, $user_id, $db) {
    try {
        $stmt = $db->prepare("
            SELECT f.* FROM files f 
            JOIN tasks t ON f.task_id = t.id 
            WHERE f.task_id = ? AND t.user_id = ? 
            ORDER BY f.upload_date DESC
        ");
        $stmt->execute([$task_id, $user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$search = cleanInput($_GET['search'] ?? '');
$view_task_id = (int)($_GET['view'] ?? 0);

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

// Get tasks with files count
try {
    $stmt = $db->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM files WHERE task_id = t.id) as file_count
        FROM tasks t
        WHERE $where_clause 
        ORDER BY 
            CASE WHEN status = 'completed' THEN 1 ELSE 0 END,
            CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END,
            due_date ASC,
            created_at DESC
    ");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log('Error loading tasks: ' . $e->getMessage());
    $tasks = [];
    setMessage('Error loading tasks: ' . $e->getMessage(), 'error');
}

// Get detailed task info if viewing specific task
$selected_task = null;
$task_files = [];
if ($view_task_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$view_task_id, $user['id']]);
        $selected_task = $stmt->fetch();
        
        if ($selected_task) {
            $task_files = getTaskFiles($view_task_id, $user['id'], $db);
        }
    } catch (Exception $e) {
        $selected_task = null;
        $task_files = [];
    }
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management with Files - EduHive</title>
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
            cursor: pointer;
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

        /* File attachments in task card */
        .task-files {
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #4A90A4;
        }

        .task-files-header {
            font-size: 12px;
            font-weight: 600;
            color: #4A90A4;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .file-count {
            background: #4A90A4;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
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

        .task-btn.info {
            background-color: #17a2b8;
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
            max-width: 600px;
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

        /* File Upload Styles */
        .file-upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f9f9f9;
        }

        .file-upload-area:hover {
            border-color: #4A90A4;
            background: #f0f8ff;
        }

        .file-upload-area.dragover {
            border-color: #4A90A4;
            background: #e3f2fd;
        }

        .file-upload-text {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .file-upload-info {
            font-size: 12px;
            color: #999;
        }

        .selected-files {
            margin-top: 15px;
        }

        .selected-file {
            display: flex;
            align-items: center;
            justify-content: between;
            padding: 8px 12px;
            background: #e3f2fd;
            border-radius: 6px;
            margin-bottom: 5px;
        }

        .selected-file-name {
            flex: 1;
            font-size: 14px;
            color: #333;
        }

        .remove-file-btn {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 10px;
            cursor: pointer;
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

        /* Task Detail Modal */
        .task-detail-content {
            max-height: 70vh;
            overflow-y: auto;
        }

        .detail-section {
            margin-bottom: 25px;
        }

        .detail-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-list {
            display: grid;
            gap: 10px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #4A90A4;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }

        .file-icon {
            font-size: 20px;
        }

        .file-details {
            flex: 1;
        }

        .file-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 2px;
        }

        .file-meta {
            font-size: 12px;
            color: #666;
        }

        .file-actions {
            display: flex;
            gap: 5px;
        }

        .file-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-btn.view {
            background-color: #4A90A4;
            color: white;
        }

        .file-btn.delete {
            background-color: #dc3545;
            color: white;
        }

        .file-btn:hover {
            opacity: 0.8;
        }

        .attach-file-form {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
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
            <h1 class="page-title">Task Management with Files</h1>
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
                    <div class="task-card <?php echo $task['priority']; ?> <?php echo $task['status']; ?>" 
                         onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
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
                                <?php echo nl2br(htmlspecialchars(substr($task['description'], 0, 100))); ?>
                                <?php if (strlen($task['description']) > 100) echo '...'; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($task['file_count'] > 0): ?>
                            <div class="task-files">
                                <div class="task-files-header">
                                    📎 Attachments <span class="file-count"><?php echo $task['file_count']; ?></span>
                                </div>
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

                        <div class="task-actions" onclick="event.stopPropagation();">
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

                            <button class="task-btn info" onclick="openAttachFileModal(<?php echo $task['id']; ?>)">
                                📎 Attach File
                            </button>

                            <a href="record_time.php?task_id=<?php echo $task['id']; ?>" class="task-btn primary">
                                ⏱️ Track Time
                            </a>

                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to delete this task and all its files?');">
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
                <h2 class="modal-title">Add New Task with Files</h2>
                <button class="close-btn" onclick="closeModal('addTaskModal')">&times;</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_task_with_files">

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

                <div class="form-group">
                    <label class="form-label">Attach Files (Optional)</label>
                    <div class="file-upload-area" onclick="document.getElementById('task_files').click();">
                        <div class="file-upload-text">📁 Click to select files or drag and drop</div>
                        <div class="file-upload-info">
                            Supported: PDF, DOC, DOCX, TXT, JPG, PNG, GIF, ZIP, RAR<br>
                            Maximum file size: 10MB per file
                        </div>
                    </div>
                    <input type="file" id="task_files" name="task_files[]" multiple 
                           accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.zip,.rar" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div id="selectedFiles" class="selected-files"></div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('addTaskModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn primary">Create Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Task Detail Modal -->
    <div id="taskDetailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="taskDetailTitle">Task Details</h2>
                <button class="close-btn" onclick="closeModal('taskDetailModal')">&times;</button>
            </div>
            <div id="taskDetailContent" class="task-detail-content">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Attach File Modal -->
    <div id="attachFileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Attach File to Task</h2>
                <button class="close-btn" onclick="closeModal('attachFileModal')">&times;</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="attach_file">
                <input type="hidden" name="task_id" id="attachFileTaskId">

                <div class="form-group">
                    <label class="form-label">Select File to Attach</label>
                    <div class="file-upload-area" onclick="document.getElementById('attach_file').click();">
                        <div class="file-upload-text">📁 Click to select a file</div>
                        <div class="file-upload-info">
                            Supported: PDF, DOC, DOCX, TXT, JPG, PNG, GIF, ZIP, RAR<br>
                            Maximum file size: 10MB
                        </div>
                    </div>
                    <input type="file" id="attach_file" name="file" required
                           accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.zip,.rar" 
                           style="display: none;" onchange="showSelectedFile(this)">
                    <div id="attachSelectedFile" class="selected-files"></div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('attachFileModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn primary">Attach File</button>
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

        function viewTaskDetails(taskId) {
            window.location.href = 'task.php?view=' + taskId;
        }

        function openAttachFileModal(taskId) {
            document.getElementById('attachFileTaskId').value = taskId;
            openModal('attachFileModal');
        }

        function handleFileSelection(input) {
            const filesDiv = document.getElementById('selectedFiles');
            filesDiv.innerHTML = '';
            
            if (input.files.length > 0) {
                for (let i = 0; i < input.files.length; i++) {
                    const file = input.files[i];
                    const fileDiv = document.createElement('div');
                    fileDiv.className = 'selected-file';
                    fileDiv.innerHTML = `
                        <span class="selected-file-name">📎 ${file.name} (${formatFileSize(file.size)})</span>
                        <button type="button" class="remove-file-btn" onclick="removeFile(${i})">✕</button>
                    `;
                    filesDiv.appendChild(fileDiv);
                }
            }
        }

        function showSelectedFile(input) {
            const filesDiv = document.getElementById('attachSelectedFile');
            filesDiv.innerHTML = '';
            
            if (input.files[0]) {
                const file = input.files[0];
                const fileDiv = document.createElement('div');
                fileDiv.className = 'selected-file';
                fileDiv.innerHTML = `
                    <span class="selected-file-name">📎 ${file.name} (${formatFileSize(file.size)})</span>
                `;
                filesDiv.appendChild(fileDiv);
            }
        }

        function removeFile(index) {
            const input = document.getElementById('task_files');
            const dt = new DataTransfer();
            
            for (let i = 0; i < input.files.length; i++) {
                if (i !== index) {
                    dt.items.add(input.files[i]);
                }
            }
            
            input.files = dt.files;
            handleFileSelection(input);
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function deleteTaskFile(fileId) {
            if (confirm('Are you sure you want to delete this file?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_file">
                    <input type="hidden" name="file_id" value="${fileId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Drag and drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.querySelectorAll('.file-upload-area').forEach(area => {
                area.addEventListener(eventName, preventDefaults, false);
            });
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            document.querySelectorAll('.file-upload-area').forEach(area => {
                area.addEventListener(eventName, highlight, false);
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            document.querySelectorAll('.file-upload-area').forEach(area => {
                area.addEventListener(eventName, unhighlight, false);
            });
        });

        function highlight(e) {
            e.currentTarget.classList.add('dragover');
        }

        function unhighlight(e) {
            e.currentTarget.classList.remove('dragover');
        }

        document.querySelectorAll('.file-upload-area').forEach(area => {
            area.addEventListener('drop', handleDrop, false);
        });

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            // Find the corresponding file input
            const fileInput = e.currentTarget.parentElement.querySelector('input[type="file"]');
            if (fileInput) {
                fileInput.files = files;
                if (fileInput.id === 'task_files') {
                    handleFileSelection(fileInput);
                } else {
                    showSelectedFile(fileInput);
                }
            }
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

        // Load task details if viewing specific task
        <?php if ($selected_task): ?>
        document.addEventListener('DOMContentLoaded', function() {
            loadTaskDetails(<?php echo $selected_task['id']; ?>);
        });

        function loadTaskDetails(taskId) {
            const modal = document.getElementById('taskDetailModal');
            const content = document.getElementById('taskDetailContent');
            const title = document.getElementById('taskDetailTitle');
            
            // Set task data
            const taskData = <?php echo json_encode($selected_task); ?>;
            const taskFiles = <?php echo json_encode($task_files); ?>;
            
            title.textContent = taskData.title;
            
            let html = `
                <div class="detail-section">
                    <div class="detail-title">📋 Task Information</div>
                    <div style="margin-bottom: 15px;">
                        <strong>Description:</strong><br>
                        ${taskData.description ? taskData.description.replace(/\n/g, '<br>') : 'No description provided'}
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px;">
                        <div><strong>Priority:</strong> <span class="task-priority ${taskData.priority}">${taskData.priority.charAt(0).toUpperCase() + taskData.priority.slice(1)}</span></div>
                        <div><strong>Status:</strong> <span class="task-status ${taskData.status}">${taskData.status.replace('_', ' ').charAt(0).toUpperCase() + taskData.status.replace('_', ' ').slice(1)}</span></div>
                        <div><strong>Course:</strong> ${taskData.course || 'Not specified'}</div>
                        <div><strong>Due Date:</strong> ${taskData.due_date || 'No due date'}</div>
                    </div>
                </div>
            `;
            
            if (taskFiles.length > 0) {
                html += `
                    <div class="detail-section">
                        <div class="detail-title">📎 Attached Files (${taskFiles.length})</div>
                        <div class="file-list">
                `;
                
                taskFiles.forEach(file => {
                    const fileIcon = getFileIcon(file.original_name);
                    html += `
                        <div class="file-item">
                            <div class="file-info">
                                <span class="file-icon">${fileIcon}</span>
                                <div class="file-details">
                                    <div class="file-name">${file.original_name}</div>
                                    <div class="file-meta">${formatFileSize(file.file_size)} • Uploaded ${formatDate(file.upload_date)}</div>
                                </div>
                            </div>
                            <div class="file-actions">
                                <a href="${file.file_path}" target="_blank" class="file-btn view">👁️ View</a>
                                <button class="file-btn delete" onclick="deleteTaskFile(${file.id})">🗑️ Delete</button>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            html += `
                <div class="attach-file-form">
                    <div class="detail-title">📎 Attach New File</div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="attach_file">
                        <input type="hidden" name="task_id" value="${taskData.id}">
                        <div style="display: flex; gap: 10px; align-items: end;">
                            <div style="flex: 1;">
                                <input type="file" name="file" class="form-input" 
                                       accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.zip,.rar" required>
                            </div>
                            <button type="submit" class="btn primary">Attach</button>
                        </div>
                    </form>
                </div>
            `;
            
            content.innerHTML = html;
            openModal('taskDetailModal');
        }

        function getFileIcon(filename) {
            const ext = filename.toLowerCase().split('.').pop();
            switch (ext) {
                case 'pdf': return '📄';
                case 'doc':
                case 'docx': return '📝';
                case 'txt': return '📋';
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif': return '🖼️';
                case 'zip':
                case 'rar': return '📦';
                default: return '📎';
            }
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>