<?php
/**
 * Student & Instructor Dashboard
 * MAS'S Classroom - University DBMS Project
 * Features: 
 *   - For Students: Enrolled courses, payment info, Drop course action, profile update
 *   - For Instructors/Admins: Instructor Course & Enrollment Overview with per-course student breakdown
 */
require_once __DIR__ . '/db.php';

// Authentication guard
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please sign in to access your dashboard.');
    $target = 'login.php?redirect=my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

$studentId = $_SESSION['student_id'];

// ====================================================================
// HANDLE ACTIONS (POST)
// ====================================================================

// Action 0A: ADMIN VERIFY / REJECT PENDING ENROLLMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_enrollment', 'reject_enrollment'], true)) {
    if (isAdmin()) {
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $decision = ($_POST['action'] === 'approve_enrollment') ? 'approved' : 'rejected';
        processEnrollmentVerification($pdo, $decision, $enrollmentId);
    } else {
        setFlashMessage('danger', 'Administrator privileges are required to verify enrollments.');
    }
    $target = 'my_courses.php#pending-verifications';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Action 0B: DISMISS NEWLY APPROVED NOTIFICATION BANNER (For Students)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_approval_notice') {
    try {
        $stmtAck = $pdo->prepare("UPDATE enrollments SET approval_seen = 1 WHERE student_id = :student_id AND LOWER(COALESCE(status, 'approved')) = 'approved'");
        $stmtAck->execute([':student_id' => $studentId]);
    } catch (PDOException $e) {}
    $target = 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Action 1: DROP / CANCEL ENROLLMENT (Soft-drop: preserves audit & revenue ledger)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'drop_course') {
    $enrollmentId = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;
    $courseTitle = isset($_POST['course_title']) ? trim($_POST['course_title']) : 'the course';

    if ($enrollmentId > 0) {
        try {
            $stmtDrop = $pdo->prepare("UPDATE enrollments
                                       SET status = 'dropped', dropped_at = NOW(), approval_seen = 1
                                       WHERE enrollment_id = :enrollment_id
                                         AND student_id = :student_id
                                         AND LOWER(COALESCE(status, 'approved')) IN ('approved', 'pending')");
            $stmtDrop->execute([
                ':enrollment_id' => $enrollmentId,
                ':student_id'    => $studentId
            ]);

            if ($stmtDrop->rowCount() > 0) {
                setFlashMessage('warning', "You have dropped <strong>" . htmlspecialchars($courseTitle) . "</strong>. Course access has been removed (tuition payments are non-refundable and archived in enrollment history).");
            } else {
                setFlashMessage('danger', 'Enrollment record could not be found or has already been dropped.');
            }
        } catch (PDOException $e) {
            error_log('Drop course error: ' . $e->getMessage());
            setFlashMessage('danger', 'Unable to drop this course right now. Please try again.');
        }
    }
    $target = 'my_courses.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Action 2: UPDATE PROFILE (For both Students & Instructors/Admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_new_password'] ?? '';

    if (empty($name) || empty($email)) {
        setFlashMessage('danger', 'Name and email address cannot be empty.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlashMessage('danger', 'Please enter a valid email address.');
    } else {
        try {
            // Check if email is already taken by another student
            $stmtCheck = $pdo->prepare("SELECT student_id FROM students WHERE email = :email AND student_id != :student_id");
            $stmtCheck->execute([
                ':email'      => $email,
                ':student_id' => $studentId
            ]);

            if ($stmtCheck->fetch()) {
                setFlashMessage('danger', 'The email address is already in use by another account.');
            } else {
                // Fetch current student profile details
                $stmtCurr = $pdo->prepare("SELECT name, email, phone, role, profile_image FROM students WHERE student_id = :student_id");
                $stmtCurr->execute([':student_id' => $studentId]);
                $currRow = $stmtCurr->fetch();
                $currName = $currRow['name'] ?? '';
                $currEmail = $currRow['email'] ?? '';
                $profileImagePath = $currRow['profile_image'] ?? null;

                // Handle Profile Photo Upload
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['profile_image']['tmp_name'];
                    $fileName = $_FILES['profile_image']['name'];
                    $fileSize = $_FILES['profile_image']['size'];
                    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                    if (!in_array($fileExt, $allowedExts)) {
                        setFlashMessage('danger', 'Invalid file type. Please upload a JPG, PNG, GIF, or WEBP image.');
                        $target = 'my_courses.php?tab=profile#edit-profile';
                        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                        header("Location: {$target}");
                        exit;
                    }

                    if ($fileSize > 5 * 1024 * 1024) { // 5MB limit
                        setFlashMessage('danger', 'Profile photo exceeds maximum limit of 5MB.');
                        $target = 'my_courses.php?tab=profile#edit-profile';
                        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                        header("Location: {$target}");
                        exit;
                    }

                    $uploadDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $newFileName = 'user_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $destination = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmp, $destination)) {
                        if (!empty($profileImagePath) && file_exists(__DIR__ . '/' . $profileImagePath)) {
                            @unlink(__DIR__ . '/' . $profileImagePath);
                        }
                        $profileImagePath = 'uploads/' . $newFileName;
                    } else {
                        setFlashMessage('danger', 'Failed to save uploaded profile photo.');
                        $target = 'my_courses.php?tab=profile#edit-profile';
                        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                        header("Location: {$target}");
                        exit;
                    }
                } elseif (!empty($_POST['profile_image_cropped']) && preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $_POST['profile_image_cropped'], $b64Match)) {
                    $decodedBytes = base64_decode($b64Match[2], true);
                    if ($decodedBytes !== false && strlen($decodedBytes) <= 5 * 1024 * 1024) {
                        $uploadDir = __DIR__ . '/uploads/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $extOut = ($b64Match[1] === 'jpeg') ? 'jpg' : $b64Match[1];
                        $newFileName = 'user_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extOut;
                        if (file_put_contents($uploadDir . $newFileName, $decodedBytes) !== false) {
                            if (!empty($profileImagePath) && file_exists(__DIR__ . '/' . $profileImagePath)) {
                                @unlink(__DIR__ . '/' . $profileImagePath);
                            }
                            $profileImagePath = 'uploads/' . $newFileName;
                        }
                    }
                }

                // If new password provided, validate and hash it
                if (!empty($newPassword)) {
                    if (strlen($newPassword) < 6) {
                        setFlashMessage('danger', 'New password must be at least 6 characters long.');
                        $target = 'my_courses.php?tab=profile#edit-profile';
                        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                        header("Location: {$target}");
                        exit;
                    }
                    if ($newPassword !== $confirmPassword) {
                        setFlashMessage('danger', 'New passwords do not match.');
                        $target = 'my_courses.php?tab=profile#edit-profile';
                        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
                        header("Location: {$target}");
                        exit;
                    }

                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name, email = :email, phone = :phone, password = :password, profile_image = :profile_image WHERE student_id = :student_id");
                    $stmtUpdate->execute([
                        ':name'          => $name,
                        ':email'         => $email,
                        ':phone'         => $phone,
                        ':password'      => $hashedPassword,
                        ':profile_image' => $profileImagePath,
                        ':student_id'    => $studentId
                    ]);
                } else {
                    $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name, email = :email, phone = :phone, profile_image = :profile_image WHERE student_id = :student_id");
                    $stmtUpdate->execute([
                        ':name'          => $name,
                        ':email'         => $email,
                        ':phone'         => $phone,
                        ':profile_image' => $profileImagePath,
                        ':student_id'    => $studentId
                    ]);
                }

                // Ensure instructors.avatar column exists, then sync Name, Email, Phone, and Profile Photo to matching instructors record
                try {
                    try {
                        $pdo->query("SELECT avatar FROM instructors LIMIT 1");
                    } catch (Exception $colEx) {
                        $pdo->exec("ALTER TABLE `instructors` ADD COLUMN `avatar` VARCHAR(255) DEFAULT NULL AFTER `bio`");
                    }
                    $stmtSync = $pdo->prepare("UPDATE instructors SET name = :name, email = :email, phone = :phone, avatar = COALESCE(NULLIF(:avatar, ''), avatar), profile_image = COALESCE(NULLIF(:profile_image, ''), profile_image) WHERE LOWER(TRIM(email)) = LOWER(TRIM(:curr_email)) OR LOWER(TRIM(email)) = LOWER(TRIM(:new_email)) OR LOWER(TRIM(name)) = LOWER(TRIM(:curr_name)) OR LOWER(TRIM(name)) = LOWER(TRIM(:new_name))");
                    $stmtSync->execute([
                        ':name'          => $name,
                        ':email'         => $email,
                        ':phone'         => $phone,
                        ':avatar'        => $profileImagePath,
                        ':profile_image' => $profileImagePath,
                        ':curr_email'    => $currEmail,
                        ':new_email'     => $email,
                        ':curr_name'     => $currName,
                        ':new_name'      => $name
                    ]);
                } catch (Exception $syncEx) {}

                $_SESSION['student_name'] = $name;
                $_SESSION['student_email'] = $email;
                $_SESSION['student_phone'] = $phone;
                $_SESSION['student_profile_image'] = $profileImagePath;

                setFlashMessage('success', 'Profile information, phone number, and photo updated successfully!');
            }
        } catch (PDOException $e) {
            error_log('Profile update error: ' . $e->getMessage());
            setFlashMessage('danger', 'Unable to update your profile right now. Please try again.');
        }
    }
    $target = 'my_courses.php?tab=profile#edit-profile';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Action 3: POST NOTICE OR DISCOUNT OFFER (For Instructors & Admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_notice') {
    if (!isCurrentUserInstructorOrAdmin($pdo, $studentId)) {
        setFlashMessage('danger', 'Administrator or instructor privileges are required to publish notices.');
        $target = 'my_courses.php';
        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
        header("Location: {$target}");
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['Notice', 'Offer']) ? $_POST['type'] : 'Notice';
    $discountBadge = trim($_POST['discount_badge'] ?? '');

    if (empty($title) || empty($description)) {
        setFlashMessage('danger', 'Title and description are required to post an announcement or offer.');
    } else {
        try {
            // Find instructor_id for the logged-in user if available
            $stmtFindInst = $pdo->prepare("SELECT instructor_id FROM instructors WHERE email = :email OR name = :name LIMIT 1");
            $stmtFindInst->execute([
                ':email' => $_SESSION['student_email'] ?? '',
                ':name'  => $_SESSION['student_name'] ?? ''
            ]);
            $instRow = $stmtFindInst->fetch();
            $assignedInstructorId = $instRow ? (int)$instRow['instructor_id'] : null;

            $courseId = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;

            $stmtAddNotice = $pdo->prepare("INSERT INTO notices (title, description, type, discount_badge, instructor_id, course_id) VALUES (:title, :description, :type, :discount_badge, :instructor_id, :course_id)");
            $stmtAddNotice->execute([
                ':title'          => $title,
                ':description'    => $description,
                ':type'           => $type,
                ':discount_badge' => !empty($discountBadge) ? $discountBadge : ($type === 'Offer' ? 'Special Offer' : 'Instructor Notice'),
                ':instructor_id'  => $assignedInstructorId,
                ':course_id'      => $courseId
            ]);

            setFlashMessage('success', "New <strong>" . htmlspecialchars($type) . "</strong> titled <em>" . htmlspecialchars($title) . "</em> has been published successfully!");
        } catch (PDOException $e) {
            error_log('Add notice error: ' . $e->getMessage());
            setFlashMessage('danger', 'Unable to publish notice right now. Please try again.');
        }
    }
    $target = 'my_courses.php?tab=notices#notices-section';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// Action 4: DELETE NOTICE (For Instructors & Admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_notice') {
    if (!isCurrentUserInstructorOrAdmin($pdo, $studentId)) {
        setFlashMessage('danger', 'Administrator or instructor privileges are required to delete notices.');
        $target = 'my_courses.php';
        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
        header("Location: {$target}");
        exit;
    }

    $noticeId = (int)($_POST['notice_id'] ?? 0);
    $noticeTitle = trim($_POST['notice_title'] ?? "Notice #{$noticeId}");

    if ($noticeId > 0) {
        try {
            $stmtDel = $pdo->prepare("DELETE FROM notices WHERE notice_id = :id");
            $stmtDel->execute([':id' => $noticeId]);
            setFlashMessage('success', "Notice <strong>" . htmlspecialchars($noticeTitle) . "</strong> has been deleted successfully.");
        } catch (PDOException $e) {
            error_log('Delete notice error: ' . $e->getMessage());
            setFlashMessage('danger', 'Unable to delete notice right now. Please try again.');
        }
    }
    $target = 'my_courses.php?tab=notices#notices-section';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// ====================================================================
// FETCH DATA
// ====================================================================

// 1. Fetch Logged-in User Profile details (including phone)
$stmtProfile = $pdo->prepare("SELECT student_id, name, email, phone, role, profile_image, created_at FROM students WHERE student_id = :student_id LIMIT 1");
$stmtProfile->execute([':student_id' => $studentId]);
$profile = $stmtProfile->fetch();

if (!$profile) {
    session_destroy();
    $target = 'login.php';
    echo "<script>window.location.href=" . json_encode($target) . ";</script>";
    header("Location: {$target}");
    exit;
}

// 2. Instructor / Admin Detection
$isAdmin = isAdmin() || (($profile['role'] ?? 'student') === 'admin');

// Match against instructors table by email or name
$stmtInst = $pdo->prepare("SELECT * FROM instructors WHERE email = :email OR name = :name LIMIT 1");
$stmtInst->execute([
    ':email' => $profile['email'],
    ':name'  => $profile['name']
]);
$instructorRecord = $stmtInst->fetch();
$isInstructor = ($isAdmin || !empty($instructorRecord));

// If student phone is empty but instructor phone exists, populate it
if (empty($profile['phone']) && !empty($instructorRecord['phone'])) {
    $profile['phone'] = $instructorRecord['phone'];
}

// 3. Fetch Notices and Offers
$noticesList = [];
$allCoursesForNotices = [];
try {
    $stmtNL = $pdo->query("
        SELECT n.*, i.name AS instructor_name, i.expertise AS instructor_expertise, i.phone AS instructor_phone,
               c.title AS course_title, c.category AS course_category
        FROM notices n
        LEFT JOIN instructors i ON n.instructor_id = i.instructor_id
        LEFT JOIN courses c ON n.course_id = c.course_id
        ORDER BY n.created_at DESC
    ");
    $noticesList = $stmtNL->fetchAll();

    $allCoursesForNotices = $pdo->query("SELECT course_id, title FROM courses ORDER BY title ASC")->fetchAll();
} catch (PDOException $e) {
    $noticesList = [];
    $allCoursesForNotices = [];
}

// 4. Fetch Data Based on User Role
$managedCourses = [];
$pendingEnrollments = [];
$totalAssignedCourses = 0;
$totalEnrolledStudentsCount = 0;
$totalCourseRevenue = 0;
$enrolledCourses = [];
$approvedCourses = [];
$pendingCourses = [];
$rejectedCourses = [];
$newlyApprovedCourses = [];
$totalValue = 0;

if ($isInstructor) {
    $instructorId = $instructorRecord ? (int)$instructorRecord['instructor_id'] : 0;
    $scope = $_GET['scope'] ?? 'assigned';
    $totalPlatformCoursesCount = 0;
    try {
        $totalPlatformCoursesCount = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    } catch (Exception $eCnt) {}

    if ($isAdmin) {
        $pendingEnrollments = getPendingEnrollments($pdo);
    }

    // If scope is 'all', show all platform courses; else show courses taught by this instructor
    if ($scope === 'all' && $isAdmin) {
        $sqlIC = "SELECT c.*, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email
                  FROM courses c
                  LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                  ORDER BY c.course_id ASC";
        $stmtIC = $pdo->query($sqlIC);
        $managedCourses = $stmtIC->fetchAll();
    } else {
        if ($instructorId > 0) {
            $sqlIC = "SELECT c.*, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email
                      FROM courses c
                      LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                      WHERE c.instructor_id = :instructor_id
                      ORDER BY c.course_id ASC";
            $stmtIC = $pdo->prepare($sqlIC);
            $stmtIC->execute([':instructor_id' => $instructorId]);
            $managedCourses = $stmtIC->fetchAll();
        } else {
            // Admin with no direct instructor assignment: show all courses
            $sqlIC = "SELECT c.*, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email
                      FROM courses c
                      LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                      ORDER BY c.course_id ASC";
            $stmtIC = $pdo->query($sqlIC);
            $managedCourses = $stmtIC->fetchAll();
        }
    }

    // Fallback if instructor has no courses yet and is an admin
    if (empty($managedCourses) && $isAdmin) {
        $sqlIC = "SELECT c.*, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email
                  FROM courses c
                  LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                  ORDER BY c.course_id ASC";
        $managedCourses = $pdo->query($sqlIC)->fetchAll();
    }

    // Attach student enrollments for each course separately
    $totalAssignedCourses = count($managedCourses);
    foreach ($managedCourses as &$mCourse) {
        $stmtStudents = $pdo->prepare("
            SELECT s.student_id, s.name, s.email, s.profile_image, 
                   e.enrollment_id, e.payment_method, e.wallet_number, e.transaction_id,
                   COALESCE(NULLIF(e.paid_amount, 0), :course_price) AS paid_amount,
                   LOWER(COALESCE(e.status, 'approved')) AS status, e.enrollment_date, e.dropped_at
            FROM enrollments e
            JOIN students s ON e.student_id = s.student_id
            WHERE e.course_id = :cid
            ORDER BY e.enrollment_date DESC
        ");
        $stmtStudents->execute([
            ':cid'          => $mCourse['course_id'],
            ':course_price' => floatval($mCourse['price'])
        ]);
        $mCourse['students'] = $stmtStudents->fetchAll();
        $approvedCount = 0;
        $droppedCount = 0;
        $courseRevenue = 0.0;
        foreach ($mCourse['students'] as $stuRow) {
            $rowStatus = $stuRow['status'] ?? 'approved';
            if ($rowStatus === 'approved') {
                $approvedCount++;
                $courseRevenue += floatval($stuRow['paid_amount']);
            } elseif ($rowStatus === 'dropped') {
                $droppedCount++;
                // Dropped enrollments remain non-refundable in total collected revenue
                $courseRevenue += floatval($stuRow['paid_amount']);
            }
        }
        $mCourse['student_count'] = $approvedCount;
        $mCourse['dropped_count'] = $droppedCount;
        $mCourse['course_revenue'] = $courseRevenue;

        $totalEnrolledStudentsCount += $approvedCount;
        $totalCourseRevenue += $mCourse['course_revenue'];
    }
    unset($mCourse);

} else {
    // Normal Student: Fetch their active/pending/rejected enrollments (dropped courses are hidden & inactive)
    $sqlEnrolled = "SELECT e.enrollment_id, e.enrollment_date, e.payment_method, e.wallet_number, e.transaction_id,
                           LOWER(COALESCE(e.status, 'approved')) AS status, e.verified_at, COALESCE(e.approval_seen, 1) AS approval_seen,
                           c.course_id, c.title, c.description, c.price, c.category, c.thumbnail,
                           i.instructor_id, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email,
                           COALESCE(
                               NULLIF(i.avatar, ''),
                               NULLIF(i.profile_image, ''),
                               NULLIF(u.profile_image, ''),
                               (SELECT s2.profile_image FROM students s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(i.name)) AND s2.profile_image IS NOT NULL AND s2.profile_image != '' ORDER BY CASE WHEN LOWER(s2.role) IN ('admin', 'instructor') THEN 0 ELSE 1 END, s2.student_id ASC LIMIT 1)
                           ) AS instructor_avatar,
                           i.profile_image AS instructor_profile_image,
                           u.profile_image AS user_profile_image
                    FROM enrollments e
                    INNER JOIN courses c ON e.course_id = c.course_id
                    LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                    LEFT JOIN students u ON LOWER(TRIM(i.email)) = LOWER(TRIM(u.email))
                    WHERE e.student_id = :student_id
                      AND LOWER(COALESCE(e.status, 'approved')) != 'dropped'
                    ORDER BY e.enrollment_date DESC";

    $stmtEnrolled = $pdo->prepare($sqlEnrolled);
    $stmtEnrolled->execute([':student_id' => $studentId]);
    $enrolledCourses = $stmtEnrolled->fetchAll();

    foreach ($enrolledCourses as $item) {
        $st = $item['status'] ?? 'approved';
        if ($st === 'approved') {
            $approvedCourses[] = $item;
            if (!empty($item['verified_at']) && (int)($item['approval_seen'] ?? 0) === 0) {
                $newlyApprovedCourses[] = $item;
            }
        } elseif ($st === 'pending') {
            $pendingCourses[] = $item;
        } else {
            $rejectedCourses[] = $item;
        }
    }
}

$requestedTab = strtolower(trim($_GET['tab'] ?? ''));
$activeTab = 'courses';
if ($requestedTab === 'profile') {
    $activeTab = 'profile';
} elseif ($requestedTab === 'notices' && $isInstructor) {
    $activeTab = 'notices';
}

$pageTitle = $isInstructor ? "Instructor Dashboard & Course Overview" : "My Dashboard & Enrolled Courses";
require_once __DIR__ . '/header.php';
?>

<div class="container py-2">
    <!-- Header Profile & Statistics Banner -->
    <div class="card p-4 mb-4">
        <div class="row align-items-center">
            <div class="col-md-7 d-flex align-items-center">
                <?php if (!empty($profile['profile_image']) && file_exists(__DIR__ . '/' . $profile['profile_image'])): ?>
                    <img src="<?= htmlspecialchars($profile['profile_image']) ?>" alt="<?= htmlspecialchars($profile['name']) ?>" class="rounded-circle object-fit-cover me-3 shadow-sm border border-2" style="width: 72px; height: 72px;">
                <?php else: ?>
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm text-white" style="width: 72px; height: 72px; background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);">
                        <i class="bi bi-person-fill fs-1"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <?php if ($isInstructor): ?>
                        <span class="badge bg-warning-subtle px-2.5 py-1 rounded-pill small mb-1" style="font-weight: 700;">
                            <i class="bi bi-shield-lock-fill me-1"></i> Instructor &bull; <?= $isAdmin ? 'Administrator' : 'Faculty' ?> &bull; ID #<?= (int)$studentId ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-primary-subtle px-2.5 py-1 rounded-pill small mb-1" style="font-weight: 600;">
                            <i class="bi bi-mortarboard-fill me-1"></i> Student Account &bull; ID #<?= (int)$studentId ?>
                        </span>
                    <?php endif; ?>

                    <h3 class="fw-bold text-dark mb-0"><?= htmlspecialchars($profile['name']) ?></h3>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($profile['email']) ?>
                        <?php if (!empty($profile['phone'])): ?>
                            <span class="mx-2">&bull;</span>
                            <span class="text-dark fw-semibold"><i class="bi bi-telephone-fill text-primary me-1"></i><?= htmlspecialchars($profile['phone']) ?></span>
                        <?php endif; ?>
                        <?php if ($instructorRecord && !empty($instructorRecord['expertise'])): ?>
                            <span class="mx-2">&bull;</span>
                            <span class="text-secondary"><i class="bi bi-award me-1"></i><?= htmlspecialchars($instructorRecord['expertise']) ?></span>
                        <?php endif; ?>
                        <span class="mx-2">&bull;</span>
                        <i class="bi bi-calendar3 me-1"></i>Joined <?= formatDateSafe($profile['created_at'] ?? null) ?>
                        <?php if ($isAdmin): ?>
                            <span class="mx-2">&bull;</span>
                            <a href="manage_courses.php" class="fw-bold text-decoration-none text-primary">
                                <i class="bi bi-sliders me-1"></i> Platform Management
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Summary Stats -->
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="d-flex justify-content-md-end gap-3 flex-wrap">
                    <?php if ($isInstructor): ?>
                        <div class="stat-card py-2 px-3 text-center">
                            <span class="text-muted small d-block">Courses Managed</span>
                            <span class="fs-4 fw-bold text-primary"><?= $totalAssignedCourses ?></span>
                        </div>
                        <div class="stat-card py-2 px-3 text-center">
                            <span class="text-muted small d-block">Active Students</span>
                            <span class="fs-4 fw-bold text-info"><?= $totalEnrolledStudentsCount ?></span>
                        </div>
                        <div class="stat-card py-2 px-3 text-center" title="Non-refundable total collected from approved and dropped enrollments">
                            <span class="text-muted small d-block">Course Revenue</span>
                            <span class="fs-4 fw-bold text-success">৳<?= number_format($totalCourseRevenue) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="stat-card py-2 px-3 text-center">
                            <span class="text-muted small d-block">Verified Courses</span>
                            <span class="fs-4 fw-bold text-primary"><?= count($approvedCourses) ?></span>
                        </div>
                        <?php if (count($pendingCourses) > 0): ?>
                            <div class="stat-card py-2 px-3 text-center">
                                <span class="text-muted small d-block">Pending Review</span>
                                <span class="fs-4 fw-bold text-warning"><?= count($pendingCourses) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Nav tabs & Actions -->
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <ul class="nav nav-pills" id="dashboardTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= ($activeTab === 'courses') ? 'active' : '' ?> rounded-pill px-4 fw-semibold me-1" id="main-courses-tab" data-bs-toggle="tab" data-bs-target="#main-courses-section" type="button" role="tab" aria-controls="main-courses-section" aria-selected="<?= ($activeTab === 'courses') ? 'true' : 'false' ?>">
                    <?php if ($isInstructor): ?>
                        <i class="bi bi-book-half me-1"></i> Instructor Course Overview (<?= $totalAssignedCourses ?>)
                    <?php else: ?>
                        <i class="bi bi-journal-check me-1"></i> My Courses (<?= count($approvedCourses) ?> Active<?= count($pendingCourses) > 0 ? ', ' . count($pendingCourses) . ' Pending' : '' ?>)
                    <?php endif; ?>
                </button>
            </li>
            <?php if ($isInstructor): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= ($activeTab === 'notices') ? 'active' : '' ?> rounded-pill px-4 fw-semibold me-1" id="notices-tab" data-bs-toggle="tab" data-bs-target="#notices-section" type="button" role="tab" aria-controls="notices-section" aria-selected="<?= ($activeTab === 'notices') ? 'true' : 'false' ?>">
                        <i class="bi bi-megaphone-fill me-1"></i> Notice & Offer Center <span class="badge bg-warning text-dark rounded-pill ms-1"><?= count($noticesList) ?></span>
                    </button>
                </li>
            <?php endif; ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= ($activeTab === 'profile') ? 'active' : '' ?> rounded-pill px-4 fw-semibold" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-section" type="button" role="tab" aria-controls="profile-section" aria-selected="<?= ($activeTab === 'profile') ? 'true' : 'false' ?>">
                    <i class="bi bi-person-gear me-1"></i> Edit Profile & Settings
                </button>
            </li>
        </ul>

        <?php if ($isInstructor): ?>
            <button type="button" class="btn btn-warning rounded-pill px-3 py-2 fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#postNoticeModal">
                <i class="bi bi-plus-circle-fill"></i> Post Notice / Offer
            </button>
        <?php endif; ?>
    </div>

    <!-- Tab Content -->
    <div class="tab-content" id="dashboardTabsContent">
        
        <!-- TAB 1: COURSES OVERVIEW -->
        <div class="tab-pane fade <?= ($activeTab === 'courses') ? 'show active' : '' ?>" id="main-courses-section" role="tabpanel" aria-labelledby="main-courses-tab">
            <div id="enrolled-courses" style="scroll-margin-top: 96px;"></div>
            
            <?php if ($isInstructor): ?>
                <?php if ($isAdmin): ?>
                    <!-- ADMIN PENDING ENROLLMENT VERIFICATIONS -->
                    <?php renderPendingVerificationsSection($pendingEnrollments, 'my_courses.php'); ?>
                <?php endif; ?>

                <!-- ============================================================== -->
                <!-- INSTRUCTOR & ADMIN VIEW: PER-COURSE STUDENT BREAKDOWN          -->
                <!-- ============================================================== -->
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 pb-2 border-bottom gap-2">
                    <div>
                        <h4 class="fw-bold text-dark mb-0">Course Enrollment & Student Directory</h4>
                        <p class="text-muted small mb-0">Review courses taught and inspect all registered student enrollments per course</p>
                    </div>

                    <?php if ($isAdmin): ?>
                        <div class="d-flex align-items-center gap-2">
                            <div class="btn-group btn-group-sm p-1 spatial-subpanel" role="group">
                                <a href="my_courses.php?scope=assigned" class="btn rounded-pill px-3 fw-semibold <?= (!isset($_GET['scope']) || $_GET['scope'] === 'assigned') ? 'btn-primary' : 'btn-light text-muted' ?>">
                                    <i class="bi bi-person-video3 me-1"></i> My Assigned Courses (<?= $totalAssignedCourses ?>)
                                </a>
                                <a href="my_courses.php?scope=all" class="btn rounded-pill px-3 fw-semibold <?= (isset($_GET['scope']) && $_GET['scope'] === 'all') ? 'btn-primary' : 'btn-light text-muted' ?>">
                                    <i class="bi bi-grid-fill me-1"></i> All Platform Courses (<?= $totalPlatformCoursesCount ?>)
                                </a>
                            </div>

                            <a href="manage_courses.php" class="btn btn-sm btn-outline-warning rounded-pill px-3 fw-semibold" title="Go to Platform Management CRUD Center">
                                <i class="bi bi-sliders me-1"></i> CRUD Center
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($managedCourses)): ?>
                    <div class="card p-5 text-center my-3">
                        <div class="text-muted mb-3">
                            <i class="bi bi-journal-x text-secondary" style="font-size: 3.5rem;"></i>
                        </div>
                        <h4 class="fw-bold text-dark">No courses assigned yet</h4>
                        <p class="text-muted mb-4">You do not have any courses assigned to your instructor profile. Use the Management Center to assign or create courses.</p>
                        <div>
                            <a href="manage_courses.php" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold">
                                <i class="bi bi-plus-circle me-1"></i> Create or Assign Courses
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- List each course as its own distinct card with its students breakdown -->
                    <div class="d-flex flex-column gap-4">
                        <?php foreach ($managedCourses as $c): 
                            $mThumbUrl = getCourseThumbnailUrl($c['title'], $c['thumbnail'] ?? null);
                            list($mHeaderClass, $mIconClass) = getCourseCategoryVisual($c['category'] ?? '');
                        ?>
                            <div class="card overflow-hidden">
                                <!-- Course Card Header -->
                                <div class="p-4 border-bottom public-modal-header">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
                                        <div class="d-flex flex-column flex-sm-row align-items-sm-start gap-3 flex-grow-1">
                                            <div class="course-thumb-wrap instructor-course-thumb <?= $mHeaderClass ?>">
                                                <?php if ($mThumbUrl): ?>
                                                    <img src="<?= htmlspecialchars($mThumbUrl) ?>" alt="<?= htmlspecialchars($c['title']) ?>" class="course-card-thumb" loading="lazy">
                                                    <div class="course-card-scrim"></div>
                                                <?php else: ?>
                                                    <div class="d-flex align-items-center justify-content-center h-100 text-white-50">
                                                        <i class="bi <?= $mIconClass ?> fs-2"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 small">
                                                        <?= htmlspecialchars($c['category']) ?>
                                                    </span>
                                                    <span class="badge bg-secondary-subtle small">
                                                        Course ID #<?= (int)$c['course_id'] ?>
                                                    </span>
                                                    <?php if (isset($_GET['scope']) && $_GET['scope'] === 'all' && !empty($c['instructor_name'])): ?>
                                                        <span class="badge bg-secondary-subtle small">
                                                            <i class="bi bi-person-fill me-1"></i>Instructor: <?= htmlspecialchars($c['instructor_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <h4 class="fw-bold text-dark mb-1">
                                                    <?= htmlspecialchars($c['title']) ?>
                                                </h4>
                                                <p class="text-secondary small mb-0" style="max-width: 780px;">
                                                    <?= htmlspecialchars($c['description']) ?>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="text-md-end flex-shrink-0">
                                            <div class="d-flex align-items-center justify-content-md-end gap-2 mb-2">
                                                <span class="fw-bold text-dark fs-4">
                                                    ৳<?= number_format($c['price']) ?>
                                                </span>
                                                <span class="badge <?= ($c['student_count'] > 0) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle' ?> rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1">
                                                    <i class="bi bi-people-fill"></i>
                                                    <?= $c['student_count'] ?> <?= ($c['student_count'] === 1) ? 'Student Enrolled' : 'Students Enrolled' ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted">
                                                Course Revenue (Non-Refundable): <strong class="text-success">৳<?= number_format($c['course_revenue']) ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Course Card Body: Enrolled Students List -->
                                <div class="p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h6 class="fw-bold text-dark mb-0">
                                            <i class="bi bi-people text-primary me-1"></i> Course Enrollment Records
                                            <span class="text-muted fw-normal small">(<?= $c['student_count'] ?> Active<?= ($c['dropped_count'] ?? 0) > 0 ? ', ' . $c['dropped_count'] . ' Dropped' : '' ?>)</span>
                                        </h6>
                                        <?php if (!empty($c['students'])): ?>
                                            <span class="small text-muted">Real-time enrollment &amp; payment ledger</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (empty($c['students'])): ?>
                                        <!-- Clean Empty State Inside Specific Course Card -->
                                        <div class="p-4 text-center spatial-subpanel my-2">
                                            <i class="bi bi-person-x text-muted fs-2 d-block mb-1"></i>
                                            <div class="fw-semibold text-secondary small">No students enrolled in this course yet</div>
                                            <p class="text-muted small mb-0 mt-1">When students complete enrollment via bKash, Nagad, Rocket, or Card, their details will appear here automatically.</p>
                                        </div>
                                    <?php else: ?>
                                        <!-- Enrolled Students Table -->
                                        <div class="table-responsive border">
                                            <table class="table custom-table table-hover align-middle mb-0 small">
                                                <thead>
                                                    <tr>
                                                        <th class="ps-3 py-2.5">Student Profile</th>
                                                        <th class="py-2.5">Email Address</th>
                                                        <th class="py-2.5">Payment &amp; Status</th>
                                                        <th class="py-2.5">Timeline</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($c['students'] as $st): 
                                                        $pm = $st['payment_method'] ?? 'bKash';
                                                        $pmMeta = getPaymentMethodBadgeMeta($pm);
                                                        $stStatus = $st['status'] ?? 'approved';
                                                        $stStatusClass = $stStatus === 'approved' ? 'bg-success-subtle' : ($stStatus === 'pending' ? 'bg-warning-subtle' : ($stStatus === 'dropped' ? 'status-badge-dropped' : 'bg-danger-subtle'));
                                                        $stStatusLabel = $stStatus === 'approved' ? 'Verified' : ($stStatus === 'pending' ? 'Pending' : ($stStatus === 'dropped' ? 'Dropped' : 'Rejected'));
                                                    ?>
                                                        <tr>
                                                            <td class="ps-3 py-2.5">
                                                                <div class="d-flex align-items-center">
                                                                    <?php if (!empty($st['profile_image']) && file_exists(__DIR__ . '/' . $st['profile_image'])): ?>
                                                                        <img src="<?= htmlspecialchars($st['profile_image']) ?>" alt="<?= htmlspecialchars($st['name']) ?>" class="rounded-circle object-fit-cover me-2 border" style="width: 32px; height: 32px; border-color: #CBD5E1 !important;">
                                                                    <?php else: ?>
                                                                        <div class="instructor-avatar me-2" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                                            <?= strtoupper(substr($st['name'], 0, 1)) ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <div>
                                                                        <span class="fw-bold text-dark d-block"><?= htmlspecialchars($st['name']) ?></span>
                                                                        <small class="text-muted">MAS-STU-<?= str_pad((int)$st['student_id'], 4, '0', STR_PAD_LEFT) ?></small>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="py-2.5">
                                                                <a href="mailto:<?= htmlspecialchars($st['email']) ?>" class="text-decoration-none text-secondary">
                                                                    <i class="bi bi-envelope me-1 text-muted"></i><?= htmlspecialchars($st['email']) ?>
                                                                </a>
                                                            </td>
                                                            <td class="py-2.5">
                                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                                    <span class="badge <?= $pmMeta['class'] ?> enrolled-payment-badge">
                                                                        <?php if ($pmMeta['logo'] !== '' && file_exists(__DIR__ . '/' . $pmMeta['logo'])): ?>
                                                                            <img src="<?= htmlspecialchars($pmMeta['logo']) ?>" alt="<?= htmlspecialchars($pm) ?>" class="enrolled-payment-logo">
                                                                        <?php else: ?>
                                                                            <i class="bi <?= $pmMeta['icon'] ?>"></i>
                                                                        <?php endif; ?>
                                                                        <span><?= htmlspecialchars($pm) ?></span>
                                                                    </span>
                                                                    <span class="badge <?= $stStatusClass ?>" style="font-size: 0.7rem;">
                                                                        <?= $stStatusLabel ?>
                                                                    </span>
                                                                    <span class="fw-semibold text-dark">৳<?= number_format((float)$st['paid_amount']) ?></span>
                                                                </div>
                                                                <?php if (!empty($st['transaction_id']) || !empty($st['wallet_number'])): ?>
                                                                    <small class="d-block text-muted font-monospace mt-1" style="font-size: 0.72rem;">
                                                                        <?php if (!empty($st['wallet_number'])): ?>
                                                                            No: <?= htmlspecialchars($st['wallet_number']) ?> &bull;
                                                                        <?php endif; ?>
                                                                        Trx: <?= htmlspecialchars($st['transaction_id'] ?? 'N/A') ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="py-2.5 text-muted">
                                                                <div><i class="bi bi-clock-history me-1"></i><?= formatDateSafe($st['enrollment_date'] ?? null, 'M d, Y h:i A') ?></div>
                                                                <?php if ($stStatus === 'dropped' && !empty($st['dropped_at'])): ?>
                                                                    <small class="text-danger d-block"><i class="bi bi-calendar-x me-1"></i>Dropped: <?= formatDateSafe($st['dropped_at'] ?? null, 'M d, Y h:i A') ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ============================================================== -->
                <!-- NORMAL STUDENT VIEW: PERSONAL ENROLLED COURSES                 -->
                <!-- ============================================================== -->
                <?php if (!empty($newlyApprovedCourses)): ?>
                    <div class="alert alert-success rounded-4 p-4 mb-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <span class="p-2 rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="bi bi-patch-check-fill fs-5"></i>
                            </span>
                            <div>
                                <h5 class="fw-bold mb-1">Enrollment Approved &amp; Course Access Unlocked!</h5>
                                <p class="mb-0 small">
                                    Welcome to <strong><?= htmlspecialchars(implode(', ', array_column($newlyApprovedCourses, 'title'))) ?></strong>!
                                    An administrator has verified your payment TrxID and granted you full course access.
                                </p>
                            </div>
                        </div>
                        <form method="POST" action="my_courses.php" class="m-0 flex-shrink-0">
                            <input type="hidden" name="action" value="dismiss_approval_notice">
                            <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold">
                                <i class="bi bi-check2 me-1"></i> Got it
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (empty($enrolledCourses)): ?>
                    <div class="card p-5 text-center my-3">
                        <div class="text-muted mb-3">
                            <i class="bi bi-journal-x text-secondary" style="font-size: 3.5rem;"></i>
                        </div>
                        <h4 class="fw-bold text-dark">You haven't enrolled in any courses yet</h4>
                        <p class="text-muted mb-4">Explore our course catalog to find university-level courses taught by top instructors and kickstart your learning journey!</p>
                        <div>
                            <a href="index.php#courses-catalog" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold">
                                <i class="bi bi-compass me-1"></i> Browse Course Catalog
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($enrolledCourses as $course): 
                            $eThumbUrl = getCourseThumbnailUrl($course['title'], $course['thumbnail'] ?? null);
                            $eInstAvatarUrl = getInstructorAvatarUrl($course['instructor_name'] ?? '', [$course['instructor_avatar'] ?? null, $course['instructor_profile_image'] ?? null, $course['user_profile_image'] ?? null], (int)($course['instructor_id'] ?? 0));
                            list($eHeaderClass, $eIconClass) = getCourseCategoryVisual($course['category'] ?? '');
                            $pm = $course['payment_method'] ?? 'bKash';
                            $pmMeta = getPaymentMethodBadgeMeta($pm);
                            $enrollStatus = $course['status'] ?? 'approved';
                            $isVerified = ($enrollStatus === 'approved');
                            $isPending = ($enrollStatus === 'pending');
                            $isRejected = ($enrollStatus === 'rejected');
                            $isNewlyApproved = ($isVerified && !empty($course['verified_at']) && (int)($course['approval_seen'] ?? 0) === 0);
                        ?>
                            <div class="col-lg-6">
                                <div class="card course-card h-100 d-flex flex-column">
                                    <!-- Uniform Course Thumbnail Header -->
                                    <div class="course-card-header <?= $eHeaderClass ?>">
                                        <?php if ($eThumbUrl): ?>
                                            <img src="<?= htmlspecialchars($eThumbUrl) ?>" alt="<?= htmlspecialchars($course['title']) ?>" class="course-card-thumb" loading="lazy">
                                            <div class="course-card-scrim"></div>
                                        <?php else: ?>
                                            <i class="bi <?= $eIconClass ?> course-icon-bg"></i>
                                        <?php endif; ?>
                                        <span class="card-category-badge">
                                            <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($course['category']) ?>
                                        </span>
                                        <span class="badge rounded-pill position-relative d-inline-flex align-items-center gap-1" style="z-index: 2; background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(10px); color: #F8FAFC; font-weight: 600;">
                                            <i class="bi <?= $isVerified ? 'bi-patch-check-fill text-success' : ($isPending ? 'bi-hourglass-split text-warning' : 'bi-x-octagon-fill text-danger') ?>"></i>
                                            <span><?= $isVerified ? 'Verified & Enrolled' : ($isPending ? 'Pending Verification' : 'Verification Rejected') ?></span>
                                        </span>
                                    </div>

                                    <div class="card-body p-4 d-flex flex-column">
                                        <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($course['title']) ?></h5>

                                        <!-- Instructor Details -->
                                        <div class="d-flex align-items-center mb-3 p-2.5 spatial-subpanel">
                                            <div class="instructor-avatar me-2 overflow-hidden" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                <?php if ($eInstAvatarUrl): ?>
                                                    <img src="<?= htmlspecialchars($eInstAvatarUrl) ?>" alt="<?= htmlspecialchars($course['instructor_name'] ?? 'Instructor') ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($course['instructor_name'] ?? 'U', 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small">
                                                <span class="fw-semibold text-dark"><?= htmlspecialchars($course['instructor_name'] ?? 'Unassigned Instructor') ?></span>
                                                <span class="text-muted">&bull; <?= htmlspecialchars($course['instructor_expertise'] ?? 'Specialist') ?></span>
                                            </div>
                                        </div>

                                        <!-- Payment Method, Status & Transaction Reference -->
                                        <div class="mb-3 enrolled-payment-row">
                                            <?php if ($isVerified): ?>
                                                <span class="badge bg-success-subtle enrolled-status-pill">
                                                    <i class="bi bi-patch-check-fill"></i>
                                                    <span>Verified &amp; Enrolled</span>
                                                </span>
                                            <?php elseif ($isPending): ?>
                                                <span class="badge bg-warning-subtle enrolled-status-pill">
                                                    <i class="bi bi-hourglass-split"></i>
                                                    <span>Pending Verification</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle enrolled-status-pill">
                                                    <i class="bi bi-x-octagon-fill"></i>
                                                    <span>Verification Rejected</span>
                                                </span>
                                            <?php endif; ?>

                                            <span class="badge <?= $pmMeta['class'] ?> enrolled-payment-badge">
                                                <?php if ($pmMeta['logo'] !== '' && file_exists(__DIR__ . '/' . $pmMeta['logo'])): ?>
                                                    <img src="<?= htmlspecialchars($pmMeta['logo']) ?>" alt="<?= htmlspecialchars($pm) ?>" class="enrolled-payment-logo">
                                                <?php else: ?>
                                                    <i class="bi <?= $pmMeta['icon'] ?>"></i>
                                                <?php endif; ?>
                                                <span>Paid via <?= htmlspecialchars($pm) ?></span>
                                            </span>

                                            <?php if (!empty($course['transaction_id'])): ?>
                                                <span class="badge bg-secondary-subtle enrolled-trx-pill">
                                                    <i class="bi bi-receipt text-muted"></i>
                                                    <span>TrxID: <code><?= htmlspecialchars($course['transaction_id']) ?></code></span>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($isPending): ?>
                                            <div class="alert spatial-alert-amber small py-2 px-3 mb-3 d-flex align-items-start gap-2">
                                                <i class="bi bi-lock-fill mt-1 flex-shrink-0"></i>
                                                <div>
                                                    <strong>Course Access Locked:</strong> Your payment is pending admin verification. Full course access will unlock automatically once approved.
                                                </div>
                                            </div>
                                        <?php elseif ($isRejected): ?>
                                            <div class="alert alert-danger small py-2 px-3 mb-3 d-flex align-items-start gap-2">
                                                <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
                                                <div>
                                                    <strong>Payment Not Verified:</strong> Your submitted TrxID could not be confirmed. Please resubmit valid payment details.
                                                </div>
                                            </div>
                                        <?php elseif ($isNewlyApproved): ?>
                                            <div class="alert alert-success small py-2 px-3 mb-3 d-flex align-items-start gap-2">
                                                <i class="bi bi-stars mt-1 flex-shrink-0"></i>
                                                <div>
                                                    <strong>Welcome!</strong> Your enrollment has been approved by MAS'S Classroom Admin. Full course access is ready!
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <p class="text-secondary small mb-4 flex-grow-1">
                                            <?= htmlspecialchars(mb_strimwidth($course['description'], 0, 150, '...')) ?>
                                        </p>

                                        <div class="pt-3 border-top d-flex align-items-center justify-content-between mt-auto gap-2 flex-wrap">
                                            <div>
                                                <span class="text-muted small d-block" style="font-size: 0.72rem;"><?= $isVerified ? 'COURSE VALUE' : 'SUBMITTED FEE' ?></span>
                                                <span class="fw-bold text-dark"><?= formatPrice($course['price']) ?></span>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <?php if ($isVerified): ?>
                                                    <button type="button" class="btn btn-success btn-sm rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#accessCourseModal<?= $course['enrollment_id'] ?>">
                                                        <i class="bi bi-unlock-fill me-1"></i> Access Course
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#dropModal<?= $course['enrollment_id'] ?>">
                                                        <i class="bi bi-trash3 me-1"></i> Drop Course
                                                    </button>
                                                <?php elseif ($isPending): ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" disabled title="Course access unlocks after Admin approves your payment">
                                                        <i class="bi bi-lock-fill me-1"></i> Access Locked
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#dropModal<?= $course['enrollment_id'] ?>">
                                                        <i class="bi bi-x-circle me-1"></i> Cancel
                                                    </button>
                                                <?php else: ?>
                                                    <a href="checkout.php?course_id=<?= (int)$course['course_id'] ?>" class="btn btn-warning btn-sm rounded-pill px-3 fw-semibold">
                                                        <i class="bi bi-arrow-repeat me-1"></i> Resubmit Payment
                                                    </a>
                                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#dropModal<?= $course['enrollment_id'] ?>">
                                                        <i class="bi bi-trash3 me-1"></i> Remove
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($isVerified): ?>
                                <!-- Verified Course Access Modal -->
                                <div class="modal fade" id="accessCourseModal<?= $course['enrollment_id'] ?>" tabindex="-1" aria-labelledby="accessModalLabel<?= $course['enrollment_id'] ?>" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header public-modal-header py-3 px-4">
                                                <div>
                                                    <span class="badge bg-success-subtle mb-1">
                                                        <i class="bi bi-patch-check-fill me-1"></i> Verified &amp; Enrolled
                                                    </span>
                                                    <h5 class="modal-title fw-bold text-dark mb-0" id="accessModalLabel<?= $course['enrollment_id'] ?>">
                                                        <?= htmlspecialchars($course['title']) ?>
                                                    </h5>
                                                </div>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="alert alert-success small d-flex align-items-center gap-2 mb-4">
                                                    <i class="bi bi-unlock-fill fs-5 flex-shrink-0"></i>
                                                    <div>
                                                        <strong>Full Course Access Unlocked!</strong> Welcome to the classroom. Your payment (TrxID: <code><?= htmlspecialchars($course['transaction_id'] ?? 'N/A') ?></code>) is verified.
                                                    </div>
                                                </div>

                                                <h6 class="fw-bold text-dark mb-2">Course Syllabus &amp; Learning Overview</h6>
                                                <p class="text-secondary small mb-4" style="line-height: 1.65;">
                                                    <?= nl2br(htmlspecialchars($course['description'])) ?>
                                                </p>

                                                <div class="p-3 spatial-subpanel d-flex flex-wrap align-items-center justify-content-between gap-3">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <div class="instructor-avatar overflow-hidden" style="width: 44px; height: 44px;">
                                                            <?php if ($eInstAvatarUrl): ?>
                                                                <img src="<?= htmlspecialchars($eInstAvatarUrl) ?>" alt="<?= htmlspecialchars($course['instructor_name'] ?? 'Instructor') ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                            <?php else: ?>
                                                                <?= strtoupper(substr($course['instructor_name'] ?? 'U', 0, 1)) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($course['instructor_name'] ?? 'Instructor') ?></div>
                                                            <div class="small text-muted"><?= htmlspecialchars($course['instructor_expertise'] ?? 'Faculty Member') ?></div>
                                                            <?php if (!empty($course['instructor_email'])): ?>
                                                                <a href="mailto:<?= htmlspecialchars($course['instructor_email']) ?>" class="small text-primary text-decoration-none">
                                                                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($course['instructor_email']) ?>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-end small text-muted">
                                                        <div>Enrolled: <strong class="text-dark"><?= formatDateSafe($course['enrollment_date'] ?? null) ?></strong></div>
                                                        <div>Student ID: <strong class="text-primary">MAS-STU-<?= str_pad($studentId, 4, '0', STR_PAD_LEFT) ?></strong></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer public-modal-footer py-3 px-4">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                                <a href="index.php#courses" class="btn btn-primary rounded-pill px-4 fw-semibold">
                                                    <i class="bi bi-compass me-1"></i> Explore More Courses
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Drop Course Confirmation Modal -->
                            <div class="modal fade" id="dropModal<?= $course['enrollment_id'] ?>" tabindex="-1" aria-labelledby="dropModalLabel<?= $course['enrollment_id'] ?>" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header public-modal-header py-3 px-4">
                                            <h5 class="modal-title fw-bold text-danger" id="dropModalLabel<?= $course['enrollment_id'] ?>">
                                                <i class="bi bi-exclamation-triangle-fill me-2"></i>Drop Course Enrollment?
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <p class="mb-2">Are you sure you want to drop and cancel your enrollment in:</p>
                                            <div class="p-3 spatial-subpanel fw-bold text-dark mb-3">
                                                <?= htmlspecialchars($course['title']) ?>
                                            </div>
                                            <p class="text-muted small mb-0">
                                                Dropping this course removes your active access for Student ID <code>MAS-STU-<?= str_pad($studentId, 4, '0', STR_PAD_LEFT) ?></code>. Tuition payments are non-refundable, and your transaction remains archived. You may re-enroll later at any time.
                                            </p>
                                        </div>
                                        <div class="modal-footer public-modal-footer py-3 px-4">
                                            <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Keep Enrolled</button>
                                            <form method="POST" action="my_courses.php" class="m-0">
                                                <input type="hidden" name="action" value="drop_course">
                                                <input type="hidden" name="enrollment_id" value="<?= $course['enrollment_id'] ?>">
                                                <input type="hidden" name="course_title" value="<?= htmlspecialchars($course['title']) ?>">
                                                <button type="submit" class="btn btn-danger rounded-pill px-3">
                                                    <i class="bi bi-trash3 me-1"></i> Confirm & Drop
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>

        <!-- TAB 2: EDIT PROFILE -->
        <div class="tab-pane fade <?= ($activeTab === 'profile') ? 'show active' : '' ?>" id="profile-section" role="tabpanel" aria-labelledby="profile-tab">
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-9">
                    <div class="card p-4 p-md-5" id="edit-profile" style="scroll-margin-top: 96px;">
                        <div class="d-flex align-items-center mb-4">
                            <div class="p-3 bg-primary-subtle text-primary rounded-circle me-3">
                                <i class="bi bi-person-gear fs-3"></i>
                            </div>
                            <div>
                                <h4 class="fw-bold text-dark mb-0">Update Profile Details</h4>
                                <p class="text-muted small mb-0">Update your name, email, profile photo, or change your account password</p>
                            </div>
                        </div>

                        <form method="POST" action="my_courses.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_profile">

                            <!-- Profile Photo Upload -->
                            <div class="p-3 spatial-subpanel mb-4 d-flex align-items-center gap-3">
                                <div id="profilePhotoPreviewWrap" class="flex-shrink-0">
                                    <?php if (!empty($profile['profile_image']) && file_exists(__DIR__ . '/' . $profile['profile_image'])): ?>
                                        <img src="<?= htmlspecialchars($profile['profile_image']) ?>" alt="Profile Photo" class="rounded-circle object-fit-cover shadow-sm border border-2" style="width: 72px; height: 72px;">
                                    <?php else: ?>
                                        <img src="" alt="Profile Photo" class="rounded-circle object-fit-cover shadow-sm border border-2 d-none" style="width: 72px; height: 72px;">
                                        <div class="crop-avatar-placeholder rounded-circle d-flex align-items-center justify-content-center shadow-sm text-white" style="width: 72px; height: 72px; background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);">
                                            <i class="bi bi-person-fill fs-2"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <label for="profile_image" class="form-label fw-semibold small text-dark mb-1">
                                        <i class="bi bi-camera-fill text-primary me-1"></i> Profile Photo
                                    </label>
                                    <input type="hidden" name="profile_image_cropped" id="profile_image_cropped" value="">
                                    <input type="file"
                                           class="form-control form-control-sm"
                                           id="profile_image"
                                           name="profile_image"
                                           accept="image/*"
                                           data-crop-aspect="1:1"
                                           data-crop-width="600"
                                           data-crop-height="600"
                                           data-crop-shape="circle"
                                           data-crop-title="Adjust Profile Photo"
                                           data-preview-target="#profilePhotoPreviewWrap img"
                                           data-base64-target="#profile_image_cropped">
                                    <div class="form-text small text-muted">Upload JPG, PNG, GIF, or WEBP (Max 5MB). Opens pan &amp; zoom cropper before saving.</div>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label for="name" class="form-label fw-semibold small text-muted">Full Name</label>
                                    <div class="auth-input-box auth-input-compact">
                                        <i class="bi bi-person auth-input-icon"></i>
                                        <input type="text" class="form-control auth-input-control" id="name" name="name" value="<?= htmlspecialchars($profile['name']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <label for="email" class="form-label fw-semibold small text-muted">Email Address</label>
                                    <div class="auth-input-box auth-input-compact">
                                        <i class="bi bi-envelope auth-input-icon"></i>
                                        <input type="email" class="form-control auth-input-control" id="email" name="email" value="<?= htmlspecialchars($profile['email']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label for="phone" class="form-label fw-semibold small text-muted">Phone Number</label>
                                    <div class="auth-input-box auth-input-compact">
                                        <i class="bi bi-telephone auth-input-icon"></i>
                                        <input type="text" class="form-control auth-input-control" id="phone" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" placeholder="e.g. 01709016144">
                                    </div>
                                </div>
                            </div>

                            <div class="p-3 spatial-subpanel mb-4">
                                <h6 class="fw-bold text-dark mb-2"><i class="bi bi-shield-lock text-primary me-1"></i> Change Password (Optional)</h6>
                                <p class="text-muted small mb-3">Leave both password fields blank if you do not wish to change your current password.</p>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="new_password" class="form-label fw-semibold small text-muted">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" placeholder="At least 6 characters" minlength="6">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="confirm_new_password" class="form-label fw-semibold small text-muted">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" placeholder="Re-type new password" minlength="6">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end align-items-center pt-3 border-top">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">
                                    <i class="bi bi-check2-circle me-1"></i> Save Profile Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isInstructor): ?>
        <!-- TAB 3: NOTICE & OFFER MANAGEMENT -->
        <div class="tab-pane fade <?= ($activeTab === 'notices') ? 'show active' : '' ?>" id="notices-section" role="tabpanel" aria-labelledby="notices-tab" style="scroll-margin-top: 96px;">
            <div class="card p-4 p-md-5 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between pb-3 mb-4 border-bottom gap-3">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-megaphone-fill text-warning me-2"></i>Platform Notice & Offer Center
                        </h4>
                        <p class="text-muted small mb-0">Publish announcements and time-limited discount offers displayed across the platform and home catalog.</p>
                    </div>
                    <button type="button" class="btn btn-warning rounded-pill px-4 py-2 fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#postNoticeModal">
                        <i class="bi bi-plus-circle-fill"></i> Post New Notice / Offer
                    </button>
                </div>

                <?php if (empty($noticesList)): ?>
                    <div class="p-5 text-center spatial-subpanel my-3">
                        <i class="bi bi-megaphone text-muted fs-1 d-block mb-2"></i>
                        <h5 class="fw-bold text-dark">No notices or offers published yet</h5>
                        <p class="text-muted small mb-3">Create your first course promotion or class notice using the button below.</p>
                        <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#postNoticeModal">
                            <i class="bi bi-plus-circle me-1"></i> Post Now
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($noticesList as $nt): 
                            $isOffer = (strtolower($nt['type'] ?? '') === 'offer');
                            $borderTop = $isOffer ? 'border-top: 4px solid #F59E0B !important;' : 'border-top: 4px solid #4F46E5 !important;';
                        ?>
                            <div class="col-lg-6">
                                <div class="card public-notice-card h-100 p-4" style="<?= $borderTop ?>">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <?php if ($isOffer): ?>
                                                <span class="badge offer-discount-badge rounded-pill">
                                                    <i class="bi bi-tag-fill"></i><span><?= htmlspecialchars(!empty($nt['discount_badge']) ? $nt['discount_badge'] : 'Special Offer') ?></span>
                                                </span>
                                                <?php if (!empty($nt['course_title'])): ?>
                                                    <span class="badge rounded-pill px-3 py-1 small offer-course-pill d-inline-flex align-items-center gap-1">
                                                        <i class="bi bi-book-half"></i><span>Course: <?= htmlspecialchars($nt['course_title']) ?></span>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill px-3 py-1 small offer-course-pill d-inline-flex align-items-center gap-1">
                                                        <i class="bi bi-tags-fill"></i><span>All Courses</span>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1">
                                                    <i class="bi bi-bell-fill"></i><span><?= htmlspecialchars($nt['discount_badge'] ?? 'Notice') ?></span>
                                                </span>
                                                <?php if (!empty($nt['course_title'])): ?>
                                                    <span class="badge rounded-pill px-3 py-1 small notice-course-pill d-inline-flex align-items-center gap-1">
                                                        <i class="bi bi-book-half"></i><span>Course: <?= htmlspecialchars($nt['course_title']) ?></span>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill px-3 py-1 small notice-course-pill d-inline-flex align-items-center gap-1">
                                                        <i class="bi bi-megaphone"></i><span>Instructor Notice</span>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Delete Action Button -->
                                        <form method="POST" action="my_courses.php" onsubmit="return confirm('Are you sure you want to delete this notice?');" class="m-0">
                                            <input type="hidden" name="action" value="delete_notice">
                                            <input type="hidden" name="notice_id" value="<?= (int)$nt['notice_id'] ?>">
                                            <input type="hidden" name="notice_title" value="<?= htmlspecialchars($nt['title']) ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-circle p-1 d-flex align-items-center justify-content-center" title="Delete Notice" style="width: 34px; height: 34px;">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($nt['title']) ?></h5>
                                    <p class="text-secondary small mb-3 flex-grow-1" style="white-space: pre-line;">
                                        <?= htmlspecialchars($nt['description']) ?>
                                    </p>

                                    <div class="pt-3 border-top d-flex align-items-center justify-content-between small text-muted gap-2">
                                        <div class="modal-instructor-meta">
                                            <i class="bi bi-person-badge-fill text-primary modal-instructor-icon"></i>
                                            <span>Instructor: <strong class="instructor-name-highlight text-dark"><?= htmlspecialchars($nt['instructor_name'] ?? "MAS'S Classroom Instructor") ?></strong></span>
                                        </div>
                                        <div>
                                            <i class="bi bi-clock me-1"></i><?= formatDateSafe($nt['created_at'] ?? null) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($isInstructor): ?>
<!-- Post Notice / Offer Modal -->
<div class="modal fade" id="postNoticeModal" tabindex="-1" aria-labelledby="postNoticeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="p-2 rounded-circle bg-warning-subtle text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                        <i class="bi bi-megaphone-fill fs-5"></i>
                    </span>
                    <div>
                        <span class="badge bg-warning-subtle text-warning mb-1" style="font-size: 0.68rem;">INSTRUCTOR BROADCAST</span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="postNoticeModalLabel">Post New Notice or Discount Offer</h5>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="my_courses.php" class="m-0">
                <input type="hidden" name="action" value="add_notice">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="notice_type" class="form-label fw-semibold small text-muted">Post Category / Type</label>
                            <select name="type" id="notice_type" class="form-select" required>
                                <option value="Notice">Instructor Notice</option>
                                <option value="Offer">Discount Offer</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="notice_course_id" class="form-label fw-semibold small text-muted">Related Course (Optional)</label>
                            <select name="course_id" id="notice_course_id" class="form-select">
                                <option value="">General / All Courses</option>
                                <?php foreach ($allCoursesForNotices as $ac): ?>
                                    <option value="<?= (int)$ac['course_id'] ?>">
                                        <?= htmlspecialchars($ac['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="discount_badge" class="form-label fw-semibold small text-muted">Badge / Tag (e.g. 20% OFF, Exam)</label>
                            <input type="text" name="discount_badge" id="discount_badge" class="form-control" placeholder="e.g. 25% OFF, ৳500 OFF, Exam">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notice_title" class="form-label fw-semibold small text-muted">Title / Heading</label>
                        <input type="text" name="title" id="notice_title" class="form-control" placeholder="e.g. Mid-Term Special Discount - 25% OFF on Web & Database Courses" required>
                    </div>

                    <div class="mb-2">
                        <label for="notice_description" class="form-label fw-semibold small text-muted">Detailed Description / Offer Details</label>
                        <textarea name="description" id="notice_description" class="form-control" rows="4" placeholder="Write full details about the offer, eligible courses, coupons, or academic requirements..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer public-modal-footer p-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">
                        <i class="bi bi-send-fill me-1"></i> Publish Notice / Offer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var tabParam = (params.get('tab') || '').toLowerCase();
    var hash = (window.location.hash || '').toLowerCase();

    var targetTabSelector = null;
    var scrollTargetSelector = null;

    if (tabParam === 'profile' || hash === '#edit-profile' || hash === '#profile-section') {
        targetTabSelector = '#profile-tab';
        scrollTargetSelector = '#edit-profile';
    } else if (tabParam === 'notices' || hash === '#notices-section') {
        targetTabSelector = '#notices-tab';
        scrollTargetSelector = '#notices-section';
    } else if (tabParam === 'courses' || hash === '#enrolled-courses' || hash === '#main-courses-section') {
        targetTabSelector = '#main-courses-tab';
        scrollTargetSelector = '#enrolled-courses';
    }

    function scrollTargetIntoView() {
        if (!scrollTargetSelector) return;
        var el = document.querySelector(scrollTargetSelector);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    if (targetTabSelector) {
        var tabTrigger = document.querySelector(targetTabSelector);
        if (tabTrigger && window.bootstrap && bootstrap.Tab) {
            if (!tabTrigger.classList.contains('active')) {
                tabTrigger.addEventListener('shown.bs.tab', scrollTargetIntoView, { once: true });
                bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
            } else {
                setTimeout(scrollTargetIntoView, 60);
            }
        } else {
            setTimeout(scrollTargetIntoView, 60);
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
