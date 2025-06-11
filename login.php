<?php
// login.php - User Login System

// Include required files (session will be started in session.php)
require_once 'config/session.php';
require_once 'config/database.php';
require_once 'config/functions.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Get any existing message
$message = getMessage();

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Basic validation
    if (empty($email)) {
        setMessage('Please enter your email address.', 'error');
    } elseif (empty($password)) {
        setMessage('Please enter your password.', 'error');
    } elseif (!isValidEmail($email)) {
        setMessage('Please enter a valid email address.', 'error');
    } else {
        // Try to login
        $database = new Database();
        $db = $database->getConnection();
        
        try {
            // Get user from database
            $query = "SELECT * FROM users WHERE email = :email AND status = 'active'";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check password
                if (password_verify($password, $user['password'])) {
                    // Password is correct - login user
                    if (loginUser($user)) {
                        // Update last login time
                        $update_query = "UPDATE users SET last_login = NOW() WHERE id = :id";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bindParam(':id', $user['id']);
                        $update_stmt->execute();
                        
                        // Login successful - redirect to dashboard
                        setMessage('Welcome back, ' . $user['name'] . '!', 'success');
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        setMessage('Login failed. Please try again.', 'error');
                    }
                } else {
                    // Wrong password
                    setMessage('Invalid email or password.', 'error');
                }
            } else {
                // User not found or inactive
                setMessage('Invalid email or password.', 'error');
            }
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            setMessage('System error. Please try again later.', 'error');
        }
    }
    
    // Redirect to avoid form resubmission
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduHive - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Left Logo */
        .top-logo {
            position: fixed;
            top: 30px;
            left: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 1000;
        }

        .logo-circle {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #4A90A4, #357A8C);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 4px 12px rgba(74, 144, 164, 0.3);
        }

        .graduation-cap {
            font-size: 24px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
        }

        .location-pin {
            font-size: 14px;
            position: absolute;
            bottom: -2px;
            right: -2px;
            background: #FF9800;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            color: white;
        }

        .logo-text {
            font-size: 32px;
            font-weight: 600;
            color: #333;
            letter-spacing: -1px;
        }

        /* Main Container */
        .login-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }

        .login-content {
            width: 100%;
            max-width: 500px;
            text-align: center;
        }

        /* Page Title */
        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 60px;
            letter-spacing: 2px;
        }

        /* Message Styles */
        .message {
            padding: 15px 20px;
            margin-bottom: 30px;
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

        /* Form Styling */
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .form-group {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 20px 25px;
            font-size: 18px;
            border: 3px solid #333;
            border-radius: 50px;
            outline: none;
            background: #f8f9fa;
            color: #333;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            border-color: #4A90A4;
            background: white;
            box-shadow: 0 0 0 3px rgba(74, 144, 164, 0.1);
        }

        .form-input::placeholder {
            color: #666;
            font-weight: 500;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 18px;
            font-size: 18px;
            font-weight: 600;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .submit-btn:hover {
            background: linear-gradient(135deg, #B8956A, #A6845C);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(196, 164, 132, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Footer Links */
        .footer-links {
            margin-top: 50px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            font-size: 16px;
        }

        .footer-links a {
            color: #666;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: #333;
        }

        .separator {
            color: #999;
            font-size: 20px;
        }

        /* Additional Options */
        .additional-options {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
        }

        .remember-me input[type="checkbox"] {
            transform: scale(1.2);
            accent-color: #4A90A4;
        }

        .forgot-password {
            color: #4A90A4;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: #357A8C;
            text-decoration: underline;
        }

        /* Demo Account Info */
        .demo-info {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }

        .demo-info h4 {
            color: #495057;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .demo-info p {
            color: #6c757d;
            font-size: 14px;
            margin: 5px 0;
        }

        .demo-fill-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.3s ease;
        }

        .demo-fill-btn:hover {
            background: #5a6268;
        }

        /* Loading State */
        .loading {
            display: none;
            text-align: center;
            margin-top: 20px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #C4A484;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-logo {
                top: 20px;
                left: 20px;
                gap: 10px;
            }

            .logo-circle {
                width: 50px;
                height: 50px;
            }

            .graduation-cap {
                font-size: 20px;
            }

            .location-pin {
                font-size: 12px;
                width: 20px;
                height: 20px;
            }

            .logo-text {
                font-size: 28px;
            }

            .page-title {
                font-size: 20px;
                margin-bottom: 40px;
            }

            .form-input {
                padding: 18px 22px;
                font-size: 16px;
            }

            .submit-btn {
                padding: 16px;
                font-size: 16px;
            }

            .footer-links {
                flex-direction: column;
                gap: 20px;
            }

            .separator {
                display: none;
            }

            .additional-options {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .top-logo {
                position: static;
                justify-content: center;
                margin-bottom: 30px;
                margin-top: 20px;
            }

            .login-container {
                padding: 20px;
            }

            .login-content {
                max-width: 100%;
            }

            .page-title {
                font-size: 18px;
                margin-bottom: 30px;
            }

            .form-input {
                padding: 16px 20px;
                font-size: 16px;
            }

            .submit-btn {
                padding: 14px;
                font-size: 16px;
            }

            .footer-links {
                margin-top: 40px;
                font-size: 14px;
            }
        }

        /* Success animation */
        .success-animation {
            animation: successPulse 0.6s ease-out;
        }

        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Top Left Logo -->
    <div class="top-logo">
        <img src="logoo.png" width="60px" alt="EduHive Logo">
        <div class="logo-text">EduHive</div>
    </div>

    <!-- Main Login Container -->
    <div class="login-container">
        <div class="login-content">
            <h1 class="page-title">LOG IN TO YOUR ACCOUNT</h1>
            
            <!-- Show Message if exists -->
            <?php if ($message): ?>
                <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endif; ?>

            <!-- Demo Account Info -->
            <div class="demo-info">
                <h4>Demo Account Available</h4>
                <p><strong>Email:</strong> demo@eduhive.com</p>
                <p><strong>Password:</strong> demo123</p>
                <button type="button" class="demo-fill-btn" onclick="fillDemoCredentials()">
                    Use Demo Account
                </button>
            </div>
            
            <!-- Login Form -->
            <form class="login-form" method="POST" id="loginForm">
                <div class="form-group">
                    <input type="email" 
                           name="email" 
                           id="email"
                           class="form-input" 
                           placeholder="Email Address" 
                           required 
                           autocomplete="email" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <input type="password" 
                           name="password" 
                           id="password"
                           class="form-input" 
                           placeholder="Password" 
                           required 
                           autocomplete="current-password">
                </div>

                <div class="additional-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember_me">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot-password" onclick="showForgotPassword(); return false;">
                        Forgot Password?
                    </a>
                </div>
                
                <button type="submit" class="submit-btn" id="submitBtn">
                    Sign In
                </button>

                <div class="loading" id="loading">
                    <div class="spinner"></div>
                    <p>Signing you in...</p>
                </div>
            </form>
            
            <!-- Footer Links -->
            <div class="footer-links">
                <a href="#" onclick="showRecoveryInfo(); return false;">Can't Log in?</a>
                <span class="separator">•</span>
                <a href="register.php">Create an account</a>
            </div>
        </div>
    </div>

    <script>
        // Enhanced form functionality
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const loading = document.getElementById('loading');
            
            // Auto-focus email field if empty, otherwise focus password
            if (emailInput.value === '') {
                emailInput.focus();
            } else {
                passwordInput.focus();
            }
            
            // Handle Enter key navigation
            emailInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    passwordInput.focus();
                }
            });

            // Form submission with loading state
            form.addEventListener('submit', function(e) {
                const email = emailInput.value.trim();
                const password = passwordInput.value;

                // Basic client-side validation
                if (!email || !password) {
                    e.preventDefault();
                    return false;
                }

                // Show loading animation
                submitBtn.style.display = 'none';
                loading.style.display = 'block';
                
                // Re-enable after 10 seconds (fallback)
                setTimeout(() => {
                    submitBtn.style.display = 'block';
                    loading.style.display = 'none';
                }, 10000);
            });

            // Real-time email validation
            emailInput.addEventListener('input', function() {
                const email = this.value.trim();
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                
                if (email.length > 0) {
                    if (emailRegex.test(email)) {
                        this.style.borderColor = '#28a745';
                    } else {
                        this.style.borderColor = '#dc3545';
                    }
                } else {
                    this.style.borderColor = '#333';
                }
            });

            // Password strength indicator (subtle)
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                
                if (password.length > 0) {
                    if (password.length >= 6) {
                        this.style.borderColor = '#28a745';
                    } else {
                        this.style.borderColor = '#ffc107';
                    }
                } else {
                    this.style.borderColor = '#333';
                }
            });
            
            // Auto-hide success messages after 3 seconds
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.classList.add('success-animation');
                setTimeout(function() {
                    successMessage.style.opacity = '0';
                    setTimeout(function() {
                        successMessage.style.display = 'none';
                    }, 300);
                }, 3000);
            }

            // Caps Lock detection
            document.addEventListener('keydown', function(e) {
                if (e.getModifierState && e.getModifierState('CapsLock')) {
                    if (document.activeElement === passwordInput) {
                        // Could show a subtle caps lock warning
                        console.log('Caps Lock is ON');
                    }
                }
            });
        });

        // Demo credentials function
        function fillDemoCredentials() {
            document.getElementById('email').value = 'demo@eduhive.com';
            document.getElementById('password').value = 'demo123';
            
            // Trigger validation styles
            document.getElementById('email').dispatchEvent(new Event('input'));
            document.getElementById('password').dispatchEvent(new Event('input'));
            
            // Focus submit button
            document.getElementById('submitBtn').focus();
        }

        // Forgot password helper
        function showForgotPassword() {
            const email = document.getElementById('email').value;
            let message = 'Password Reset Information:\n\n';
            
            if (email) {
                message += `If an account exists for ${email}, you will receive a password reset email shortly.\n\n`;
            } else {
                message += 'Please enter your email address first, then click "Forgot Password?" again.\n\n';
            }
            
            message += 'For demo purposes: Use demo@eduhive.com / demo123\n';
            message += 'Or contact support for assistance.';
            
            alert(message);
            
            if (!email) {
                document.getElementById('email').focus();
            }
        }

        // Recovery info helper
        function showRecoveryInfo() {
            alert('Account Recovery Options:\n\n' +
                  '1. Use "Forgot Password?" link\n' +
                  '2. Try the demo account: demo@eduhive.com / demo123\n' +
                  '3. Create a new account if needed\n' +
                  '4. Contact support for further assistance\n\n' +
                  'Note: This is a demo system');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter to submit
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
            
            // Escape to clear form
            if (e.key === 'Escape') {
                document.getElementById('loginForm').reset();
                document.getElementById('email').focus();
            }
        });

        // Progressive enhancement for better UX
        window.addEventListener('online', function() {
            console.log('Connection restored');
        });

        window.addEventListener('offline', function() {
            console.log('Connection lost');
        });
    </script>
</body>
</html>