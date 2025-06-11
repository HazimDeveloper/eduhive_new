<?php
// sidebar.php - Reusable sidebar component
// Usage: include 'sidebar.php'; or include_once 'sidebar.php';

// Get current page name to determine active menu item
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Define menu items with their corresponding files and labels
$menu_items = [
    'dashboard' => [
        'file' => 'dashboard.php',
        'label' => 'Dashboard',
        'icon' => '📊'
    ],
    'calendar' => [
        'file' => 'calendar.php',
        'label' => 'Calendar',
        'icon' => '📅'
    ],
    'class_schedule' => [
        'file' => 'class_schedule.php',
        'label' => 'Class Schedules',
        'icon' => '📚'
    ],
    'task' => [
        'file' => 'task.php',
        'label' => 'Task',
        'icon' => '📋'
    ],
    'record_time' => [
        'file' => 'record_time.php',
        'label' => 'Record Time',
        'icon' => '⏱️'
    ],
    'file_manager' => [           // NEW
        'file' => 'file_manager.php',
        'label' => 'Files',
        'icon' => '📁'
    ],
    'notifications' => [          // NEW
        'file' => 'notifications.php',
        'label' => 'Notifications',
        'icon' => '🔔'
    ],
    'reward' => [
        'file' => 'reward.php',
        'label' => 'Reward',
        'icon' => '🏆'
    ],
    'team_member' => [
        'file' => 'team_member.php',
        'label' => 'Team Members',
        'icon' => '👥'
    ]
];

// Function to check if current page is active
function isActivePage($page_key, $current_page) {
    global $menu_items;
    return $current_page === basename($menu_items[$page_key]['file'], '.php');
}
?>

<!-- Sidebar Navigation -->
<nav class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
           <img src="logoo.png" alt="">
        </div>
        <h2>EduHive</h2>
    </div>
    
    <ul class="nav-menu">
        <?php foreach ($menu_items as $key => $item): ?>
            <li class="nav-item <?php echo isActivePage($key, $current_page) ? 'active' : ''; ?>">
                <a href="<?php echo htmlspecialchars($item['file']); ?>">
                    <span class="menu-icon"><?php echo $item['icon']; ?></span>
                    <span class="menu-label"><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
        
        <!-- Logout option -->
        <li class="nav-item logout-item">
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?')">
                <span class="menu-icon">🚪</span>
                <span class="menu-label">Logout</span>
            </a>
        </li>
    </ul>
</nav>

<style>

 /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #B8956A, #A6845C);
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
        
.menu-icon {
    margin-right: 10px;
    font-size: 16px;
    width: 20px;
    display: inline-block;
}

.menu-label {
    flex: 1;
}

.nav-item a {
    display: flex;
    align-items: center;
}

.logout-item {
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    margin-top: 20px;
}

.logout-item a:hover {
    background: rgba(244, 67, 54, 0.2);
}

/* Mobile responsive improvements */
@media (max-width: 1024px) {
    .nav-menu {
        display: flex;
        overflow-x: auto;
        padding: 0;
    }
    
    .nav-item {
        border-bottom: none;
        border-right: 1px solid rgba(255, 255, 255, 0.1);
        min-width: 120px;
        flex-shrink: 0;
    }
    
    .nav-item:last-child {
        border-right: none;
    }
    
    .menu-icon {
        margin-right: 5px;
        font-size: 14px;
    }
    
    .menu-label {
        font-size: 14px;
    }
    
    .logout-item {
        margin-top: 0;
        border-top: none;
    }
}

@media (max-width: 768px) {
    .menu-label {
        display: none;
    }
    
    .menu-icon {
        margin-right: 0;
        font-size: 18px;
    }
    
    .nav-item {
        min-width: 60px;
        text-align: center;
    }
    
    .nav-item a {
        justify-content: center;
        padding: 15px 10px;
    }
}
</style>