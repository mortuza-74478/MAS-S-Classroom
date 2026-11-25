<?php
/**
 * Password Recovery Portal
 * MAS'S Classroom - University DBMS Project
 * Supports email recovery with 6-digit OTP for Students and Administrators
 */
require_once __DIR__ . '/db.php';

// If already logged in, redirect
if (isLoggedIn()) {
    $target = isAdmin() ? 'manage_courses.php' : 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$roleContext = $_GET['role'] ?? ($_POST['role'] ?? 'student');
$step = $_GET['step'] ?? 'request';
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));
$error = '';
$success = '';
$demoCode = '';

// Step 1: Handle Request Recovery Code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_code') {
    $email = trim($_POST['email'] ?? '');
    $roleContext = $_POST['role'] ?? 'student';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid registered email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT student_id, name, email, role FROM students WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate 6-digit numeric recovery code
                $code = sprintf("%06d", random_int(100000, 999999));
                $expiresTime = time() + (15 * 60); // 15 minutes from now
                $expiresSql = date('Y-m-d H:i:s', $expiresTime);

                // Update database with reset token and expiry
                try {
                    $updateStmt = $pdo->prepare("UPDATE students SET reset_token = :token, reset_expires = :expires WHERE student_id = :id");
                    $updateStmt->execute([
                        ':token'   => $code,
                        ':expires' => $expiresSql,
                        ':id'      => $user['student_id']
                    ]);
                } catch (Exception $ex) {
                    // Fallback handled via session
                }

                // Store in session for resilient fallback
                $_SESSION['pw_reset_user_id'] = $user['student_id'];
                $_SESSION['pw_reset_email'] = $user['email'];
                $_SESSION['pw_reset_name'] = $user['name'];
                $_SESSION['pw_reset_code'] = $code;
                $_SESSION['pw_reset_role'] = $user['role'];
                $_SESSION['pw_reset_expires'] = $expiresTime;

                // Dispatch Email via PHP mail()
                $to = $user['email'];
                $subject = "MAS'S Classroom - Password Recovery Code: " . $code;
                $mailBody = "Hello " . $user['name'] . ",\n\n"
                          . "We received a password reset request for your MAS'S Classroom account.\n\n"
                          . "Your 6-digit recovery code is: " . $code . "\n\n"
                          . "This code is valid for 15 minutes.\n\n"
                          . "If you did not request a password reset, you can safely ignore this email.\n\n"
                          . "Best regards,\nMAS'S Classroom Engineering Team";
                $headers = "From: noreply@massclassroom.local\r\n"
                         . "Reply-To: noreply@massclassroom.local\r\n"
                         . "X-Mailer: PHP/" . phpversion();
                @mail($to, $subject, $mailBody, $headers);

                // Redirect to verify step with email and role parameter
                $target = "forgot_password.php?step=verify&email=" . urlencode($email) . "&role=" . urlencode($user['role'] === 'admin' ? 'admin' : $roleContext);
                echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                header("Location: {$target}");
                exit;
            } else {
                $error = 'No account found with this email address. Please check your spelling or register a new account.';
            }
        } catch (PDOException $e) {
            error_log('Password reset request error: ' . $e->getMessage());
            $error = 'Unable to process recovery request right now. Please try again.';
        }
    }
}

// Step 2 & 3: Handle Verify Code & Set New Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $roleContext = $_POST['role'] ?? 'student';
    $step = 'verify';

    if (empty($email) || empty($code) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Please fill out all fields including the 6-digit recovery code and your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new passwords do not match. Please ensure both password fields are identical.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters in length.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT student_id, name, email, role, reset_token, reset_expires FROM students WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            $isCodeValid = false;
            if ($user) {
                // Check database token & expiry
                $tokenMatch = (!empty($user['reset_token']) && $user['reset_token'] === $code);
                $notExpired = (!empty($user['reset_expires']) && strtotime($user['reset_expires']) >= time());

                // Or check session token & expiry
                $sessionMatch = (isset($_SESSION['pw_reset_email'], $_SESSION['pw_reset_code']) 
                                 && $_SESSION['pw_reset_email'] === $email 
                                 && $_SESSION['pw_reset_code'] === $code 
                                 && ($_SESSION['pw_reset_expires'] ?? 0) >= time());

                if (($tokenMatch && $notExpired) || $sessionMatch) {
                    $isCodeValid = true;
                }
            }

            if ($isCodeValid && $user) {
                // Hash new password
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Update database and clear reset tokens
                $updateStmt = $pdo->prepare("UPDATE students SET password = :password, reset_token = NULL, reset_expires = NULL WHERE student_id = :id");
                $updateStmt->execute([
                    ':password' => $newHash,
                    ':id'       => $user['student_id']
                ]);

                // Clear session reset info
                unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_email'], $_SESSION['pw_reset_name'], $_SESSION['pw_reset_code'], $_SESSION['pw_reset_role'], $_SESSION['pw_reset_expires']);

                setFlashMessage('success', "🎉 Password reset successfully! You can now log in with your new password.");

                $target = (($user['role'] ?? '') === 'admin') ? "admin-login.php?email=" . urlencode($email) : "login.php?email=" . urlencode($email);
                echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                header("Location: {$target}");
                exit;
            } else {
                $error = 'Invalid or expired 6-digit recovery code. Please check the code or request a new one.';
            }
        } catch (PDOException $e) {
            error_log('Password reset update error: ' . $e->getMessage());
            $error = 'Unable to update your password right now. Please try again.';
        }
    }
}

// Fetch active demo code for display on localhost
if ($step === 'verify' && !empty($email)) {
    if (isset($_SESSION['pw_reset_email']) && $_SESSION['pw_reset_email'] === $email && !empty($_SESSION['pw_reset_code'])) {
        $demoCode = $_SESSION['pw_reset_code'];
    } else {
        try {
            $stmtCode = $pdo->prepare("SELECT reset_token, reset_expires FROM students WHERE email = :email LIMIT 1");
            $stmtCode->execute([':email' => $email]);
            $tokenRow = $stmtCode->fetch();
            if ($tokenRow && !empty($tokenRow['reset_token']) && strtotime($tokenRow['reset_expires']) >= time()) {
                $demoCode = $tokenRow['reset_token'];
            }
        } catch (Exception $ex) {}
    }
}

$pageTitle = "Reset Password";
require_once __DIR__ . '/header.php';
?>

<div class="container py-5 my-3">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            
            <div class="card auth-card p-4 p-md-5">
                
                <div class="text-center mb-4">
                    <div class="brand-icon-box mx-auto mb-3" style="width: 50px; height: 50px; border-radius: 16px;">
                        <i class="bi bi-shield-lock-fill fs-4"></i>
                    </div>
                    <span class="badge <?= ($roleContext === 'admin') ? 'bg-warning-subtle text-warning-emphasis' : 'bg-primary-subtle text-primary' ?> fw-bold px-3 py-1 text-uppercase mb-2" style="font-size: 0.7rem; letter-spacing: 0.06em;">
                        <?= ($roleContext === 'admin') ? 'Administrator Recovery' : 'Student Password Recovery' ?>
                    </span>
                    <h3 class="fw-bold text-dark mb-1">Reset Password</h3>
                    <p class="text-muted small mb-0">
                        <?= ($step === 'verify') ? 'Enter verification code &amp; choose new password' : 'Enter your registered email to receive a recovery code' ?>
                    </p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-start mb-4 small">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'verify'): ?>
                    
                    <!-- Prominent Localhost Demo OTP Card -->
                    <?php if (!empty($demoCode)): ?>
                        <div class="alert alert-info border-info-subtle p-3 mb-4">
                            <div class="d-flex align-items-center gap-1.5 mb-1.5">
                                <span class="badge bg-primary text-white" style="font-size: 0.68rem;">Localhost Demo Testing</span>
                                <small class="text-muted" style="font-size: 0.72rem;">Email dispatched via mail()</small>
                            </div>
                            <div class="fw-semibold small text-dark mb-1">Your 6-Digit Password Recovery Code:</div>
                            <div class="display-6 fw-bold text-primary font-monospace text-center py-2 spatial-subpanel my-2" style="letter-spacing: 0.25em;">
                                <?= htmlspecialchars($demoCode) ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.75rem;">
                                Sent to <strong class="text-dark"><?= htmlspecialchars($email) ?></strong> &bull; Valid for 15 minutes.
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Step 2 & 3 Form: Code & New Password -->
                    <form method="POST" action="forgot_password.php">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                        <input type="hidden" name="role" value="<?= htmlspecialchars($roleContext) ?>">

                        <div class="mb-3">
                            <label for="recoveryCode" class="form-label fw-semibold small text-muted">6-Digit Recovery Code</label>
                            <div class="auth-input-box">
                                <i class="bi bi-hash auth-input-icon"></i>
                                <input type="text" class="form-control auth-input-control text-center fw-bold fs-5 font-monospace" id="recoveryCode" name="code" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required autofocus style="letter-spacing: 0.2em;">
                            </div>
                            <div class="form-text small text-muted">Enter the 6-digit code shown above or received in your inbox.</div>
                        </div>

                        <div class="mb-3">
                            <label for="newPassword" class="form-label fw-semibold small text-muted">New Password</label>
                            <div class="auth-input-box">
                                <i class="bi bi-lock auth-input-icon"></i>
                                <input type="password" class="form-control auth-input-control" id="newPassword" name="new_password" placeholder="At least 6 characters" minlength="6" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirmPassword" class="form-label fw-semibold small text-muted">Confirm New Password</label>
                            <div class="auth-input-box">
                                <i class="bi bi-shield-check auth-input-icon"></i>
                                <input type="password" class="form-control auth-input-control" id="confirmPassword" name="confirm_password" placeholder="Re-type new password" minlength="6" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 auth-submit-btn rounded-pill fw-semibold mb-3">
                            <i class="bi bi-check-circle-fill me-1"></i> Update Password &amp; Sign In
                        </button>
                    </form>

                    <div class="text-center pt-2 border-top mt-3">
                        <a href="forgot_password.php?step=request&email=<?= urlencode($email) ?>&role=<?= urlencode($roleContext) ?>" class="small text-muted text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i> Request a different code or email
                        </a>
                    </div>

                <?php else: ?>

                    <!-- Step 1 Form: Request Code -->
                    <form method="POST" action="forgot_password.php">
                        <input type="hidden" name="action" value="request_code">
                        <input type="hidden" name="role" value="<?= htmlspecialchars($roleContext) ?>">

                        <div class="mb-4">
                            <label for="email" class="form-label fw-semibold small text-muted">Registered Email Address</label>
                            <div class="auth-input-box">
                                <i class="bi bi-envelope auth-input-icon"></i>
                                <input type="email" class="form-control auth-input-control" id="email" name="email" placeholder="name@example.com" value="<?= htmlspecialchars($email) ?>" required autofocus>
                            </div>
                            <div class="form-text small text-muted">We will generate a secure 6-digit recovery code and dispatch it to your email.</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 auth-submit-btn rounded-pill fw-semibold mb-3">
                            <i class="bi bi-send-fill me-1"></i> Send Recovery Code
                        </button>
                    </form>

                <?php endif; ?>

                <!-- Back to Login Navigation -->
                <div class="text-center small pt-3 pb-1 mt-2 border-top text-muted d-flex justify-content-between align-items-center">
                    <a href="login.php" class="text-decoration-none fw-medium text-primary">
                        &larr; Student Login
                    </a>
                    <a href="admin-login.php" class="text-decoration-none fw-medium text-secondary">
                        Admin Portal &rarr;
                    </a>
                </div>

            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
