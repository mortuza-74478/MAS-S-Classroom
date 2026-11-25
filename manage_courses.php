<?php
/**
 * Admin / Course & Instructor Management (Live CRUD)
 * MAS'S Classroom - University DBMS Project
 * Features: INSERT, SELECT, UPDATE, DELETE for courses & instructors
 */
require_once __DIR__ . '/db.php';
requireAdmin();

// Ensure any legacy Unicode multiplication sign (×) in courses.thumbnail is normalized to ASCII x
try {
    $pdo->exec("UPDATE courses SET thumbnail = REPLACE(thumbnail, '×', 'x') WHERE thumbnail LIKE '%×%'");
} catch (Exception $eThumbNorm) {}

/**
 * Save an uploaded or base64-cropped 1280x720 course thumbnail inside images/courses/.
 */
function handleCourseThumbnailUpload(string $courseTitle, ?array $fileEntry, ?string $base64Data): ?string {
    $exactMap = getCourseThumbnailFilenameMap();
    $titleKey = strtolower(trim($courseTitle));

    $destRelPath = $exactMap[$titleKey] ?? null;
    if ($destRelPath === null) {
        $cleanSpaces = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[\\\\\/:*?"<>|&]/', ' ', $courseTitle)));
        $baseName = $cleanSpaces !== '' ? str_replace(' ', '_', $cleanSpaces) : ('course_' . time());
        $destRelPath = 'images/courses/' . $baseName . '_1280x720.png';
    }

    $destAbsDir = __DIR__ . '/images/courses';
    if (!is_dir($destAbsDir)) {
        mkdir($destAbsDir, 0755, true);
    }

    if ($fileEntry && ($fileEntry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo((string)$fileEntry['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) && ($fileEntry['size'] ?? 0) <= 8 * 1024 * 1024) {
            if (move_uploaded_file($fileEntry['tmp_name'], __DIR__ . '/' . $destRelPath)) {
                return $destRelPath;
            }
        }
    } elseif ($base64Data && preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $base64Data, $m)) {
        $bytes = base64_decode($m[2], true);
        if ($bytes !== false && strlen($bytes) <= 8 * 1024 * 1024) {
            if (file_put_contents(__DIR__ . '/' . $destRelPath, $bytes) !== false) {
                return $destRelPath;
            }
        }
    }

    return null;
}

/**
 * Save an uploaded or base64-cropped 1:1 instructor avatar inside images/instructors/.
 */
function handleInstructorAvatarUpload(string $instructorName, ?array $fileEntry, ?string $base64Data, ?string $existingAvatar = null): ?string {
    $destAbsDir = __DIR__ . '/images/instructors';
    if (!is_dir($destAbsDir)) {
        mkdir($destAbsDir, 0755, true);
    }

    $cleanSpaces = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[\\\\\/:*?"<>|&]/', ' ', $instructorName)));
    $slug = $cleanSpaces !== '' ? str_replace(' ', '_', $cleanSpaces) : 'instructor';
    $savedRelPath = null;

    if ($fileEntry && ($fileEntry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo((string)$fileEntry['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) && ($fileEntry['size'] ?? 0) <= 5 * 1024 * 1024) {
            $newFileName = $slug . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            if (move_uploaded_file($fileEntry['tmp_name'], $destAbsDir . '/' . $newFileName)) {
                $savedRelPath = 'images/instructors/' . $newFileName;
            }
        }
    } elseif ($base64Data && preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $base64Data, $m)) {
        $bytes = base64_decode($m[2], true);
        if ($bytes !== false && strlen($bytes) <= 5 * 1024 * 1024) {
            $extOut = ($m[1] === 'jpeg') ? 'jpg' : $m[1];
            $newFileName = $slug . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $extOut;
            if (file_put_contents($destAbsDir . '/' . $newFileName, $bytes) !== false) {
                $savedRelPath = 'images/instructors/' . $newFileName;
            }
        }
    }

    if ($savedRelPath !== null && !empty($existingAvatar)) {
        $oldClean = ltrim(str_replace('\\', '/', $existingAvatar), './');
        if ((strpos($oldClean, 'images/instructors/') === 0 || strpos($oldClean, 'uploads/') === 0) && is_file(__DIR__ . '/' . $oldClean)) {
            @unlink(__DIR__ . '/' . $oldClean);
        }
    }

    return $savedRelPath;
}

/**
 * Optionally record notice/offer deletion events in audit_logs if that table exists.
 */
function logNoticeAuditIfSupported(PDO $pdo, string $actionName, string $details): void {
    try {
        $hasAudit = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn();
        if ($hasAudit) {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (action, details, performed_by, created_at) VALUES (:action, :details, :actor, NOW())");
            $stmt->execute([
                ':action'  => $actionName,
                ':details' => $details,
                ':actor'   => $_SESSION['student_email'] ?? ($_SESSION['student_name'] ?? 'admin')
            ]);
        }
    } catch (Exception $e) {}
}

// ====================================================================
// HANDLE ACTIONS (POST)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----------------------------------------------------------------
    // 1. ADD COURSE (INSERT)
    // ----------------------------------------------------------------
    if ($action === 'add_course') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
        $category = trim($_POST['category'] ?? 'General');
        $thumbnailInput = str_replace(['×', '&times;'], 'x', trim($_POST['thumbnail'] ?? ''));
        $uploadedThumb = handleCourseThumbnailUpload($title, $_FILES['thumbnail_file'] ?? null, $_POST['thumbnail_cropped'] ?? null);
        $resolvedThumbnail = $uploadedThumb ?? resolveCourseThumbnailFilePath($title, $thumbnailInput !== '' ? $thumbnailInput : null);

        if (empty($title) || empty($description)) {
            setFlashMessage('danger', 'Course title and description are required.');
        } elseif ($price < 0) {
            setFlashMessage('danger', 'Course tuition price cannot be negative.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO courses (title, description, price, instructor_id, category, thumbnail) VALUES (:title, :description, :price, :instructor_id, :category, :thumbnail)");
                $stmt->execute([
                    ':title'         => $title,
                    ':description'   => $description,
                    ':price'         => $price,
                    ':instructor_id' => $instructor_id,
                    ':category'      => $category,
                    ':thumbnail'     => $resolvedThumbnail
                ]);
                setFlashMessage('success', "Course <strong>" . htmlspecialchars($title) . "</strong> has been created successfully (INSERT executed).");
            } catch (PDOException $e) {
                error_log('Add course error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to add course right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#courses-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 2. UPDATE COURSE (UPDATE)
    // ----------------------------------------------------------------
    if ($action === 'edit_course') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
        $category = trim($_POST['category'] ?? 'General');
        $thumbnailInput = str_replace(['×', '&times;'], 'x', trim($_POST['thumbnail'] ?? ''));
        $uploadedThumb = handleCourseThumbnailUpload($title, $_FILES['thumbnail_file'] ?? null, $_POST['thumbnail_cropped'] ?? null);
        $resolvedThumbnail = $uploadedThumb ?? resolveCourseThumbnailFilePath($title, $thumbnailInput !== '' ? $thumbnailInput : null);

        if ($course_id <= 0 || empty($title) || empty($description)) {
            setFlashMessage('danger', 'Invalid course details provided for update.');
        } elseif ($price < 0) {
            setFlashMessage('danger', 'Course tuition price cannot be negative.');
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE courses SET title = :title, description = :description, price = :price, instructor_id = :instructor_id, category = :category, thumbnail = :thumbnail WHERE course_id = :course_id");
                $stmt->execute([
                    ':title'         => $title,
                    ':description'   => $description,
                    ':price'         => $price,
                    ':instructor_id' => $instructor_id,
                    ':category'      => $category,
                    ':thumbnail'     => $resolvedThumbnail,
                    ':course_id'     => $course_id
                ]);
                setFlashMessage('success', "Course #{$course_id} <strong>" . htmlspecialchars($title) . "</strong> updated successfully (UPDATE executed).");
            } catch (PDOException $e) {
                error_log('Update course error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to update course right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#courses-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 3. DELETE COURSE (DELETE)
    // ----------------------------------------------------------------
    if ($action === 'delete_course') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $course_title = trim($_POST['course_title'] ?? "Course #{$course_id}");

        if ($course_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM courses WHERE course_id = :course_id");
                $stmt->execute([':course_id' => $course_id]);
                setFlashMessage('success', "Course <strong>" . htmlspecialchars($course_title) . "</strong> and associated enrollments deleted successfully (DELETE executed with CASCADE).");
            } catch (PDOException $e) {
                error_log('Delete course error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to delete course right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#courses-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 4. ADD INSTRUCTOR (INSERT)
    // ----------------------------------------------------------------
    if ($action === 'add_instructor') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $expertise = trim($_POST['expertise'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if (empty($name) || empty($email) || empty($expertise)) {
            setFlashMessage('danger', 'Instructor name, email, and expertise are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage('danger', 'Please provide a valid instructor email address.');
        } else {
            try {
                $avatarFile = $_FILES['avatar'] ?? ($_FILES['profile_image'] ?? null);
                $avatarB64 = $_POST['avatar_cropped'] ?? ($_POST['profile_image_cropped'] ?? null);
                $instructorAvatarPath = handleInstructorAvatarUpload($name, $avatarFile, $avatarB64);

                $stmt = $pdo->prepare("INSERT INTO instructors (name, email, phone, expertise, bio, avatar, profile_image) VALUES (:name, :email, :phone, :expertise, :bio, :avatar, :profile_image)");
                $stmt->execute([
                    ':name'          => $name,
                    ':email'         => $email,
                    ':phone'         => $phone,
                    ':expertise'     => $expertise,
                    ':bio'           => $bio,
                    ':avatar'        => $instructorAvatarPath,
                    ':profile_image' => $instructorAvatarPath
                ]);
                setFlashMessage('success', "Instructor <strong>" . htmlspecialchars($name) . "</strong> added successfully (INSERT executed).");
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    setFlashMessage('danger', 'An instructor with this email address already exists.');
                } else {
                    error_log('Add instructor error: ' . $e->getMessage());
                    setFlashMessage('danger', 'Unable to add instructor right now. Please try again.');
                }
            }
        }
        header('Location: manage_courses.php#instructors-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 4B. EDIT INSTRUCTOR (UPDATE)
    // ----------------------------------------------------------------
    if ($action === 'edit_instructor') {
        $instructor_id = (int)($_POST['instructor_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $expertise = trim($_POST['expertise'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if ($instructor_id <= 0 || empty($name) || empty($email) || empty($expertise)) {
            setFlashMessage('danger', 'Instructor ID, name, email, and expertise are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage('danger', 'Please provide a valid instructor email address.');
        } else {
            try {
                $stmtCheck = $pdo->prepare("SELECT instructor_id FROM instructors WHERE email = :email AND instructor_id != :id LIMIT 1");
                $stmtCheck->execute([':email' => $email, ':id' => $instructor_id]);
                if ($stmtCheck->fetch()) {
                    setFlashMessage('danger', 'Another instructor already uses this email address.');
                } else {
                    $stmtCurr = $pdo->prepare("SELECT avatar, profile_image FROM instructors WHERE instructor_id = :id LIMIT 1");
                    $stmtCurr->execute([':id' => $instructor_id]);
                    $currInst = $stmtCurr->fetch();
                    $currentAvatar = $currInst['avatar'] ?: ($currInst['profile_image'] ?? null);

                    $avatarFile = $_FILES['avatar'] ?? ($_FILES['profile_image'] ?? null);
                    $avatarB64 = $_POST['avatar_cropped'] ?? ($_POST['profile_image_cropped'] ?? null);
                    $newAvatar = handleInstructorAvatarUpload($name, $avatarFile, $avatarB64, $currentAvatar);
                    $finalAvatar = $newAvatar ?? $currentAvatar;

                    $stmtUpd = $pdo->prepare("UPDATE instructors SET name = :name, email = :email, phone = :phone, expertise = :expertise, bio = :bio, avatar = :avatar, profile_image = :profile_image WHERE instructor_id = :id");
                    $stmtUpd->execute([
                        ':name'          => $name,
                        ':email'         => $email,
                        ':phone'         => $phone,
                        ':expertise'     => $expertise,
                        ':bio'           => $bio,
                        ':avatar'        => $finalAvatar,
                        ':profile_image' => $finalAvatar,
                        ':id'            => $instructor_id
                    ]);

                    // Keep matching students account profile_image synced if an account shares this email
                    if ($newAvatar !== null) {
                        try {
                            $pdo->prepare("UPDATE students SET profile_image = :img WHERE email = :email")->execute([
                                ':img'   => $newAvatar,
                                ':email' => $email
                            ]);
                        } catch (Exception $eSync) {}
                    }

                    setFlashMessage('success', "Instructor #{$instructor_id} <strong>" . htmlspecialchars($name) . "</strong> updated successfully (UPDATE executed).");
                }
            } catch (PDOException $e) {
                error_log('Edit instructor error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to update instructor right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#instructors-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 5. DELETE INSTRUCTOR (DELETE)
    // ----------------------------------------------------------------
    if ($action === 'delete_instructor') {
        $instructor_id = (int)($_POST['instructor_id'] ?? 0);
        $instructor_name = trim($_POST['instructor_name'] ?? "Instructor #{$instructor_id}");

        if ($instructor_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM instructors WHERE instructor_id = :instructor_id");
                $stmt->execute([':instructor_id' => $instructor_id]);
                setFlashMessage('success', "Instructor <strong>" . htmlspecialchars($instructor_name) . "</strong> deleted successfully (Linked courses set to NULL via ON DELETE SET NULL).");
            } catch (PDOException $e) {
                error_log('Delete instructor error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to delete instructor right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#instructors-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 6. ADD STUDENT (INSERT)
    // ----------------------------------------------------------------
    if ($action === 'add_student') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? 'password123';
        $role = in_array($_POST['role'] ?? '', ['admin', 'student']) ? $_POST['role'] : 'student';

        if (empty($name) || empty($email)) {
            setFlashMessage('danger', 'Student name and email are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage('danger', 'Please provide a valid email address.');
        } elseif (strlen($password) < 6) {
            setFlashMessage('danger', 'Initial password must be at least 6 characters long.');
        } else {
            try {
                // Check if email already exists
                $stmtCheck = $pdo->prepare("SELECT student_id FROM students WHERE email = :email");
                $stmtCheck->execute([':email' => $email]);
                if ($stmtCheck->fetch()) {
                    setFlashMessage('danger', 'A student with this email address already exists.');
                } else {
                    $profileImagePath = null;
                    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['profile_image']['tmp_name'];
                        $fileName = $_FILES['profile_image']['name'];
                        $fileSize = $_FILES['profile_image']['size'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                        if (in_array($fileExt, $allowedExts) && $fileSize <= 5 * 1024 * 1024) {
                            $uploadDir = __DIR__ . '/uploads/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $newFileName = 'student_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                            if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                                $profileImagePath = 'uploads/' . $newFileName;
                            }
                        }
                    } elseif (!empty($_POST['profile_image_cropped']) && preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $_POST['profile_image_cropped'], $b64Match)) {
                        $decodedBytes = base64_decode($b64Match[2], true);
                        if ($decodedBytes !== false && strlen($decodedBytes) <= 5 * 1024 * 1024) {
                            $uploadDir = __DIR__ . '/uploads/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $extOut = ($b64Match[1] === 'jpeg') ? 'jpg' : $b64Match[1];
                            $newFileName = 'student_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extOut;
                            if (file_put_contents($uploadDir . $newFileName, $decodedBytes) !== false) {
                                $profileImagePath = 'uploads/' . $newFileName;
                            }
                        }
                    }

                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO students (name, email, phone, role, password, profile_image) VALUES (:name, :email, :phone, :role, :password, :profile_image)");
                    $stmt->execute([
                        ':name'          => $name,
                        ':email'         => $email,
                        ':phone'         => $phone !== '' ? $phone : null,
                        ':role'          => $role,
                        ':password'      => $hashedPassword,
                        ':profile_image' => $profileImagePath
                    ]);
                    setFlashMessage('success', "Student <strong>" . htmlspecialchars($name) . "</strong> (" . ucfirst($role) . ") created successfully (INSERT executed).");
                }
            } catch (PDOException $e) {
                error_log('Add student error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to add student right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#students-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 7. EDIT STUDENT (UPDATE)
    // ----------------------------------------------------------------
    if ($action === 'edit_student') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'student']) ? $_POST['role'] : 'student';
        $newPassword = $_POST['new_password'] ?? '';

        if ($student_id <= 0 || empty($name) || empty($email)) {
            setFlashMessage('danger', 'Student ID, name, and email are required for update.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage('danger', 'Please enter a valid email address.');
        } elseif (!empty($newPassword) && strlen($newPassword) < 6) {
            setFlashMessage('danger', 'New password must be at least 6 characters long.');
        } else {
            try {
                // Check if email taken by someone else
                $stmtCheck = $pdo->prepare("SELECT student_id FROM students WHERE email = :email AND student_id != :student_id");
                $stmtCheck->execute([
                    ':email'      => $email,
                    ':student_id' => $student_id
                ]);
                if ($stmtCheck->fetch()) {
                    setFlashMessage('danger', 'The email address is already in use by another student.');
                } else {
                    $stmtCurr = $pdo->prepare("SELECT name, email, phone, profile_image FROM students WHERE student_id = :id");
                    $stmtCurr->execute([':id' => $student_id]);
                    $currRow = $stmtCurr->fetch();
                    $profileImagePath = $currRow['profile_image'] ?? null;

                    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['profile_image']['tmp_name'];
                        $fileName = $_FILES['profile_image']['name'];
                        $fileSize = $_FILES['profile_image']['size'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                        if (in_array($fileExt, $allowedExts) && $fileSize <= 5 * 1024 * 1024) {
                            $uploadDir = __DIR__ . '/uploads/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $newFileName = 'student_' . $student_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                            if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                                if (!empty($profileImagePath) && file_exists(__DIR__ . '/' . $profileImagePath)) {
                                    @unlink(__DIR__ . '/' . $profileImagePath);
                                }
                                $profileImagePath = 'uploads/' . $newFileName;
                            }
                        }
                    } elseif (!empty($_POST['profile_image_cropped']) && preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $_POST['profile_image_cropped'], $b64Match)) {
                        $decodedBytes = base64_decode($b64Match[2], true);
                        if ($decodedBytes !== false && strlen($decodedBytes) <= 5 * 1024 * 1024) {
                            $uploadDir = __DIR__ . '/uploads/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $extOut = ($b64Match[1] === 'jpeg') ? 'jpg' : $b64Match[1];
                            $newFileName = 'student_' . $student_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extOut;
                            if (file_put_contents($uploadDir . $newFileName, $decodedBytes) !== false) {
                                if (!empty($profileImagePath) && file_exists(__DIR__ . '/' . $profileImagePath)) {
                                    @unlink(__DIR__ . '/' . $profileImagePath);
                                }
                                $profileImagePath = 'uploads/' . $newFileName;
                            }
                        }
                    }

                    if (!empty($newPassword)) {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name, email = :email, phone = :phone, role = :role, password = :password, profile_image = :profile_image WHERE student_id = :student_id");
                        $stmtUpdate->execute([
                            ':name'          => $name,
                            ':email'         => $email,
                            ':phone'         => $phone !== '' ? $phone : null,
                            ':role'          => $role,
                            ':password'      => $hashedPassword,
                            ':profile_image' => $profileImagePath,
                            ':student_id'    => $student_id
                        ]);
                    } else {
                        $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name, email = :email, phone = :phone, role = :role, profile_image = :profile_image WHERE student_id = :student_id");
                        $stmtUpdate->execute([
                            ':name'          => $name,
                            ':email'         => $email,
                            ':phone'         => $phone !== '' ? $phone : null,
                            ':role'          => $role,
                            ':profile_image' => $profileImagePath,
                            ':student_id'    => $student_id
                        ]);
                    }

                    // Keep matching instructors row synced when an instructor/admin account is edited
                    try {
                        $pdo->prepare("UPDATE instructors SET name = :name, email = :email, avatar = COALESCE(NULLIF(:avatar, ''), avatar), profile_image = COALESCE(NULLIF(:profile_image, ''), profile_image) WHERE LOWER(TRIM(email)) = LOWER(TRIM(:curr_email)) OR LOWER(TRIM(email)) = LOWER(TRIM(:new_email)) OR LOWER(TRIM(name)) = LOWER(TRIM(:curr_name))")
                            ->execute([
                                ':name'          => $name,
                                ':email'         => $email,
                                ':avatar'        => $profileImagePath,
                                ':profile_image' => $profileImagePath,
                                ':curr_email'    => $currRow['email'] ?? '',
                                ':new_email'     => $email,
                                ':curr_name'     => $currRow['name'] ?? ''
                            ]);
                    } catch (Exception $eInstSync) {}

                    // If currently logged-in user edited themselves, update session
                    if (isset($_SESSION['student_id']) && (int)$_SESSION['student_id'] === $student_id) {
                        $_SESSION['student_name'] = $name;
                        $_SESSION['student_email'] = $email;
                        $_SESSION['student_phone'] = $phone;
                        $_SESSION['role'] = $role;
                        $_SESSION['student_profile_image'] = $profileImagePath;
                    }

                    setFlashMessage('success', "Student #{$student_id} <strong>" . htmlspecialchars($name) . "</strong> updated successfully (UPDATE executed).");
                }
            } catch (PDOException $e) {
                error_log('Edit student error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to update student record right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#students-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 8. DELETE STUDENT (DELETE)
    // ----------------------------------------------------------------
    if ($action === 'delete_student') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $student_name = trim($_POST['student_name'] ?? "Student #{$student_id}");

        if ($student_id > 0) {
            try {
                // Begin transaction to ensure relational delete integrity
                $pdo->beginTransaction();

                // 1. Remove profile photo if present
                $stmtImg = $pdo->prepare("SELECT profile_image FROM students WHERE student_id = :id");
                $stmtImg->execute([':id' => $student_id]);
                $imgRow = $stmtImg->fetch();
                if (!empty($imgRow['profile_image']) && file_exists(__DIR__ . '/' . $imgRow['profile_image'])) {
                    @unlink(__DIR__ . '/' . $imgRow['profile_image']);
                }

                // 2. Delete linked enrollments first (ensures foreign key constraints never block deletion)
                $stmtDelEnr = $pdo->prepare("DELETE FROM enrollments WHERE student_id = :student_id");
                $stmtDelEnr->execute([':student_id' => $student_id]);

                // 3. Delete student record
                $stmt = $pdo->prepare("DELETE FROM students WHERE student_id = :student_id");
                $stmt->execute([':student_id' => $student_id]);

                // Commit the transaction
                $pdo->commit();

                // If logged in student deleted their own account, clear and restart session cleanly for flash display
                if (isset($_SESSION['student_id']) && (int)$_SESSION['student_id'] === $student_id) {
                    $_SESSION = [];
                    session_destroy();
                    session_start();
                }

                setFlashMessage('success', "Student <strong>" . htmlspecialchars($student_name) . "</strong> and all linked enrollment records deleted successfully (DELETE executed inside transaction).");
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Delete student error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to delete student record right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#students-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 9A. COMPOSE NOTICE (INSERT Notice)
    // ----------------------------------------------------------------
    if ($action === 'compose_notice') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $targetLabel = trim($_POST['discount_badge'] ?? 'All Students');
        $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
        $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;

        if (empty($title) || empty($description)) {
            setFlashMessage('danger', 'Notice title and message are required.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO notices (title, description, type, discount_badge, discount_percent, offer_price, instructor_id, course_id)
                                       VALUES (:title, :description, 'Notice', :badge, NULL, NULL, :inst_id, :course_id)");
                $stmt->execute([
                    ':title'       => $title,
                    ':description' => $description,
                    ':badge'       => $targetLabel !== '' ? $targetLabel : 'All Students',
                    ':inst_id'     => $instructor_id,
                    ':course_id'   => $course_id
                ]);
                setFlashMessage('success', "Notice <strong>" . htmlspecialchars($title) . "</strong> published successfully!");
            } catch (PDOException $e) {
                error_log('Compose notice error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to publish notice right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#notices-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 9B. COMPOSE OFFER (INSERT Offer with Calculated Price)
    // ----------------------------------------------------------------
    if ($action === 'compose_offer') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $discountPercent = floatval($_POST['discount_percent'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($course_id <= 0 || $discountPercent <= 0 || $discountPercent > 99 || empty($title) || empty($description)) {
            setFlashMessage('danger', 'Please select a course, enter a valid discount percentage (1-99%), and fill out offer title and description.');
        } else {
            try {
                $stmtCourseInfo = $pdo->prepare("SELECT course_id, price, instructor_id FROM courses WHERE course_id = :cid LIMIT 1");
                $stmtCourseInfo->execute([':cid' => $course_id]);
                $courseRow = $stmtCourseInfo->fetch();

                if (!$courseRow) {
                    setFlashMessage('danger', 'Selected course was not found.');
                } else {
                    $basePrice = (float)$courseRow['price'];
                    $pctLabel = (fmod($discountPercent, 1.0) === 0.0)
                        ? (int)$discountPercent
                        : rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.');
                    $offerPrice = max(0, round($basePrice - (($basePrice * $discountPercent) / 100), 2));
                    $discountBadge = $pctLabel . '% OFF';
                    $instId = !empty($courseRow['instructor_id']) ? (int)$courseRow['instructor_id'] : null;

                    $stmt = $pdo->prepare("INSERT INTO notices (title, description, type, discount_badge, discount_percent, offer_price, instructor_id, course_id)
                                           VALUES (:title, :description, 'Offer', :badge, :discount_percent, :offer_price, :inst_id, :course_id)");
                    $stmt->execute([
                        ':title'            => $title,
                        ':description'      => $description,
                        ':badge'            => $discountBadge,
                        ':discount_percent' => $discountPercent,
                        ':offer_price'      => $offerPrice,
                        ':inst_id'          => $instId,
                        ':course_id'        => $course_id
                    ]);
                    setFlashMessage('success', "Course Offer <strong>" . htmlspecialchars($title) . "</strong> (" . htmlspecialchars($discountBadge) . " &bull; Offer Price ৳" . number_format($offerPrice) . ") published!");
                }
            } catch (PDOException $e) {
                error_log('Compose offer error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to publish course offer right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#notices-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 9C. ADD NOTICE / OFFER (LEGACY FALLBACK INSERT)
    // ----------------------------------------------------------------
    if ($action === 'add_notice') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['Notice', 'Offer']) ? $_POST['type'] : 'Notice';
        $discountBadge = trim($_POST['discount_badge'] ?? '');
        $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
        $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;

        if (empty($title) || empty($description)) {
            setFlashMessage('danger', 'Notice title and description are required.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO notices (title, description, type, discount_badge, instructor_id, course_id) VALUES (:title, :description, :type, :badge, :inst_id, :course_id)");
                $stmt->execute([
                    ':title'       => $title,
                    ':description' => $description,
                    ':type'        => $type,
                    ':badge'       => !empty($discountBadge) ? $discountBadge : null,
                    ':inst_id'     => $instructor_id,
                    ':course_id'   => $course_id
                ]);
                setFlashMessage('success', "Notice / Offer <strong>" . htmlspecialchars($title) . "</strong> created successfully (INSERT executed).");
            } catch (PDOException $e) {
                error_log('Add notice fallback error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to add notice right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#notices-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 10. DELETE NOTICE / OFFER (SINGLE & BULK DELETE)
    // ----------------------------------------------------------------
    if ($action === 'delete_notice') {
        $notice_id = (int)($_POST['notice_id'] ?? 0);
        if ($notice_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM notices WHERE notice_id = :id");
                $stmt->execute([':id' => $notice_id]);
                if ($stmt->rowCount() > 0) {
                    logNoticeAuditIfSupported($pdo, 'delete_notice', "Deleted notice/offer ID #{$notice_id}");
                }
                setFlashMessage('success', "Notice / Offer #{$notice_id} deleted successfully (DELETE executed).");
            } catch (PDOException $e) {
                error_log('Delete notice error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to delete notice right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#notices-tab');
        exit;
    }

    if ($action === 'bulk_delete_notices') {
        $rawIds = $_POST['notice_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), function ($id) {
            return $id > 0;
        })));
        $scopeLabel = (($_POST['bulk_scope'] ?? '') === 'offers') ? 'offer(s)' : 'notice(s)';

        if (empty($ids)) {
            setFlashMessage('warning', 'Please select at least one item to delete.');
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM notices WHERE notice_id IN ({$placeholders})");
                $stmt->execute($ids);
                $deletedCount = $stmt->rowCount();
                logNoticeAuditIfSupported($pdo, 'bulk_delete_notices', "Bulk deleted {$deletedCount} {$scopeLabel}: IDs " . implode(', ', $ids));
                setFlashMessage('success', "Deleted <strong>{$deletedCount}</strong> selected {$scopeLabel} successfully.");
            } catch (PDOException $e) {
                error_log('Bulk delete notices error: ' . $e->getMessage());
                setFlashMessage('danger', 'Unable to delete selected items right now. Please try again.');
            }
        }
        header('Location: manage_courses.php#notices-tab');
        exit;
    }

    // ----------------------------------------------------------------
    // 11. ENROLLMENT PAYMENT VERIFICATION (APPROVE / REJECT)
    // ----------------------------------------------------------------
    if ($action === 'approve_enrollment' || $action === 'reject_enrollment') {
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $decision = ($action === 'approve_enrollment') ? 'approved' : 'rejected';
        processEnrollmentVerification($pdo, $decision, $enrollmentId);
        header('Location: manage_courses.php#pending-verifications');
        exit;
    }
}

// ====================================================================
// FETCH DATA (SELECT QUERIES WITH AGGREGATIONS & JOINS)
// ====================================================================

// Fetch All Courses with Instructor Name & Verified Enrolled Student Count
$sqlCourses = "SELECT c.course_id, c.title, c.description, c.price, c.category, c.thumbnail, c.created_at,
                      c.instructor_id, i.name AS instructor_name, i.email AS instructor_email,
                      SUM(CASE WHEN LOWER(COALESCE(e.status, 'approved')) = 'approved' THEN 1 ELSE 0 END) AS enrolled_count
               FROM courses c
               LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
               LEFT JOIN enrollments e ON c.course_id = e.course_id
               GROUP BY c.course_id
               ORDER BY c.course_id DESC";
$courses = $pdo->query($sqlCourses)->fetchAll();

// Fetch All Instructors with Course Count
$sqlInstructors = "SELECT i.instructor_id, i.name, i.email, i.phone, i.expertise, i.bio,
                          COALESCE(
                              NULLIF(i.avatar, ''),
                              NULLIF(i.profile_image, ''),
                              NULLIF(MAX(u.profile_image), ''),
                              (SELECT s2.profile_image FROM students s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(i.name)) AND s2.profile_image IS NOT NULL AND s2.profile_image != '' ORDER BY CASE WHEN LOWER(s2.role) IN ('admin', 'instructor') THEN 0 ELSE 1 END, s2.student_id ASC LIMIT 1)
                          ) AS avatar,
                          i.profile_image,
                          MAX(u.profile_image) AS user_profile_image,
                          i.created_at,
                          COUNT(c.course_id) AS course_count
                   FROM instructors i
                   LEFT JOIN courses c ON i.instructor_id = c.instructor_id
                   LEFT JOIN students u ON LOWER(TRIM(i.email)) = LOWER(TRIM(u.email))
                   GROUP BY i.instructor_id
                   ORDER BY i.instructor_id ASC";
$instructors = $pdo->query($sqlInstructors)->fetchAll();

// Fetch All Students with Verified Enrolled Courses Count (via LEFT JOIN on enrollments)
// Filter strictly to role = 'student' so admin/instructor accounts are excluded from the registered students directory
$sqlStudents = "SELECT s.student_id, s.name, s.email, s.phone, s.role, s.profile_image, s.created_at,
                       SUM(CASE WHEN LOWER(COALESCE(e.status, 'approved')) = 'approved' THEN 1 ELSE 0 END) AS enrolled_courses_count
                FROM students s
                LEFT JOIN enrollments e ON s.student_id = e.student_id
                WHERE LOWER(COALESCE(s.role, 'student')) = 'student'
                GROUP BY s.student_id
                ORDER BY s.student_id ASC";
$students = $pdo->query($sqlStudents)->fetchAll();

// Fetch Pending Enrollment Verifications & Complete Audit History
$pendingEnrollments = getPendingEnrollments($pdo);
$pendingEnrollmentsCount = count($pendingEnrollments);
$enrollmentHistory = getAllEnrollmentHistory($pdo);
$totalNonRefundableRevenue = getTotalNonRefundableRevenue($pdo);

// Fetch Notices and Offers
try {
    $notices = $pdo->query("
        SELECT n.*, i.name AS instructor_name, i.expertise AS instructor_expertise,
               c.title AS course_title, c.category AS course_category, c.price AS course_price
        FROM notices n
        LEFT JOIN instructors i ON n.instructor_id = i.instructor_id
        LEFT JOIN courses c ON n.course_id = c.course_id
        ORDER BY n.created_at DESC, n.notice_id DESC
    ")->fetchAll();
} catch (Exception $e) {
    $notices = [];
}

$activeCourseOffers = getActiveCourseOffers($pdo);
$postedOffersList = array_values(array_filter($notices, function ($n) {
    return strtolower((string)($n['type'] ?? '')) === 'offer';
}));
$postedNoticesList = array_values(array_filter($notices, function ($n) {
    return strtolower((string)($n['type'] ?? '')) !== 'offer';
}));

$loggedInEmail = strtolower(trim((string)($_SESSION['student_email'] ?? '')));
$loggedInName = strtolower(trim((string)($_SESSION['student_name'] ?? '')));
$defaultAdminInstructorId = 0;
foreach ($instructors as $instItem) {
    if (strtolower(trim((string)$instItem['email'])) === $loggedInEmail || strtolower(trim((string)$instItem['name'])) === $loggedInName) {
        $defaultAdminInstructorId = (int)$instItem['instructor_id'];
        break;
    }
}
if ($defaultAdminInstructorId === 0 && !empty($instructors)) {
    $defaultAdminInstructorId = (int)$instructors[0]['instructor_id'];
}

// Fetch System KPI Aggregates (verified active enrollments)
$totalCoursesCount = count($courses);
$totalInstructorsCount = count($instructors);
$totalStudentsCount = count($students);
$totalEnrollmentsCount = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE LOWER(COALESCE(status, 'approved')) = 'approved'")->fetchColumn();

$pageTitle = "Platform Management - Live DBMS CRUD";
require_once __DIR__ . '/header.php';
?>

<div class="container py-2">
    <!-- Header Banner -->
    <div class="card p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill small mb-2">
                    <i class="bi bi-shield-lock-fill me-1"></i> Live Demonstration Panel
                </span>
                <h2 class="fw-bold text-dark mb-1">Platform Management & CRUD Center</h2>
                <p class="text-muted small mb-0">Demonstrating Full Relational Database Operations (INSERT, SELECT with JOIN, UPDATE, DELETE) across all 5 tables.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-lg-end gap-2">
                <button type="button" class="btn btn-primary admin-action-btn" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                    <i class="bi bi-plus-circle-fill"></i><span>Add Course</span>
                </button>
                <button type="button" class="btn btn-outline-primary admin-action-btn" data-bs-toggle="modal" data-bs-target="#addInstructorModal">
                    <i class="bi bi-person-plus-fill"></i><span>Add Instructor</span>
                </button>
                <button type="button" class="btn btn-outline-secondary admin-action-btn" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                    <i class="bi bi-mortarboard-fill"></i><span>Add Student</span>
                </button>
                <button type="button" class="btn btn-outline-info admin-action-btn" data-bs-toggle="modal" data-bs-target="#composeNoticeModal">
                    <i class="bi bi-megaphone-fill"></i><span>Compose Notice</span>
                </button>
                <button type="button" class="btn btn-warning admin-action-btn" data-bs-toggle="modal" data-bs-target="#composeOfferModal">
                    <i class="bi bi-tag-fill"></i><span>Compose Offer</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Live DBMS Aggregate Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold mb-1">
                    <i class="bi bi-table text-primary me-1"></i> courses
                </div>
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <h3 class="fw-bold mb-0 text-dark"><?= $totalCoursesCount ?></h3>
                    <span class="badge bg-primary-subtle text-primary">SELECT COUNT</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold mb-1">
                    <i class="bi bi-table text-success me-1"></i> instructors
                </div>
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <h3 class="fw-bold mb-0 text-dark"><?= $totalInstructorsCount ?></h3>
                    <span class="badge bg-success-subtle text-success">SELECT COUNT</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold mb-1">
                    <i class="bi bi-table text-info me-1"></i> students
                </div>
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <h3 class="fw-bold mb-0 text-dark"><?= $totalStudentsCount ?></h3>
                    <span class="badge bg-info-subtle text-info">SELECT COUNT</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl">
            <div class="stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold mb-1">
                    <i class="bi bi-table text-warning me-1"></i> active enrollments
                </div>
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <h3 class="fw-bold mb-0 text-dark"><?= $totalEnrollmentsCount ?></h3>
                    <?php if ($pendingEnrollmentsCount > 0): ?>
                        <span class="badge bg-warning-subtle"><?= $pendingEnrollmentsCount ?> Pending</span>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis">Verified</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl">
            <div class="stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold mb-1">
                    <i class="bi bi-cash-coin text-success me-1"></i> total revenue
                </div>
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <h3 class="fw-bold mb-0 text-success">৳<?= number_format($totalNonRefundableRevenue) ?></h3>
                    <span class="badge bg-success-subtle">Non-Refundable</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Enrollment Verifications Section -->
    <?php renderPendingVerificationsSection($pendingEnrollments, 'manage_courses.php'); ?>

    <!-- Tabs for Course Management, Instructor Management, Student Management, and Schema Inspector -->
    <ul class="nav nav-pills mb-4" id="manageTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill px-4 fw-semibold me-1" id="courses-tab" data-bs-toggle="tab" data-bs-target="#courses-panel" type="button" role="tab" aria-controls="courses-panel" aria-selected="true">
                <i class="bi bi-journal-text me-1"></i> Manage Courses (<?= $totalCoursesCount ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-semibold me-1" id="instructors-tab" data-bs-toggle="tab" data-bs-target="#instructors-panel" type="button" role="tab" aria-controls="instructors-panel" aria-selected="false">
                <i class="bi bi-people-fill me-1"></i> Manage Instructors (<?= $totalInstructorsCount ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-semibold me-1" id="students-tab" data-bs-toggle="tab" data-bs-target="#students-panel" type="button" role="tab" aria-controls="students-panel" aria-selected="false">
                <i class="bi bi-mortarboard-fill me-1"></i> Manage Students (<?= $totalStudentsCount ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-semibold me-1" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-panel" type="button" role="tab" aria-controls="history-panel" aria-selected="false">
                <i class="bi bi-clock-history me-1"></i> Enrollment History (<?= count($enrollmentHistory) ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-semibold me-1" id="notices-tab" data-bs-toggle="tab" data-bs-target="#notices-panel" type="button" role="tab" aria-controls="notices-panel" aria-selected="false">
                <i class="bi bi-megaphone-fill me-1"></i> Notices &amp; Offers (<?= count($notices) ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-semibold" id="schema-tab" data-bs-toggle="tab" data-bs-target="#schema-panel" type="button" role="tab" aria-controls="schema-panel" aria-selected="false">
                <i class="bi bi-diagram-3-fill me-1"></i> Relational Schema & SQL
            </button>
        </li>
    </ul>

    <!-- Tab Contents -->
    <div class="tab-content" id="manageTabsContent">
        <!-- ------------------------------------------------------------- -->
        <!-- TAB 1: COURSES MANAGEMENT TABLE (CRUD: READ, UPDATE, DELETE)   -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade show active" id="courses-panel" role="tabpanel" aria-labelledby="courses-tab">
            <div class="card overflow-hidden mb-4">
                <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">Course Catalog Records (<code>courses</code>)</h5>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                        <i class="bi bi-plus-lg me-1"></i> Add New Course
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Course Title</th>
                                <th>Instructor</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Enrolled</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No courses found in database. Click "Add New Course" to create one.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c):
                                    $cThumbUrl = getCourseThumbnailUrl($c['title'], $c['thumbnail'] ?? null);
                                    $cResolvedPath = resolveCourseThumbnailFilePath($c['title'], $c['thumbnail'] ?? null) ?? '';
                                    list($cHeaderClass, $cIconClass) = getCourseCategoryVisual($c['category'] ?? '');
                                    $cOffer = getOfferForCourse($c['course_id'], $c['title'], $activeCourseOffers);
                                    $cDiscount = $cOffer
                                        ? calculateCourseDiscount($c['price'], $cOffer['discount_badge'] ?? '', $cOffer['discount_percent'] ?? null, $cOffer['offer_price'] ?? null)
                                        : ['has_discount' => false, 'discount_amount' => 0, 'discounted_price' => $c['price'], 'badge' => ''];
                                ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted">#<?= $c['course_id'] ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="course-thumb-wrap admin-course-thumb <?= $cHeaderClass ?>">
                                                    <?php if ($cThumbUrl): ?>
                                                        <img src="<?= htmlspecialchars($cThumbUrl) ?>" alt="<?= htmlspecialchars($c['title']) ?>" class="course-card-thumb" loading="lazy">
                                                    <?php else: ?>
                                                        <div class="d-flex align-items-center justify-content-center h-100 text-white-50">
                                                            <i class="bi <?= $cIconClass ?> fs-6"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($c['title']) ?></div>
                                                    <small class="text-muted text-truncate d-block" style="max-width: 280px;">
                                                        <?= htmlspecialchars(mb_strimwidth($c['description'], 0, 75, '...')) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($c['instructor_name'])): ?>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="bi bi-person-fill text-primary me-1"></i><?= htmlspecialchars($c['instructor_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                <?= htmlspecialchars($c['category']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($cDiscount['has_discount']): ?>
                                                <div class="d-flex align-items-baseline gap-1 flex-wrap">
                                                    <del class="small strikethrough-old-price"><?= formatPrice($c['price']) ?></del>
                                                    <span class="fw-bold text-success"><?= formatPrice($cDiscount['discounted_price']) ?></span>
                                                </div>
                                                <span class="badge bg-warning-subtle mt-1" style="font-size: 0.68rem;">
                                                    <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($cDiscount['badge']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="fw-bold text-dark"><?= formatPrice($c['price']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                <i class="bi bi-person-check-fill me-1"></i><?= $c['enrolled_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group btn-group-sm">
                                                <!-- Edit Modal Trigger -->
                                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editCourseModal<?= $c['course_id'] ?>" title="Edit Course (UPDATE)">
                                                    <i class="bi bi-pencil-square text-primary"></i> Edit
                                                </button>
                                                <!-- Delete Modal Trigger -->
                                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteCourseModal<?= $c['course_id'] ?>" title="Delete Course (DELETE)">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 2: INSTRUCTORS MANAGEMENT (CRUD: READ, INSERT, DELETE)      -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="instructors-panel" role="tabpanel" aria-labelledby="instructors-tab">
            <div class="card overflow-hidden mb-4">
                <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">Instructors Directory (<code>instructors</code>)</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addInstructorModal">
                        <i class="bi bi-person-plus-fill me-1"></i> Add Instructor
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Instructor Name</th>
                                <th>Email</th>
                                <th>Expertise Domain</th>
                                <th>Courses Teaching</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($instructors as $inst):
                                $instRowAvatar = getInstructorAvatarUrl($inst['name'], [$inst['avatar'] ?? null, $inst['profile_image'] ?? null, $inst['user_profile_image'] ?? null], (int)$inst['instructor_id']);
                            ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-muted">#<?= $inst['instructor_id'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="instructor-avatar me-2 overflow-hidden" style="width: 32px; height: 32px;">
                                                <?php if ($instRowAvatar): ?>
                                                    <img src="<?= htmlspecialchars($instRowAvatar) ?>" alt="<?= htmlspecialchars($inst['name']) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($inst['name'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($inst['name']) ?></span>
                                        </div>
                                    </td>
                                    <td><span class="text-muted small"><?= htmlspecialchars($inst['email']) ?></span></td>
                                    <td><span class="badge bg-secondary-subtle"><?= htmlspecialchars($inst['expertise']) ?></span></td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary">
                                            <?= $inst['course_count'] ?> <?= $inst['course_count'] == 1 ? 'course' : 'courses' ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editInstructorModal<?= $inst['instructor_id'] ?>" title="Edit Instructor (UPDATE)">
                                                <i class="bi bi-pencil-square text-primary"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteInstModal<?= $inst['instructor_id'] ?>" title="Delete Instructor">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 3: STUDENTS MANAGEMENT (CRUD: READ, INSERT, UPDATE, DELETE) -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="students-panel" role="tabpanel" aria-labelledby="students-tab">
            <div class="card overflow-hidden mb-4">
                <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">Registered Students Directory (<code>students</code>)</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                        <i class="bi bi-person-plus-fill me-1"></i> Add Student
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Student Name</th>
                                <th>Email Address</th>
                                <th>Role</th>
                                <th>Enrolled Courses</th>
                                <th>Registered Date</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No students found in database. Click "Add Student" to create one.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $st): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted">#<?= $st['student_id'] ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($st['profile_image']) && file_exists(__DIR__ . '/' . $st['profile_image'])): ?>
                                                    <img src="<?= htmlspecialchars($st['profile_image']) ?>" alt="Avatar" class="rounded-circle object-fit-cover me-2 border border-2" style="width: 32px; height: 32px;">
                                                <?php else: ?>
                                                    <div class="instructor-avatar me-2" style="width: 32px; height: 32px; background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%); color: #FFFFFF; font-size: 0.85rem;">
                                                        <?= strtoupper(substr($st['name'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($st['name']) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="text-muted small"><?= htmlspecialchars($st['email']) ?></span>
                                        </td>
                                        <td>
                                            <?php if (($st['role'] ?? 'student') === 'admin'): ?>
                                                <span class="badge bg-warning-subtle"><i class="bi bi-shield-lock-fill me-1"></i>Admin</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle"><i class="bi bi-person me-1"></i>Student</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary">
                                                <i class="bi bi-journal-check me-1"></i><?= $st['enrolled_courses_count'] ?> <?= $st['enrolled_courses_count'] == 1 ? 'course' : 'courses' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-muted small">
                                                <i class="bi bi-calendar3 me-1"></i><?= formatDateSafe($st['created_at'] ?? null) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group btn-group-sm">
                                                <!-- Edit Student Modal Trigger -->
                                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editStudentModal<?= $st['student_id'] ?>" title="Edit Student (UPDATE)">
                                                    <i class="bi bi-pencil-square text-primary"></i> Edit
                                                </button>
                                                <!-- Delete Student Modal Trigger -->
                                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteStudentModal<?= $st['student_id'] ?>" title="Delete Student (DELETE)">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 4: ENROLLMENT HISTORY (FULL AUDIT LOG)                    -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="history-panel" role="tabpanel" aria-labelledby="history-tab">
            <div class="card overflow-hidden mb-4">
                <div class="card-header py-3 px-4 public-modal-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">
                            <i class="bi bi-journal-text text-primary me-2"></i>Enrollment History (Full Audit Log)
                        </h5>
                        <div class="text-muted small">
                            Permanent ledger of all enrollment requests, verified payments, dropped enrollments, and rejected attempts.
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="badge bg-success-subtle rounded-pill px-3 py-2 fw-bold">
                            <i class="bi bi-cash-stack me-1"></i>Collected (Non-Refundable): ৳<?= number_format($totalNonRefundableRevenue) ?>
                        </span>
                    </div>
                </div>

                <!-- Search & Filter Toolbar -->
                <div class="p-3 px-4 border-bottom">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-5">
                            <div class="auth-input-box auth-input-compact">
                                <i class="bi bi-search auth-input-icon"></i>
                                <input type="text" id="historySearchInput" class="form-control auth-input-control" placeholder="Search student, email, course, wallet, or TrxID...">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <select id="historyStatusFilter" class="form-select form-select-sm" style="height: 44px; border-radius: var(--radius-control);">
                                <option value="all">All Statuses</option>
                                <option value="approved">Approved</option>
                                <option value="pending">Pending</option>
                                <option value="dropped">Dropped</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <select id="historyMethodFilter" class="form-select form-select-sm" style="height: 44px; border-radius: var(--radius-control);">
                                <option value="all">All Payment Methods</option>
                                <option value="bKash">bKash</option>
                                <option value="Nagad">Nagad</option>
                                <option value="Rocket">Rocket</option>
                                <option value="Debit / Credit Card">Debit / Credit Card</option>
                            </select>
                        </div>
                        <div class="col-md-1 text-md-end">
                            <span id="historyVisibleCount" class="badge bg-secondary-subtle px-2 py-2 w-100 text-center">
                                <?= count($enrollmentHistory) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table custom-table table-hover align-middle mb-0 small" id="enrollmentHistoryTable">
                        <thead>
                            <tr>
                                <th class="ps-4 py-3">Enrollment ID</th>
                                <th class="py-3">Student</th>
                                <th class="py-3">Course &amp; Instructor</th>
                                <th class="py-3">Payment &amp; TrxID</th>
                                <th class="py-3">Paid Amount</th>
                                <th class="py-3">Status</th>
                                <th class="pe-4 py-3">Timeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($enrollmentHistory)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No enrollment transactions have been recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($enrollmentHistory as $eh):
                                    $ehStatus = $eh['status'] ?? 'approved';
                                    $ehBadgeClass = 'bg-success-subtle';
                                    $ehBadgeIcon = 'bi-patch-check-fill';
                                    $ehBadgeLabel = 'Approved';
                                    if ($ehStatus === 'pending') {
                                        $ehBadgeClass = 'bg-warning-subtle';
                                        $ehBadgeIcon = 'bi-hourglass-split';
                                        $ehBadgeLabel = 'Pending';
                                    } elseif ($ehStatus === 'dropped') {
                                        $ehBadgeClass = 'status-badge-dropped';
                                        $ehBadgeIcon = 'bi-box-arrow-left';
                                        $ehBadgeLabel = 'Dropped';
                                    } elseif ($ehStatus === 'rejected') {
                                        $ehBadgeClass = 'bg-danger-subtle';
                                        $ehBadgeIcon = 'bi-x-octagon-fill';
                                        $ehBadgeLabel = 'Rejected';
                                    }

                                    $ehPm = $eh['payment_method'] ?? 'bKash';
                                    $ehPmMeta = getPaymentMethodBadgeMeta($ehPm);
                                    $ehWallet = trim((string)($eh['wallet_number'] ?? ''));
                                    if ($ehWallet === '') {
                                        $ehWallet = trim((string)($eh['student_phone'] ?? ''));
                                    }
                                    $ehStuCode = 'MAS-STU-' . str_pad((int)$eh['student_id'], 4, '0', STR_PAD_LEFT);
                                    $searchHaystack = strtolower(
                                        '#' . $eh['enrollment_id'] . ' ' .
                                        $eh['student_name'] . ' ' .
                                        $eh['student_email'] . ' ' .
                                        $ehStuCode . ' ' .
                                        $eh['course_title'] . ' ' .
                                        ($eh['instructor_name'] ?? '') . ' ' .
                                        $ehPm . ' ' .
                                        $ehWallet . ' ' .
                                        ($eh['transaction_id'] ?? '')
                                    );
                                ?>
                                    <tr class="history-audit-row"
                                        data-search="<?= htmlspecialchars($searchHaystack) ?>"
                                        data-status="<?= htmlspecialchars($ehStatus) ?>"
                                        data-method="<?= htmlspecialchars($ehPm) ?>">
                                        <td class="ps-4 py-3 fw-bold text-dark font-monospace">
                                            #<?= (int)$eh['enrollment_id'] ?>
                                        </td>
                                        <td class="py-3">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($eh['student_name']) ?></div>
                                            <div class="text-muted" style="font-size: 0.76rem;">
                                                <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($eh['student_email']) ?>
                                            </div>
                                            <span class="badge bg-primary-subtle mt-1" style="font-size: 0.68rem;">
                                                <?= htmlspecialchars($ehStuCode) ?>
                                            </span>
                                        </td>
                                        <td class="py-3">
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($eh['course_title']) ?></div>
                                            <div class="text-muted" style="font-size: 0.76rem;">
                                                <i class="bi bi-person-workspace me-1"></i><?= htmlspecialchars($eh['instructor_name'] ?? 'Unassigned') ?>
                                            </div>
                                        </td>
                                        <td class="py-3">
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <span class="badge <?= $ehPmMeta['class'] ?> enrolled-payment-badge">
                                                    <?php if ($ehPmMeta['logo'] !== '' && file_exists(__DIR__ . '/' . $ehPmMeta['logo'])): ?>
                                                        <img src="<?= htmlspecialchars($ehPmMeta['logo']) ?>" alt="<?= htmlspecialchars($ehPm) ?>" class="enrolled-payment-logo">
                                                    <?php else: ?>
                                                        <i class="bi <?= $ehPmMeta['icon'] ?>"></i>
                                                    <?php endif; ?>
                                                    <span><?= htmlspecialchars($ehPm) ?></span>
                                                </span>
                                                <?php if (!empty($eh['transaction_id'])): ?>
                                                    <span class="badge bg-secondary-subtle enrolled-trx-pill">
                                                        <code><?= htmlspecialchars($eh['transaction_id']) ?></code>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted font-monospace" style="font-size: 0.74rem;">
                                                <i class="bi bi-wallet2 me-1"></i><?= $ehWallet !== '' ? htmlspecialchars($ehWallet) : 'N/A' ?>
                                            </div>
                                        </td>
                                        <td class="py-3">
                                            <div class="fw-bold text-dark">৳<?= number_format((float)$eh['paid_amount']) ?></div>
                                            <?php if (in_array($ehStatus, ['approved', 'dropped'], true)): ?>
                                                <span class="badge bg-success-subtle mt-1" style="font-size: 0.68rem;">Non-Refundable</span>
                                            <?php elseif ($ehStatus === 'pending'): ?>
                                                <span class="badge bg-warning-subtle mt-1" style="font-size: 0.68rem;">Unverified</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle mt-1" style="font-size: 0.68rem;">Not Collected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3">
                                            <span class="badge <?= $ehBadgeClass ?> enrolled-status-pill">
                                                <i class="bi <?= $ehBadgeIcon ?>"></i>
                                                <span><?= $ehBadgeLabel ?></span>
                                            </span>
                                        </td>
                                        <td class="pe-4 py-3 text-muted" style="font-size: 0.76rem;">
                                            <div>
                                                <i class="bi bi-calendar-plus me-1"></i>Enrolled: <?= formatDateSafe($eh['enrollment_date'] ?? null, 'M d, Y h:i A') ?>
                                            </div>
                                            <?php if (!empty($eh['verified_at']) && $ehStatus === 'approved'): ?>
                                                <div class="text-success mt-1">
                                                    <i class="bi bi-patch-check me-1"></i>Verified: <?= formatDateSafe($eh['verified_at'] ?? null, 'M d, Y h:i A') ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($eh['dropped_at'])): ?>
                                                <div class="text-danger mt-1 fw-semibold">
                                                    <i class="bi bi-calendar-x me-1"></i>Dropped: <?= formatDateSafe($eh['dropped_at'] ?? null, 'M d, Y h:i A') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="historyNoMatchesRow" class="d-none">
                                    <td colspan="7" class="text-center py-4 text-muted">No enrollment records match the selected filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 5: NOTICES & COURSE OFFERS MANAGEMENT                     -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="notices-panel" role="tabpanel" aria-labelledby="notices-tab">
            <div class="card p-4 mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-4 border-bottom">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="bi bi-megaphone-fill text-primary me-2"></i>Notices &amp; Course Offers Manager
                        </h5>
                        <p class="text-muted small mb-0">Publish instructor announcements or percentage-based course discount offers displayed across catalog and checkout.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-info admin-action-btn" data-bs-toggle="modal" data-bs-target="#composeNoticeModal">
                            <i class="bi bi-megaphone-fill"></i><span>Compose Notice</span>
                        </button>
                        <button type="button" class="btn btn-warning admin-action-btn" data-bs-toggle="modal" data-bs-target="#composeOfferModal">
                            <i class="bi bi-tag-fill"></i><span>Compose Offer</span>
                        </button>
                    </div>
                </div>

                <!-- Active Course Offers Section -->
                <div class="mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="bi bi-tags-fill text-warning me-2"></i>Active Course Offers (<?= count($postedOffersList) ?>)
                        </h6>
                        <?php if (!empty($postedOffersList)): ?>
                            <form method="POST" action="manage_courses.php" id="bulkOffersForm" class="d-flex align-items-center gap-3 m-0" onsubmit="return confirmBulkNoticeDelete('offers');">
                                <input type="hidden" name="action" value="bulk_delete_notices">
                                <input type="hidden" name="bulk_scope" value="offers">
                                <div class="form-check mb-0">
                                    <input class="form-check-input bulk-select-all" type="checkbox" id="selectAllOffers" data-group="offers">
                                    <label class="form-check-label small fw-semibold text-muted" for="selectAllOffers">Select All</label>
                                </div>
                                <button type="submit" id="deleteSelectedOffersBtn" class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold" disabled>
                                    <i class="bi bi-trash3 me-1"></i>Delete Selected <span class="bulk-selected-count" data-group="offers"></span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($postedOffersList)): ?>
                        <div class="p-4 text-center spatial-subpanel small text-muted">
                            No course discount offers published yet. Click <strong>Compose Offer</strong> to launch one.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($postedOffersList as $off):
                                $offBasePrice = !empty($off['course_price']) ? (float)$off['course_price'] : 0;
                                $offCalc = ($offBasePrice > 0)
                                    ? calculateCourseDiscount($offBasePrice, $off['discount_badge'] ?? '', $off['discount_percent'] ?? null, $off['offer_price'] ?? null)
                                    : ['has_discount' => false, 'discounted_price' => 0, 'badge' => ($off['discount_badge'] ?? 'Offer')];
                            ?>
                                <div class="col-md-6">
                                    <div class="card public-notice-card p-3 h-100" style="border-left: 4px solid #F59E0B !important;">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <input type="checkbox"
                                                       form="bulkOffersForm"
                                                       name="notice_ids[]"
                                                       value="<?= (int)$off['notice_id'] ?>"
                                                       class="form-check-input bulk-item-checkbox mt-0"
                                                       data-group="offers"
                                                       aria-label="Select offer <?= htmlspecialchars($off['title']) ?>">
                                                <span class="badge offer-discount-badge rounded-pill">
                                                    <i class="bi bi-tag-fill"></i><span><?= htmlspecialchars($offCalc['badge'] ?: ($off['discount_badge'] ?? 'Offer')) ?></span>
                                                </span>
                                                <?php if (!empty($off['course_title'])): ?>
                                                    <span class="badge bg-primary-subtle rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                                        <?= htmlspecialchars($off['course_title']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" action="manage_courses.php" class="m-0 flex-shrink-0">
                                                <input type="hidden" name="action" value="delete_notice">
                                                <input type="hidden" name="notice_id" value="<?= (int)$off['notice_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Delete Offer" onclick="return confirm('Delete this course offer?');">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($off['title']) ?></h6>
                                        <p class="text-secondary small mb-3 flex-grow-1" style="white-space: pre-line;"><?= htmlspecialchars($off['description']) ?></p>

                                        <?php if ($offBasePrice > 0 && $offCalc['has_discount']): ?>
                                            <div class="p-2 px-3 spatial-subpanel d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                                <small class="text-muted fw-semibold">COURSE PRICING:</small>
                                                <div class="d-flex align-items-baseline gap-2">
                                                    <del class="small strikethrough-old-price"><?= formatPrice($offBasePrice) ?></del>
                                                    <span class="fw-bold text-success fs-6"><?= formatPrice($offCalc['discounted_price']) ?></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="pt-2 border-top d-flex justify-content-between align-items-center small text-muted">
                                            <span><i class="bi bi-person-badge me-1 text-warning"></i><?= htmlspecialchars($off['instructor_name'] ?? 'Admin') ?></span>
                                            <span><i class="bi bi-clock me-1"></i><?= formatDateSafe($off['created_at'] ?? null) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Posted Notices Section -->
                <div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="bi bi-megaphone text-primary me-2"></i>Published Notices (<?= count($postedNoticesList) ?>)
                        </h6>
                        <?php if (!empty($postedNoticesList)): ?>
                            <form method="POST" action="manage_courses.php" id="bulkNoticesForm" class="d-flex align-items-center gap-3 m-0" onsubmit="return confirmBulkNoticeDelete('notices');">
                                <input type="hidden" name="action" value="bulk_delete_notices">
                                <input type="hidden" name="bulk_scope" value="notices">
                                <div class="form-check mb-0">
                                    <input class="form-check-input bulk-select-all" type="checkbox" id="selectAllNotices" data-group="notices">
                                    <label class="form-check-label small fw-semibold text-muted" for="selectAllNotices">Select All</label>
                                </div>
                                <button type="submit" id="deleteSelectedNoticesBtn" class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold" disabled>
                                    <i class="bi bi-trash3 me-1"></i>Delete Selected <span class="bulk-selected-count" data-group="notices"></span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($postedNoticesList)): ?>
                        <div class="p-4 text-center spatial-subpanel small text-muted">
                            No instructor notices published yet. Click <strong>Compose Notice</strong> to post an announcement.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($postedNoticesList as $nt): ?>
                                <div class="col-md-6">
                                    <div class="card public-notice-card p-3 h-100" style="border-left: 4px solid var(--primary-brand) !important;">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <input type="checkbox"
                                                       form="bulkNoticesForm"
                                                       name="notice_ids[]"
                                                       value="<?= (int)$nt['notice_id'] ?>"
                                                       class="form-check-input bulk-item-checkbox mt-0"
                                                       data-group="notices"
                                                       aria-label="Select notice <?= htmlspecialchars($nt['title']) ?>">
                                                <span class="badge bg-info-subtle rounded-pill px-2 py-1">
                                                    <i class="bi bi-people-fill me-1"></i><?= htmlspecialchars(!empty($nt['discount_badge']) ? $nt['discount_badge'] : 'All Students') ?>
                                                </span>
                                                <?php if (!empty($nt['course_title'])): ?>
                                                    <span class="badge bg-secondary-subtle rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                                        <?= htmlspecialchars($nt['course_title']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" action="manage_courses.php" class="m-0 flex-shrink-0">
                                                <input type="hidden" name="action" value="delete_notice">
                                                <input type="hidden" name="notice_id" value="<?= (int)$nt['notice_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Delete Notice" onclick="return confirm('Delete this notice?');">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($nt['title']) ?></h6>
                                        <p class="text-secondary small mb-3 flex-grow-1" style="white-space: pre-line;"><?= htmlspecialchars($nt['description']) ?></p>

                                        <div class="pt-2 border-top d-flex justify-content-between align-items-center small text-muted">
                                            <span><i class="bi bi-person-badge me-1 text-primary"></i><?= htmlspecialchars($nt['instructor_name'] ?? 'Admin') ?></span>
                                            <span><i class="bi bi-clock me-1"></i><?= formatDateSafe($nt['created_at'] ?? null) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 6: RELATIONAL SCHEMA & QUERY INSPECTOR (VIVA & DEFENSE)    -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="schema-panel" role="tabpanel" aria-labelledby="schema-tab">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-dark mb-3">
                            <i class="bi bi-diagram-3-fill text-primary me-2"></i>Database Schema: <code>course_db</code>
                        </h5>
                        <p class="text-muted small">Five relational tables connected by Primary Keys (PK) and Foreign Keys (FK):</p>

                        <div class="list-group list-group-flush small">
                            <div class="list-group-item bg-transparent px-0 py-2">
                                <strong class="text-primary">1. instructors</strong> &mdash; <code>(instructor_id PK, name, email, phone, expertise, bio, avatar, profile_image)</code>
                            </div>
                            <div class="list-group-item bg-transparent px-0 py-2">
                                <strong class="text-primary">2. courses</strong> &mdash; <code>(course_id PK, title, description, price, instructor_id FK, category, thumbnail)</code>
                                <div class="text-muted ps-3 small">&rarr; FK: <code>instructor_id</code> references <code>instructors(instructor_id)</code> ON DELETE SET NULL</div>
                            </div>
                            <div class="list-group-item bg-transparent px-0 py-2">
                                <strong class="text-primary">3. students</strong> &mdash; <code>(student_id PK, name, email, phone, role, password, profile_image, created_at)</code>
                            </div>
                            <div class="list-group-item bg-transparent px-0 py-2">
                                <strong class="text-primary">4. enrollments</strong> &mdash; <code>(enrollment_id PK, student_id FK, course_id FK, payment_method, wallet_number, transaction_id, paid_amount, status, enrollment_date, verified_at, dropped_at)</code>
                                <div class="text-muted ps-3 small">&rarr; FK 1: <code>student_id</code> references <code>students(student_id)</code> ON DELETE CASCADE</div>
                                <div class="text-muted ps-3 small">&rarr; FK 2: <code>course_id</code> references <code>courses(course_id)</code> ON DELETE CASCADE</div>
                                <div class="text-muted ps-3 small">&rarr; Unique Key: <code>(student_id, course_id)</code> prevents duplicate enrollments</div>
                            </div>
                            <div class="list-group-item bg-transparent px-0 py-2">
                                <strong class="text-primary">5. notices</strong> &mdash; <code>(notice_id PK, title, description, type, discount_badge, discount_percent, offer_price, instructor_id FK, course_id FK, created_at)</code>
                                <div class="text-muted ps-3 small">&rarr; FK 1: <code>instructor_id</code> references <code>instructors(instructor_id)</code> ON DELETE SET NULL</div>
                                <div class="text-muted ps-3 small">&rarr; FK 2: <code>course_id</code> references <code>courses(course_id)</code> ON DELETE SET NULL</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-dark mb-3">
                            <i class="bi bi-terminal-fill text-success me-2"></i>CRUD SQL Operations Executed
                        </h5>
                        <div class="p-3 spatial-subpanel font-monospace small text-dark mb-3" style="max-height: 280px; overflow-y: auto;">
                            <div class="text-success mb-2">-- 1. CREATE (INSERT Course)</div>
                            <code>INSERT INTO courses (title, description, price, instructor_id, category, thumbnail) VALUES (?, ?, ?, ?, ?, ?);</code>
                            <hr class="my-2">
                            <div class="text-primary mb-2">-- 2. READ (SELECT with JOIN)</div>
                            <code>SELECT c.*, i.name, COUNT(e.enrollment_id) FROM courses c LEFT JOIN instructors i ON c.instructor_id = i.instructor_id LEFT JOIN enrollments e ON c.course_id = e.course_id GROUP BY c.course_id;</code>
                            <hr class="my-2">
                            <div class="text-warning-emphasis mb-2">-- 3. UPDATE (Edit Course)</div>
                            <code>UPDATE courses SET title = ?, description = ?, price = ?, instructor_id = ?, category = ?, thumbnail = ? WHERE course_id = ?;</code>
                            <hr class="my-2">
                            <div class="text-danger mb-2">-- 4. DELETE (Remove Course)</div>
                            <code>DELETE FROM courses WHERE course_id = ?;</code>
                        </div>
                        <div class="text-center">
                            <a href="setup.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                                <i class="bi bi-arrow-clockwise me-1"></i> Re-run / Reset Database with Setup Tool
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- TOP-LEVEL MODALS (OUTSIDE CARDS / BLUR / OVERFLOW CONTAINERS)         -->
<!-- ==================================================================== -->
<?php foreach ($courses as $c):
    $cResolvedPath = resolveCourseThumbnailFilePath($c['title'], $c['thumbnail'] ?? null) ?? '';
    $cEditThumbUrl = getCourseThumbnailUrl($c['title'], $c['thumbnail'] ?? null);
?>
    <!-- EDIT COURSE MODAL (UPDATE) -->
    <div class="modal fade" id="editCourseModal<?= $c['course_id'] ?>" tabindex="-1" aria-labelledby="editLabel<?= $c['course_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                    <div class="d-flex align-items-center gap-3">
                        <span class="p-2 rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                            <i class="bi bi-pencil-square fs-5"></i>
                        </span>
                        <div>
                            <span class="badge bg-primary-subtle text-primary mb-1" style="font-size: 0.68rem;">DBMS UPDATE</span>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="editLabel<?= $c['course_id'] ?>">Edit Course #<?= $c['course_id'] ?></h5>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="manage_courses.php" enctype="multipart/form-data" class="d-flex flex-column min-h-0 flex-grow-1 overflow-hidden">
                    <input type="hidden" name="action" value="edit_course">
                    <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Course Title</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($c['title']) ?>" required>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-5 col-sm-6">
                                <label for="editInstructor<?= $c['course_id'] ?>" class="form-label fw-semibold small text-muted">Instructor (Foreign Key: instructor_id)</label>
                                <select id="editInstructor<?= $c['course_id'] ?>" name="instructor_id" class="form-select">
                                    <option value="">-- No Instructor Assigned --</option>
                                    <?php foreach ($instructors as $inst): ?>
                                        <option value="<?= $inst['instructor_id'] ?>"
                                                title="<?= htmlspecialchars($inst['name'] . ' — ' . $inst['expertise']) ?>"
                                                <?= ($c['instructor_id'] == $inst['instructor_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($inst['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <label for="editCategory<?= $c['course_id'] ?>" class="form-label fw-semibold small text-muted">Category</label>
                                <input id="editCategory<?= $c['course_id'] ?>" type="text" name="category" class="form-control" value="<?= htmlspecialchars($c['category']) ?>" required>
                            </div>
                            <div class="col-md-3 col-sm-12">
                                <label for="editPrice<?= $c['course_id'] ?>" class="form-label fw-semibold small text-muted">Tuition Price (৳)</label>
                                <input id="editPrice<?= $c['course_id'] ?>" type="number" step="0.01" min="0" name="price" class="form-control" value="<?= htmlspecialchars($c['price']) ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Course Description</label>
                            <textarea name="description" class="form-control" rows="4" required><?= htmlspecialchars($c['description']) ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Upload New Thumbnail (16:9 &middot; 1280x720)</label>
                            <div class="d-flex align-items-center gap-3 p-2.5 spatial-subpanel">
                                <div class="crop-upload-thumb-preview" id="editThumbPreview<?= $c['course_id'] ?>">
                                    <?php if ($cEditThumbUrl): ?>
                                        <img src="<?= htmlspecialchars($cEditThumbUrl) ?>" alt="Thumbnail preview">
                                    <?php else: ?>
                                        <i class="bi bi-image text-muted fs-4"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <input type="hidden" name="thumbnail_cropped" id="editThumbCropped<?= $c['course_id'] ?>" value="">
                                    <input type="file"
                                           name="thumbnail_file"
                                           class="form-control form-control-sm"
                                           accept="image/*"
                                           data-crop-aspect="16:9"
                                           data-crop-width="1280"
                                           data-crop-height="720"
                                           data-crop-shape="rect"
                                           data-crop-title="Adjust Course Thumbnail (1280x720)"
                                           data-preview-target="#editThumbPreview<?= $c['course_id'] ?>"
                                           data-base64-target="#editThumbCropped<?= $c['course_id'] ?>">
                                    <div class="form-text small text-muted mb-0">Selecting an image opens the pan/zoom cropper with alignment guide lines.</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Course Thumbnail Path (<code>images/courses/</code>)</label>
                            <input type="text" name="thumbnail" class="form-control" value="<?= htmlspecialchars($cResolvedPath) ?>" placeholder="e.g. images/courses/Database_Systems_SQL_Mastery_1280x720.png">
                            <div class="form-text small text-muted">Leave as-is or blank to auto-resolve the 1280x720 asset inside <code>images/courses/</code>.</div>
                        </div>

                        <div class="sql-preview-box font-monospace">
                            UPDATE courses SET title = ?, description = ?, price = ?, instructor_id = ?, category = ?, thumbnail = ? WHERE course_id = <?= $c['course_id'] ?>;
                        </div>
                    </div>
                    <div class="modal-footer public-modal-footer p-3 px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="bi bi-check2-circle me-1"></i> Save Changes (UPDATE)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE COURSE MODAL (DELETE) -->
    <div class="modal fade" id="deleteCourseModal<?= $c['course_id'] ?>" tabindex="-1" aria-labelledby="deleteLabel<?= $c['course_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header public-modal-header py-3 px-4">
                    <h5 class="modal-title fw-bold text-danger" id="deleteLabel<?= $c['course_id'] ?>">
                        <i class="bi bi-trash3-fill me-2"></i>Delete Course #<?= $c['course_id'] ?>?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-2">Are you sure you want to permanently delete this course?</p>
                    <div class="p-3 spatial-subpanel fw-bold text-dark mb-3">
                        <?= htmlspecialchars($c['title']) ?>
                    </div>
                    <div class="alert spatial-alert-amber small mb-0">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <strong>Foreign Key Cascade:</strong> All <strong><?= $c['enrolled_count'] ?></strong> student enrollment records for this course will be automatically deleted via <code>ON DELETE CASCADE</code>.
                    </div>
                </div>
                <div class="modal-footer public-modal-footer py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="manage_courses.php" class="m-0">
                        <input type="hidden" name="action" value="delete_course">
                        <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">
                        <input type="hidden" name="course_title" value="<?= htmlspecialchars($c['title']) ?>">
                        <button type="submit" class="btn btn-danger rounded-pill px-3">
                            <i class="bi bi-trash3 me-1"></i> Delete Course (DELETE)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($instructors as $inst):
    $editInstAvatarUrl = getInstructorAvatarUrl($inst['name'], [$inst['avatar'] ?? null, $inst['profile_image'] ?? null, $inst['user_profile_image'] ?? null], (int)$inst['instructor_id']);
?>
    <!-- EDIT INSTRUCTOR MODAL (UPDATE) -->
    <div class="modal fade" id="editInstructorModal<?= $inst['instructor_id'] ?>" tabindex="-1" aria-labelledby="editInstLabel<?= $inst['instructor_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                    <div class="d-flex align-items-center gap-3">
                        <span class="p-2 rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                            <i class="bi bi-person-badge-fill fs-5"></i>
                        </span>
                        <div>
                            <span class="badge bg-primary-subtle text-primary mb-1" style="font-size: 0.68rem;">DBMS UPDATE</span>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="editInstLabel<?= $inst['instructor_id'] ?>">Edit Instructor #<?= $inst['instructor_id'] ?></h5>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="manage_courses.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_instructor">
                    <input type="hidden" name="instructor_id" value="<?= $inst['instructor_id'] ?>">

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($inst['name']) ?>" required>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold small text-muted">Email Address (Unique)</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($inst['email']) ?>" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small text-muted">Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($inst['phone'] ?? '') ?>" placeholder="017...">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Primary Expertise</label>
                            <input type="text" name="expertise" class="form-control" value="<?= htmlspecialchars($inst['expertise']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Bio / Academic Background</label>
                            <textarea name="bio" class="form-control" rows="3"><?= htmlspecialchars($inst['bio'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-muted">Instructor Photo (<code>images/instructors/</code>)</label>
                            <div class="d-flex align-items-center gap-3">
                                <div id="editInstThumbPreview<?= $inst['instructor_id'] ?>" class="flex-shrink-0">
                                    <?php if ($editInstAvatarUrl): ?>
                                        <img src="<?= htmlspecialchars($editInstAvatarUrl) ?>" alt="Instructor Photo" class="rounded-circle object-fit-cover border" style="width: 48px; height: 48px;">
                                    <?php else: ?>
                                        <img src="" alt="Instructor Photo" class="rounded-circle object-fit-cover border d-none" style="width: 48px; height: 48px;">
                                        <div class="crop-avatar-placeholder instructor-avatar" style="width: 48px; height: 48px;">
                                            <?= strtoupper(substr($inst['name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <input type="hidden" name="avatar_cropped" id="editInstCropped<?= $inst['instructor_id'] ?>" value="">
                                    <input type="file"
                                           name="avatar"
                                           class="form-control form-control-sm"
                                           accept="image/*"
                                           data-crop-aspect="1:1"
                                           data-crop-width="600"
                                           data-crop-height="600"
                                           data-crop-shape="circle"
                                           data-crop-title="Adjust Instructor Photo"
                                           data-preview-target="#editInstThumbPreview<?= $inst['instructor_id'] ?> img"
                                           data-base64-target="#editInstCropped<?= $inst['instructor_id'] ?>">
                                    <div class="form-text small text-muted">Leave blank to keep existing photo. Opens 1:1 pan &amp; zoom cropper.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer public-modal-footer p-3 px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="bi bi-check2-circle me-1"></i> Save Changes (UPDATE)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE INSTRUCTOR MODAL -->
    <div class="modal fade" id="deleteInstModal<?= $inst['instructor_id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header public-modal-header py-3 px-4">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Instructor?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p>Are you sure you want to delete instructor <strong><?= htmlspecialchars($inst['name']) ?></strong>?</p>
                    <div class="alert alert-info small mb-0">
                        <strong>Relational Integrity:</strong> This instructor currently teaches <strong><?= $inst['course_count'] ?></strong> course(s). Deleting them will set the <code>instructor_id</code> in the <code>courses</code> table to <code>NULL</code> (via <code>ON DELETE SET NULL</code>), preserving the courses.
                    </div>
                </div>
                <div class="modal-footer public-modal-footer py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="manage_courses.php" class="m-0">
                        <input type="hidden" name="action" value="delete_instructor">
                        <input type="hidden" name="instructor_id" value="<?= $inst['instructor_id'] ?>">
                        <input type="hidden" name="instructor_name" value="<?= htmlspecialchars($inst['name']) ?>">
                        <button type="submit" class="btn btn-danger rounded-pill px-3">
                            <i class="bi bi-trash3 me-1"></i> Delete Instructor
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($students as $st): ?>
    <!-- EDIT STUDENT MODAL (UPDATE) -->
    <div class="modal fade" id="editStudentModal<?= $st['student_id'] ?>" tabindex="-1" aria-labelledby="editStudentLabel<?= $st['student_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                    <div class="d-flex align-items-center gap-3">
                        <span class="p-2 rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                            <i class="bi bi-person-gear fs-5"></i>
                        </span>
                        <div>
                            <span class="badge bg-primary-subtle text-primary mb-1" style="font-size: 0.68rem;">DBMS UPDATE</span>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="editStudentLabel<?= $st['student_id'] ?>">Edit Student #<?= $st['student_id'] ?></h5>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="manage_courses.php" enctype="multipart/form-data" class="d-flex flex-column min-h-0 flex-grow-1 overflow-hidden">
                    <input type="hidden" name="action" value="edit_student">
                    <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($st['name']) ?>" required>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold small text-muted">Email Address (Unique)</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($st['email']) ?>" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small text-muted">Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($st['phone'] ?? '') ?>" placeholder="017...">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Role (Access Control)</label>
                            <select name="role" class="form-select">
                                <option value="student" <?= (($st['role'] ?? 'student') === 'student') ? 'selected' : '' ?>>Student (Normal User)</option>
                                <option value="admin" <?= (($st['role'] ?? 'student') === 'admin') ? 'selected' : '' ?>>Administrator (Full CRUD Access)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">Change Password (Optional)</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current password" minlength="6">
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-muted">Profile Photo (Optional)</label>
                            <div class="d-flex align-items-center gap-3">
                                <div id="editStudentThumbPreview<?= $st['student_id'] ?>" class="flex-shrink-0">
                                    <?php if (!empty($st['profile_image']) && file_exists(__DIR__ . '/' . $st['profile_image'])): ?>
                                        <img src="<?= htmlspecialchars($st['profile_image']) ?>" alt="Student Photo" class="rounded-circle object-fit-cover border" style="width: 48px; height: 48px;">
                                    <?php else: ?>
                                        <img src="" alt="Student Photo" class="rounded-circle object-fit-cover border d-none" style="width: 48px; height: 48px;">
                                        <div class="crop-avatar-placeholder instructor-avatar" style="width: 48px; height: 48px;">
                                            <?= strtoupper(substr($st['name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <input type="hidden" name="profile_image_cropped" id="editStudentCropped<?= $st['student_id'] ?>" value="">
                                    <input type="file"
                                           name="profile_image"
                                           class="form-control form-control-sm"
                                           accept="image/*"
                                           data-crop-aspect="1:1"
                                           data-crop-width="600"
                                           data-crop-height="600"
                                           data-crop-shape="circle"
                                           data-crop-title="Adjust Student Photo"
                                           data-preview-target="#editStudentThumbPreview<?= $st['student_id'] ?> img"
                                           data-base64-target="#editStudentCropped<?= $st['student_id'] ?>">
                                    <div class="form-text small text-muted">Leave blank to keep existing photo.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer public-modal-footer p-3 px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="bi bi-check-circle-fill me-1"></i> Save Changes (UPDATE)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE STUDENT MODAL (DELETE) -->
    <div class="modal fade" id="deleteStudentModal<?= $st['student_id'] ?>" tabindex="-1" aria-labelledby="deleteStudentLabel<?= $st['student_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header public-modal-header py-3 px-4">
                    <h5 class="modal-title fw-bold text-danger" id="deleteStudentLabel<?= $st['student_id'] ?>">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Student #<?= $st['student_id'] ?>?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-2">Are you sure you want to permanently delete student:</p>
                    <div class="p-3 spatial-subpanel fw-bold text-dark mb-3">
                        <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['email']) ?>)
                    </div>
                    <div class="alert spatial-alert-amber small mb-0">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <strong>Relational Integrity & Cascade:</strong> This student has <strong><?= $st['enrolled_courses_count'] ?></strong> course enrollment(s). Deleting them will automatically remove all their enrollment records from the <code>enrollments</code> table via <code>ON DELETE CASCADE</code>.
                    </div>
                </div>
                <div class="modal-footer public-modal-footer py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="manage_courses.php" class="m-0">
                        <input type="hidden" name="action" value="delete_student">
                        <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">
                        <input type="hidden" name="student_name" value="<?= htmlspecialchars($st['name']) ?>">
                        <button type="submit" class="btn btn-danger rounded-pill px-3">
                            <i class="bi bi-trash3 me-1"></i> Delete Student (DELETE)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- ==================================================================== -->
<!-- MODAL: ADD COURSE (INSERT)                                            -->
<!-- ==================================================================== -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-labelledby="addCourseLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="p-2 rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                        <i class="bi bi-plus-circle-fill fs-5"></i>
                    </span>
                    <div>
                        <span class="badge bg-primary-subtle text-primary mb-1" style="font-size: 0.68rem;">DBMS CREATE (INSERT)</span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="addCourseLabel">Add New Course</h5>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_courses.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_course">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Course Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Distributed Database Architecture" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-5 col-sm-6">
                            <label for="addCourseInstructor" class="form-label fw-semibold small text-muted">Instructor (Relational Foreign Key)</label>
                            <select id="addCourseInstructor" name="instructor_id" class="form-select">
                                <option value="">-- Select Instructor --</option>
                                <?php foreach ($instructors as $inst): ?>
                                    <option value="<?= $inst['instructor_id'] ?>"
                                            title="<?= htmlspecialchars($inst['name'] . ' — ' . $inst['expertise']) ?>">
                                        <?= htmlspecialchars($inst['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <label for="addCourseCategory" class="form-label fw-semibold small text-muted">Category</label>
                            <input id="addCourseCategory" type="text" name="category" class="form-control" placeholder="e.g. Database Systems" value="Database Systems" required>
                        </div>
                        <div class="col-md-3 col-sm-12">
                            <label for="addCoursePrice" class="form-label fw-semibold small text-muted">Tuition Price (৳)</label>
                            <input id="addCoursePrice" type="number" step="0.01" min="0" name="price" class="form-control" placeholder="2500" value="2500" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Course Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Comprehensive course syllabus, learning outcomes, and technical prerequisites..." required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Upload Thumbnail Image (16:9 &middot; 1280x720)</label>
                        <div class="d-flex align-items-center gap-3 p-2.5 spatial-subpanel">
                            <div class="crop-upload-thumb-preview" id="addCourseThumbPreview">
                                <i class="bi bi-image text-muted fs-4"></i>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <input type="hidden" name="thumbnail_cropped" id="addCourseThumbCropped" value="">
                                <input type="file"
                                       name="thumbnail_file"
                                       class="form-control form-control-sm"
                                       accept="image/*"
                                       data-crop-aspect="16:9"
                                       data-crop-width="1280"
                                       data-crop-height="720"
                                       data-crop-shape="rect"
                                       data-crop-title="Adjust Course Thumbnail (1280x720)"
                                       data-preview-target="#addCourseThumbPreview"
                                       data-base64-target="#addCourseThumbCropped">
                                <div class="form-text small text-muted mb-0">Selecting an image opens the pan/zoom cropper with guide lines.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold small text-muted">Thumbnail Path (Optional, inside <code>images/courses/</code>)</label>
                        <input type="text" name="thumbnail" class="form-control" placeholder="e.g. images/courses/My_Course_1280x720.png">
                        <div class="form-text small text-muted">Leave blank to auto-resolve matching 1280x720 image inside <code>images/courses/</code>.</div>
                    </div>
                </div>

                <div class="modal-footer public-modal-footer p-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                        <i class="bi bi-plus-circle-fill me-1"></i> Insert Course Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- MODAL: ADD INSTRUCTOR (INSERT)                                        -->
<!-- ==================================================================== -->
<div class="modal fade" id="addInstructorModal" tabindex="-1" aria-labelledby="addInstLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="p-2 rounded-circle bg-success-subtle text-success d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                        <i class="bi bi-person-plus-fill fs-5"></i>
                    </span>
                    <div>
                        <span class="badge bg-success-subtle text-success mb-1" style="font-size: 0.68rem;">DBMS CREATE (INSERT)</span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="addInstLabel">Add New Instructor</h5>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_courses.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_instructor">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Full Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Golam Mortuza Shipu" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-muted">Email Address (Unique)</label>
                            <input type="email" name="email" class="form-control" placeholder="gmortuza2003@gmail.com" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small text-muted">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="017...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Primary Expertise</label>
                        <input type="text" name="expertise" class="form-control" placeholder="e.g. Distributed SQL & Query Engines" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Bio / Academic Background</label>
                        <textarea name="bio" class="form-control" rows="3" placeholder="Brief biographical overview..."></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold small text-muted">Instructor Photo (Optional)</label>
                        <div class="d-flex align-items-center gap-3">
                            <div id="addInstructorThumbPreview" class="flex-shrink-0">
                                <img src="" alt="Instructor Photo" class="rounded-circle object-fit-cover border d-none" style="width: 48px; height: 48px;">
                                <div class="crop-avatar-placeholder instructor-avatar" style="width: 48px; height: 48px;">
                                    <i class="bi bi-person-workspace"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <input type="hidden" name="avatar_cropped" id="addInstructorCropped" value="">
                                <input type="file"
                                       name="avatar"
                                       class="form-control form-control-sm"
                                       accept="image/*"
                                       data-crop-aspect="1:1"
                                       data-crop-width="600"
                                       data-crop-height="600"
                                       data-crop-shape="circle"
                                       data-crop-title="Adjust Instructor Photo"
                                       data-preview-target="#addInstructorThumbPreview img"
                                       data-base64-target="#addInstructorCropped">
                                <div class="form-text small text-muted">Upload JPG, PNG, GIF, or WEBP (Max 5MB). Opens 1:1 pan &amp; zoom cropper.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer public-modal-footer p-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4">
                        <i class="bi bi-person-plus-fill me-1"></i> Insert Instructor Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- MODAL: ADD STUDENT (INSERT)                                           -->
<!-- ==================================================================== -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="p-2 rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                        <i class="bi bi-mortarboard-fill fs-5"></i>
                    </span>
                    <div>
                        <span class="badge bg-primary-subtle text-primary mb-1" style="font-size: 0.68rem;">DBMS CREATE (INSERT)</span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="addStudentLabel">Add New Student</h5>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_courses.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_student">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Full Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. John Doe" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-muted">Email Address (Unique)</label>
                            <input type="email" name="email" class="form-control" placeholder="student@example.com" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small text-muted">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="017...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Role (Access Control)</label>
                        <select name="role" class="form-select">
                            <option value="student" selected>Student (Normal User)</option>
                            <option value="admin">Administrator (Full CRUD Access)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Initial Password</label>
                        <input type="password" name="password" class="form-control" placeholder="At least 6 characters" value="password123" required minlength="6">
                        <div class="form-text small text-muted">Default is set to <code>password123</code> for convenience.</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold small text-muted">Profile Photo (Optional)</label>
                        <div class="d-flex align-items-center gap-3">
                            <div id="addStudentThumbPreview" class="flex-shrink-0">
                                <img src="" alt="Student Photo" class="rounded-circle object-fit-cover border d-none" style="width: 48px; height: 48px;">
                                <div class="crop-avatar-placeholder instructor-avatar" style="width: 48px; height: 48px;">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <input type="hidden" name="profile_image_cropped" id="addStudentCropped" value="">
                                <input type="file"
                                       name="profile_image"
                                       class="form-control form-control-sm"
                                       accept="image/*"
                                       data-crop-aspect="1:1"
                                       data-crop-width="600"
                                       data-crop-height="600"
                                       data-crop-shape="circle"
                                       data-crop-title="Adjust Student Photo"
                                       data-preview-target="#addStudentThumbPreview img"
                                       data-base64-target="#addStudentCropped">
                                <div class="form-text small text-muted">Upload JPG, PNG, GIF, or WEBP (Max 5MB).</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer public-modal-footer p-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                        <i class="bi bi-person-plus-fill me-1"></i> Insert Student Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- MODAL: COMPOSE NOTICE (ADMIN ANNOUNCEMENT)                            -->
<!-- ==================================================================== -->
<div class="modal fade" id="composeNoticeModal" tabindex="-1" aria-labelledby="composeNoticeLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header public-modal-header spatial-modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="p-2 rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                        <i class="bi bi-megaphone-fill fs-5"></i>
                    </span>
                    <div>
                        <span class="badge bg-primary-subtle text-primary mb-1" style="font-size: 0.68rem;">ADMIN ANNOUNCEMENT</span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="composeNoticeLabel">Compose Notice</h5>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_courses.php">
                <input type="hidden" name="action" value="compose_notice">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="noticeTitleInput" class="form-label fw-semibold small text-muted">Notice Title <span class="text-danger">*</span></label>
                        <input type="text" id="noticeTitleInput" name="title" class="form-control" placeholder="e.g. Mid-Term DBMS Project Submission Schedule" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="noticeTargetLabel" class="form-label fw-semibold small text-muted">Target Audience / Label</label>
                            <input type="text" id="noticeTargetLabel" name="discount_badge" class="form-control" value="All Students" placeholder="e.g. All Students, Academic, Workshop">
                        </div>
                        <div class="col-md-4">
                            <label for="noticeInstructorId" class="form-label fw-semibold small text-muted">Instructor / Author</label>
                            <select id="noticeInstructorId" name="instructor_id" class="form-select">
                                <?php foreach ($instructors as $instOpt): ?>
                                    <option value="<?= (int)$instOpt['instructor_id'] ?>" <?= ((int)$instOpt['instructor_id'] === $defaultAdminInstructorId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($instOpt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="noticeCourseId" class="form-label fw-semibold small text-muted">Related Course (Optional)</label>
                            <select id="noticeCourseId" name="course_id" class="form-select">
                                <option value="">All Platform Courses</option>
                                <?php foreach ($courses as $cOpt): ?>
                                    <option value="<?= (int)$cOpt['course_id'] ?>">
                                        <?= htmlspecialchars($cOpt['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="noticeDescriptionInput" class="form-label fw-semibold small text-muted">Notice Message <span class="text-danger">*</span></label>
                        <textarea id="noticeDescriptionInput" name="description" class="form-control" rows="4" placeholder="Write the official notice details for students..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer public-modal-footer p-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">
                        <i class="bi bi-send-fill me-1"></i> Publish Notice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- MODAL: COMPOSE OFFER WITH LIVE DISCOUNT CALCULATOR                    -->
<!-- ==================================================================== -->
<div class="modal fade" id="composeOfferModal" tabindex="-1" aria-labelledby="composeOfferLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header public-modal-header p-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="p-2 rounded-circle bg-warning-subtle text-warning d-inline-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="bi bi-tag-fill fs-5"></i>
                    </span>
                    <div>
                        <span class="badge bg-warning-subtle mb-1">COURSE PROMOTION</span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="composeOfferLabel">Compose Course Discount Offer</h5>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_courses.php">
                <input type="hidden" name="action" value="compose_offer">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label for="offerCourseSelect" class="form-label fw-semibold small text-muted">Select Course <span class="text-danger">*</span></label>
                            <select id="offerCourseSelect" name="course_id" class="form-select" required>
                                <?php foreach ($courses as $cOpt): ?>
                                    <option value="<?= (int)$cOpt['course_id'] ?>"
                                            data-price="<?= htmlspecialchars($cOpt['price']) ?>"
                                            data-title="<?= htmlspecialchars($cOpt['title']) ?>">
                                        <?= htmlspecialchars($cOpt['title']) ?> (৳<?= number_format((float)$cOpt['price']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="offerDiscountPercent" class="form-label fw-semibold small text-muted">Discount Percentage (%) <span class="text-danger">*</span></label>
                            <div class="auth-input-box auth-input-compact">
                                <i class="bi bi-percent auth-input-icon"></i>
                                <input type="number" id="offerDiscountPercent" name="discount_percent" class="form-control auth-input-control" min="1" max="99" step="1" value="20" placeholder="e.g. 20" required>
                            </div>
                        </div>
                    </div>

                    <!-- Live JS Price Preview -->
                    <div id="offerLivePreview" class="p-3 spatial-subpanel mb-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.7rem;">Original Price</small>
                                <del id="previewOriginalPrice" class="fs-5 strikethrough-old-price fw-semibold">৳0</del>
                            </div>
                            <div class="text-center">
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.7rem;">Discount</small>
                                <span id="previewDiscountBadge" class="badge offer-discount-badge rounded-pill">20% OFF</span>
                                <small id="previewSavings" class="d-block text-muted mt-1" style="font-size: 0.72rem;">Save ৳0</small>
                            </div>
                            <div class="text-md-end">
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.7rem;">Final Offer Price</small>
                                <span id="previewFinalPrice" class="fs-4 fw-bold text-success">৳0</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="offerTitleInput" class="form-label fw-semibold small text-muted">Offer Title <span class="text-danger">*</span></label>
                        <input type="text" id="offerTitleInput" name="title" class="form-control" placeholder="e.g. Special Semester Discount - 20% OFF" required>
                    </div>

                    <div class="mb-2">
                        <label for="offerDescriptionInput" class="form-label fw-semibold small text-muted">Offer Description <span class="text-danger">*</span></label>
                        <textarea id="offerDescriptionInput" name="description" class="form-control" rows="3" placeholder="Explain offer eligibility and enrollment benefits..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer public-modal-footer p-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">
                        <i class="bi bi-tag-fill me-1"></i> Publish Course Offer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tab URL Hash Switching & Audit Log Filter Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash) {
        var triggerEl = document.querySelector('#manageTabs button[data-bs-toggle="tab"]' + hash)
            || document.querySelector('#manageTabs button[data-bs-toggle="tab"][data-bs-target="' + hash + '"]')
            || document.querySelector('#manageTabs button[data-bs-toggle="tab"][data-bs-target="' + hash.replace('-tab', '-panel') + '"]');
        if (triggerEl && window.bootstrap) {
            var tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }

    // Live Course Offer Discount Calculator
    var courseSelect = document.getElementById('offerCourseSelect');
    var pctInput = document.getElementById('offerDiscountPercent');
    var origEl = document.getElementById('previewOriginalPrice');
    var badgeEl = document.getElementById('previewDiscountBadge');
    var savingsEl = document.getElementById('previewSavings');
    var finalEl = document.getElementById('previewFinalPrice');

    function updateOfferPreview() {
        if (!courseSelect || !pctInput) return;
        var selectedOpt = courseSelect.options[courseSelect.selectedIndex];
        var basePrice = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-price') || '0') : 0;
        var pct = parseFloat(pctInput.value || '0');
        if (isNaN(pct) || pct < 0) pct = 0;
        if (pct > 99) pct = 99;

        var discountAmt = Math.round((basePrice * pct) / 100);
        var finalPrice = Math.max(0, basePrice - discountAmt);

        if (origEl) origEl.textContent = '৳' + Math.round(basePrice).toLocaleString('en-US');
        if (badgeEl) badgeEl.textContent = (pct % 1 === 0 ? pct.toFixed(0) : pct) + '% OFF';
        if (savingsEl) savingsEl.textContent = 'Save ৳' + discountAmt.toLocaleString('en-US');
        if (finalEl) finalEl.textContent = '৳' + Math.round(finalPrice).toLocaleString('en-US');
    }

    if (courseSelect) courseSelect.addEventListener('change', updateOfferPreview);
    if (pctInput) pctInput.addEventListener('input', updateOfferPreview);
    updateOfferPreview();

    var searchInput = document.getElementById('historySearchInput');
    var statusFilter = document.getElementById('historyStatusFilter');
    var methodFilter = document.getElementById('historyMethodFilter');
    var visibleCountBadge = document.getElementById('historyVisibleCount');
    var noMatchesRow = document.getElementById('historyNoMatchesRow');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.history-audit-row'));

    function filterAuditHistory() {
        if (!rows.length) return;
        var kw = (searchInput ? searchInput.value : '').trim().toLowerCase();
        var st = statusFilter ? statusFilter.value : 'all';
        var pm = methodFilter ? methodFilter.value : 'all';
        var visible = 0;

        rows.forEach(function(row) {
            var matchKw = !kw || (row.getAttribute('data-search') || '').indexOf(kw) !== -1;
            var matchSt = (st === 'all') || (row.getAttribute('data-status') === st);
            var matchPm = (pm === 'all') || (row.getAttribute('data-method') === pm);

            if (matchKw && matchSt && matchPm) {
                row.classList.remove('d-none');
                visible++;
            } else {
                row.classList.add('d-none');
            }
        });

        if (visibleCountBadge) visibleCountBadge.textContent = visible;
        if (noMatchesRow) {
            if (visible === 0) noMatchesRow.classList.remove('d-none');
            else noMatchesRow.classList.add('d-none');
        }
    }

    if (searchInput) searchInput.addEventListener('input', filterAuditHistory);
    if (statusFilter) statusFilter.addEventListener('change', filterAuditHistory);
    if (methodFilter) methodFilter.addEventListener('change', filterAuditHistory);

    // Multi-select bulk delete controls for Notices & Offers
    function syncBulkSelection(group) {
        var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.bulk-item-checkbox[data-group="' + group + '"]'));
        var checked = checkboxes.filter(function(cb) { return cb.checked; });
        var selectAllEl = document.querySelector('.bulk-select-all[data-group="' + group + '"]');
        var deleteBtn = document.getElementById(group === 'offers' ? 'deleteSelectedOffersBtn' : 'deleteSelectedNoticesBtn');
        var countSpan = document.querySelector('.bulk-selected-count[data-group="' + group + '"]');

        checkboxes.forEach(function(cb) {
            var card = cb.closest('.public-notice-card');
            if (card) card.classList.toggle('is-selected', cb.checked);
        });

        if (selectAllEl) {
            selectAllEl.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
            selectAllEl.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
        }
        if (deleteBtn) {
            deleteBtn.disabled = checked.length === 0;
        }
        if (countSpan) {
            countSpan.textContent = checked.length > 0 ? '(' + checked.length + ')' : '';
        }
    }

    document.querySelectorAll('.bulk-select-all').forEach(function(selectAllEl) {
        selectAllEl.addEventListener('change', function() {
            var group = selectAllEl.getAttribute('data-group');
            document.querySelectorAll('.bulk-item-checkbox[data-group="' + group + '"]').forEach(function(cb) {
                cb.checked = selectAllEl.checked;
            });
            syncBulkSelection(group);
        });
    });

    document.querySelectorAll('.bulk-item-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            syncBulkSelection(cb.getAttribute('data-group'));
        });
    });

    syncBulkSelection('offers');
    syncBulkSelection('notices');
});

function confirmBulkNoticeDelete(group) {
    var checkedCount = document.querySelectorAll('.bulk-item-checkbox[data-group="' + group + '"]:checked').length;
    if (checkedCount === 0) return false;
    var label = (group === 'offers') ? 'course offer(s)' : 'notice(s)';
    return confirm('Are you sure you want to delete ' + checkedCount + ' selected ' + label + '?');
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
