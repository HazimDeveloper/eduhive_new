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
            case 'create_team':
                $name = cleanInput($_POST['name'] ?? '');
                $description = cleanInput($_POST['description'] ?? '');
                
                if (empty($name)) {
                    setMessage('Team name is required.', 'error');
                } else {
                    try {
                        // Generate unique invite code
                        $invite_code = strtoupper(generateRandomString(6));
                        
                        // Create team
                        $stmt = $db->prepare("
                            INSERT INTO teams (name, description, created_by, invite_code, created_at) 
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$name, $description, $user['id'], $invite_code]);
                        $team_id = $db->lastInsertId();
                        
                        // Add creator as team leader
                        $stmt = $db->prepare("
                            INSERT INTO team_members (team_id, user_id, role, joined_at) 
                            VALUES (?, ?, 'leader', NOW())
                        ");
                        $stmt->execute([$team_id, $user['id']]);
                        
                        setMessage('Team created successfully! Share invite code: ' . $invite_code, 'success');
                    } catch (Exception $e) {
                        setMessage('Error creating team. Please try again.', 'error');
                    }
                }
                break;
                
            case 'join_team':
                $invite_code = cleanInput($_POST['invite_code'] ?? '');
                
                if (empty($invite_code)) {
                    setMessage('Invite code is required.', 'error');
                } else {
                    try {
                        // Find team by invite code
                        $stmt = $db->prepare("SELECT id FROM teams WHERE invite_code = ?");
                        $stmt->execute([$invite_code]);
                        $team = $stmt->fetch();
                        
                        if (!$team) {
                            setMessage('Invalid invite code.', 'error');
                        } else {
                            // Check if user is already a member
                            $stmt = $db->prepare("SELECT COUNT(*) as is_member FROM team_members WHERE team_id = ? AND user_id = ?");
                            $stmt->execute([$team['id'], $user['id']]);
                            $is_member = $stmt->fetch()['is_member'];
                            
                            if ($is_member > 0) {
                                setMessage('You are already a member of this team.', 'info');
                            } else {
                                // Join team
                                $stmt = $db->prepare("
                                    INSERT INTO team_members (team_id, user_id, role, joined_at) 
                                    VALUES (?, ?, 'member', NOW())
                                ");
                                $stmt->execute([$team['id'], $user['id']]);
                                
                                setMessage('Successfully joined the team!', 'success');
                            }
                        }
                    } catch (Exception $e) {
                        setMessage('Error joining team. Please try again.', 'error');
                    }
                }
                break;
                
            case 'leave_team':
                $team_id = (int)($_POST['team_id'] ?? 0);
                
                try {
                    // Check if user is the only leader
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as leader_count 
                        FROM team_members 
                        WHERE team_id = ? AND role = 'leader'
                    ");
                    $stmt->execute([$team_id]);
                    $leader_count = $stmt->fetch()['leader_count'];
                    
                    $stmt = $db->prepare("
                        SELECT role FROM team_members 
                        WHERE team_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$team_id, $user['id']]);
                    $user_role = $stmt->fetch()['role'] ?? '';
                    
                    if ($user_role === 'leader' && $leader_count <= 1) {
                        setMessage('Cannot leave team. You are the only leader. Transfer leadership first or delete the team.', 'error');
                    } else {
                        // Leave team
                        $stmt = $db->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
                        $stmt->execute([$team_id, $user['id']]);
                        
                        setMessage('You have left the team.', 'success');
                    }
                } catch (Exception $e) {
                    setMessage('Error leaving team.', 'error');
                }
                break;
                
            case 'remove_member':
                $team_id = (int)($_POST['team_id'] ?? 0);
                $member_id = (int)($_POST['member_id'] ?? 0);
                
                try {
                    // Check if current user is leader
                    $stmt = $db->prepare("
                        SELECT role FROM team_members 
                        WHERE team_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$team_id, $user['id']]);
                    $user_role = $stmt->fetch()['role'] ?? '';
                    
                    if ($user_role !== 'leader') {
                        setMessage('Only team leaders can remove members.', 'error');
                    } else {
                        // Remove member
                        $stmt = $db->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
                        $stmt->execute([$team_id, $member_id]);
                        
                        setMessage('Member removed from team.', 'success');
                    }
                } catch (Exception $e) {
                    setMessage('Error removing member.', 'error');
                }
                break;
                
            case 'promote_member':
                $team_id = (int)($_POST['team_id'] ?? 0);
                $member_id = (int)($_POST['member_id'] ?? 0);
                
                try {
                    // Check if current user is leader
                    $stmt = $db->prepare("
                        SELECT role FROM team_members 
                        WHERE team_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$team_id, $user['id']]);
                    $user_role = $stmt->fetch()['role'] ?? '';
                    
                    if ($user_role !== 'leader') {
                        setMessage('Only team leaders can promote members.', 'error');
                    } else {
                        // Promote to leader
                        $stmt = $db->prepare("
                            UPDATE team_members 
                            SET role = 'leader' 
                            WHERE team_id = ? AND user_id = ?
                        ");
                        $stmt->execute([$team_id, $member_id]);
                        
                        setMessage('Member promoted to leader.', 'success');
                    }
                } catch (Exception $e) {
                    setMessage('Error promoting member.', 'error');
                }
                break;
                
            case 'delete_team':
                $team_id = (int)($_POST['team_id'] ?? 0);
                
                try {
                    // Check if user is team creator
                    $stmt = $db->prepare("
                        SELECT created_by FROM teams 
                        WHERE id = ?
                    ");
                    $stmt->execute([$team_id]);
                    $team = $stmt->fetch();
                    
                    if (!$team || $team['created_by'] != $user['id']) {
                        setMessage('Only team creators can delete teams.', 'error');
                    } else {
                        // Delete team members first
                        $stmt = $db->prepare("DELETE FROM team_members WHERE team_id = ?");
                        $stmt->execute([$team_id]);
                        
                        // Delete team files (if any)
                        $stmt = $db->prepare("DELETE FROM files WHERE team_id = ?");
                        $stmt->execute([$team_id]);
                        
                        // Delete team
                        $stmt = $db->prepare("DELETE FROM teams WHERE id = ?");
                        $stmt->execute([$team_id]);
                        
                        setMessage('Team deleted successfully.', 'success');
                    }
                } catch (Exception $e) {
                    setMessage('Error deleting team.', 'error');
                }
                break;
        }
    }
    
    header("Location: team_member.php");
    exit();
}

// Get user's teams
try {
    $stmt = $db->prepare("
        SELECT t.*, tm.role, tm.joined_at,
               (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as member_count
        FROM teams t
        JOIN team_members tm ON t.id = tm.team_id
        WHERE tm.user_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $user_teams = $stmt->fetchAll();
} catch (Exception $e) {
    $user_teams = [];
}

// Get team details if viewing a specific team
$selected_team_id = (int)($_GET['team_id'] ?? 0);
$selected_team = null;
$team_members = [];

if ($selected_team_id > 0) {
    try {
        // Get team details
        $stmt = $db->prepare("
            SELECT t.*, tm.role as user_role
            FROM teams t
            JOIN team_members tm ON t.id = tm.team_id
            WHERE t.id = ? AND tm.user_id = ?
        ");
        $stmt->execute([$selected_team_id, $user['id']]);
        $selected_team = $stmt->fetch();
        
        if ($selected_team) {
            // Get team members
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.email, tm.role, tm.joined_at
                FROM users u
                JOIN team_members tm ON u.id = tm.user_id
                WHERE tm.team_id = ?
                ORDER BY tm.role DESC, tm.joined_at ASC
            ");
            $stmt->execute([$selected_team_id]);
            $team_members = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $selected_team = null;
        $team_members = [];
    }
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Members - EduHive</title>
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

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .action-btn {
            padding: 12px 20px;
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

        .action-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(196, 164, 132, 0.4);
        }

        .action-btn.secondary {
            background: #4A90A4;
        }

        .action-btn.secondary:hover {
            background: #357A8C;
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
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

        /* Team List */
        .team-list {
            list-style: none;
        }

        .team-item {
            padding: 20px 0;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .team-item:hover {
            background-color: #f8f9fa;
            margin: 0 -25px;
            padding-left: 25px;
            padding-right: 25px;
        }

        .team-item:last-child {
            border-bottom: none;
        }

        .team-item.active {
            background-color: #e3f2fd;
            margin: 0 -25px;
            padding-left: 25px;
            padding-right: 25px;
            border-left: 4px solid #4A90A4;
        }

        .team-name {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .team-meta {
            font-size: 12px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .team-role {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .team-role.leader {
            background-color: #fff3cd;
            color: #856404;
        }

        .team-role.member {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        /* Team Details */
        .team-header {
            text-align: center;
            padding: 30px 20px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            margin: -25px -25px 25px -25px;
        }

        .team-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 15px;
        }

        .team-details-name {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .team-description {
            opacity: 0.9;
            margin-bottom: 15px;
        }

        .team-stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            font-size: 14px;
        }

        .stat {
            text-align: center;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 600;
        }

        .stat-label {
            opacity: 0.8;
        }

        /* Members List */
        .member-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .member-item:last-child {
            border-bottom: none;
        }

        .member-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 15px;
        }

        .member-info {
            flex: 1;
        }

        .member-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .member-email {
            font-size: 12px;
            color: #666;
        }

        .member-joined {
            font-size: 11px;
            color: #999;
        }

        .member-actions {
            display: flex;
            gap: 5px;
        }

        .member-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .member-btn.promote {
            background-color: #28a745;
            color: white;
        }

        .member-btn.remove {
            background-color: #dc3545;
            color: white;
        }

        .member-btn:hover {
            opacity: 0.8;
        }

        /* Invite Section */
        .invite-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }

        .invite-code {
            display: inline-block;
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 24px;
            font-weight: 600;
            color: #333;
            border: 2px dashed #C4A484;
            margin: 15px 0;
            letter-spacing: 3px;
        }

        .copy-btn {
            padding: 8px 16px;
            background: #4A90A4;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
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
        .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-input:focus,
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
            padding: 40px 20px;
            color: #666;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Team Management Actions */
        .team-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .team-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .team-btn.danger {
            background-color: #dc3545;
            color: white;
        }

        .team-btn.warning {
            background-color: #ffc107;
            color: #333;
        }

        .team-btn:hover {
            opacity: 0.8;
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

            .header-actions {
                justify-content: center;
            }

            .invite-code {
                font-size: 18px;
                letter-spacing: 2px;
            }

            .team-stats {
                gap: 20px;
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
            <h1 class="page-title">Team Collaboration</h1>
            <div class="header-actions">
                <button class="action-btn" onclick="openModal('createTeamModal')">
                    ➕ Create Team
                </button>
                <button class="action-btn secondary" onclick="openModal('joinTeamModal')">
                    🔗 Join Team
                </button>
            </div>
        </div>

        <!-- Show Message if exists -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($user_teams)): ?>
            <div class="content-grid">
                <!-- Teams List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Your Teams</h3>
                    </div>
                    <div class="card-body">
                        <ul class="team-list">
                            <?php foreach ($user_teams as $team): ?>
                                <li class="team-item <?php echo $team['id'] == $selected_team_id ? 'active' : ''; ?>" 
                                    onclick="window.location.href='team_member.php?team_id=<?php echo $team['id']; ?>'">
                                    <div class="team-name"><?php echo htmlspecialchars($team['name']); ?></div>
                                    <div class="team-meta">
                                        <span>
                                            <?php echo $team['member_count']; ?> member<?php echo $team['member_count'] > 1 ? 's' : ''; ?>
                                            • Created <?php echo formatDate($team['created_at'], 'M d, Y'); ?>
                                        </span>
                                        <span class="team-role <?php echo $team['role']; ?>">
                                            <?php echo ucfirst($team['role']); ?>
                                        </span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Team Details -->
                <div class="card">
                    <?php if ($selected_team): ?>
                        <div class="team-header">
                            <div class="team-avatar">👥</div>
                            <div class="team-details-name"><?php echo htmlspecialchars($selected_team['name']); ?></div>
                            <?php if ($selected_team['description']): ?>
                                <div class="team-description"><?php echo htmlspecialchars($selected_team['description']); ?></div>
                            <?php endif; ?>
                            <div class="team-stats">
                                <div class="stat">
                                    <div class="stat-value"><?php echo count($team_members); ?></div>
                                    <div class="stat-label">Members</div>
                                </div>
                                <div class="stat">
                                    <div class="stat-value"><?php echo ucfirst($selected_team['user_role']); ?></div>
                                    <div class="stat-label">Your Role</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <!-- Invite Code Section -->
                            <?php if ($selected_team['user_role'] === 'leader'): ?>
                                <div class="invite-section">
                                    <h4 style="margin-bottom: 10px; color: #333;">Invite New Members</h4>
                                    <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
                                        Share this invite code with others to join your team
                                    </p>
                                    <div class="invite-code" id="inviteCode"><?php echo htmlspecialchars($selected_team['invite_code']); ?></div>
                                    <button class="copy-btn" onclick="copyInviteCode()">📋 Copy</button>
                                </div>
                            <?php endif; ?>

                            <!-- Team Members -->
                            <h4 style="margin-bottom: 20px; color: #333;">Team Members</h4>
                            <?php if (!empty($team_members)): ?>
                                <?php foreach ($team_members as $member): ?>
                                    <div class="member-item">
                                        <div class="member-avatar">
                                            <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                        </div>
                                        <div class="member-info">
                                            <div class="member-name">
                                                <?php echo htmlspecialchars($member['name']); ?>
                                                <?php if ($member['id'] == $user['id']): ?>
                                                    <span style="color: #C4A484; font-size: 12px;">(You)</span>
                                                <?php endif; ?>
                                                <span class="team-role <?php echo $member['role']; ?>" style="margin-left: 10px;">
                                                    <?php echo ucfirst($member['role']); ?>
                                                </span>
                                            </div>
                                            <div class="member-email"><?php echo htmlspecialchars($member['email']); ?></div>
                                            <div class="member-joined">Joined <?php echo formatDate($member['joined_at']); ?></div>
                                        </div>
                                        
                                        <?php if ($selected_team['user_role'] === 'leader' && $member['id'] != $user['id']): ?>
                                            <div class="member-actions">
                                                <?php if ($member['role'] === 'member'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="promote_member">
                                                        <input type="hidden" name="team_id" value="<?php echo $selected_team['id']; ?>">
                                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                        <button type="submit" class="member-btn promote" 
                                                                onclick="return confirm('Promote this member to leader?')">
                                                            ⬆️ Promote
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="remove_member">
                                                    <input type="hidden" name="team_id" value="<?php echo $selected_team['id']; ?>">
                                                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                    <button type="submit" class="member-btn remove" 
                                                            onclick="return confirm('Remove this member from the team?')">
                                                        🗑️ Remove
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">👥</div>
                                    <p>No team members found</p>
                                </div>
                            <?php endif; ?>

                            <!-- Team Management Actions -->
                            <div class="team-actions">
                                <?php if ($selected_team['user_role'] === 'leader' && $selected_team['created_by'] == $user['id']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_team">
                                        <input type="hidden" name="team_id" value="<?php echo $selected_team['id']; ?>">
                                        <button type="submit" class="team-btn danger" 
                                                onclick="return confirm('Are you sure you want to delete this team? This action cannot be undone.')">
                                            🗑️ Delete Team
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="leave_team">
                                        <input type="hidden" name="team_id" value="<?php echo $selected_team['id']; ?>">
                                        <button type="submit" class="team-btn warning" 
                                                onclick="return confirm('Are you sure you want to leave this team?')">
                                            🚪 Leave Team
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card-body">
                            <div class="empty-state">
                                <div class="empty-state-icon">👥</div>
                                <h3>Select a Team</h3>
                                <p>Choose a team from the list to view members and manage collaboration</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- No Teams State -->
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">👥</div>
                        <h3>No Teams Yet</h3>
                        <p>Create your first team or join an existing one to start collaborating!</p>
                        <div style="margin-top: 20px;">
                            <button class="action-btn" onclick="openModal('createTeamModal')">
                                ➕ Create Your First Team
                            </button>
                            <button class="action-btn secondary" onclick="openModal('joinTeamModal')" style="margin-left: 10px;">
                                🔗 Join a Team
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Team Modal -->
    <div id="createTeamModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Create New Team</h2>
                <button class="close-btn" onclick="closeModal('createTeamModal')">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="create_team">

                <div class="form-group">
                    <label for="team_name" class="form-label">Team Name *</label>
                    <input type="text" id="team_name" name="name" class="form-input" 
                           placeholder="Enter team name" required maxlength="100">
                </div>

                <div class="form-group">
                    <label for="team_description" class="form-label">Description</label>
                    <textarea id="team_description" name="description" class="form-textarea" 
                              placeholder="Describe your team's purpose (optional)"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('createTeamModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn primary">Create Team</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Join Team Modal -->
    <div id="joinTeamModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Join Team</h2>
                <button class="close-btn" onclick="closeModal('joinTeamModal')">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="join_team">

                <div class="form-group">
                    <label for="invite_code" class="form-label">Invite Code *</label>
                    <input type="text" id="invite_code" name="invite_code" class="form-input" 
                           placeholder="Enter 6-character invite code" required maxlength="6" 
                           style="text-transform: uppercase; letter-spacing: 2px; text-align: center; font-family: 'Courier New', monospace;">
                </div>

                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="color: #333; margin-bottom: 8px; font-size: 14px;">💡 How to Join</h4>
                    <p style="color: #666; font-size: 13px; margin: 0;">
                        Ask a team leader for the invite code. It's a 6-character code that looks like "ABC123".
                    </p>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('joinTeamModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn primary">Join Team</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Auto-focus first input
            setTimeout(() => {
                const firstInput = document.querySelector(`#${modalId} input[type="text"]`);
                if (firstInput) firstInput.focus();
            }, 300);
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function copyInviteCode() {
            const inviteCode = document.getElementById('inviteCode').textContent;
            navigator.clipboard.writeText(inviteCode).then(() => {
                // Show feedback
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✅ Copied!';
                btn.style.background = '#28a745';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '#4A90A4';
                }, 2000);
            }).catch(() => {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = inviteCode;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                alert('Invite code copied: ' + inviteCode);
            });
        }

        // Auto-uppercase invite code input
        document.getElementById('invite_code').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });

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

        // Auto-hide success messages
        setTimeout(function() {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                setTimeout(function() {
                    successMessage.style.display = 'none';
                }, 300);
            }
        }, 5000);

        // Form validation
        document.querySelector('#createTeamModal form').addEventListener('submit', function(e) {
            const teamName = document.getElementById('team_name').value.trim();
            if (teamName.length < 2) {
                e.preventDefault();
                alert('Team name must be at least 2 characters long.');
                document.getElementById('team_name').focus();
            }
        });

        document.querySelector('#joinTeamModal form').addEventListener('submit', function(e) {
            const inviteCode = document.getElementById('invite_code').value.trim();
            if (inviteCode.length !== 6) {
                e.preventDefault();
                alert('Invite code must be exactly 6 characters long.');
                document.getElementById('invite_code').focus();
            }
        });

        // Add loading states to buttons
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    const originalText = submitBtn.textContent;
                    submitBtn.textContent = '⏳ Processing...';
                    
                    // Re-enable after 3 seconds (fallback)
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }, 3000);
                }
            });
        });

        // Enhanced team item interactions
        document.querySelectorAll('.team-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(5px)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });

        // Add confirmation for dangerous actions
        document.querySelectorAll('.member-btn.remove, .team-btn.danger').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (!confirm('This action cannot be undone. Are you sure?')) {
                    e.preventDefault();
                }
            });
        });

        // Dynamic member count updates
        function updateMemberCount() {
            const memberItems = document.querySelectorAll('.member-item').length;
            const statValue = document.querySelector('.stat-value');
            if (statValue && memberItems > 0) {
                statValue.textContent = memberItems;
            }
        }

        // Call on page load
        updateMemberCount();

        // Auto-refresh team data every 30 seconds (optional)
        <?php if ($selected_team_id > 0): ?>
        setInterval(function() {
            // Only refresh if no modals are open
            if (!document.querySelector('.modal.active')) {
                fetch(`team_member.php?team_id=<?php echo $selected_team_id; ?>&ajax=1`)
                    .then(response => response.text())
                    .then(data => {
                        // Could implement partial page updates here
                        // For now, just log that we could refresh
                        console.log('Auto-refresh available');
                    })
                    .catch(err => console.log('Auto-refresh failed:', err));
            }
        }, 30000);
        <?php endif; ?>

        // Add visual feedback for team actions
        document.querySelectorAll('.team-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
            });
        });

        // Animate member avatars on hover
        document.querySelectorAll('.member-avatar').forEach(avatar => {
            avatar.addEventListener('mouseenter', function() {
                this.style.transform = 'rotate(10deg) scale(1.1)';
            });
            
            avatar.addEventListener('mouseleave', function() {
                this.style.transform = 'rotate(0deg) scale(1)';
            });
        });
    </script>
</body>
</html>