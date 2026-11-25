<?php
/**
 * Student Registration - MAS'S Classroom
 * University DBMS Project
 */
require_once __DIR__ . '/db.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    $target = 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$name = '';
$email = '';
$error = '';

$redirect = sanitizeRedirectPath($_REQUEST['redirect'] ?? '', 'my_courses.php');
$enrollCourseId = isset($_REQUEST['enroll_course']) && is_numeric($_REQUEST['enroll_course']) ? (int)$_REQUEST['enroll_course'] : 0;

// Handle Google Quick Sign-In
handleGoogleAuth($pdo, $redirect, $enrollCourseId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'google_auth')) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $redirect = sanitizeRedirectPath($_POST['redirect'] ?? $redirect, 'my_courses.php');
    $enrollCourseId = isset($_POST['enroll_course']) ? (int)$_POST['enroll_course'] : $enrollCourseId;

    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Check if email already exists in students table
            $stmtCheck = $pdo->prepare("SELECT student_id FROM students WHERE email = :email");
            $stmtCheck->execute([':email' => $email]);

            if ($stmtCheck->fetch()) {
                $error = 'An account with this email address already exists. Please log in instead.';
            } else {
                // Securely hash password using bcrypt
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmtInsert = $pdo->prepare("INSERT INTO students (name, email, role, password) VALUES (:name, :email, 'student', :password)");
                $stmtInsert->execute([
                    ':name'     => $name,
                    ':email'    => $email,
                    ':password' => $hashedPassword
                ]);

                setFlashMessage('success', 'Account created successfully. Please log in to continue.');

                $loginQuery = ['registered_email' => $email];
                if (!empty($redirect) && $redirect !== 'my_courses.php') {
                    $loginQuery['redirect'] = $redirect;
                }
                if ($enrollCourseId > 0) {
                    $loginQuery['enroll_course'] = $enrollCourseId;
                    if (empty($loginQuery['redirect'])) {
                        $loginQuery['redirect'] = "checkout.php?course_id={$enrollCourseId}";
                    }
                }
                $target = 'login.php?' . http_build_query($loginQuery);
                echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                header("Location: {$target}");
                exit;
            }
        } catch (PDOException $e) {
            error_log('Registration error: ' . $e->getMessage());
            $error = 'Unable to create your account right now. Please try again shortly.';
        }
    }
}

$pageTitle = "Student Registration";
require_once __DIR__ . '/header.php';
?>

<div class="container py-5 my-2">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card auth-card p-4 p-md-5">
                <div class="text-center mb-4">
                    <h3 class="fw-bold text-dark mb-1">Create an account</h3>
                    <p class="text-muted small">Start learning today on MAS'S Classroom</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4 small">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php<?= isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <input type="hidden" name="enroll_course" value="<?= $enrollCourseId ?>">

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold small text-muted">Full Name</label>
                        <div class="auth-input-box">
                            <i class="bi bi-person auth-input-icon"></i>
                            <input type="text" class="form-control auth-input-control" id="name" name="name" placeholder="e.g. John Doe" value="<?= htmlspecialchars($name) ?>" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold small text-muted">Email address</label>
                        <div class="auth-input-box">
                            <i class="bi bi-envelope auth-input-icon"></i>
                            <input type="email" class="form-control auth-input-control" id="email" name="email" placeholder="name@example.com" value="<?= htmlspecialchars($email) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold small text-muted">Password (min 6 characters)</label>
                        <div class="auth-input-box">
                            <i class="bi bi-lock auth-input-icon"></i>
                            <input type="password" class="form-control auth-input-control" id="password" name="password" placeholder="Create a password" required minlength="6">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold small text-muted">Confirm Password</label>
                        <div class="auth-input-box">
                            <i class="bi bi-shield-lock auth-input-icon"></i>
                            <input type="password" class="form-control auth-input-control" id="confirm_password" name="confirm_password" placeholder="Re-type your password" required minlength="6">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 auth-submit-btn rounded-pill fw-semibold mb-3">
                        Create Account
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

                    <div class="text-center small text-muted pt-3 mt-2 pb-2 border-top">
                        Already have an account? <a href="login.php<?= $enrollCourseId ? '?redirect=' . urlencode("checkout.php?course_id={$enrollCourseId}") . '&enroll_course=' . $enrollCourseId : '' ?>" class="text-primary fw-semibold text-decoration-none text-nowrap">Sign in</a>
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
            <form method="POST" action="register.php">
                <input type="hidden" name="action" value="google_auth">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <input type="hidden" name="enroll_course" value="<?= $enrollCourseId ?>">

                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i> <strong>Localhost Demonstration Mode:</strong> Enter your Google Account Name and Gmail address to instantly create or sign in to your student account without live OAuth credentials.
                    </div>

                    <div class="mb-3">
                        <label for="reg_google_name" class="form-label fw-semibold small text-muted">Google Full Name</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control border-start-0" id="reg_google_name" name="google_name" placeholder="e.g. Golam Mortuza" value="Demo Student" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="reg_google_email" class="form-label fw-semibold small text-muted">Gmail Address</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control border-start-0" id="reg_google_email" name="google_email" placeholder="e.g. student@gmail.com" value="demo.student@gmail.com" required>
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

<?php require_once __DIR__ . '/footer.php'; ?>
