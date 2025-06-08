<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Handle reward claim
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'claim_daily_reward') {
        try {
            // Check if user already claimed today
            $today = date('Y-m-d');
            $stmt = $db->prepare("
                SELECT COUNT(*) as claimed_today 
                FROM rewards 
                WHERE user_id = ? AND badge_type = 'daily' AND earned_date = ?
            ");
            $stmt->execute([$user['id'], $today]);
            $already_claimed = $stmt->fetch()['claimed_today'];
            
            if ($already_claimed == 0) {
                // Award daily login reward
                $stmt = $db->prepare("
                    INSERT INTO rewards (user_id, badge_name, badge_type, points, description, earned_date) 
                    VALUES (?, 'Daily Login', 'daily', 5, 'Logged in today', ?)
                ");
                $stmt->execute([$user['id'], $today]);
                setMessage('Daily reward claimed! +5 points', 'success');
            } else {
                setMessage('You have already claimed your daily reward today!', 'info');
            }
        } catch (Exception $e) {
            setMessage('Error claiming reward. Please try again.', 'error');
        }
    }
    
    header("Location: reward.php");
    exit();
}

// Auto-check and award achievements
try {
    // Get user statistics for achievement checking
    $stmt = $db->prepare("SELECT COUNT(*) as total_tasks FROM tasks WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $total_tasks = $stmt->fetch()['total_tasks'];
    
    $stmt = $db->prepare("SELECT COUNT(*) as completed_tasks FROM tasks WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$user['id']]);
    $completed_tasks = $stmt->fetch()['completed_tasks'];
    
    $stmt = $db->prepare("SELECT SUM(duration) as total_time FROM time_records WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $total_time = $stmt->fetch()['total_time'] ?? 0;
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT earned_date) as login_days FROM rewards WHERE user_id = ? AND badge_type = 'daily'");
    $stmt->execute([$user['id']]);
    $login_days = $stmt->fetch()['login_days'];
    
    // Define achievements
    $achievements = [
        ['name' => 'First Steps', 'type' => 'achievement', 'points' => 10, 'description' => 'Created your first task', 'condition' => $total_tasks >= 1],
        ['name' => 'Task Master', 'type' => 'achievement', 'points' => 25, 'description' => 'Completed 10 tasks', 'condition' => $completed_tasks >= 10],
        ['name' => 'Productivity Pro', 'type' => 'achievement', 'points' => 50, 'description' => 'Completed 50 tasks', 'condition' => $completed_tasks >= 50],
        ['name' => 'Study Warrior', 'type' => 'achievement', 'points' => 30, 'description' => 'Logged 10 hours of study time', 'condition' => $total_time >= 600],
        ['name' => 'Dedicated Student', 'type' => 'achievement', 'points' => 75, 'description' => 'Logged 50 hours of study time', 'condition' => $total_time >= 3000],
        ['name' => 'Consistent Learner', 'type' => 'achievement', 'points' => 40, 'description' => 'Logged in for 7 days', 'condition' => $login_days >= 7],
        ['name' => 'Habit Builder', 'type' => 'achievement', 'points' => 100, 'description' => 'Logged in for 30 days', 'condition' => $login_days >= 30]
    ];
    
    // Check and award achievements
    foreach ($achievements as $achievement) {
        if ($achievement['condition']) {
            // Check if user already has this achievement
            $stmt = $db->prepare("
                SELECT COUNT(*) as has_achievement 
                FROM rewards 
                WHERE user_id = ? AND badge_name = ? AND badge_type = 'achievement'
            ");
            $stmt->execute([$user['id'], $achievement['name']]);
            $has_achievement = $stmt->fetch()['has_achievement'];
            
            if ($has_achievement == 0) {
                // Award the achievement
                $stmt = $db->prepare("
                    INSERT INTO rewards (user_id, badge_name, badge_type, points, description, earned_date) 
                    VALUES (?, ?, ?, ?, ?, CURRENT_DATE)
                ");
                $stmt->execute([
                    $user['id'], 
                    $achievement['name'], 
                    $achievement['type'], 
                    $achievement['points'], 
                    $achievement['description']
                ]);
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking achievements: " . $e->getMessage());
}

// Get user's total points
try {
    $stmt = $db->prepare("SELECT SUM(points) as total_points FROM rewards WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $total_points = $stmt->fetch()['total_points'] ?? 0;
} catch (Exception $e) {
    $total_points = 0;
}

// Get recent rewards
try {
    $stmt = $db->prepare("
        SELECT * FROM rewards 
        WHERE user_id = ? 
        ORDER BY earned_date DESC, created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $recent_rewards = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_rewards = [];
}

// Get achievement badges (excluding daily rewards)
try {
    $stmt = $db->prepare("
        SELECT * FROM rewards 
        WHERE user_id = ? AND badge_type = 'achievement' 
        ORDER BY earned_date DESC
    ");
    $stmt->execute([$user['id']]);
    $achievement_badges = $stmt->fetchAll();
} catch (Exception $e) {
    $achievement_badges = [];
}

// Check if daily reward is available
$today = date('Y-m-d');
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as claimed_today 
        FROM rewards 
        WHERE user_id = ? AND badge_type = 'daily' AND earned_date = ?
    ");
    $stmt->execute([$user['id'], $today]);
    $daily_reward_available = $stmt->fetch()['claimed_today'] == 0;
} catch (Exception $e) {
    $daily_reward_available = false;
}

// Calculate level based on points
$level = 1;
$points_for_next_level = 100;
if ($total_points >= 100) {
    $level = floor($total_points / 100) + 1;
    $points_for_next_level = ($level * 100) - $total_points;
}

// Get leaderboard (top 10 users)
try {
    $stmt = $db->prepare("
        SELECT u.name, SUM(r.points) as total_points, COUNT(r.id) as badge_count
        FROM users u 
        LEFT JOIN rewards r ON u.id = r.user_id 
        GROUP BY u.id, u.name 
        HAVING total_points > 0
        ORDER BY total_points DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $leaderboard = $stmt->fetchAll();
} catch (Exception $e) {
    $leaderboard = [];
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards & Achievements - EduHive</title>
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
            text-align: center;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: #666;
            font-size: 18px;
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

        /* Stats Section */
        .stats-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
        }

        .stat-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 16px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Level Progress */
        .level-card {
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            position: relative;
        }

        .level-card::before {
            background: rgba(255, 255, 255, 0.2);
        }

        .level-progress {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            margin-top: 15px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: white;
            border-radius: 4px;
            transition: width 0.5s ease;
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
            border-radius: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
        }

        .card-body {
            padding: 30px;
        }

        /* Daily Reward */
        .daily-reward {
            text-align: center;
            padding: 40px 30px;
        }

        .reward-icon {
            font-size: 80px;
            margin-bottom: 20px;
            display: block;
        }

        .reward-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .reward-description {
            color: #666;
            margin-bottom: 25px;
            font-size: 16px;
        }

        .claim-btn {
            padding: 15px 30px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 25px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .claim-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(196, 164, 132, 0.4);
        }

        .claim-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        /* Achievement Badges */
        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .badge-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .badge-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .badge-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
        }

        .badge-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .badge-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .badge-description {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .badge-points {
            font-size: 16px;
            font-weight: 600;
            color: #C4A484;
        }

        .badge-date {
            font-size: 12px;
            color: #999;
            margin-top: 8px;
        }

        /* Recent Activity */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            font-size: 24px;
            margin-right: 15px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, #C4A484, #B8956A);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .activity-meta {
            font-size: 12px;
            color: #666;
        }

        .activity-points {
            font-weight: 600;
            color: #C4A484;
            font-size: 14px;
        }

        /* Leaderboard */
        .leaderboard-list {
            list-style: none;
        }

        .leaderboard-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .leaderboard-item:last-child {
            border-bottom: none;
        }

        .rank {
            font-size: 18px;
            font-weight: 700;
            color: #C4A484;
            width: 30px;
        }

        .rank.top3 {
            color: #ffc107;
        }

        .user-info {
            flex: 1;
            margin-left: 15px;
        }

        .user-name {
            font-weight: 500;
            color: #333;
        }

        .user-badges {
            font-size: 12px;
            color: #666;
        }

        .user-points {
            font-weight: 600;
            color: #C4A484;
            font-size: 16px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-section {
                grid-template-columns: 1fr 1fr;
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

            .stats-section {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .badges-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 28px;
            }

            .stat-card {
                padding: 20px;
            }

            .card-body {
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

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .daily-available {
            animation: pulse 2s infinite;
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
            <h1 class="page-title">🏆 Rewards & Achievements</h1>
            <p class="page-subtitle">Track your progress and earn badges for your accomplishments!</p>
        </div>

        <!-- Show Message if exists -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Section -->
        <div class="stats-section">
            <div class="stat-card level-card">
                <span class="stat-icon">🎖️</span>
                <div class="stat-value">Level <?php echo $level; ?></div>
                <div class="stat-label">Current Level</div>
                <div class="level-progress">
                    <div class="progress-bar" style="width: <?php echo $points_for_next_level > 0 ? ((100 - $points_for_next_level) / 100) * 100 : 100; ?>%"></div>
                </div>
                <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                    <?php echo $points_for_next_level; ?> points to next level
                </div>
            </div>

            <div class="stat-card">
                <span class="stat-icon">⭐</span>
                <div class="stat-value"><?php echo number_format($total_points); ?></div>
                <div class="stat-label">Total Points</div>
            </div>

            <div class="stat-card">
                <span class="stat-icon">🏅</span>
                <div class="stat-value"><?php echo count($achievement_badges); ?></div>
                <div class="stat-label">Achievements</div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div>
                <!-- Daily Reward -->
                <div class="card <?php echo $daily_reward_available ? 'daily-available' : ''; ?>" style="margin-bottom: 30px;">
                    <div class="card-header">
                        <h3 class="card-title">Daily Reward</h3>
                    </div>
                    <div class="daily-reward">
                        <span class="reward-icon"><?php echo $daily_reward_available ? '🎁' : '✅'; ?></span>
                        <h3 class="reward-title">
                            <?php echo $daily_reward_available ? 'Daily Login Bonus' : 'Already Claimed'; ?>
                        </h3>
                        <p class="reward-description">
                            <?php echo $daily_reward_available ? 'Claim your daily 5 points for logging in!' : 'Come back tomorrow for your next reward!'; ?>
                        </p>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="claim_daily_reward">
                            <button type="submit" class="claim-btn" <?php echo !$daily_reward_available ? 'disabled' : ''; ?>>
                                <?php echo $daily_reward_available ? 'Claim +5 Points' : 'Claimed Today'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Achievement Badges -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Your Achievements</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($achievement_badges)): ?>
                            <div class="badges-grid">
                                <?php foreach ($achievement_badges as $badge): ?>
                                    <div class="badge-card">
                                        <span class="badge-icon">🏆</span>
                                        <div class="badge-name"><?php echo htmlspecialchars($badge['badge_name']); ?></div>
                                        <div class="badge-description"><?php echo htmlspecialchars($badge['description']); ?></div>
                                        <div class="badge-points">+<?php echo $badge['points']; ?> points</div>
                                        <div class="badge-date">Earned on <?php echo formatDate($badge['earned_date']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🏆</div>
                                <h3>No achievements yet</h3>
                                <p>Complete tasks and use EduHive regularly to earn your first achievement!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div>
                <!-- Recent Activity -->
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-header">
                        <h3 class="card-title">Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_rewards)): ?>
                            <ul class="activity-list">
                                <?php foreach ($recent_rewards as $reward): ?>
                                    <li class="activity-item">
                                        <span class="activity-icon">
                                            <?php 
                                            echo $reward['badge_type'] === 'daily' ? '📅' : 
                                                ($reward['badge_type'] === 'achievement' ? '🏆' : '⭐'); 
                                            ?>
                                        </span>
                                        <div class="activity-content">
                                            <div class="activity-title"><?php echo htmlspecialchars($reward['badge_name']); ?></div>
                                            <div class="activity-meta">
                                                <?php echo formatDate($reward['earned_date']); ?>
                                                <?php if ($reward['description']): ?>
                                                    • <?php echo htmlspecialchars($reward['description']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="activity-points">+<?php echo $reward['points']; ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📋</div>
                                <p>No recent activity. Start using EduHive to see your progress!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Leaderboard -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Leaderboard</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($leaderboard)): ?>
                            <ul class="leaderboard-list">
                                <?php foreach ($leaderboard as $index => $leader): ?>
                                    <li class="leaderboard-item">
                                        <div class="rank <?php echo $index < 3 ? 'top3' : ''; ?>">
                                            #<?php echo $index + 1; ?>
                                        </div>
                                        <div class="user-info">
                                            <div class="user-name">
                                                <?php echo htmlspecialchars($leader['name']); ?>
                                                <?php if ($leader['name'] === $user['name']): ?>
                                                    <span style="color: #C4A484;">(You)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-badges"><?php echo $leader['badge_count']; ?> badges earned</div>
                                        </div>
                                        <div class="user-points"><?php echo number_format($leader['total_points']); ?> pts</div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🏆</div>
                                <p>Leaderboard is empty. Be the first to earn points!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Achievement Goals Section -->
        <div class="card" style="margin-top: 30px;">
            <div class="card-header">
                <h3 class="card-title">🎯 Achievement Goals</h3>
            </div>
            <div class="card-body">
                <div class="badges-grid">
                    <!-- Achievement progress cards -->
                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $total_tasks >= 1 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $total_tasks >= 1 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">First Steps</div>
                        <div class="badge-description">Create your first task</div>
                        <div class="badge-points">+10 points</div>
                        <div class="badge-date">
                            <?php if ($total_tasks >= 1): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo $total_tasks; ?>/1</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $completed_tasks >= 10 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $completed_tasks >= 10 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">Task Master</div>
                        <div class="badge-description">Complete 10 tasks</div>
                        <div class="badge-points">+25 points</div>
                        <div class="badge-date">
                            <?php if ($completed_tasks >= 10): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo $completed_tasks; ?>/10</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $completed_tasks >= 50 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $completed_tasks >= 50 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">Productivity Pro</div>
                        <div class="badge-description">Complete 50 tasks</div>
                        <div class="badge-points">+50 points</div>
                        <div class="badge-date">
                            <?php if ($completed_tasks >= 50): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo $completed_tasks; ?>/50</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $total_time >= 600 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $total_time >= 600 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">Study Warrior</div>
                        <div class="badge-description">Log 10 hours of study time</div>
                        <div class="badge-points">+30 points</div>
                        <div class="badge-date">
                            <?php if ($total_time >= 600): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo formatDuration($total_time); ?>/10 hours</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $total_time >= 3000 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $total_time >= 3000 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">Dedicated Student</div>
                        <div class="badge-description">Log 50 hours of study time</div>
                        <div class="badge-points">+75 points</div>
                        <div class="badge-date">
                            <?php if ($total_time >= 3000): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo formatDuration($total_time); ?>/50 hours</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $login_days >= 7 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $login_days >= 7 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">Consistent Learner</div>
                        <div class="badge-description">Log in for 7 days</div>
                        <div class="badge-points">+40 points</div>
                        <div class="badge-date">
                            <?php if ($login_days >= 7): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo $login_days; ?>/7 days</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: <?php echo $login_days >= 30 ? '0.5' : '1'; ?>;">
                        <span class="badge-icon"><?php echo $login_days >= 30 ? '✅' : '🎯'; ?></span>
                        <div class="badge-name">Habit Builder</div>
                        <div class="badge-description">Log in for 30 days</div>
                        <div class="badge-points">+100 points</div>
                        <div class="badge-date">
                            <?php if ($login_days >= 30): ?>
                                <span style="color: #28a745;">✓ Completed</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">Progress: <?php echo $login_days; ?>/30 days</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Coming Soon Achievement -->
                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: 0.6;">
                        <span class="badge-icon">🔮</span>
                        <div class="badge-name">Team Player</div>
                        <div class="badge-description">Join your first team</div>
                        <div class="badge-points">+20 points</div>
                        <div class="badge-date" style="color: #6c757d;">Coming Soon</div>
                    </div>

                    <div class="badge-card" style="border: 2px dashed #ddd; opacity: 0.6;">
                        <span class="badge-icon">🔮</span>
                        <div class="badge-name">Calendar Master</div>
                        <div class="badge-description">Schedule 20 classes</div>
                        <div class="badge-points">+35 points</div>
                        <div class="badge-date" style="color: #6c757d;">Coming Soon</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tips Section -->
        <div class="card" style="margin-top: 30px;">
            <div class="card-header">
                <h3 class="card-title">💡 Tips to Earn More Points</h3>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                    <div style="padding: 20px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #C4A484;">
                        <h4 style="color: #333; margin-bottom: 10px;">📋 Complete Tasks</h4>
                        <p style="color: #666; margin: 0;">Each completed task earns you 10 points. Set realistic goals and complete them consistently!</p>
                    </div>
                    
                    <div style="padding: 20px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #C4A484;">
                        <h4 style="color: #333; margin-bottom: 10px;">📅 Daily Login</h4>
                        <p style="color: #666; margin: 0;">Log in every day to claim your daily 5-point bonus. Consistency is key!</p>
                    </div>
                    
                    <div style="padding: 20px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #C4A484;">
                        <h4 style="color: #333; margin-bottom: 10px;">⏱️ Track Study Time</h4>
                        <p style="color: #666; margin: 0;">Use the time tracking feature to monitor your study sessions and unlock study-related achievements.</p>
                    </div>
                    
                    <div style="padding: 20px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #C4A484;">
                        <h4 style="color: #333; margin-bottom: 10px;">🎯 Set Goals</h4>
                        <p style="color: #666; margin: 0;">Create meaningful tasks and work towards bigger achievements for maximum point rewards.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
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

        // Add some interactivity to achievement cards
        document.querySelectorAll('.badge-card').forEach(card => {
            card.addEventListener('click', function() {
                if (!this.style.opacity || this.style.opacity === '1') {
                    // Only animate if not completed
                    this.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 200);
                }
            });
        });

        // Animate progress bars on load
        window.addEventListener('load', function() {
            const progressBars = document.querySelectorAll('.progress-bar');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
        });

        // Add celebration effect for daily reward
        const claimBtn = document.querySelector('.claim-btn');
        if (claimBtn && !claimBtn.disabled) {
            claimBtn.addEventListener('click', function() {
                // Add some visual feedback
                this.innerHTML = '🎉 Claiming...';
                this.disabled = true;
            });
        }

        // Tooltip functionality for achievement cards
        document.querySelectorAll('.badge-card').forEach(card => {
            card.setAttribute('title', 'Click to view details');
        });

        // Add some responsive behavior
        function handleResize() {
            const statsSection = document.querySelector('.stats-section');
            if (window.innerWidth <= 768) {
                // Mobile adjustments already handled in CSS
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize(); // Call on load

        // Add keyboard navigation for accessibility
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                if (event.target.classList.contains('badge-card')) {
                    event.target.click();
                }
            }
        });

        // Show encouraging messages based on progress
        const totalPoints = <?php echo $total_points; ?>;
        const completedTasks = <?php echo $completed_tasks; ?>;
        
        if (totalPoints === 0 && completedTasks === 0) {
            // First time user
            setTimeout(() => {
                console.log('Welcome to EduHive! Start by creating your first task to earn points!');
            }, 2000);
        }

        // Add visual feedback for new achievements
        function celebrateAchievement() {
            // Create confetti effect (simple version)
            const colors = ['#C4A484', '#B8956A', '#ffc107', '#28a745'];
            for (let i = 0; i < 50; i++) {
                createConfetti(colors[Math.floor(Math.random() * colors.length)]);
            }
        }

        function createConfetti(color) {
            const confetti = document.createElement('div');
            confetti.style.position = 'fixed';
            confetti.style.width = '10px';
            confetti.style.height = '10px';
            confetti.style.backgroundColor = color;
            confetti.style.left = Math.random() * window.innerWidth + 'px';
            confetti.style.top = '-10px';
            confetti.style.opacity = '0.8';
            confetti.style.borderRadius = '50%';
            confetti.style.pointerEvents = 'none';
            confetti.style.zIndex = '9999';
            
            document.body.appendChild(confetti);
            
            const animation = confetti.animate([
                { transform: 'translateY(0px) rotate(0deg)', opacity: 0.8 },
                { transform: `translateY(${window.innerHeight + 100}px) rotate(360deg)`, opacity: 0 }
            ], {
                duration: 3000,
                easing: 'linear'
            });
            
            animation.onfinish = () => confetti.remove();
        }

        // Check if user just earned an achievement (simple check)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('achievement') === 'new') {
            setTimeout(celebrateAchievement, 1000);
        }
    </script>
</body>
</html>