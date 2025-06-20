<?php
// debug_email.php - Email Configuration Testing Tool
// Place this file in your project root directory

require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Require login
requireLogin();

$user = getCurrentUser();
$test_results = [];
$form_submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $form_submitted = true;
    $action = $_POST['action'];
    
    switch ($action) {
        case 'test_basic_mail':
            $test_results['basic'] = testBasicMail($user['email'], $user['name']);
            break;
            
        case 'test_phpmailer':
            $test_results['phpmailer'] = testPHPMailer($user['email'], $user['name']);
            break;
            
        case 'test_gmail_smtp':
            $gmail_email = $_POST['gmail_email'] ?? '';
            $gmail_password = $_POST['gmail_password'] ?? '';
            $test_results['gmail'] = testGmailSMTP($user['email'], $user['name'], $gmail_email, $gmail_password);
            break;
            
        case 'check_server_config':
            $test_results['server'] = checkServerEmailConfig();
            break;
            
        case 'test_notification_system':
            $test_results['notification'] = testNotificationSystem($user['id'], $user['email'], $user['name']);
            break;
    }
}

function testBasicMail($to_email, $to_name) {
    try {
        $subject = "EduHive - Basic Mail Test";
        $message = "This is a test email using PHP's basic mail() function.\n\nTime: " . date('Y-m-d H:i:s');
        $headers = "From: noreply@eduhive.com\r\nReply-To: noreply@eduhive.com";
        
        $result = mail($to_email, $subject, $message, $headers);
        
        return [
            'success' => $result,
            'message' => $result ? 'Basic mail sent successfully!' : 'Basic mail failed to send.',
            'details' => [
                'Function used' => 'PHP mail()',
                'To' => $to_email,
                'From' => 'noreply@eduhive.com',
                'Method' => 'Server default mail configuration'
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'details' => ['Error' => $e->getMessage()]
        ];
    }
}

function testPHPMailer($to_email, $to_name) {
    try {
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return [
                'success' => false,
                'message' => 'PHPMailer not found. Run: composer require phpmailer/phpmailer',
                'details' => ['Issue' => 'PHPMailer library not installed']
            ];
        }

        // No use statements here; they must be at the top of the file
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'your_email@gmail.com'; // Update with your email
        $mail->Password = 'your_app_password'; // Update with your app password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('noreply@eduhive.com', 'EduHive System');
        $mail->addAddress($to_email, $to_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'EduHive - PHPMailer Test';
        $mail->Body = '
        <html>
        <body>
            <h2>PHPMailer Test Email</h2>
            <p>This email was sent using PHPMailer with Gmail SMTP.</p>
            <p><strong>Test Details:</strong></p>
            <ul>
                <li>Time: ' . date('Y-m-d H:i:s') . '</li>
                <li>Method: PHPMailer + Gmail SMTP</li>
                <li>Port: 587 (TLS)</li>
            </ul>
        </body>
        </html>';

        $mail->send();

        return [
            'success' => true,
            'message' => 'PHPMailer email sent successfully!',
            'details' => [
                'SMTP Host' => 'smtp.gmail.com',
                'Port' => '587',
                'Encryption' => 'TLS',
                'Authentication' => 'Yes'
            ]
        ];

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return [
            'success' => false,
            'message' => 'PHPMailer Error: ' . $e->getMessage(),
            'details' => [
                'Error' => $e->getMessage(),
                'Possible causes' => [
                    'Wrong Gmail credentials',
                    'App password not enabled',
                    '2-Factor Authentication not setup',
                    'Less secure app access disabled'
                ]
            ]
        ];
    }
}

function testGmailSMTP($to_email, $to_name, $gmail_email, $gmail_password) {
    try {
        if (empty($gmail_email) || empty($gmail_password)) {
            return [
                'success' => false,
                'message' => 'Gmail email and password required',
                'details' => ['Error' => 'Missing credentials']
            ];
        }

        // No use statements here; they must be at the top of the file
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $gmail_email;
        $mail->Password = $gmail_password;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->SMTPDebug = 0; // Enable for debugging

        // Recipients
        $mail->setFrom($gmail_email, 'EduHive System');
        $mail->addAddress($to_email, $to_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'EduHive - Gmail SMTP Test';
        $mail->Body = '
        <html>
        <body style="font-family: Arial, sans-serif;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #C4A484;">✅ Gmail SMTP Test Successful!</h2>
                <p>This email was sent successfully using your Gmail SMTP credentials.</p>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3>Configuration Details:</h3>
                    <ul>
                        <li><strong>SMTP Host:</strong> smtp.gmail.com</li>
                        <li><strong>Port:</strong> 587 (TLS)</li>
                        <li><strong>From Email:</strong> ' . $gmail_email . '</li>
                        <li><strong>Test Time:</strong> ' . date('Y-m-d H:i:s') . '</li>
                    </ul>
                </div>
                
                <p style="color: #28a745;"><strong>Your email configuration is working correctly!</strong></p>
                <p>You can now update your functions.php file with these credentials.</p>
            </div>
        </body>
        </html>';

        $mail->send();

        return [
            'success' => true,
            'message' => 'Gmail SMTP test successful!',
            'details' => [
                'Gmail Account' => $gmail_email,
                'SMTP Host' => 'smtp.gmail.com',
                'Port' => '587 (TLS)',
                'Authentication' => 'Successful',
                'Next Step' => 'Update functions.php with these credentials'
            ]
        ];

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return [
            'success' => false,
            'message' => 'Gmail SMTP Error: ' . $e->getMessage(),
            'details' => [
                'Error' => $e->getMessage(),
                'Common Solutions' => [
                    '1. Enable 2-Factor Authentication on Gmail',
                    '2. Generate App Password (not regular password)',
                    '3. Use App Password instead of regular password',
                    '4. Check Gmail security settings'
                ]
            ]
        ];
    }
}

function checkServerEmailConfig() {
    $config = [];
    
    // Check PHP mail configuration
    $config['sendmail_path'] = ini_get('sendmail_path') ?: 'Not set';
    $config['SMTP'] = ini_get('SMTP') ?: 'Not set';
    $config['smtp_port'] = ini_get('smtp_port') ?: 'Not set';
    $config['sendmail_from'] = ini_get('sendmail_from') ?: 'Not set';
    
    // Check if mail function is available
    $config['mail_function_available'] = function_exists('mail') ? 'Yes' : 'No';
    
    // Check PHPMailer
    $config['phpmailer_available'] = class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'Yes' : 'No';
    
    // Check server type
    $config['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $config['php_version'] = phpversion();
    
    // Check if running on localhost
    $config['is_localhost'] = (in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1'])) ? 'Yes' : 'No';
    
    return [
        'success' => true,
        'message' => 'Server configuration checked',
        'details' => $config,
        'recommendations' => [
            $config['is_localhost'] === 'Yes' ? 'You are on localhost - external SMTP recommended' : '',
            $config['phpmailer_available'] === 'No' ? 'Install PHPMailer: composer require phpmailer/phpmailer' : '',
            $config['mail_function_available'] === 'No' ? 'PHP mail() function not available' : ''
        ]
    ];
}

function testNotificationSystem($user_id, $email, $name) {
    try {
        // Test the actual notification function from your system
        $result = sendEmailNotification($email, $name, 'System Test', 'This is a test from the notification system.');
        
        return [
            'success' => $result,
            'message' => $result ? 'Notification system test passed!' : 'Notification system test failed!',
            'details' => [
                'Function' => 'sendEmailNotification()',
                'User ID' => $user_id,
                'Email' => $email,
                'Result' => $result ? 'Success' : 'Failed'
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Notification system error: ' . $e->getMessage(),
            'details' => ['Error' => $e->getMessage()]
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Debug Tool - EduHive</title>
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
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .page-subtitle {
            color: #666;
            font-size: 16px;
        }

        .debug-container {
            display: grid;
            gap: 20px;
            max-width: 1200px;
        }

        .debug-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-description {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .test-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-label {
            font-weight: 500;
            color: #333;
        }

        .form-input {
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #4A90A4;
        }

        .test-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .test-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-1px);
        }

        .test-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .result-card {
            margin-top: 20px;
            padding: 20px;
            border-radius: 10px;
            border-left: 5px solid;
        }

        .result-card.success {
            background-color: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }

        .result-card.error {
            background-color: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }

        .result-title {
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .result-message {
            margin-bottom: 15px;
        }

        .result-details {
            background: rgba(255, 255, 255, 0.5);
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }

        .detail-item {
            margin-bottom: 8px;
        }

        .detail-key {
            font-weight: 600;
            display: inline-block;
            min-width: 120px;
        }

        .recommendations {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .recommendations-title {
            font-weight: 600;
            color: #856404;
            margin-bottom: 10px;
        }

        .recommendation-item {
            color: #856404;
            margin-bottom: 5px;
            padding-left: 15px;
            position: relative;
        }

        .recommendation-item::before {
            content: '💡';
            position: absolute;
            left: 0;
        }

        .gmail-setup {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .gmail-setup-title {
            font-weight: 600;
            color: #1976d2;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .gmail-step {
            margin-bottom: 10px;
            color: #1976d2;
        }

        .gmail-step strong {
            color: #0d47a1;
        }

        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .warning-title {
            font-weight: 600;
            color: #856404;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .code-block {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            margin: 10px 0;
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .debug-container {
                gap: 15px;
            }

            .debug-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">🔧 Email Configuration Debug Tool</h1>
            <p class="page-subtitle">Test and troubleshoot email notifications for EduHive</p>
        </div>

        <div class="debug-container">
            <!-- Gmail Setup Instructions -->
            <div class="debug-card">
                <h2 class="card-title">📧 Gmail SMTP Setup Instructions</h2>
                <div class="gmail-setup">
                    <div class="gmail-setup-title">🔑 How to Setup Gmail App Password</div>
                    <div class="gmail-step"><strong>Step 1:</strong> Enable 2-Factor Authentication on your Gmail account</div>
                    <div class="gmail-step"><strong>Step 2:</strong> Go to Google Account Settings → Security → App passwords</div>
                    <div class="gmail-step"><strong>Step 3:</strong> Generate a new app password for "EduHive"</div>
                    <div class="gmail-step"><strong>Step 4:</strong> Use the generated 16-character password (not your regular password)</div>
                    <div class="gmail-step"><strong>Step 5:</strong> Test with the form below</div>
                </div>
            </div>

            <!-- Current Configuration -->
            <div class="debug-card">
                <h2 class="card-title">⚙️ Check Server Configuration</h2>
                <p class="card-description">Check your server's email configuration and PHP settings.</p>
                
                <form method="POST" class="test-form">
                    <input type="hidden" name="action" value="check_server_config">
                    <button type="submit" class="test-btn">Check Server Config</button>
                </form>

                <?php if (isset($test_results['server'])): ?>
                    <div class="result-card <?php echo $test_results['server']['success'] ? 'success' : 'error'; ?>">
                        <div class="result-title">
                            <?php echo $test_results['server']['success'] ? '✅' : '❌'; ?>
                            Server Configuration Results
                        </div>
                        <div class="result-message"><?php echo $test_results['server']['message']; ?></div>
                        <div class="result-details">
                            <?php foreach ($test_results['server']['details'] as $key => $value): ?>
                                <div class="detail-item">
                                    <span class="detail-key"><?php echo $key; ?>:</span> <?php echo $value; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty(array_filter($test_results['server']['recommendations']))): ?>
                            <div class="recommendations">
                                <div class="recommendations-title">📋 Recommendations:</div>
                                <?php foreach (array_filter($test_results['server']['recommendations']) as $rec): ?>
                                    <div class="recommendation-item"><?php echo $rec; ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Basic Mail Test -->
            <div class="debug-card">
                <h2 class="card-title">📮 Test Basic PHP Mail</h2>
                <p class="card-description">Test using PHP's basic mail() function with server default settings.</p>
                
                <div class="warning-box">
                    <div class="warning-title">⚠️ Note</div>
                    Basic mail() often doesn't work on localhost or shared hosting without proper SMTP configuration.
                </div>

                <form method="POST" class="test-form">
                    <input type="hidden" name="action" value="test_basic_mail">
                    <button type="submit" class="test-btn">Send Basic Mail Test</button>
                </form>

                <?php if (isset($test_results['basic'])): ?>
                    <div class="result-card <?php echo $test_results['basic']['success'] ? 'success' : 'error'; ?>">
                        <div class="result-title">
                            <?php echo $test_results['basic']['success'] ? '✅' : '❌'; ?>
                            Basic Mail Test Results
                        </div>
                        <div class="result-message"><?php echo $test_results['basic']['message']; ?></div>
                        <div class="result-details">
                            <?php foreach ($test_results['basic']['details'] as $key => $value): ?>
                                <div class="detail-item">
                                    <span class="detail-key"><?php echo $key; ?>:</span> <?php echo $value; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Gmail SMTP Test -->
            <div class="debug-card">
                <h2 class="card-title">🔐 Test Gmail SMTP (Recommended)</h2>
                <p class="card-description">Test email sending using your Gmail account with SMTP authentication.</p>

                <form method="POST" class="test-form">
                    <input type="hidden" name="action" value="test_gmail_smtp">
                    
                    <div class="form-group">
                        <label class="form-label">Your Gmail Address:</label>
                        <input type="email" name="gmail_email" class="form-input" 
                               placeholder="your.email@gmail.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Gmail App Password (16 characters):</label>
                        <input type="password" name="gmail_password" class="form-input" 
                               placeholder="abcd efgh ijkl mnop" required>
                        <small style="color: #666;">Use App Password, not your regular Gmail password</small>
                    </div>
                    
                    <button type="submit" class="test-btn">Test Gmail SMTP</button>
                </form>

                <?php if (isset($test_results['gmail'])): ?>
                    <div class="result-card <?php echo $test_results['gmail']['success'] ? 'success' : 'error'; ?>">
                        <div class="result-title">
                            <?php echo $test_results['gmail']['success'] ? '✅' : '❌'; ?>
                            Gmail SMTP Test Results
                        </div>
                        <div class="result-message"><?php echo $test_results['gmail']['message']; ?></div>
                        <div class="result-details">
                            <?php foreach ($test_results['gmail']['details'] as $key => $value): ?>
                                <div class="detail-item">
                                    <span class="detail-key"><?php echo $key; ?>:</span> 
                                    <?php echo is_array($value) ? implode(', ', $value) : $value; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($test_results['gmail']['success']): ?>
                            <div class="recommendations">
                                <div class="recommendations-title">🎉 Success! Next Steps:</div>
                                <div class="recommendation-item">Update your functions.php file with these credentials</div>
                                <div class="recommendation-item">Enable email notifications in your profile</div>
                                <div class="recommendation-item">Test the notification system below</div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Test Current Functions -->
            <div class="debug-card">
                <h2 class="card-title">🧪 Test Current Notification System</h2>
                <p class="card-description">Test the actual notification function used by EduHive.</p>

                <form method="POST" class="test-form">
                    <input type="hidden" name="action" value="test_notification_system">
                    <button type="submit" class="test-btn">Test Notification System</button>
                </form>

                <?php if (isset($test_results['notification'])): ?>
                    <div class="result-card <?php echo $test_results['notification']['success'] ? 'success' : 'error'; ?>">
                        <div class="result-title">
                            <?php echo $test_results['notification']['success'] ? '✅' : '❌'; ?>
                            Notification System Results
                        </div>
                        <div class="result-message"><?php echo $test_results['notification']['message']; ?></div>
                        <div class="result-details">
                            <?php foreach ($test_results['notification']['details'] as $key => $value): ?>
                                <div class="detail-item">
                                    <span class="detail-key"><?php echo $key; ?>:</span> <?php echo $value; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Fix Instructions -->
            <div class="debug-card">
                <h2 class="card-title">🔧 How to Fix Email Issues</h2>
                <div class="card-description">
                    <h3 style="margin-bottom: 15px;">Common Solutions:</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <strong>1. Update functions.php email configuration:</strong>
                        <div class="code-block">
// In config/functions.php, update sendEmail() function:
$mail->Username = 'your.email@gmail.com'; // Your Gmail
$mail->Password = 'your_app_password'; // 16-character app password</div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <strong>2. Enable Gmail App Password:</strong>
                        <ul style="margin-left: 20px; margin-top: 5px;">
                            <li>Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a></li>
                            <li>Enable 2-Factor Authentication</li>
                            <li>Generate App Password for "Mail"</li>
                            <li>Use the 16-character password in your code</li>
                        </ul>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <strong>3. Check spam folder:</strong>
                        <p style="margin-left: 20px; margin-top: 5px;">Emails might be going to spam/junk folder</p>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <strong>4. Alternative SMTP providers:</strong>
                        <ul style="margin-left: 20px; margin-top: 5px;">
                            <li>Gmail SMTP (recommended)</li>
                            <li>Outlook/Hotmail SMTP</li>
                            <li>SendGrid, Mailgun (for production)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Show loading state on form submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const button = this.querySelector('.test-btn');
                button.disabled = true;
                button.textContent = '🔄 Testing...';
            });
        });

        // Auto-scroll to results
        <?php if ($form_submitted): ?>
        setTimeout(() => {
            const resultCards = document.querySelectorAll('.result-card');
            if (resultCards.length > 0) {
                resultCards[resultCards.length - 1].scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
            }
        }, 100);
        <?php endif; ?>
    </script>
</body>
</html>