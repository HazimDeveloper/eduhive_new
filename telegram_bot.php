<?php
// telegram_bot.php - Save as separate file dalam project root

$bot_token = "8122156077:AAEL_j6QN-vnPrfyqbOnam4mCfBQfIjId9k";

// Get incoming message dari Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Log untuk debugging (optional)
file_put_contents('telegram_log.txt', date('Y-m-d H:i:s') . " - " . $content . "\n", FILE_APPEND);

if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $message_text = $update['message']['text'] ?? '';
    $first_name = $update['message']['from']['first_name'] ?? 'User';
    $username = $update['message']['from']['username'] ?? '';
    
    if ($message_text === '/start') {
        $response_text = "🎓 *Welcome to EduHive Bot!*\n\n";
        $response_text .= "Hello $first_name! 👋\n\n";
        $response_text .= "📋 *Your Chat ID:* `$chat_id`\n\n";
        $response_text .= "💡 *Setup Instructions:*\n";
        $response_text .= "1. Copy your Chat ID above\n";
        $response_text .= "2. Go to EduHive → Profile Settings\n";
        $response_text .= "3. Paste Chat ID in Telegram field\n";
        $response_text .= "4. Enable Telegram notifications\n";
        $response_text .= "5. Save settings\n\n";
        $response_text .= "✅ You'll receive task reminders here!\n\n";
        $response_text .= "Use /help to see available commands.";
        
        sendTelegramMessage($chat_id, $response_text);
    }
    elseif ($message_text === '/help') {
        $response_text = "🆘 *EduHive Bot Commands:*\n\n";
        $response_text .= "🔹 `/start` - Get your Chat ID for setup\n";
        $response_text .= "🔹 `/help` - Show this help message\n";
        $response_text .= "🔹 `/test` - Test notification\n";
        $response_text .= "🔹 `/info` - Show your info\n\n";
        $response_text .= "💻 [Open EduHive](http://localhost/eduhive)\n\n";
        $response_text .= "Need help? Contact your system admin.";
        
        sendTelegramMessage($chat_id, $response_text);
    }
    elseif ($message_text === '/test') {
        $response_text = "🧪 *Test Notification*\n\n";
        $response_text .= "✅ Great! Your Telegram notifications are working!\n\n";
        $response_text .= "You'll receive messages like this when:\n";
        $response_text .= "• Tasks are assigned to you\n";
        $response_text .= "• Tasks are due tomorrow\n";
        $response_text .= "• You earn achievements\n\n";
        $response_text .= "🎯 All set up correctly!";
        
        sendTelegramMessage($chat_id, $response_text);
    }
    elseif ($message_text === '/info') {
        $response_text = "ℹ️ *Your Information:*\n\n";
        $response_text .= "👤 *Name:* $first_name\n";
        if ($username) {
            $response_text .= "📱 *Username:* @$username\n";
        }
        $response_text .= "💬 *Chat ID:* `$chat_id`\n\n";
        $response_text .= "🤖 *Bot:* @eduhive_notifications_bot\n";
        $response_text .= "🔗 Connected to EduHive system";
        
        sendTelegramMessage($chat_id, $response_text);
    }
    else {
        $response_text = "❓ *Unknown command.*\n\n";
        $response_text .= "Available commands:\n";
        $response_text .= "• /start - Get setup info\n";
        $response_text .= "• /help - Show help\n";
        $response_text .= "• /test - Test notifications\n";
        $response_text .= "• /info - Show your info\n\n";
        $response_text .= "Type /help for more details.";
        
        sendTelegramMessage($chat_id, $response_text);
    }
}

function sendTelegramMessage($chat_id, $text) {
    global $bot_token;
    
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}
?>