<?php
// login.php - User Login System
session_start();

// Include required files
require_once 'config/database.php';
require_once 'config/functions.php';
require_once 'config/session.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $redirect_url = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'dashboard.php';
    unset($_SESSION['redirect_after_login']);
    header("Location: " . $redirect_url);
    exit();
}

// Initialize database
$database = new Database();
$db = $database->getConnection();

// Handle form submission
$error_message = '';
$success_message = '';

// Check for registration success message
if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $success_message = 'Registration successful! Please log in with your credentials.';
}

// Check for logout message
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $success_message = 'You have been successfully logged out.';
}

// Check for session timeout message
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error_message = 'Your session has expired. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $result = authenticateUser($_POST, $db);
        if ($result['success']) {
            // Successful login - redirect to dashboard or intended page
            $redirect_url = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'dashboard.php';
            unset($_SESSION['redirect_after_login']);
            
            // Set success message in session if staying on same page
            if ($redirect_url === $_SERVER['PHP_SELF']) {
                setMessage('Welcome back! You have been successfully logged in.', 'success');
            }
            
            header("Location: " . $redirect_url);
            exit();
        } else {
            $error_message = $result['message'];
        }
    }
}

// Authentication function
function authenticateUser($data, $db) {
    try {
        // Validate input data
        $validation_result = validateLoginData($data);
        if (!$validation_result['valid']) {
            return ['success' => false, 'message' => $validation_result['message']];
        }
        
        // Clean input data
        $email = cleanInput($data['email']);
        $password = $data['password'];
        $remember_me = isset($data['remember_me']);
        
        // Check if user exists and is active
        $stmt = $db->prepare("SELECT id, name, email, password, status, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Add small delay to prevent timing attacks
            usleep(500000); // 0.5 seconds
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }
        
        // Check if account is active
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Your account has been deactivated. Please contact the administrator.'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Add small delay to prevent timing attacks
            usleep(500000); // 0.5 seconds
            
            // Log failed login attempt
            logActivity("Failed login attempt for email: $email", 'WARNING');
            
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }
        
        // Successful authentication - create session
        $login_success = loginUser($user);
        
        if (!$login_success) {
            return ['success' => false, 'message' => 'An error occurred during login. Please try again.'];
        }
        
        // Update last login timestamp
        $stmt = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Handle "Remember Me" functionality
        if ($remember_me) {
            // Set a secure remember-me cookie (optional feature)
            $token = generateRandomString(32);
            $expires = time() + (30 * 24 * 60 * 60); // 30 days
            
            // Store token in database (you'd need a remember_tokens table)
            // For simplicity, we'll just extend the session lifetime
            ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60); // 30 days
        }
        
        // Log successful login
        logActivity("Successful login for user: $email (ID: {$user['id']})", 'INFO');
        
        return ['success' => true, 'message' => 'Login successful!'];
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred during login. Please try again later.'];
    }
}

// Validation function
function validateLoginData($data) {
    $errors = [];
    
    // Validate email
    if (empty(trim($data['email']))) {
        $errors[] = 'Email address is required';
    } elseif (!isValidEmail($data['email'])) {
        $errors[] = 'Please enter a valid email address';
    }
    
    // Validate password
    if (empty($data['password'])) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        return ['valid' => true];
    } else {
        return ['valid' => false, 'message' => implode('<br>', $errors)];
    }
}

// Generate CSRF token for the form
$csrf_token = generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EduHive</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .site-name {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }

        .login-title {
            color: #333;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 2px;
            text-align: center;
            margin-bottom: 30px;
        }

        .subtitle {
            color: #666;
            font-size: 14px;
            text-align: center;
            margin-bottom: 20px;
        }

        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            border: none;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }

        /* Form Styles */
        .login-form {
            width: 100%;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 18px 25px;
            border: 3px solid #333;
            border-radius: 50px;
            font-size: 16px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: white;
            transition: all 0.3s ease;
            outline: none;
        }

        .form-input:focus {
            border-color: #4A90A4;
            box-shadow: 0 0 0 3px rgba(74, 144, 164, 0.1);
            transform: translateY(-1px);
        }

        .form-input::placeholder {
            color: #999;
        }

        /* Password Input with Show/Hide */
        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 14px;
            padding: 5px;
            border-radius: 3px;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #333;
        }

        /* Checkbox and Remember Me */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-input {
            transform: scale(1.2);
            accent-color: #C4A484;
        }

        .checkbox-label {
            color: #333;
            font-size: 14px;
            cursor: pointer;
        }

        .forgot-password {
            color: #C4A484;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: #B8956A;
            text-decoration: underline;
        }

        /* Button Styles */
        .login-btn {
            width: 100%;
            padding: 18px 25px;
            background: linear-gradient(135deg, #C4A484, #B8956A);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            letter-spacing: 1px;
        }

        .login-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(196, 164, 132, 0.3);
        }

        .login-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Links */
        .form-links {
            text-align: center;
            margin-top: 20px;
        }

        .form-links a {
            color: #C4A484;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .form-links a:hover {
            color: #B8956A;
            text-decoration: underline;
        }

        .divider {
            text-align: center;
            margin: 15px 0;
            color: #666;
            font-size: 14px;
        }

        /* Loading Animation */
        .loading {
            display: none;
            text-align: center;
            margin-top: 10px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #C4A484;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Demo Credentials Box */
        .demo-credentials {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }

        .demo-credentials h4 {
            color: #495057;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .demo-credentials p {
            color: #6c757d;
            font-size: 12px;
            margin: 5px 0;
        }

        .demo-fill-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 11px;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.3s ease;
        }

        .demo-fill-btn:hover {
            background: #5a6268;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
                margin: 10px;
            }

            .site-name {
                font-size: 24px;
            }

            .login-title {
                font-size: 20px;
            }

            .form-input,
            .login-btn {
                padding: 15px 20px;
                font-size: 14px;
            }

            .form-options {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
        }

        /* Form validation styles */
        .form-input.error {
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-input.success {
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        /* Animation for form appearance */
        .login-container {
            animation: slideUp 0.6s ease-out;
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

        /* Caps Lock Warning */
        .caps-warning {
            color: #f39c12;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }

        .caps-warning.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <div class="logo">🎓</div>
            <div class="site-name">EduHive</div>
        </div>

        <h1 class="login-title">WELCOME BACK</h1>
        <p class="subtitle">Sign in to your account to continue</p>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- Demo Credentials (remove in production) -->
        <div class="demo-credentials">
            <h4>Demo Account</h4>
            <p>Email: demo@eduhive.com</p>
            <p>Password: demo123</p>
            <button type="button" class="demo-fill-btn" onclick="fillDemoCredentials()">
                Use Demo Account
            </button>
        </div>

        <form class="login-form" method="POST" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" 
                       name="email" 
                       id="email"
                       class="form-input" 
                       placeholder="Enter your email address"
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       required 
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="password-group">
                    <input type="password" 
                           name="password" 
                           id="password"
                           class="form-input" 
                           placeholder="Enter your password"
                           required 
                           autocomplete="current-password">
                    <button type="button" class="password-toggle" onclick="togglePassword()">
                        👁️
                    </button>
                </div>
                <div class="caps-warning" id="caps-warning">
                    ⚠️ Caps Lock is ON
                </div>
            </div>

            <div class="form-options">
                <div class="checkbox-group">
                    <input type="checkbox" 
                           name="remember_me" 
                           id="remember_me" 
                           class="checkbox-input">
                    <label for="remember_me" class="checkbox-label">
                        Remember me
                    </label>
                </div>
                <a href="#" class="forgot-password" onclick="showForgotPassword(); return false;">
                    Forgot Password?
                </a>
            </div>

            <button type="submit" class="login-btn" id="loginBtn">
                Sign In
            </button>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Signing you in...</p>
            </div>
        </form>

        <div class="form-links">
            <div class="divider">Don't have an account?</div>
            <a href="register.php">Create account here</a>
        </div>
    </div>

    <script>
        // Form enhancement and validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const loginBtn = document.getElementById('loginBtn');

            // Form submission with loading animation
            form.addEventListener('submit', function(e) {
                const email = emailInput.value.trim();
                const password = passwordInput.value;

                // Basic validation
                if (!email || !password) {
                    e.preventDefault();
                    return false;
                }

                // Show loading animation
                loginBtn.style.display = 'none';
                document.getElementById('loading').style.display = 'block';
            });

            // Caps Lock detection
            passwordInput.addEventListener('keyup', function(e) {
                const capsLockOn = e.getModifierState && e.getModifierState('CapsLock');
                const warning = document.getElementById('caps-warning');
                
                if (capsLockOn) {
                    warning.classList.add('show');
                } else {
                    warning.classList.remove('show');
                }
            });

            // Real-time validation feedback
            emailInput.addEventListener('input', function() {
                validateEmail(this);
            });

            passwordInput.addEventListener('input', function() {
                validatePassword(this);
            });

            function validateEmail(input) {
                const email = input.value.trim();
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                
                if (email.length > 0) {
                    if (emailRegex.test(email)) {
                        input.classList.remove('error');
                        input.classList.add('success');
                    } else {
                        input.classList.remove('success');
                        input.classList.add('error');
                    }
                } else {
                    input.classList.remove('success', 'error');
                }
            }

            function validatePassword(input) {
                const password = input.value;
                
                if (password.length > 0) {
                    if (password.length >= 6) {
                        input.classList.remove('error');
                        input.classList.add('success');
                    } else {
                        input.classList.remove('success');
                        input.classList.add('error');
                    }
                } else {
                    input.classList.remove('success', 'error');
                }
            }

            // Auto-focus on first empty field
            if (!emailInput.value) {
                emailInput.focus();
            } else if (!passwordInput.value) {
                passwordInput.focus();
            }
        });

        // Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.textContent = '🙈';
            } else {
                passwordInput.type = 'password';
                toggleBtn.textContent = '👁️';
            }
        }

        // Demo credentials filler (remove in production)
        function fillDemoCredentials() {
            document.getElementById('email').value = 'demo@eduhive.com';
            document.getElementById('password').value = 'demo123';
            
            // Trigger validation
            document.getElementById('email').dispatchEvent(new Event('input'));
            document.getElementById('password').dispatchEvent(new Event('input'));
        }

        // Forgot password functionality
        function showForgotPassword() {
            const email = document.getElementById('email').value;
            let message = 'Password Reset:\n\n';
            
            if (email) {
                message += `A password reset link will be sent to: ${email}\n\n`;
            } else {
                message += 'Please enter your email address first, then click "Forgot Password?" again.\n\n';
            }
            
            message += '(This is a demo - implement actual password reset functionality as needed)';
            alert(message);
            
            if (!email) {
                document.getElementById('email').focus();
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Enter key to submit form when focused on any input
            if (e.key === 'Enter' && (e.target.matches('input'))) {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        });
    </script>
</body>
</html>