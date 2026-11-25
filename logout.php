<?php
/**
 * Student Logout - MAS'S Classroom
 * University DBMS Project
 */
require_once __DIR__ . '/db.php';

// Only destroy session on explicit confirmation (POST or confirmed=1)
$isConfirmed = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout']))
               || (isset($_GET['confirmed']) && $_GET['confirmed'] === '1');

if (!$isConfirmed && isLoggedIn()) {
    $fallbackTarget = isAdmin() ? 'manage_courses.php' : 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($fallbackTarget) . ";</script>";
    header("Location: {$fallbackTarget}");
    exit;
}

// Unset all session variables
$_SESSION = [];

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Start fresh session to set flash message
session_start();
setFlashMessage('info', 'You have been successfully logged out. See you next time!');

$target = 'index.php';
echo "<script>window.location.href=" . json_encode($target) . ";</script>";
header("Location: {$target}");
exit;
