<?php
// register.php - User Registration System
session_start();

// Include required files
require_once 'config/database.php';
require_once 'config/functions.php';
require_once 'config/session.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Check if registration is enabled
if (!getBasicSetting('registration_enabled', true)) {
    $error_message = 'Registration is currently disabled. Please contact the administrator.';
} else {
    // Initialize database
    $database = new Database();
    $db = $database->getConnection();

    // Handle form submission
    $error_message = '';
    $success_message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
            $error_message = 'Security token mismatch. Please try again.';
        } else {
            $result = registerUser($_POST, $db);
            if ($result['success']) {
                $success_message = $result['message'];
                // Optionally redirect to login page after successful registration
                // header("Location: login.php?registered=1");
                // exit();
            } else {
                $error_message = $result['message'];
            }
        }
    }
}

// Registration function
function registerUser($data, $db) {
    try {
        // Validate input data
        $validation_result = validateRegistrationData($data);
        if (!$validation_result['valid']) {
            return ['success' => false, 'message' => $validation_result['message']];
        }
        
        // Clean input data
        $name = cleanInput($data['name']);
        $email = cleanInput($data['email']);
        $password = $data['password'];
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Email address is already registered. Please use a different email or try logging in.'];
        }
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (?, ?, ?, 'user', 'active', CURRENT_TIMESTAMP)");
        $stmt->execute([$name, $email, $password_hash]);
        
        // Get the new user ID
        $user_id = $db->lastInsertId();
        
        // Create default user settings
        $stmt = $db->prepare("INSERT INTO user_settings (user_id, notification_email, notification_browser, reminder_time, theme, timezone) VALUES (?, 1, 1, 24, 'light', 'UTC')");
        $stmt->execute([$user_id]);
        
        // Log the registration
        logActivity("New user registered: $email (ID: $user_id)", 'INFO');
        
        // Optionally send welcome email
        if (function_exists('sendEmail')) {
            $welcome_subject = "Welcome to EduHive!";
            $welcome_message = "
                <h2>Welcome to EduHive, $name!</h2>
                <p>Your account has been successfully created. You can now log in and start managing your academic tasks and schedules.</p>
                <p>Get started by:</p>
                <ul>
                    <li>Adding your class schedule</li>
                    <li>Creating your first task</li>
                    <li>Setting up notifications</li>
                    <li>Customizing your profile</li>
                </ul>
                <p>If you have any questions, feel free to contact our support team.</p>
                <p>Happy studying!</p>
                <p>The EduHive Team</p>
            ";
            sendEmail($email, $welcome_subject, $welcome_message);
        }
        
        return ['success' => true, 'message' => 'Account created successfully! You can now log in with your credentials.'];
        
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred during registration. Please try again later.'];
    }
}

// Validation function
function validateRegistrationData($data) {
    $errors = [];
    
    // Validate name
    if (empty(trim($data['name']))) {
        $errors[] = 'Full name is required';
    } elseif (strlen(trim($data['name'])) < 2) {
        $errors[] = 'Name must be at least 2 characters long';
    } elseif (strlen(trim($data['name'])) > 100) {
        $errors[] = 'Name must be less than 100 characters';
    }
    
    // Validate email
    if (empty(trim($data['email']))) {
        $errors[] = 'Email address is required';
    } elseif (!isValidEmail($data['email'])) {
        $errors[] = 'Please enter a valid email address';
    } elseif (strlen($data['email']) > 100) {
        $errors[] = 'Email address is too long';
    }
    
    // Validate password
    if (empty($data['password'])) {
        $errors[] = 'Password is required';
    } elseif (strlen($data['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    } elseif (strlen($data['password']) > 255) {
        $errors[] = 'Password is too long';
    }
    
    // Validate password confirmation
    if (empty($data['confirm_password'])) {
        $errors[] = 'Password confirmation is required';
    } elseif ($data['password'] !== $data['confirm_password']) {
        $errors[] = 'Passwords do not match';
    }
    
    // Check password strength (optional)
    if (!empty($data['password']) && strlen($data['password']) >= 6) {
        $password = $data['password'];
        $strength_errors = [];
        
        if (!preg_match('/[a-z]/', $password)) {
            $strength_errors[] = 'lowercase letter';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $strength_errors[] = 'uppercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $strength_errors[] = 'number';
        }
        
        // Optional: Require special characters
        // if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        //     $strength_errors[] = 'special character';
        // }
        
        if (count($strength_errors) > 2) {
            $errors[] = 'Password should contain at least a ' . implode(', ', array_slice($strength_errors, 0, -1)) . ' and ' . end($strength_errors);
        }
    }
    
    // Validate terms acceptance (if you have terms)
    if (!isset($data['terms']) || !$data['terms']) {
        $errors[] = 'You must accept the terms and conditions';
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
    <title>Register - EduHive</title>
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

        .register-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
            position: relative;
            overflow: hidden;
        }

        .register-container::before {
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

        .register-title {
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
        .register-form {
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

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 5px;
            height: 4px;
            border-radius: 2px;
            background: #e0e0e0;
            overflow: hidden;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .password-strength.show {
            opacity: 1;
        }

        .strength-bar {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak { background: #dc3545; width: 25%; }
        .strength-fair { background: #ffc107; width: 50%; }
        .strength-good { background: #28a745; width: 75%; }
        .strength-strong { background: #007bff; width: 100%; }

        .strength-text {
            font-size: 12px;
            margin-top: 3px;
            color: #666;
        }

        /* Checkbox Styles */
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            margin-bottom: 25px;
            gap: 10px;
        }

        .checkbox-input {
            margin-top: 3px;
            transform: scale(1.2);
            accent-color: #C4A484;
        }

        .checkbox-label {
            color: #333;
            font-size: 14px;
            line-height: 1.4;
            cursor: pointer;
        }

        .checkbox-label a {
            color: #C4A484;
            text-decoration: none;
            font-weight: 600;
        }

        .checkbox-label a:hover {
            text-decoration: underline;
        }

        /* Button Styles */
        .register-btn {
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

        .register-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(196, 164, 132, 0.3);
        }

        .register-btn:disabled {
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

        /* Responsive Design */
        @media (max-width: 480px) {
            .register-container {
                padding: 30px 20px;
                margin: 10px;
            }

            .site-name {
                font-size: 24px;
            }

            .register-title {
                font-size: 20px;
            }

            .form-input,
            .register-btn {
                padding: 15px 20px;
                font-size: 14px;
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

        .field-error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }

        .field-error.show {
            display: block;
        }

        /* Animation for form appearance */
        .register-container {
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
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo-section">
            <div class="logo">🎓</div>
            <div class="site-name">EduHive</div>
        </div>

        <h1 class="register-title">CREATE ACCOUNT</h1>
        <p class="subtitle">Join EduHive to start managing your academic life</p>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
                <div class="form-links" style="margin-top: 15px;">
                    <a href="login.php">Click here to login</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($success_message) && getBasicSetting('registration_enabled', true)): ?>
        <form class="register-form" method="POST" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" 
                       name="name" 
                       id="name"
                       class="form-input" 
                       placeholder="Enter your full name"
                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                       required 
                       maxlength="100">
                <div class="field-error" id="name-error"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address *</label>
                <input type="email" 
                       name="email" 
                       id="email"
                       class="form-input" 
                       placeholder="Enter your email address"
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       required 
                       maxlength="100">
                <div class="field-error" id="email-error"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Password *</label>
                <input type="password" 
                       name="password" 
                       id="password"
                       class="form-input" 
                       placeholder="Create a strong password"
                       required 
                       minlength="6"
                       maxlength="255">
                <div class="password-strength" id="password-strength">
                    <div class="strength-bar" id="strength-bar"></div>
                </div>
                <div class="strength-text" id="strength-text"></div>
                <div class="field-error" id="password-error"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Confirm Password *</label>
                <input type="password" 
                       name="confirm_password" 
                       id="confirm_password"
                       class="form-input" 
                       placeholder="Confirm your password"
                       required 
                       minlength="6"
                       maxlength="255">
                <div class="field-error" id="confirm-password-error"></div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" 
                       name="terms" 
                       id="terms" 
                       class="checkbox-input" 
                       required>
                <label for="terms" class="checkbox-label">
                    I agree to the <a href="#" onclick="showTerms(); return false;">Terms of Service</a> 
                    and <a href="#" onclick="showPrivacy(); return false;">Privacy Policy</a>
                </label>
            </div>

            <button type="submit" class="register-btn" id="registerBtn">
                Create Account
            </button>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Creating your account...</p>
            </div>
        </form>

        <div class="form-links">
            <div class="divider">Already have an account?</div>
            <a href="login.php">Sign in here</a>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Form validation and enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registerForm');
            const nameInput = document.getElementById('name');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const termsCheckbox = document.getElementById('terms');
            const registerBtn = document.getElementById('registerBtn');

            // Real-time validation
            nameInput.addEventListener('input', validateName);
            emailInput.addEventListener('input', validateEmail);
            passwordInput.addEventListener('input', validatePassword);
            confirmPasswordInput.addEventListener('input', validateConfirmPassword);
            termsCheckbox.addEventListener('change', validateTerms);

            // Form submission
            form.addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading animation
                registerBtn.style.display = 'none';
                document.getElementById('loading').style.display = 'block';
            });

            function validateName() {
                const name = nameInput.value.trim();
                const errorElement = document.getElementById('name-error');
                
                if (name.length === 0) {
                    showFieldError(nameInput, errorElement, 'Name is required');
                    return false;
                } else if (name.length < 2) {
                    showFieldError(nameInput, errorElement, 'Name must be at least 2 characters');
                    return false;
                } else {
                    showFieldSuccess(nameInput, errorElement);
                    return true;
                }
            }

            function validateEmail() {
                const email = emailInput.value.trim();
                const errorElement = document.getElementById('email-error');
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                
                if (email.length === 0) {
                    showFieldError(emailInput, errorElement, 'Email is required');
                    return false;
                } else if (!emailRegex.test(email)) {
                    showFieldError(emailInput, errorElement, 'Please enter a valid email address');
                    return false;
                } else {
                    showFieldSuccess(emailInput, errorElement);
                    return true;
                }
            }

            function validatePassword() {
                const password = passwordInput.value;
                const errorElement = document.getElementById('password-error');
                const strengthIndicator = document.getElementById('password-strength');
                const strengthBar = document.getElementById('strength-bar');
                const strengthText = document.getElementById('strength-text');
                
                if (password.length === 0) {
                    strengthIndicator.classList.remove('show');
                    showFieldError(passwordInput, errorElement, 'Password is required');
                    return false;
                } else if (password.length < 6) {
                    strengthIndicator.classList.remove('show');
                    showFieldError(passwordInput, errorElement, 'Password must be at least 6 characters');
                    return false;
                } else {
                    // Calculate password strength
                    let strength = 0;
                    if (password.length >= 8) strength++;
                    if (/[a-z]/.test(password)) strength++;
                    if (/[A-Z]/.test(password)) strength++;
                    if (/[0-9]/.test(password)) strength++;
                    if (/[^a-zA-Z0-9]/.test(password)) strength++;
                    
                    strengthIndicator.classList.add('show');
                    
                    // Update strength indicator
                    const strengthClasses = ['strength-weak', 'strength-fair', 'strength-good', 'strength-strong'];
                    const strengthTexts = ['Weak', 'Fair', 'Good', 'Strong'];
                    
                    strengthBar.className = 'strength-bar';
                    if (strength >= 1) strengthBar.classList.add(strengthClasses[Math.min(strength - 1, 3)]);
                    strengthText.textContent = `Password strength: ${strengthTexts[Math.min(strength - 1, 3)]}`;
                    
                    showFieldSuccess(passwordInput, errorElement);
                    return true;
                }
            }

            function validateConfirmPassword() {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                const errorElement = document.getElementById('confirm-password-error');
                
                if (confirmPassword.length === 0) {
                    showFieldError(confirmPasswordInput, errorElement, 'Please confirm your password');
                    return false;
                } else if (password !== confirmPassword) {
                    showFieldError(confirmPasswordInput, errorElement, 'Passwords do not match');
                    return false;
                } else {
                    showFieldSuccess(confirmPasswordInput, errorElement);
                    return true;
                }
            }

            function validateTerms() {
                const errorElement = document.getElementById('terms-error');
                if (!termsCheckbox.checked) {
                    // You might want to add visual feedback for terms checkbox
                    return false;
                } else {
                    return true;
                }
            }

            function validateForm() {
                const nameValid = validateName();
                const emailValid = validateEmail();
                const passwordValid = validatePassword();
                const confirmPasswordValid = validateConfirmPassword();
                const termsValid = validateTerms();
                
                return nameValid && emailValid && passwordValid && confirmPasswordValid && termsValid;
            }

            function showFieldError(input, errorElement, message) {
                input.classList.remove('success');
                input.classList.add('error');
                errorElement.textContent = message;
                errorElement.classList.add('show');
            }

            function showFieldSuccess(input, errorElement) {
                input.classList.remove('error');
                input.classList.add('success');
                errorElement.classList.remove('show');
            }
        });

        // Terms and Privacy modal functions (basic alerts for now)
        function showTerms() {
            alert('Terms of Service:\n\n1. Use EduHive responsibly for academic purposes\n2. Keep your account secure\n3. Do not share inappropriate content\n4. Respect other users\n5. Report any issues to administrators\n\n(This is a simplified version - implement full terms as needed)');
        }

        function showPrivacy() {
            alert('Privacy Policy:\n\n1. We collect only necessary information for the service\n2. Your data is securely stored and encrypted\n3. We do not share your personal information with third parties\n4. You can request data deletion at any time\n5. We use cookies for authentication and preferences\n\n(This is a simplified version - implement full privacy policy as needed)');
        }

        // Auto-hide alerts after 7 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (!alert.querySelector('a')) { // Don't hide success message with login link
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }
            });
        }, 7000);
    </script>
</body>
</html>