<?php
/**
 * Course Enrollment Controller
 * MAS'S Classroom - University DBMS Project
 * Supports Bangladeshi Payment Methods (bKash, Nagad, Rocket, Debit / Credit Card)
 */
require_once __DIR__ . '/db.php';

// Check if student is authenticated
if (!isLoggedIn()) {
    $courseId = isset($_REQUEST['course_id']) ? (int)$_REQUEST['course_id'] : 0;
    setFlashMessage('warning', 'Please sign in or create an account to enroll in courses.');
    $target = "login.php?redirect=index.php" . ($courseId ? "&enroll_course={$courseId}" : "");
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$studentId = $_SESSION['student_id'];
$courseId = isset($_REQUEST['course_id']) ? (int)$_REQUEST['course_id'] : 0;

if ($courseId <= 0) {
    setFlashMessage('danger', 'Invalid course selection.');
    $target = 'index.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Redirect GET requests to dedicated Checkout page
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $target = "checkout.php?course_id={$courseId}";
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Payment method handling
$paymentMethod = trim($_REQUEST['payment_method'] ?? 'bKash');
$allowedMethods = ['bKash', 'Nagad', 'Rocket', 'Debit / Credit Card'];
if (!in_array($paymentMethod, $allowedMethods)) {
    $paymentMethod = 'bKash';
}

$walletNumber = trim($_REQUEST['account_number'] ?? ($_REQUEST['phone_number'] ?? ($_SESSION['student_phone'] ?? '')));
$transactionId = trim($_REQUEST['transaction_id'] ?? '');
if (empty($transactionId)) {
    setFlashMessage('warning', 'Transaction ID (TrxID) is required to submit your enrollment payment for admin verification.');
    $target = "checkout.php?course_id={$courseId}";
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

try {
    // 1. Verify that the course exists
    $stmtCourse = $pdo->prepare("SELECT course_id, title, price FROM courses WHERE course_id = :course_id LIMIT 1");
    $stmtCourse->execute([':course_id' => $courseId]);
    $course = $stmtCourse->fetch();

    if (!$course) {
        setFlashMessage('danger', 'The selected course does not exist.');
        header('Location: index.php');
        exit;
    }

    // Calculate payable price with active course offer
    $activeOffersList = getActiveCourseOffers($pdo);
    $courseOffer = getOfferForCourse($course['course_id'], $course['title'], $activeOffersList);
    $discountInfo = calculateCourseDiscount($course['price'], $courseOffer['discount_badge'] ?? '', $courseOffer['discount_percent'] ?? null, $courseOffer['offer_price'] ?? null);
    $paidAmount = (float)$discountInfo['discounted_price'];

    // 2. Check if student is already enrolled or pending (Duplicate Prevention)
    $stmtCheck = $pdo->prepare("SELECT enrollment_id, LOWER(COALESCE(status, 'approved')) AS status FROM enrollments WHERE student_id = :student_id AND course_id = :course_id LIMIT 1");
    $stmtCheck->execute([
        ':student_id' => $studentId,
        ':course_id'  => $courseId
    ]);
    $existing = $stmtCheck->fetch();

    if ($existing && !in_array($existing['status'], ['rejected', 'dropped'], true)) {
        $msg = ($existing['status'] === 'pending')
            ? "Your payment for <strong>" . htmlspecialchars($course['title']) . "</strong> is already submitted and awaiting admin verification."
            : "You are already enrolled in <strong>" . htmlspecialchars($course['title']) . "</strong>.";
        setFlashMessage($existing['status'] === 'pending' ? 'warning' : 'info', $msg);
        $target = 'my_courses.php';
        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
        header("Location: {$target}");
        exit;
    }

    // 3. Insert or re-submit enrollment record as pending admin verification
    if ($existing && in_array($existing['status'], ['rejected', 'dropped'], true)) {
        $stmtUpd = $pdo->prepare("UPDATE enrollments
                                  SET payment_method = :payment_method, wallet_number = :wallet_number, transaction_id = :transaction_id,
                                      paid_amount = :paid_amount, status = 'pending', verified_at = NULL, dropped_at = NULL, approval_seen = 0, enrollment_date = NOW()
                                  WHERE enrollment_id = :eid AND student_id = :sid");
        $stmtUpd->execute([
            ':payment_method' => $paymentMethod,
            ':wallet_number'  => $walletNumber !== '' ? $walletNumber : null,
            ':transaction_id' => $transactionId,
            ':paid_amount'    => $paidAmount,
            ':eid'            => $existing['enrollment_id'],
            ':sid'            => $studentId
        ]);
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, payment_method, wallet_number, transaction_id, paid_amount, status, enrollment_date, approval_seen) VALUES (:student_id, :course_id, :payment_method, :wallet_number, :transaction_id, :paid_amount, 'pending', NOW(), 0)");
        $stmtInsert->execute([
            ':student_id'     => $studentId,
            ':course_id'      => $courseId,
            ':payment_method' => $paymentMethod,
            ':wallet_number'  => $walletNumber !== '' ? $walletNumber : null,
            ':transaction_id' => $transactionId,
            ':paid_amount'    => $paidAmount
        ]);
    }

    setFlashMessage('warning', "<strong>Payment submitted!</strong> Your enrollment is pending admin verification. You will get full course access once approved. (Method: <strong>" . htmlspecialchars($paymentMethod) . "</strong> &bull; TrxID: <code>" . htmlspecialchars($transactionId) . "</code>)");
    $target = 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;

} catch (PDOException $e) {
    error_log('Enrollment submit error: ' . $e->getMessage());
    setFlashMessage('danger', 'Unable to submit your enrollment right now. Please try again.');
    $target = 'index.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}
