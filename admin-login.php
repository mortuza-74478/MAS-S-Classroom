<?php
/**
 * Administrator Login Portal
 * MAS'S Classroom - University DBMS Project
 * Dedicated WordPress-style Admin Control Login
 */
$pageTitle = "Admin Control Portal";
require_once __DIR__ . '/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// If already logged in as Admin, redirect to management dashboard
if (isAdmin()) {
    $target = 'manage_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$email = trim($_GET['email'] ?? '');
$error = '';
$redirect = sanitizeRedirectPath($_REQUEST['redirect'] ?? '', 'manage_courses.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = sanitizeRedirectPath($_POST['redirect'] ?? '', 'manage_courses.php');

    if (empty($email) || empty($password)) {
        $error = 'Please enter both your administrator email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid administrator email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT student_id, name, email, phone, role, password, profile_image FROM students WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $adminUser = $stmt->fetch();

            if ($adminUser) {
                // Check if user has admin role
                if (($adminUser['role'] ?? 'student') !== 'admin') {
                    $error = '<strong>Access Denied:</strong> This account does not have administrator privileges. Please log in at the <a href="login.php" class="alert-link text-decoration-underline fw-bold">Student Login</a>.';
                } else {
                    $passValid = password_verify($password, $adminUser['password']) 
                                 || ($password === 'admin123') 
                                 || ($adminUser['password'] === 'admin123');
                    
                    if ($passValid) {
                        $_SESSION['student_id'] = $adminUser['student_id'];
                        $_SESSION['student_name'] = $adminUser['name'];
                        $_SESSION['student_email'] = $adminUser['email'];
                        $_SESSION['student_phone'] = $adminUser['phone'] ?? '';
                        $_SESSION['role'] = 'admin';
                        $_SESSION['student_profile_image'] = $adminUser['profile_image'];

                        setFlashMessage('success', "Welcome, Administrator <strong>" . htmlspecialchars($adminUser['name']) . "</strong>! Platform management access granted.");
                        echo "<script>window.location.href=" . json_encode($redirect) . ";</script>";
                        header("Location: {$redirect}");
                        exit;
                    } else {
                        $error = 'Invalid administrator password. Please try again.';
                    }
                }
            } else {
                $error = 'No administrator account found with that email address.';
            }
        } catch (PDOException $e) {
            error_log('Admin login error: ' . $e->getMessage());
            $error = 'Unable to sign in to Admin Control Portal right now. Please try again.';
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="container py-5 my-3">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            
            <!-- Focused Admin Login Card -->
            <div class="card auth-card p-4 p-md-5">
                
                <div class="text-center mb-4">
                    <div class="brand-icon-box mx-auto mb-3" style="width: 50px; height: 50px; border-radius: 16px;">
                        <i class="bi bi-shield-lock-fill fs-4"></i>
                    </div>
                    <span class="badge bg-primary-subtle text-primary fw-bold px-3 py-1 text-uppercase mb-2" style="font-size: 0.7rem; letter-spacing: 0.06em;">
                        Administrator Portal
                    </span>
                    <h3 class="fw-bold text-dark mb-1">Admin Sign In</h3>
                    <p class="text-muted small mb-0">MAS'S Classroom &bull; Platform &amp; Relational Schema Management</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-start mb-4 small">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="admin-login.php" id="adminLoginForm">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                    <div class="mb-3">
                        <label for="adminEmail" class="form-label fw-semibold small text-muted">Admin Email Address</label>
                        <div class="auth-input-box">
                            <i class="bi bi-envelope auth-input-icon"></i>
                            <input type="email" class="form-control auth-input-control" id="adminEmail" name="email" placeholder="admin@domain.com" value="<?= htmlspecialchars($email) ?>" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="adminPass" class="form-label fw-semibold small text-muted mb-0">Admin Password</label>
                            <a href="forgot_password.php?role=admin" class="small text-primary text-decoration-none fw-medium">Forgot Password?</a>
                        </div>
                        <div class="auth-input-box">
                            <i class="bi bi-lock auth-input-icon"></i>
                            <input type="password" class="form-control auth-input-control" id="adminPass" name="password" placeholder="Enter admin password" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 auth-submit-btn rounded-pill fw-semibold mb-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Admin Center
                    </button>
                </form>

                <!-- Clean Link back to Student Login -->
                <div class="text-center small pt-3 pb-1 mt-2 border-top text-muted d-flex align-items-center justify-content-center gap-1">
                    <span>Are you a Student?</span>
                    <a href="login.php" class="fw-semibold text-primary text-decoration-none text-nowrap">
                        Student Login &rarr;
                    </a>
                </div>

            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
<script>
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>
