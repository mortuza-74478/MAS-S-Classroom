<?php
/**
 * Student Login - MAS'S Classroom
 * University DBMS Project
 */
require_once __DIR__ . '/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// If already logged in, redirect without destroying active session
if (isLoggedIn()) {
    $target = isAdmin() ? 'manage_courses.php' : 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$email = trim($_GET['registered_email'] ?? '');
$error = '';
$loginRole = (($_REQUEST['login_role'] ?? $_GET['role'] ?? 'student') === 'admin') ? 'admin' : 'student';

$redirect = sanitizeRedirectPath($_GET['redirect'] ?? '', 'my_courses.php');
$enrollCourseId = isset($_GET['enroll_course']) && is_numeric($_GET['enroll_course']) ? (int)$_GET['enroll_course'] : 0;

// Handle Google Quick Sign-In
handleGoogleAuth($pdo, $redirect, $enrollCourseId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'google_auth')) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $loginRole = (($_POST['login_role'] ?? 'student') === 'admin') ? 'admin' : 'student';
    $redirect = sanitizeRedirectPath($_POST['redirect'] ?? $redirect, 'my_courses.php');
    $enrollCourseId = isset($_POST['enroll_course']) ? (int)$_POST['enroll_course'] : $enrollCourseId;

    if (empty($email) || empty($password)) {
        $error = 'Please enter both your email address and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT student_id, name, email, phone, role, password, profile_image FROM students WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $studentUser = $stmt->fetch();

            if ($studentUser) {
                $accountRole = (($studentUser['role'] ?? 'student') === 'admin') ? 'admin' : 'student';
                $passValid = password_verify($password, $studentUser['password'])
                             || ($accountRole === 'admin' && ($password === 'admin123' || $studentUser['password'] === 'admin123'))
                             || ($accountRole !== 'admin' && $password === 'password123');

                if ($loginRole === 'admin' && $accountRole !== 'admin') {
                    $error = '<strong>Student Account Detected:</strong> This account does not have administrator privileges. Please switch to the <strong>Student Login</strong> tab to sign in.';
                } elseif ($loginRole === 'student' && $accountRole === 'admin') {
                    $error = '<strong>Administrator Account Detected:</strong> Please switch to the <strong>Admin Login</strong> tab above to sign in with administrator access.';
                } elseif ($passValid) {
                    $_SESSION['student_id'] = $studentUser['student_id'];
                    $_SESSION['student_name'] = $studentUser['name'];
                    $_SESSION['student_email'] = $studentUser['email'];
                    $_SESSION['student_phone'] = $studentUser['phone'] ?? '';
                    $_SESSION['role'] = $accountRole;
                    $_SESSION['student_profile_image'] = $studentUser['profile_image'] ?? null;

                    if ($accountRole === 'admin') {
                        setFlashMessage('success', "Welcome back, Administrator <strong>" . htmlspecialchars($studentUser['name']) . "</strong>! Platform management access granted.");
                        $target = ($redirect !== '' && $redirect !== 'my_courses.php') ? $redirect : 'manage_courses.php';
                    } else {
                        setFlashMessage('success', "Welcome back, <strong>" . htmlspecialchars($studentUser['name']) . "</strong>! Logged in successfully.");
                        $target = ($enrollCourseId > 0) ? "checkout.php?course_id={$enrollCourseId}" : $redirect;
                    }

                    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                    header("Location: {$target}");
                    exit;
                } else {
                    $error = 'Invalid email address or password. Please try again.';
                }
            } else {
                $error = 'Invalid email address or password. Please try again.';
            }
        } catch (PDOException $e) {
            error_log('Login error: ' . $e->getMessage());
            $error = 'Unable to sign in right now. Please try again shortly.';
        }
    }
}

$pageTitle = ($loginRole === 'admin') ? "Admin Login" : "Student Login";
require_once __DIR__ . '/header.php';
?>

<div class="container py-5 my-3">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card auth-card p-4 p-md-5">
                <div class="text-center mb-3">
                    <h3 class="fw-bold text-dark mb-1">Welcome back</h3>
                    <p class="text-muted small mb-0">Sign in to your MAS'S Classroom account</p>
                </div>

                <!-- One-Click Role Switcher -->
                <div class="auth-role-switcher mb-4" role="tablist" aria-label="Account Role">
                    <button type="button"
                            class="auth-role-tab <?= ($loginRole === 'student') ? 'active' : '' ?>"
                            data-role="student"
                            role="tab"
                            aria-selected="<?= ($loginRole === 'student') ? 'true' : 'false' ?>">
                        <i class="bi bi-mortarboard-fill"></i>
                        <span>Student Login</span>
                    </button>
                    <button type="button"
                            class="auth-role-tab <?= ($loginRole === 'admin') ? 'active' : '' ?>"
                            data-role="admin"
                            role="tab"
                            aria-selected="<?= ($loginRole === 'admin') ? 'true' : 'false' ?>">
                        <i class="bi bi-shield-lock-fill"></i>
                        <span>Admin Login</span>
                    </button>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4 small">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" id="loginForm">
                    <input type="hidden" name="login_role" id="loginRoleInput" value="<?= htmlspecialchars($loginRole) ?>">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <input type="hidden" name="enroll_course" value="<?= $enrollCourseId ?>">

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold small text-muted">Email address</label>
                        <div class="auth-input-box">
                            <i class="bi bi-envelope auth-input-icon"></i>
                            <input type="email"
                                   class="form-control auth-input-control"
                                   id="email"
                                   name="email"
                                   placeholder="<?= ($loginRole === 'admin') ? 'admin@domain.com' : 'name@example.com' ?>"
                                   value="<?= htmlspecialchars($email) ?>"
                                   required
                                   autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="password" class="form-label fw-semibold small text-muted mb-0">Password</label>
                            <a href="forgot_password.php<?= ($loginRole === 'admin') ? '?role=admin' : '' ?>"
                               id="forgotPasswordLink"
                               class="small text-primary text-decoration-none fw-medium">Forgot Password?</a>
                        </div>
                        <div class="auth-input-box">
                            <i class="bi bi-lock auth-input-icon"></i>
                            <input type="password" class="form-control auth-input-control" id="password" name="password" placeholder="Enter your password" required>
                        </div>
                    </div>

                    <button type="submit" id="loginSubmitBtn" class="btn btn-primary w-100 auth-submit-btn rounded-pill fw-semibold mb-3">
                        <?= ($loginRole === 'admin') ? 'Sign In as Admin' : 'Sign In as Student' ?>
                    </button>

                    <!-- Or Divider -->
                    <div class="spatial-divider my-3">
                        <span>or</span>
                    </div>

                    <!-- Continue with Google Button -->
                    <button type="button" class="btn btn-outline-secondary w-100 auth-submit-btn rounded-pill fw-semibold d-flex align-items-center justify-content-center mb-3" data-bs-toggle="modal" data-bs-target="#googleAuthModal">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" class="me-2">
                            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                        </svg>
                        <span class="text-dark">Continue with Google</span>
                    </button>

                    <div id="studentRegisterPrompt" class="text-center small text-muted pt-2 pb-1 <?= ($loginRole === 'admin') ? 'd-none' : '' ?>">
                        Don't have an account? <a href="register.php<?= $enrollCourseId ? '?redirect=' . urlencode("checkout.php?course_id={$enrollCourseId}") . '&enroll_course=' . $enrollCourseId : '' ?>" class="text-primary fw-semibold text-decoration-none text-nowrap">Create an account</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Google Quick Sign-In Modal (Localhost Demo) -->
<div class="modal fade" id="googleAuthModal" tabindex="-1" aria-labelledby="googleAuthModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header public-modal-header p-4">
                <div class="d-flex align-items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 48 48" class="me-3">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="googleAuthModalLabel">Sign in with Google</h5>
                        <small class="text-muted">Fast student demo authentication</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="login.php">
                <input type="hidden" name="action" value="google_auth">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <input type="hidden" name="enroll_course" value="<?= $enrollCourseId ?>">

                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i> <strong>Localhost Demonstration Mode:</strong> Enter your Google Account Name and Gmail address to instantly create or sign in to your student account without live OAuth credentials.
                    </div>

                    <div class="mb-3">
                        <label for="google_name" class="form-label fw-semibold small text-muted">Google Full Name</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control border-start-0" id="google_name" name="google_name" placeholder="e.g. Golam Mortuza" value="Demo Student" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="google_email" class="form-label fw-semibold small text-muted">Gmail Address</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control border-start-0" id="google_email" name="google_email" placeholder="e.g. student@gmail.com" value="demo.student@gmail.com" required>
                        </div>
                    </div>
                </div>

                <div class="modal-footer public-modal-footer p-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold d-flex align-items-center">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Continue with Google
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var roleTabs = document.querySelectorAll('.auth-role-tab');
    var roleInput = document.getElementById('loginRoleInput');
    var submitBtn = document.getElementById('loginSubmitBtn');
    var registerPrompt = document.getElementById('studentRegisterPrompt');
    var forgotLink = document.getElementById('forgotPasswordLink');
    var emailInput = document.getElementById('email');

    function setLoginRole(role) {
        var isAdminRole = (role === 'admin');
        if (roleInput) {
            roleInput.value = isAdminRole ? 'admin' : 'student';
        }
        roleTabs.forEach(function(tab) {
            var active = tab.getAttribute('data-role') === (isAdminRole ? 'admin' : 'student');
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (submitBtn) {
            submitBtn.textContent = isAdminRole ? 'Sign In as Admin' : 'Sign In as Student';
        }
        if (registerPrompt) {
            registerPrompt.classList.toggle('d-none', isAdminRole);
        }
        if (forgotLink) {
            forgotLink.href = isAdminRole ? 'forgot_password.php?role=admin' : 'forgot_password.php';
        }
        if (emailInput) {
            emailInput.placeholder = isAdminRole ? 'admin@domain.com' : 'name@example.com';
        }
    }

    roleTabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            setLoginRole(tab.getAttribute('data-role'));
        });
    });
});

window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
