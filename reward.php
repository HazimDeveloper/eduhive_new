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
                
                // Create success notification
                createNotification(
                    $user['id'],
                    '🎉 Daily Reward Claimed!',
                    'You have successfully claimed your daily 5 points bonus!',
                    'achievement'
                );
                
                setMessage('🎉 Daily reward claimed successfully! You earned 5 points!', 'success');
            } else {
                setMessage('You have already claimed your daily reward today! Come back tomorrow.', 'info');
            }
        } catch (Exception $e) {
            error_log("Daily reward claim error: " . $e->getMessage());
            setMessage('Error claiming reward. Please try again.', 'error');
        }
    }
    
    // Redirect to prevent form resubmission
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
            min-width: 200px;
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

        .claim-btn.claiming {
            background: #ffc107;
            animation: pulse 1.5s infinite;
        }

        .claim-btn.claimed {
            background: #28a745;
        }

        /* Success Animation */
        .success-popup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
            z-index: 10000;
            display: none;
        }

        .success-popup.show {
            display: block;
            animation: successPopup 0.5s ease;
        }

        .success-popup .popup-icon {
            font-size: 60px;
            margin-bottom: 15px;
        }

        .success-popup .popup-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .success-popup .popup-message {
            color: #666;
            margin-bottom: 20px;
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

        @keyframes successPopup {
            0% {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.8);
            }
            100% {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            background: #C4A484;
            border-radius: 50%;
            pointer-events: none;
            z-index: 9999;
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
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-header">
                        <h3 class="card-title">Daily Reward</h3>
                    </div>
                    <div class="daily-reward">
                        <span class="reward-icon" id="rewardIcon"><?php echo $daily_reward_available ? '🎁' : '✅'; ?></span>
                        <h3 class="reward-title" id="rewardTitle">
                            <?php echo $daily_reward_available ? 'Daily Login Bonus' : 'Already Claimed'; ?>
                        </h3>
                        <p class="reward-description" id="rewardDescription">
                            <?php echo $daily_reward_available ? 'Claim your daily 5 points for logging in!' : 'Come back tomorrow for your next reward!'; ?>
                        </p>
                        
                        <form method="POST" id="claimForm">
                            <input type="hidden" name="action" value="claim_daily_reward">
                            <button type="button" 
                                    class="claim-btn" 
                                    id="claimBtn"
                                    onclick="claimDailyReward()"
                                    <?php echo !$daily_reward_available ? 'disabled' : ''; ?>>
                                <?php echo $daily_reward_available ? 'CLAIM +5 POINTS' : 'CLAIMED TODAY'; ?>
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
            </div>
        </div>
    </div>

    <!-- Success Popup -->
    <div id="successPopup" class="success-popup">
        <div class="popup-icon">🎉</div>
        <div class="popup-title">Congratulations!</div>
        <div class="popup-message">You have successfully claimed your daily reward!</div>
    </div>

    <script>
        function claimDailyReward() {
            const btn = document.getElementById('claimBtn');
            const form = document.getElementById('claimForm');
            const icon = document.getElementById('rewardIcon');
            const title = document.getElementById('rewardTitle');
            const description = document.getElementById('rewardDescription');
            
            // Prevent double submission
            if (btn.disabled) return;
            
            // Change button state
            btn.disabled = true;
            btn.classList.add('claiming');
            btn.innerHTML = '🎁 CLAIMING...';
            
            // Create confetti effect
            createConfetti();
            
            // Submit form after a short delay for visual effect
            setTimeout(() => {
                // Change to success state before submission
                btn.classList.remove('claiming');
                btn.classList.add('claimed');
                btn.innerHTML = '✅ CLAIMED!';
                icon.textContent = '🎉';
                title.textContent = 'Reward Claimed!';
                description.textContent = 'You earned 5 points! Come back tomorrow for more.';
                
                // Show success popup
                showSuccessPopup();
                
                // Submit form after showing success
                setTimeout(() => {
                    form.submit();
                }, 1500);
                
            }, 1000);
        }
        
        function createConfetti() {
            const colors = ['#C4A484', '#B8956A', '#ffc107', '#28a745', '#17a2b8'];
            for (let i = 0; i < 50; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * window.innerWidth + 'px';
                    confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                    confetti.style.animationDelay = Math.random() * 2 + 's';
                    document.body.appendChild(confetti);
                    
                    const animation = confetti.animate([
                        { transform: 'translateY(-100vh) rotate(0deg)', opacity: 1 },
                        { transform: `translateY(100vh) rotate(720deg)`, opacity: 0 }
                    ], {
                        duration: 3000,
                        easing: 'linear'
                    });
                    
                    animation.onfinish = () => confetti.remove();
                }, i * 50);
            }
        }
        
        function showSuccessPopup() {
            const popup = document.getElementById('successPopup');
            popup.classList.add('show');
            
            setTimeout(() => {
                popup.classList.remove('show');
            }, 3000);
        }

        // Auto-hide success messages
        setTimeout(function() {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                setTimeout(() => successMessage.remove(), 300);
            }
        }, 5000);

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

        // Animate stat cards on scroll
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'slideDown 0.6s ease forwards';
                }
            });
        });

        document.querySelectorAll('.stat-card').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>