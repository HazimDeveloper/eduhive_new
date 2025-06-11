<?php
// =============================================================================
// 3. CREATE test_telegram.php - Test bot connection
// =============================================================================
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Telegram Bot - EduHive</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        .success { color: #28a745; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        input[type="text"] { width: 300px; padding: 8px; margin: 5px; border: 1px solid #ddd; border-radius: 4px; }
        .code { background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🤖 Telegram Bot Test - EduHive</h1>
        
        <?php
        require_once 'config/functions.php';
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($_POST['action'] === 'test_bot') {
                echo "<h3>Testing Bot Connection...</h3>";
                $result = testTelegramBot();
                
                if ($result['success']) {
                    echo "<div class='success'>✅ Bot is working!</div>";
                    echo "<div class='info'>Bot Name: " . $result['bot_name'] . "</div>";
                    echo "<div class='info'>Bot Username: @" . $result['bot_username'] . "</div>";
                } else {
                    echo "<div class='error'>❌ Bot connection failed!</div>";
                    echo "<div class='error'>Error: " . htmlspecialchars($result['error']) . "</div>";
                }
            }
            elseif ($_POST['action'] === 'test_message') {
                $chat_id = $_POST['chat_id'] ?? '';
                if (empty($chat_id)) {
                    echo "<div class='error'>❌ Please enter a Chat ID!</div>";
                } else {
                    echo "<h3>Sending Test Message...</h3>";
                    $result = sendTelegramNotification($chat_id, "Test Notification", "Hello! This is a test message from EduHive. If you see this, your notifications are working perfectly! 🎉");
                    
                    if ($result) {
                        echo "<div class='success'>✅ Test message sent successfully!</div>";
                        echo "<div class='info'>Check your Telegram chat for the message.</div>";
                    } else {
                        echo "<div class='error'>❌ Failed to send test message!</div>";
                        echo "<div class='error'>Check the Chat ID or bot token.</div>";
                    }
                }
            }
        }
        ?>
        
        <h3>📋 Setup Steps:</h3>
        <ol>
            <li><strong>Test Bot Connection:</strong></li>
        </ol>
        <form method="POST">
            <input type="hidden" name="action" value="test_bot">
            <button type="submit">🔍 Test Bot Connection</button>
        </form>
        
        <ol start="2">
            <li><strong>Get Your Chat ID:</strong></li>
        </ol>
        <div class="info">
            <strong>Go to Telegram:</strong><br>
            1. Search for: <code>@eduhive_notifications_bot</code><br>
            2. Send: <code>/start</code><br>
            3. Copy the Chat ID from bot response
        </div>
        
        <ol start="3">
            <li><strong>Test Notification:</strong></li>
        </ol>
        <form method="POST">
            <input type="hidden" name="action" value="test_message">
            <input type="text" name="chat_id" placeholder="Enter your Chat ID here" required>
            <button type="submit">📱 Send Test Message</button>
        </form>
        
        <h3>🔗 Bot Link:</h3>
        <div class="code">
            <strong>Bot URL:</strong> <a href="https://t.me/eduhive_notifications_bot" target="_blank">https://t.me/eduhive_notifications_bot</a>
        </div>
        
        <h3>📝 Next Steps:</h3>
        <div class="info">
            1. ✅ Test bot connection (above)<br>
            2. ✅ Get your Chat ID from bot<br>
            3. ✅ Test notification (above)<br>
            4. ✅ Add Chat ID to your EduHive profile<br>
            5. ✅ Enable Telegram notifications in settings<br>
            6. 🎉 Enjoy automated notifications!
        </div>
        
        <p><a href="profile.php">← Back to Profile Settings</a></p>
    </div>
</body>
</html>