<?php
// logout.php - User Logout System

// Include required files (session will be started in session.php)
require_once 'config/session.php';

// Check if user is logged in
if (isLoggedIn()) {
    // Log the logout action
    $user = getCurrentUser();
    error_log("User logged out: " . $user['email'] . " (ID: " . $user['id'] . ")");
    
    // Logout user
    logoutUser();
}

// Redirect to login page with logout message
header("Location: login.php?logout=1");
exit();
?>