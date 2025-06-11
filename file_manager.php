<?php
// file_manager.php - Simple File Upload & Management for EduHive
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'upload_file':
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $upload_result = uploadUserFile($_FILES['file'], $user['id'], $db);
                setMessage($upload_result['message'], $upload_result['type']);
            } else {
                setMessage('Please select a file to upload.', 'error');
            }
            break;
            
        case 'delete_file':
            $file_id = (int)($_POST['file_id'] ?? 0);
            $result = deleteUserFile($file_id, $user['id'], $db);
            setMessage($result['message'], $result['type']);
            break;
    }
    
    header("Location: file_manager.php");
    exit();
}

// Simple file upload function
function uploadUserFile($file, $user_id, $db) {
    try {
        // Create uploads directory if not exists
        $upload_dir = 'uploads/files/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Validate file
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            return ['message' => 'File type not allowed. Allowed: PDF, DOC, DOCX, TXT, JPG, PNG, GIF', 'type' => 'error'];
        }
        
        if ($file['size'] > 5000000) { // 5MB limit
            return ['message' => 'File too large. Maximum size: 5MB', 'type' => 'error'];
        }
        
        // Generate unique filename
        $new_filename = time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;
        
        // Move file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Save to database
            $stmt = $db->prepare("
                INSERT INTO files (user_id, filename, original_name, file_path, file_size, file_type, upload_date) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                $new_filename,
                $file['name'],
                $upload_path,
                $file['size'],
                $file['type']
            ]);
            
            return ['message' => 'File uploaded successfully!', 'type' => 'success'];
        } else {
            return ['message' => 'Failed to upload file.', 'type' => 'error'];
        }
        
    } catch (Exception $e) {
        return ['message' => 'Error uploading file: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// Simple file delete function
function deleteUserFile($file_id, $user_id, $db) {
    try {
        // Get file info
        $stmt = $db->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
        $stmt->execute([$file_id, $user_id]);
        $file = $stmt->fetch();
        
        if (!$file) {
            return ['message' => 'File not found.', 'type' => 'error'];
        }
        
        // Delete physical file
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }
        
        // Delete from database
        $stmt = $db->prepare("DELETE FROM files WHERE id = ? AND user_id = ?");
        $stmt->execute([$file_id, $user_id]);
        
        return ['message' => 'File deleted successfully!', 'type' => 'success'];
        
    } catch (Exception $e) {
        return ['message' => 'Error deleting file.', 'type' => 'error'];
    }
}

// Get user files
try {
    $stmt = $db->prepare("
        SELECT * FROM files 
        WHERE user_id = ? 
        ORDER BY upload_date DESC
    ");
    $stmt->execute([$user['id']]);
    $user_files = $stmt->fetchAll();
} catch (Exception $e) {
    $user_files = [];
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager - EduHive</title>
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

        /* Upload Section */
        .upload-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .upload-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }

        .upload-form {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }

        .file-input-group {
            flex: 1;
            min-width: 300px;
        }

        .file-input-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .file-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px dashed #ddd;
            border-radius: 8px;
            background: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input:hover {
            border-color: #4A90A4;
            background: #f0f8ff;
        }

        .upload-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .upload-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-2px);
        }

        .upload-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            color: #666;
        }

        /* Files Grid */
        .files-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .files-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
        }

        .files-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            padding: 25px;
        }

        .file-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #eee;
        }

        .file-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .file-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .file-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
            word-break: break-word;
        }

        .file-meta {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
        }

        .file-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .file-btn {
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

        .file-btn.primary {
            background-color: #4A90A4;
            color: white;
        }

        .file-btn.danger {
            background-color: #dc3545;
            color: white;
        }

        .file-btn:hover {
            opacity: 0.8;
        }

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

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .upload-form {
                flex-direction: column;
                align-items: stretch;
            }

            .files-grid {
                grid-template-columns: 1fr;
                gap: 15px;
                padding: 20px;
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
            <h1 class="page-title">File Manager</h1>
        </div>

        <!-- Show Message -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Upload Section -->
        <div class="upload-section">
            <h2 class="upload-title">📁 Upload New File</h2>
            <form class="upload-form" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_file">
                
                <div class="file-input-group">
                    <label class="file-input-label">Select File</label>
                    <input type="file" name="file" class="file-input" required 
                           accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif">
                </div>
                
                <button type="submit" class="upload-btn">📤 Upload File</button>
            </form>
            
            <div class="upload-info">
                <strong>Allowed:</strong> PDF, DOC, DOCX, TXT, JPG, PNG, GIF<br>
                <strong>Max size:</strong> 5MB per file
            </div>
        </div>

        <!-- Files List -->
        <div class="files-section">
            <div class="files-header">
                <h3 class="files-title">Your Files (<?php echo count($user_files); ?>)</h3>
            </div>
            
            <?php if (!empty($user_files)): ?>
                <div class="files-grid">
                    <?php foreach ($user_files as $file): ?>
                        <div class="file-card">
                            <span class="file-icon">
                                <?php
                                $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                                switch ($ext) {
                                    case 'pdf': echo '📄'; break;
                                    case 'doc':
                                    case 'docx': echo '📝'; break;
                                    case 'txt': echo '📋'; break;
                                    case 'jpg':
                                    case 'jpeg':
                                    case 'png':
                                    case 'gif': echo '🖼️'; break;
                                    default: echo '📎';
                                }
                                ?>
                            </span>
                            <div class="file-name"><?php echo htmlspecialchars($file['original_name']); ?></div>
                            <div class="file-meta">
                                <?php echo formatFileSize($file['file_size']); ?><br>
                                <?php echo formatDate($file['upload_date'], 'M d, Y'); ?>
                            </div>
                            <div class="file-actions">
                                <a href="<?php echo htmlspecialchars($file['file_path']); ?>" 
                                   class="file-btn primary" target="_blank">
                                    👁️ View
                                </a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Delete this file?');">
                                    <input type="hidden" name="action" value="delete_file">
                                    <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                    <button type="submit" class="file-btn danger">🗑️ Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📁</div>
                    <h3>No Files Yet</h3>
                    <p>Upload your first file to get started!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-hide success messages
        setTimeout(function() {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                setTimeout(() => successMessage.remove(), 300);
            }
        }, 3000);

        // File input preview
        document.querySelector('.file-input').addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                const fileSize = e.target.files[0].size;
                
                if (fileSize > 5000000) {
                    alert('File too large! Maximum size is 5MB.');
                    e.target.value = '';
                    return;
                }
                
                console.log('Selected file:', fileName);
            }
        });

        // Add loading state to upload button
        document.querySelector('.upload-form').addEventListener('submit', function() {
            const btn = this.querySelector('.upload-btn');
            btn.disabled = true;
            btn.innerHTML = '⏳ Uploading...';
        });
    </script>
</body>
</html>