<?php
/**
 * Dedicated Enrollment Checkout
 * MAS'S Classroom - University DBMS Project
 * Two-Column Checkout with Bangladeshi Payment Gateways & Double Enrollment Protection
 */
$pageTitle = "Enrollment Checkout";
require_once __DIR__ . '/db.php';

// Authentication requirement: User must be signed in
if (!isLoggedIn()) {
    $courseId = isset($_REQUEST['course_id']) ? (int)$_REQUEST['course_id'] : 0;
    setFlashMessage('warning', 'Please sign in or register to complete course enrollment.');
    $target = "login.php?redirect=" . urlencode("checkout.php?course_id={$courseId}") . "&enroll_course={$courseId}";
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$student = getCurrentStudent();
$studentId = $student['id'];
$courseId = isset($_REQUEST['course_id']) ? (int)$_REQUEST['course_id'] : 0;

if ($courseId <= 0) {
    setFlashMessage('danger', 'Invalid course selected for checkout.');
    $target = 'index.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// 1. Fetch Course and Instructor details
try {
    $stmt = $pdo->prepare("SELECT c.*, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email,
                                  COALESCE(
                                      NULLIF(i.avatar, ''),
                                      NULLIF(i.profile_image, ''),
                                      NULLIF(u.profile_image, ''),
                                      (SELECT s2.profile_image FROM students s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(i.name)) AND s2.profile_image IS NOT NULL AND s2.profile_image != '' ORDER BY CASE WHEN LOWER(s2.role) IN ('admin', 'instructor') THEN 0 ELSE 1 END, s2.student_id ASC LIMIT 1)
                                  ) AS instructor_avatar,
                                  i.profile_image AS instructor_profile_image,
                                  u.profile_image AS user_profile_image
                           FROM courses c 
                           LEFT JOIN instructors i ON c.instructor_id = i.instructor_id 
                           LEFT JOIN students u ON LOWER(TRIM(i.email)) = LOWER(TRIM(u.email))
                           WHERE c.course_id = :id LIMIT 1");
    $stmt->execute([':id' => $courseId]);
    $course = $stmt->fetch();

    if (!$course) {
        setFlashMessage('danger', 'The requested course does not exist.');
        $target = 'index.php';
        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
        header("Location: {$target}");
        exit;
    }

    // Calculate active discount offers for this course
    $activeOffersList = getActiveCourseOffers($pdo);
    $courseOffer = getOfferForCourse($course['course_id'], $course['title'], $activeOffersList);
    $discountInfo = calculateCourseDiscount($course['price'], $courseOffer['discount_badge'] ?? '', $courseOffer['discount_percent'] ?? null, $courseOffer['offer_price'] ?? null);
    $finalPayablePrice = $discountInfo['discounted_price'];
    $courseThumbUrl = getCourseThumbnailUrl($course['title'], $course['thumbnail'] ?? null);
    $courseInstructorAvatarUrl = getInstructorAvatarUrl($course['instructor_name'] ?? '', [$course['instructor_avatar'] ?? null, $course['instructor_profile_image'] ?? null, $course['user_profile_image'] ?? null], (int)($course['instructor_id'] ?? 0));
    list($courseHeaderClass, $courseIconClass) = getCourseCategoryVisual($course['category'] ?? '');
} catch (PDOException $e) {
    error_log('Checkout load error: ' . $e->getMessage());
    setFlashMessage('danger', 'Unable to load course checkout right now. Please try again.');
    $target = 'index.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// 2. Double Enrollment Check (Backend Verification)
$isResubmission = false;
$isDroppedReenroll = false;
$existingEnrollment = null;
try {
    $stmtCheck = $pdo->prepare("SELECT enrollment_id, enrollment_date, payment_method, LOWER(COALESCE(status, 'approved')) AS status FROM enrollments WHERE student_id = :sid AND course_id = :cid LIMIT 1");
    $stmtCheck->execute([':sid' => $studentId, ':cid' => $courseId]);
    $existingEnrollment = $stmtCheck->fetch();

    if ($existingEnrollment) {
        if ($existingEnrollment['status'] === 'approved') {
            setFlashMessage('info', "You are already enrolled in <strong>" . htmlspecialchars($course['title']) . "</strong> (Verified on " . formatDateSafe($existingEnrollment['enrollment_date'] ?? null) . ").");
            $target = 'my_courses.php';
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
            header("Location: {$target}");
            exit;
        } elseif ($existingEnrollment['status'] === 'pending') {
            setFlashMessage('warning', "Your payment for <strong>" . htmlspecialchars($course['title']) . "</strong> is already submitted and awaiting admin verification.");
            $target = 'my_courses.php';
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
            header("Location: {$target}");
            exit;
        } elseif ($existingEnrollment['status'] === 'rejected') {
            $isResubmission = true;
        } elseif ($existingEnrollment['status'] === 'dropped') {
            $isDroppedReenroll = true;
        }
    }
} catch (PDOException $e) {
    error_log('Checkout verification error: ' . $e->getMessage());
    setFlashMessage('danger', 'Unable to verify enrollment eligibility right now. Please try again.');
    $target = 'index.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// 3. Handle Checkout Submission (POST)
$checkoutError = '';
$formPaymentMethod = 'bKash';
$formPhoneNumber = !empty($student['phone']) ? $student['phone'] : '';
$formAccountNumber = !empty($student['phone']) ? $student['phone'] : '';
$formTransactionId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentMethod = trim($_POST['payment_method'] ?? 'bKash');
    $allowedMethods = ['bKash', 'Nagad', 'Rocket', 'Debit / Credit Card'];
    if (!in_array($paymentMethod, $allowedMethods)) {
        $paymentMethod = 'bKash';
    }

    $accountNumber = trim($_POST['account_number'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $transactionId = trim($_POST['transaction_id'] ?? '');
    $walletNumber = $accountNumber !== '' ? $accountNumber : $phoneNumber;

    $formPaymentMethod = $paymentMethod;
    $formPhoneNumber = $phoneNumber;
    $formAccountNumber = $accountNumber;
    $formTransactionId = $transactionId;

    if (empty($phoneNumber)) {
        $checkoutError = 'Please enter your contact phone number for enrollment notifications.';
    } elseif (empty($accountNumber)) {
        $checkoutError = 'Please enter the wallet / card number used for payment.';
    } elseif (empty($transactionId)) {
        $checkoutError = 'Transaction ID (TrxID) is required so the MAS\'S Classroom Admin can verify your payment.';
    } else {
        try {
            // Re-verify double enrollment before inserting/updating
            $stmtCheckDouble = $pdo->prepare("SELECT enrollment_id, LOWER(COALESCE(status, 'approved')) AS status FROM enrollments WHERE student_id = :sid AND course_id = :cid LIMIT 1");
            $stmtCheckDouble->execute([':sid' => $studentId, ':cid' => $courseId]);
            $doubleRow = $stmtCheckDouble->fetch();

            if ($doubleRow && !in_array($doubleRow['status'], ['rejected', 'dropped'], true)) {
                setFlashMessage('warning', "You already have an active or pending enrollment for this course.");
                $target = 'my_courses.php';
                echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                header("Location: {$target}");
                exit;
            }

            if ($doubleRow && in_array($doubleRow['status'], ['rejected', 'dropped'], true)) {
                $stmtUpd = $pdo->prepare("UPDATE enrollments
                                          SET payment_method = :payment_method, wallet_number = :wallet_number, transaction_id = :transaction_id,
                                              paid_amount = :paid_amount, status = 'pending', verified_at = NULL, dropped_at = NULL, approval_seen = 0, enrollment_date = NOW()
                                          WHERE enrollment_id = :eid AND student_id = :sid");
                $stmtUpd->execute([
                    ':payment_method' => $paymentMethod,
                    ':wallet_number'  => $walletNumber,
                    ':transaction_id' => $transactionId,
                    ':paid_amount'    => $finalPayablePrice,
                    ':eid'            => $doubleRow['enrollment_id'],
                    ':sid'            => $studentId
                ]);
            } else {
                // Insert new enrollment record as pending verification
                $stmtInsert = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, payment_method, wallet_number, transaction_id, paid_amount, status, enrollment_date, approval_seen)
                                             VALUES (:student_id, :course_id, :payment_method, :wallet_number, :transaction_id, :paid_amount, 'pending', NOW(), 0)");
                $stmtInsert->execute([
                    ':student_id'     => $studentId,
                    ':course_id'      => $courseId,
                    ':payment_method' => $paymentMethod,
                    ':wallet_number'  => $walletNumber,
                    ':transaction_id' => $transactionId,
                    ':paid_amount'    => $finalPayablePrice
                ]);
            }

            setFlashMessage('warning', "<strong>Payment submitted!</strong> Your enrollment is pending admin verification. You will get full course access once approved. (Method: <strong>" . htmlspecialchars($paymentMethod) . "</strong> &bull; TrxID: <code>" . htmlspecialchars($transactionId) . "</code>)");
            $target = 'my_courses.php';
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
            header("Location: {$target}");
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                setFlashMessage('info', "You already have an enrollment record for <strong>" . htmlspecialchars($course['title']) . "</strong>.");
                $target = 'my_courses.php';
                echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                header("Location: {$target}");
                exit;
            } else {
                error_log('Checkout submit error: ' . $e->getMessage());
                $checkoutError = 'Unable to process your payment submission right now. Please verify your details and try again.';
            }
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="container py-4 my-2">
    <!-- Breadcrumb & Page Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 pb-2 border-bottom">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php#courses-catalog" class="text-decoration-none">Courses</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Checkout</li>
                </ol>
            </nav>
            <h2 class="fw-bold mb-0 text-dark">
                <i class="bi bi-shield-check text-primary me-2"></i>Enrollment Checkout
            </h2>
        </div>
        <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
                <i class="bi bi-lock-fill me-1"></i> 256-Bit SSL Encrypted
            </span>
        </div>
    </div>

    <?php if (!empty($checkoutError)): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 rounded-4 mb-4">
            <i class="bi bi-exclamation-octagon-fill fs-5 flex-shrink-0"></i>
            <div><?= htmlspecialchars($checkoutError) ?></div>
        </div>
    <?php elseif ($isResubmission): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 rounded-4 mb-4">
            <i class="bi bi-arrow-repeat fs-5 flex-shrink-0"></i>
            <div>Your previous payment submission for this course was rejected. Please verify your wallet details and submit a valid Transaction ID (TrxID).</div>
        </div>
    <?php elseif ($isDroppedReenroll): ?>
        <div class="alert alert-info d-flex align-items-center gap-2 rounded-4 mb-4">
            <i class="bi bi-arrow-clockwise fs-5 flex-shrink-0"></i>
            <div>You previously dropped this course. Submitting a new payment will request admin verification to restore your course access.</div>
        </div>
    <?php endif; ?>

    <form method="POST" action="checkout.php?course_id=<?= $courseId ?>" id="checkoutForm">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">

        <div class="row g-4 align-items-stretch">
            
            <!-- LEFT COLUMN: Course Summary & Billing Details -->
            <div class="col-lg-7 d-flex flex-column gap-4">
                
                <!-- Course Details Card -->
                <div class="card overflow-hidden">
                    <div class="course-checkout-banner <?= $courseHeaderClass ?> text-white">
                        <?php if ($courseThumbUrl): ?>
                            <img src="<?= htmlspecialchars($courseThumbUrl) ?>" alt="<?= htmlspecialchars($course['title']) ?>" class="course-card-thumb">
                            <div class="course-card-scrim-strong"></div>
                        <?php else: ?>
                            <i class="bi <?= $courseIconClass ?> course-icon-bg"></i>
                        <?php endif; ?>
                        <div class="d-flex align-items-center justify-content-between position-relative" style="z-index: 2;">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="card-category-badge">
                                    <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($course['category'] ?? 'General') ?>
                                </span>
                                <?php if ($discountInfo['has_discount']): ?>
                                    <span class="badge rounded-pill fw-bold px-3 py-1.5" style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);">
                                        <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($discountInfo['badge']) ?> APPLIED
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="badge rounded-pill px-2.5 py-1" style="background: rgba(15, 23, 42, 0.55); color: #F8FAFC; backdrop-filter: blur(8px);">
                                Course #<?= $course['course_id'] ?>
                            </span>
                        </div>
                        <h4 class="fw-bold text-white mt-3 mb-0 position-relative" style="z-index: 2; text-shadow: 0 2px 10px rgba(0,0,0,0.4);">
                            <?= htmlspecialchars($course['title']) ?>
                        </h4>
                    </div>

                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3 p-3 spatial-subpanel">
                            <div class="instructor-avatar me-3 overflow-hidden" style="width: 44px; height: 44px; font-size: 1.1rem;">
                                <?php if ($courseInstructorAvatarUrl): ?>
                                    <img src="<?= htmlspecialchars($courseInstructorAvatarUrl) ?>" alt="<?= htmlspecialchars($course['instructor_name'] ?? 'Instructor') ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <?= strtoupper(substr($course['instructor_name'] ?? 'U', 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.72rem;">Lead Instructor</small>
                                <span class="fw-bold text-dark d-block"><?= htmlspecialchars($course['instructor_name'] ?? 'Unassigned Instructor') ?></span>
                                <span class="text-muted small"><?= htmlspecialchars($course['instructor_expertise'] ?? 'Faculty Member') ?></span>
                            </div>
                        </div>

                        <p class="text-secondary small mb-3" style="line-height: 1.6;">
                            <?= nl2br(htmlspecialchars($course['description'])) ?>
                        </p>

                        <div class="row g-2 pt-2 border-top">
                            <div class="col-sm-4">
                                <div class="d-flex align-items-center text-muted small">
                                    <i class="bi bi-patch-check-fill text-primary me-2"></i> Verified Syllabus
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="d-flex align-items-center text-muted small">
                                    <i class="bi bi-infinity text-success me-2"></i> Lifetime Access
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="d-flex align-items-center text-muted small">
                                    <i class="bi bi-laptop text-info me-2"></i> 100% Online
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Student & Billing Information Card -->
                <div class="card flex-grow-1 d-flex flex-column">
                    <div class="card-header p-3 p-md-4">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="bi bi-person-lines-fill text-primary me-2"></i>Student Billing Information
                        </h5>
                    </div>
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Full Name</label>
                                <div class="auth-input-box auth-input-compact">
                                    <i class="bi bi-person auth-input-icon"></i>
                                    <input type="text" class="form-control auth-input-control" value="<?= htmlspecialchars($student['name']) ?>" readonly>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Email Address</label>
                                <div class="auth-input-box auth-input-compact">
                                    <i class="bi bi-envelope auth-input-icon"></i>
                                    <input type="email" class="form-control auth-input-control" value="<?= htmlspecialchars($student['email']) ?>" readonly>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="phoneNumber" class="form-label fw-semibold small text-muted">
                                    Phone / Mobile Number <span class="text-danger">*</span>
                                </label>
                                <div class="auth-input-box auth-input-compact">
                                    <i class="bi bi-telephone auth-input-icon"></i>
                                    <input type="text" class="form-control auth-input-control" id="phoneNumber" name="phone_number" placeholder="e.g. 01709016144" value="<?= htmlspecialchars($formPhoneNumber) ?>" required>
                                </div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.74rem;">Used for payment SMS and enrollment notifications.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Student ID</label>
                                <div class="auth-input-box auth-input-compact">
                                    <i class="bi bi-card-heading auth-input-icon"></i>
                                    <input type="text" class="form-control auth-input-control" value="MAS-STU-<?= str_pad($studentId, 4, '0', STR_PAD_LEFT) ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="mt-auto pt-4">
                            <div class="p-3 spatial-subpanel">
                                <div class="d-flex align-items-center gap-2 fw-bold text-dark small mb-2">
                                    <i class="bi bi-shield-lock-fill text-primary"></i>
                                    <span>Payment Verification Process</span>
                                </div>
                                <div class="checkout-step-list">
                                    <div class="checkout-step-item">
                                        <span class="checkout-step-num">1</span>
                                        <span>Submit your wallet / card number and authentic <strong>Transaction ID (TrxID)</strong>.</span>
                                    </div>
                                    <div class="checkout-step-item">
                                        <span class="checkout-step-num">2</span>
                                        <span>MAS'S Classroom Admin reviews and verifies the payment reference.</span>
                                    </div>
                                    <div class="checkout-step-item">
                                        <span class="checkout-step-num">3</span>
                                        <span>Your enrollment turns <strong>Verified &amp; Enrolled</strong> with full dashboard access unlocked.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: Payment Method Selection & Complete Enrollment -->
            <div class="col-lg-5 d-flex flex-column gap-4">
                
                <!-- Order Summary Card -->
                <div class="card">
                    <div class="card-header p-3 p-md-4">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="bi bi-receipt text-primary me-2"></i>Order Summary
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-secondary small">Course Tuition</span>
                            <?php if ($discountInfo['has_discount']): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <del class="small strikethrough-old-price">
                                        <?= formatPrice($course['price']) ?>
                                    </del>
                                    <span class="fw-semibold text-dark"><?= formatPrice($course['price']) ?></span>
                                </div>
                            <?php else: ?>
                                <span class="fw-semibold text-dark"><?= formatPrice($course['price']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($discountInfo['has_discount']): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-success small fw-semibold">
                                    <i class="bi bi-tag-fill me-1"></i> Special Discount (<?= htmlspecialchars($discountInfo['badge']) ?>)
                                </span>
                                <span class="badge bg-success-subtle text-success fw-bold">
                                    - <?= formatPrice($discountInfo['discount_amount']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-secondary small">Enrollment Registration Fee</span>
                            <span class="badge bg-success-subtle text-success">৳0 (FREE)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-secondary small">Online Processing Fee</span>
                            <span class="badge bg-success-subtle text-success">৳0 (FREE)</span>
                        </div>
                        <hr class="my-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold fs-6 text-dark">Total Payable Amount</span>
                            <div class="text-end">
                                <?php if ($discountInfo['has_discount']): ?>
                                    <del class="small d-block strikethrough-old-price" style="font-size: 0.85rem;">
                                        <?= formatPrice($course['price']) ?>
                                    </del>
                                    <span class="fs-4 fw-bold text-primary"><?= formatPrice($finalPayablePrice) ?></span>
                                <?php else: ?>
                                    <span class="fs-4 fw-bold text-primary"><?= formatPrice($finalPayablePrice) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Method Card -->
                <div class="card flex-grow-1 d-flex flex-column">
                    <div class="card-header p-3 p-md-4">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="bi bi-credit-card-2-front-fill text-primary me-2"></i>Payment Method (Bangladesh)
                        </h5>
                    </div>
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex flex-column gap-2 mb-3">
                            
                            <!-- 1. bKash Option -->
                            <label class="payment-method-card <?= ($formPaymentMethod === 'bKash') ? 'selected' : '' ?>" for="pm_bkash">
                                <input type="radio" name="payment_method" id="pm_bkash" value="bKash" <?= ($formPaymentMethod === 'bKash') ? 'checked' : '' ?> onchange="updatePaymentFields('bKash')">
                                <div class="payment-method-info">
                                    <div class="payment-logo-box">
                                        <img src="images/bkash.png" alt="bKash" class="payment-logo-img">
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">bKash</div>
                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">Direct Mobile Payment</small>
                                    </div>
                                </div>
                                <span class="badge bg-danger-subtle text-danger" style="font-size: 0.75rem;">Popular</span>
                            </label>

                            <!-- 2. Nagad Option -->
                            <label class="payment-method-card <?= ($formPaymentMethod === 'Nagad') ? 'selected' : '' ?>" for="pm_nagad">
                                <input type="radio" name="payment_method" id="pm_nagad" value="Nagad" <?= ($formPaymentMethod === 'Nagad') ? 'checked' : '' ?> onchange="updatePaymentFields('Nagad')">
                                <div class="payment-method-info">
                                    <div class="payment-logo-box">
                                        <img src="images/nagad.png" alt="Nagad" class="payment-logo-img">
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">Nagad</div>
                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">Mobile Financial Service</small>
                                    </div>
                                </div>
                                <span class="badge bg-warning-subtle text-warning" style="font-size: 0.75rem;">Fast</span>
                            </label>

                            <!-- 3. Rocket Option -->
                            <label class="payment-method-card <?= ($formPaymentMethod === 'Rocket') ? 'selected' : '' ?>" for="pm_rocket">
                                <input type="radio" name="payment_method" id="pm_rocket" value="Rocket" <?= ($formPaymentMethod === 'Rocket') ? 'checked' : '' ?> onchange="updatePaymentFields('Rocket')">
                                <div class="payment-method-info">
                                    <div class="payment-logo-box">
                                        <img src="images/rocket.png" alt="Rocket" class="payment-logo-img">
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">Rocket</div>
                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">DBBL Mobile Banking</small>
                                    </div>
                                </div>
                                <span class="badge bg-secondary-subtle" style="font-size: 0.75rem;">Reliable</span>
                            </label>

                            <!-- 4. Debit / Credit Card Option -->
                            <label class="payment-method-card <?= ($formPaymentMethod === 'Debit / Credit Card') ? 'selected' : '' ?>" for="pm_card">
                                <input type="radio" name="payment_method" id="pm_card" value="Debit / Credit Card" <?= ($formPaymentMethod === 'Debit / Credit Card') ? 'checked' : '' ?> onchange="updatePaymentFields('Debit / Credit Card')">
                                <div class="payment-method-info">
                                    <div class="payment-logo-box payment-card-icon-box">
                                        <i class="bi bi-credit-card-2-front" style="font-size: 1.35rem;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">Debit / Credit Card</div>
                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">Visa, Mastercard, NexusPay</small>
                                    </div>
                                </div>
                                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.75rem;">Global</span>
                            </label>

                        </div>

                        <!-- Dynamic Account / Card Inputs -->
                        <div class="p-3 spatial-subpanel mb-3">
                            <div class="mb-3">
                                <label for="accountNumber" id="accountLabel" class="form-label fw-semibold small text-dark">
                                    bKash Wallet / Mobile Number <span class="text-danger">*</span>
                                </label>
                                <div class="auth-input-box auth-input-compact">
                                    <span id="accountIcon" class="auth-input-icon"><i class="bi bi-phone"></i></span>
                                    <input type="text" class="form-control auth-input-control" id="accountNumber" name="account_number" placeholder="01XXXXXXXXX" value="<?= htmlspecialchars($formAccountNumber) ?>" required>
                                </div>
                            </div>

                            <div>
                                <label for="transactionId" class="form-label fw-semibold small text-dark">
                                    Transaction ID (TrxID) <span class="text-danger">*</span>
                                </label>
                                <div class="auth-input-box auth-input-compact">
                                    <i class="bi bi-hash auth-input-icon"></i>
                                    <input type="text" class="form-control auth-input-control" id="transactionId" name="transaction_id" placeholder="e.g. BKS84920194" value="<?= htmlspecialchars($formTransactionId) ?>" required>
                                </div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.74rem;">Required for admin payment verification before course access is unlocked.</small>
                            </div>
                        </div>

                        <div class="mt-auto">
                            <!-- Confirm Enrollment Button -->
                            <button type="submit" class="btn btn-cta btn-lg w-100 py-3 rounded-pill fw-bold d-flex align-items-center justify-content-center">
                                <i class="bi bi-shield-check me-2 fs-5"></i> Pay <?= formatPrice($finalPayablePrice) ?> &amp; Submit for Verification
                            </button>

                            <div class="text-center mt-3 text-muted small d-flex align-items-center justify-content-center gap-1" style="font-size: 0.78rem;">
                                <i class="bi bi-patch-check-fill text-primary flex-shrink-0"></i>
                                <span>Verified by MAS'S Classroom Admin upon payment confirmation</span>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </form>
</div>

<script>
function updatePaymentFields(method) {
    var label = document.getElementById('accountLabel');
    var input = document.getElementById('accountNumber');
    var icon = document.getElementById('accountIcon');
    var trx = document.getElementById('transactionId');

    if (method === 'Debit / Credit Card') {
        label.innerHTML = 'Card Number (Visa / Mastercard) <span class="text-danger">*</span>';
        input.placeholder = '4123 •••• •••• 9821';
        icon.innerHTML = '<i class="bi bi-credit-card"></i>';
        trx.placeholder = 'e.g. CRD98201934';
    } else {
        label.innerHTML = method + ' Wallet / Mobile Number <span class="text-danger">*</span>';
        input.placeholder = '01XXXXXXXXX';
        icon.innerHTML = '<i class="bi bi-phone"></i>';
        var prefix = method.substring(0, 3).toUpperCase();
        trx.placeholder = 'e.g. ' + prefix + '84920194';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
