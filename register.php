<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Check if registration is enabled
$registration_enabled = getBasicSetting('registration_enabled', true);
if (!$registration_enabled) {
    setMessage('Registration is currently disabled.', 'error');
    header("Location: login.php");
    exit();
}

// Get any existing message
$message = getMessage();

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = cleanInput($_POST['name'] ?? '');
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Basic validation
    if (empty($name)) {
        setMessage('Please enter your full name.', 'error');
    } elseif (strlen($name) < 2) {
        setMessage('Name must be at least 2 characters long.', 'error');
    } elseif (empty($email)) {
        setMessage('Please enter your email address.', 'error');
    } elseif (!isValidEmail($email)) {
        setMessage('Please enter a valid email address.', 'error');
    } elseif (empty($password)) {
        setMessage('Please enter a password.', 'error');
    } elseif (strlen($password) < 6) {
        setMessage('Password must be at least 6 characters long.', 'error');
    } elseif (empty($confirm_password)) {
        setMessage('Please confirm your password.', 'error');
    } elseif ($password !== $confirm_password) {
        setMessage('Passwords do not match.', 'error');
    } else {
        // Try to create account
        $database = new Database();
        $db = $database->getConnection();
        
        try {
            // Check if email already exists
            $check_query = "SELECT id FROM users WHERE email = :email";
            $stmt = $db->prepare($check_query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                setMessage('An account with this email address already exists.', 'error');
            } else {
                // Create new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $insert_query = "INSERT INTO users (name, email, password, role, status, created_at) 
                                VALUES (:name, :email, :password, 'user', 'active', NOW())";
                $stmt = $db->prepare($insert_query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':password', $hashed_password);
                
                if ($stmt->execute()) {
                    // Registration successful
                    setMessage('Account created successfully! You can now log in.', 'success');
                    header("Location: login.php");
                    exit();
                } else {
                    setMessage('Failed to create account. Please try again.', 'error');
                }
            }
            
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            setMessage('System error. Please try again later.', 'error');
        }
    }
    
    // Redirect to avoid form resubmission
    header("Location: register.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduHive - Create Account</title>
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
        .register-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }

        .register-content {
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
        .register-form {
            display: flex;
            flex-direction: column;
            gap: 25px;
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

        /* Password strength indicator */
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 500;
            text-align: left;
            padding-left: 25px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .password-strength.visible {
            opacity: 1;
        }

        .password-strength.weak {
            color: #dc3545;
        }

        .password-strength.medium {
            color: #ffc107;
        }

        .password-strength.strong {
            color: #28a745;
        }

        /* Password match indicator */
        .password-match {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 500;
            text-align: left;
            padding-left: 25px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .password-match.visible {
            opacity: 1;
        }

        .password-match.match {
            color: #28a745;
        }

        .password-match.no-match {
            color: #dc3545;
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

        .footer-links span {
            color: #666;
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

            .register-form {
                gap: 20px;
            }
        }

        @media (max-width: 480px) {
            .top-logo {
                position: static;
                justify-content: center;
                margin-bottom: 30px;
                margin-top: 20px;
            }

            .register-container {
                padding: 20px;
            }

            .register-content {
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

            .password-strength,
            .password-match {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Left Logo -->
    <div class="top-logo">
          <!-- Top Left Logo -->
    <div class="top-logo">
        <img src="logoo.png" width="60px" alt="">
        <div class="logo-text">EduHive</div>
    </div>
    </div>

    <!-- Main Register Container -->
    <div class="register-container">
        <div class="register-content">
            <h1 class="page-title">CREATE YOUR ACCOUNT</h1>
            
            <!-- Show Message if exists -->
            <?php if ($message): ?>
                <div class="message <?php echo htmlspecialchars($message['type']); ?>">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Register Form -->
            <form class="register-form" method="POST">
                <div class="form-group">
                    <input type="text" name="name" class="form-input" placeholder="Full Name" required autocomplete="name" maxlength="100" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <input type="email" name="email" class="form-input" placeholder="Email" required autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <input type="password" name="password" id="password" class="form-input" placeholder="Password" required autocomplete="new-password" minlength="6">
                    <div id="passwordStrength" class="password-strength"></div>
                </div>
                
                <div class="form-group">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Confirm Password" required autocomplete="new-password">
                    <div id="passwordMatch" class="password-match"></div>
                </div>
                
                <button type="submit" class="submit-btn">
                    Create Account
                </button>
            </form>
            
            <!-- Footer Links -->
            <div class="footer-links">
                <span>Already have an account? </span>
                <a href="login.php">Sign In</a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');
            const passwordStrength = document.getElementById('passwordStrength');
            const passwordMatch = document.getElementById('passwordMatch');
            
            // Password strength checker
            passwordField.addEventListener('input', function() {
                const password = this.value;
                const strength = checkPasswordStrength(password);
                
                if (password.length === 0) {
                    passwordStrength.textContent = '';
                    passwordStrength.className = 'password-strength';
                } else {
                    passwordStrength.textContent = strength.text;
                    passwordStrength.className = `password-strength visible ${strength.class}`;
                }
                
                // Check password match if confirm field has value
                if (confirmPasswordField.value) {
                    checkPasswordMatch();
                }
            });
            
            // Password match checker
            confirmPasswordField.addEventListener('input', checkPasswordMatch);
            
            function checkPasswordStrength(password) {
                if (password.length < 6) {
                    return { text: 'Too short (minimum 6 characters)', class: 'weak' };
                }
                
                let score = 0;
                
                // Length check
                if (password.length >= 8) score++;
                
                // Character variety checks
                if (/[a-z]/.test(password)) score++;
                if (/[A-Z]/.test(password)) score++;
                if (/[0-9]/.test(password)) score++;
                if (/[^A-Za-z0-9]/.test(password)) score++;
                
                if (score < 2) {
                    return { text: 'Weak password', class: 'weak' };
                } else if (score < 4) {
                    return { text: 'Medium strength', class: 'medium' };
                } else {
                    return { text: 'Strong password', class: 'strong' };
                }
            }
            
            function checkPasswordMatch() {
                const password = passwordField.value;
                const confirmPassword = confirmPasswordField.value;
                
                if (confirmPassword === '') {
                    passwordMatch.textContent = '';
                    passwordMatch.className = 'password-match';
                } else if (password === confirmPassword) {
                    passwordMatch.textContent = 'Passwords match';
                    passwordMatch.className = 'password-match visible match';
                } else {
                    passwordMatch.textContent = 'Passwords do not match';
                    passwordMatch.className = 'password-match visible no-match';
                }
            }
            
            // Auto-focus name field
            document.querySelector('input[name="name"]').focus();
            
            // Handle Enter key navigation
            const formInputs = document.querySelectorAll('.form-input');
            formInputs.forEach((input, index) => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (index < formInputs.length - 1) {
                            formInputs[index + 1].focus();
                        } else {
                            // Submit form on last field
                            this.form.submit();
                        }
                    }
                });
            });
            
            // Real-time email validation
            document.querySelector('input[name="email"]').addEventListener('blur', function() {
                const email = this.value;
                if (email && !isValidEmail(email)) {
                    this.style.borderColor = '#dc3545';
                } else {
                    this.style.borderColor = '#333';
                }
            });
            
            function isValidEmail(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(email);
            }
            
            // Auto-hide success messages after 4 seconds
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.opacity = '0';
                    setTimeout(function() {
                        successMessage.style.display = 'none';
                    }, 300);
                }, 4000);
            }
        });
    </script>
</body>
</html>