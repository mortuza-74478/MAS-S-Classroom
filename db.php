<?php
/**
 * Database Connection & Global Helpers
 * MAS'S Classroom - University DBMS Project
 */

// Start output buffering if not already active to prevent header issues
if (ob_get_level() === 0) {
    ob_start();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration (Standard XAMPP defaults — adjust here for custom local setups)
$db_host = 'localhost';
$db_name = 'course_db';
$db_user = 'root';
$db_pass = '';
$db_charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // If database does not exist (SQLSTATE 1049: Unknown database), try to offer setup
    if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
        // Attempt auto-redirect to setup.php if accessible
        if (basename($_SERVER['PHP_SELF']) !== 'setup.php') {
            header('Location: setup.php');
            exit;
        }
    }

    // Display user-friendly connection error card with troubleshooting steps
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Connection Notice - MAS'S Classroom</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    </head>
    <body class="bg-light py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-lg border-0 rounded-4">
                        <div class="card-body p-4 text-center">
                            <div class="text-danger mb-3">
                                <i class="bi bi-exclamation-triangle-fill" style="font-size: 3.5rem;"></i>
                            </div>
                            <h3 class="fw-bold text-dark">Database Connection Issue</h3>
                            <p class="text-muted">Unable to connect to the MySQL database server.</p>
                            
                            <div class="alert alert-warning text-start small">
                                <strong>Technical Error:</strong><br>
                                <code><?= htmlspecialchars($e->getMessage()) ?></code>
                            </div>

                            <div class="text-start mb-4">
                                <h6 class="fw-bold">How to resolve this in XAMPP:</h6>
                                <ol class="text-muted small ps-3">
                                    <li>Open your <strong>XAMPP Control Panel</strong> and click <strong>Start</strong> next to <strong>Apache</strong> and <strong>MySQL</strong>.</li>
                                    <li>Either click <strong>Run 1-Click Database Setup</strong> below, or open <strong>phpMyAdmin</strong> and import <code>database_setup.sql</code> from the project root.</li>
                                    <li>Reload this page once the <code>course_db</code> database is ready.</li>
                                </ol>
                            </div>

                            <a href="setup.php" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm">
                                <i class="bi bi-gear-fill me-1"></i> Run 1-Click Database Setup
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Auto-migrate schema updates if columns are missing in live database
try {
    $pdo->query("SELECT profile_image FROM students LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `profile_image` VARCHAR(255) DEFAULT NULL AFTER `password`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT role FROM students LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'student' AFTER `email`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT payment_method FROM enrollments LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'bKash' AFTER `course_id`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT wallet_number FROM enrollments LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `wallet_number` VARCHAR(50) DEFAULT NULL AFTER `payment_method`");
        try {
            $pdo->exec("UPDATE `enrollments` SET `wallet_number` = `payment_number` WHERE `wallet_number` IS NULL AND `payment_number` IS NOT NULL");
        } catch (Exception $eCopy) {}
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT paid_amount FROM enrollments LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `paid_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `transaction_id`");
        $pdo->exec("UPDATE `enrollments` e JOIN `courses` c ON e.course_id = c.course_id SET e.paid_amount = c.price WHERE e.paid_amount IS NULL OR e.paid_amount <= 0");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT status FROM enrollments LIMIT 1");
    try {
        $statusCol = $pdo->query("SHOW COLUMNS FROM `enrollments` LIKE 'status'")->fetch();
        if ($statusCol && strpos(strtolower($statusCol['Type'] ?? ''), 'dropped') === false) {
            $pdo->exec("ALTER TABLE `enrollments` MODIFY COLUMN `status` ENUM('pending', 'approved', 'rejected', 'dropped') NOT NULL DEFAULT 'pending'");
        }
    } catch (Exception $eMod) {}
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `status` ENUM('pending', 'approved', 'rejected', 'dropped') NOT NULL DEFAULT 'approved' AFTER `paid_amount`");
        $pdo->exec("ALTER TABLE `enrollments` MODIFY COLUMN `status` ENUM('pending', 'approved', 'rejected', 'dropped') NOT NULL DEFAULT 'pending'");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT verified_at FROM enrollments LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `verified_at` DATETIME DEFAULT NULL AFTER `enrollment_date`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT dropped_at FROM enrollments LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `dropped_at` DATETIME DEFAULT NULL AFTER `verified_at`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT approval_seen FROM enrollments LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `enrollments` ADD COLUMN `approval_seen` TINYINT(1) NOT NULL DEFAULT 1 AFTER `verified_at`");
        $pdo->exec("ALTER TABLE `enrollments` MODIFY COLUMN `approval_seen` TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT reset_token FROM students LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL AFTER `profile_image`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT reset_expires FROM students LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `reset_expires` DATETIME DEFAULT NULL AFTER `reset_token`");
    } catch (Exception $ex) {}
}

// Auto-migrate phone column in students
try {
    $pdo->query("SELECT phone FROM students LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `email`");
    } catch (Exception $ex) {}
}

// Auto-migrate phone column in instructors
try {
    $pdo->query("SELECT phone FROM instructors LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `instructors` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `email`");
    } catch (Exception $ex) {}
}

// Auto-migrate profile_image and avatar columns in instructors
try {
    $pdo->query("SELECT profile_image FROM instructors LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `instructors` ADD COLUMN `profile_image` VARCHAR(255) DEFAULT NULL AFTER `bio`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT avatar FROM instructors LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `instructors` ADD COLUMN `avatar` VARCHAR(255) DEFAULT NULL AFTER `bio`");
        $pdo->exec("UPDATE `instructors` SET `avatar` = `profile_image` WHERE `avatar` IS NULL AND `profile_image` IS NOT NULL");
    } catch (Exception $ex) {}
}

// Backfill any empty instructors.avatar from matching students.profile_image (uploaded via Update Profile)
try {
    $pdo->exec("UPDATE `instructors` i
                INNER JOIN `students` s
                    ON (LOWER(TRIM(i.email)) = LOWER(TRIM(s.email)) OR LOWER(TRIM(i.name)) = LOWER(TRIM(s.name)))
                SET i.`avatar` = s.`profile_image`,
                    i.`profile_image` = COALESCE(NULLIF(i.`profile_image`, ''), s.`profile_image`)
                WHERE (i.`avatar` IS NULL OR i.`avatar` = '')
                  AND s.`profile_image` IS NOT NULL
                  AND s.`profile_image` != ''");
} catch (Exception $e) {}

// Auto-migrate thumbnail column in courses
try {
    $pdo->query("SELECT thumbnail FROM courses LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `courses` ADD COLUMN `thumbnail` VARCHAR(255) DEFAULT NULL AFTER `category`");
    } catch (Exception $ex) {}
}

// Ensure created_at / enrollment_date columns exist across relational tables
foreach (['instructors', 'courses', 'students', 'notices'] as $tblName) {
    try {
        $pdo->query("SELECT created_at FROM `{$tblName}` LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE `{$tblName}` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        } catch (Exception $ex) {}
    }
}

// Auto-create notices table if missing
try {
    $pdo->query("SELECT notice_id FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `notices` (
            `notice_id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT NOT NULL,
            `type` ENUM('Notice', 'Offer') NOT NULL DEFAULT 'Notice',
            `discount_badge` VARCHAR(50) DEFAULT NULL,
            `instructor_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_notices_instructor` 
                FOREIGN KEY (`instructor_id`) 
                REFERENCES `instructors` (`instructor_id`) 
                ON DELETE SET NULL 
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $ex) {}
}

// Auto-migrate columns in notices table if missing
try {
    $pdo->query("SELECT discount_badge FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `discount_badge` VARCHAR(50) DEFAULT NULL");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT type FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `type` ENUM('Notice', 'Offer') NOT NULL DEFAULT 'Notice'");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT instructor_id FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `instructor_id` INT DEFAULT NULL");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT course_id FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `course_id` INT DEFAULT NULL AFTER `instructor_id`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT discount_percent FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `discount_percent` DECIMAL(5, 2) DEFAULT NULL AFTER `discount_badge`");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT offer_price FROM notices LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `offer_price` DECIMAL(10, 2) DEFAULT NULL AFTER `discount_percent`");
    } catch (Exception $ex) {}
}

// Auto-link and normalize existing notices/offers for brand & course consistency
try {
    // 1. Replace any legacy SAS Classroom / SAS25 references in stored notices & offers
    $pdo->exec("UPDATE notices SET title = REPLACE(title, 'SAS Classroom', \"MAS'S Classroom\") WHERE title LIKE '%SAS Classroom%'");
    $pdo->exec("UPDATE notices SET description = REPLACE(description, 'SAS Classroom', \"MAS'S Classroom\") WHERE description LIKE '%SAS Classroom%'");
    $pdo->exec("UPDATE notices SET description = REPLACE(description, 'SAS25', 'MAS25') WHERE description LIKE '%SAS25%'");

    // 2. Ensure Web Development offers link accurately to Course #2 (Full-Stack Web Development with PHP & MySQL)
    $pdo->exec("UPDATE notices SET course_id = 2 WHERE (title LIKE '%Web Development%' OR title LIKE '%Full-Stack Web%' OR description LIKE '%Full-Stack Web Development%') AND title NOT LIKE '%Database%' AND title NOT LIKE '%DBMS%' AND title NOT LIKE '%Security%' AND (course_id IS NULL OR course_id = 1)");

    // 3. Auto-link unlinked notices/offers to their matching courses
    $pdo->exec("UPDATE notices SET course_id = 1 WHERE (title LIKE '%DBMS%' OR title LIKE '%Database%' OR title LIKE '%SQL%' OR title LIKE '%Project%') AND course_id IS NULL");
    $pdo->exec("UPDATE notices SET course_id = 2 WHERE (title LIKE '%Web%' OR title LIKE '%PHP%') AND title NOT LIKE '%Security%' AND course_id IS NULL");
    $pdo->exec("UPDATE notices SET course_id = 3 WHERE (title LIKE '%Python%' OR title LIKE '%Data Science%' OR title LIKE '%Machine Learning%') AND title NOT LIKE '%PyTorch%' AND title NOT LIKE '%Deep Learning%' AND course_id IS NULL");
    $pdo->exec("UPDATE notices SET course_id = 4 WHERE (title LIKE '%Deep Learning%' OR title LIKE '%PyTorch%' OR title LIKE '%AI%' OR title LIKE '%500%') AND course_id IS NULL");
    $pdo->exec("UPDATE notices SET course_id = 5 WHERE (title LIKE '%DevOps%' OR title LIKE '%Docker%' OR title LIKE '%Cloud%' OR title LIKE '%Workshop%') AND course_id IS NULL");
    $pdo->exec("UPDATE notices SET course_id = 6 WHERE (title LIKE '%Security%' OR title LIKE '%Hacking%') AND course_id IS NULL");

    // 4. Sync instructor_id from linked course where missing
    $pdo->exec("UPDATE notices n JOIN courses c ON n.course_id = c.course_id SET n.instructor_id = c.instructor_id WHERE n.instructor_id IS NULL");
    $pdo->exec("UPDATE notices SET instructor_id = 1 WHERE (title LIKE '%Database%' OR title LIKE '%DBMS%' OR title LIKE '%Web%' OR title LIKE '%Mid-Term%' OR title LIKE '%Project%') AND instructor_id IS NULL");
    $pdo->exec("UPDATE notices SET instructor_id = 2 WHERE (title LIKE '%Data%' OR title LIKE '%Python%' OR title LIKE '%AI%' OR title LIKE '%Deep Learning%') AND instructor_id IS NULL");
    $pdo->exec("UPDATE notices SET instructor_id = 3 WHERE (title LIKE '%DevOps%' OR title LIKE '%Cloud%' OR title LIKE '%Docker%' OR title LIKE '%Security%') AND instructor_id IS NULL");
    $pdo->exec("UPDATE notices SET instructor_id = 1 WHERE instructor_id IS NULL");
} catch (Exception $ex) {}

// Auto-seed sample notices and offers if empty
try {
    $countNotices = (int)$pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn();
    if ($countNotices === 0) {
        $stmtNoticeSeed = $pdo->prepare("INSERT INTO notices (title, description, type, discount_badge, instructor_id, course_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtNoticeSeed->execute([
            'DBMS & SQL Mastery Special Discount - 25% OFF',
            "Enroll in Database Systems & SQL Mastery at MAS'S Classroom this semester and get an instant 25% discount using coupon code MAS25 during bKash/Nagad checkout.",
            'Offer',
            '25% OFF',
            1,
            1,
            date('Y-m-d H:i:s', strtotime('-5 days'))
        ]);
        $stmtNoticeSeed->execute([
            'Web Development Bundle Offer - 20% OFF',
            "Special student offer available on Full-Stack Web Development with PHP & MySQL courses in MAS'S Classroom. Complete enrollment this week for an instant 20% discount.",
            'Offer',
            '20% OFF',
            1,
            2,
            date('Y-m-d H:i:s', strtotime('-4 days'))
        ]);
        $stmtNoticeSeed->execute([
            'AI & Deep Learning Mastery Bundle - Flat ৳500 Cashback',
            "Join Deep Learning & Neural Networks in PyTorch at MAS'S Classroom. Complete enrollment within this week to receive ৳500 instant course fee concession.",
            'Offer',
            '৳500 OFF',
            2,
            4,
            date('Y-m-d H:i:s', strtotime('-3 days'))
        ]);
        $stmtNoticeSeed->execute([
            'Live Workshop: Hands-on Cloud DevOps & Docker CI/CD',
            "An interactive 3-hour live workshop will be held this Friday at 8:00 PM for all enrolled MAS'S Classroom students. Please ensure Docker Desktop is installed on your workstation.",
            'Notice',
            'Workshop',
            3,
            5,
            date('Y-m-d H:i:s', strtotime('-2 days'))
        ]);
        $stmtNoticeSeed->execute([
            'Final Term Project Submission Guidelines & Rubric Published',
            'The final DBMS schema normalization project requirements and evaluation matrix for Database Systems & SQL Mastery are now live. Submit your normalized ER diagrams and SQL scripts by the 30th.',
            'Notice',
            'Academic',
            1,
            1,
            date('Y-m-d H:i:s', strtotime('-1 days'))
        ]);
    }

    // Ensure existing notices and offers have clear, accurate discount/category badges populated
    $pdo->exec("UPDATE notices SET discount_badge = '25% OFF' WHERE (title LIKE '%25% OFF%' OR description LIKE '%25%%') AND (discount_badge IS NULL OR discount_badge = '' OR discount_badge = 'Special Offer')");
    $pdo->exec("UPDATE notices SET discount_badge = '20% OFF' WHERE (title LIKE '%20% OFF%' OR title LIKE '%Web Development Bundle%' OR description LIKE '%20%%') AND (discount_badge IS NULL OR discount_badge = '' OR discount_badge = 'Special Offer')");
    $pdo->exec("UPDATE notices SET discount_badge = '৳500 OFF' WHERE (title LIKE '%500%' OR description LIKE '%500%') AND (discount_badge IS NULL OR discount_badge = '' OR discount_badge = 'Special Offer')");
    $pdo->exec("UPDATE notices SET discount_badge = 'Workshop' WHERE title LIKE '%Workshop%' AND (discount_badge IS NULL OR discount_badge = '')");
    $pdo->exec("UPDATE notices SET discount_badge = 'Academic' WHERE title LIKE '%Project%' AND (discount_badge IS NULL OR discount_badge = '')");
    $pdo->exec("UPDATE notices SET discount_badge = 'Notice' WHERE type = 'Notice' AND (discount_badge IS NULL OR discount_badge = '')");
    $pdo->exec("UPDATE notices SET discount_badge = '20% OFF' WHERE type = 'Offer' AND (discount_badge IS NULL OR discount_badge = '' OR discount_badge = 'Special Offer')");
} catch (Exception $ex) {}

// Non-destructive enrichment of legacy 1-line course descriptions if unchanged
try {
    $legacyDescriptions = [
        'Master relational database modeling, normalization (1NF to BCNF), indexing, complex JOINs, query optimization, and transaction ACID properties.'
            => 'Master relational database modeling, normalization (1NF to BCNF), B+ Tree indexing, complex SQL JOINs, CTEs, query execution plan optimization, and ACID transaction isolation with hands-on MySQL schema design labs.',
        'Build dynamic, secure, and production-ready web applications using HTML5, CSS3, modern Bootstrap 5, PHP PDO, and MySQL databases.'
            => 'Build dynamic, secure, production-ready web applications using responsive HTML5, CSS3, Bootstrap 5, session-based role guards, prepared PHP PDO statements, and transactional MySQL architecture.',
        'Comprehensive introduction to data analysis with Pandas, NumPy, statistical modeling, data visualization with Matplotlib, and Scikit-Learn.'
            => 'Comprehensive end-to-end training in numerical computing with NumPy, data cleaning and EDA with Pandas, statistical visualization with Matplotlib, and supervised ML pipelines using Scikit-Learn.',
        'Hands-on journey into computer vision, NLP transformers, and convolutional neural networks with real-world classification projects.'
            => 'Hands-on exploration of PyTorch tensors, autograd, convolutional neural networks (CNNs), attention transformers, and mixed-precision model training with real classification and NLP labs.',
        'Learn modern containerization with Docker, CI/CD automated deployment pipelines, and scalable cloud architectures on AWS.'
            => 'Master modern containerization with multi-stage Dockerfiles, multi-service Docker Compose orchestration, automated CI/CD release pipelines, TLS reverse proxies, and AWS cloud observability.',
        'Understand OWASP Top 10 vulnerabilities, SQL injection prevention, cross-site scripting (XSS) defenses, and defensive secure coding.'
            => 'Defend web platforms against OWASP Top 10 threats through hands-on SQL injection prevention, RBAC/IDOR auditing, XSS/CSRF hardening, Content Security Policy, and penetration testing labs.'
    ];
    $stmtUpdDesc = $pdo->prepare("UPDATE courses SET description = ? WHERE description = ?");
    foreach ($legacyDescriptions as $oldDesc => $newDesc) {
        $stmtUpdDesc->execute([$newDesc, $oldDesc]);
    }
} catch (Exception $ex) {}

// Sync courses.thumbnail to the canonical 1280x720 images/courses/ files and clear stale deleted filenames
try {
    $stmtCoursesThumb = $pdo->query("SELECT course_id, title, thumbnail FROM courses");
    $stmtUpdThumb = $pdo->prepare("UPDATE courses SET thumbnail = :thumbnail WHERE course_id = :course_id");
    foreach ($stmtCoursesThumb->fetchAll() as $cThumbRow) {
        $resolvedThumb = resolveCourseThumbnailFilePath($cThumbRow['title'], $cThumbRow['thumbnail'] ?? null);
        if ($resolvedThumb !== ($cThumbRow['thumbnail'] ?? null)) {
            $stmtUpdThumb->execute([
                ':thumbnail' => $resolvedThumb,
                ':course_id' => $cThumbRow['course_id']
            ]);
        }
    }
} catch (Exception $ex) {}

// Ensure unique constraint on enrollments (student_id, course_id)
try {
    $pdo->exec("ALTER TABLE `enrollments` ADD CONSTRAINT `unique_student_course` UNIQUE (`student_id`, `course_id`)");
} catch (Exception $ex) {}

// Auto-seed/verify default admin accounts and phone numbers
try {
    $admins = [
        ['name' => 'Golam Mortuza Shipu', 'email' => 'gmortuza2003@gmail.com', 'phone' => '01709016144'],
        ['name' => 'Shahnewas Kabir Shezan', 'email' => 'shahnewaskabir79@gmail.com', 'phone' => '01850288594'],
        ['name' => 'Anik Rizvi Lam', 'email' => 'anikrizvilam123@gmail.com', 'phone' => '01782264569'],
    ];
    // Hash for admin123
    $defaultAdminHash = '$2y$12$LqAcKFAWS22627zeC8WXGuEm5h3P7c5Fkolp6FdGyE6vjDmdWY20m';

    foreach ($admins as $adm) {
        $stmtCheck = $pdo->prepare("SELECT student_id, role, password, phone FROM students WHERE email = :email LIMIT 1");
        $stmtCheck->execute([':email' => $adm['email']]);
        $existing = $stmtCheck->fetch();

        if (!$existing) {
            $stmtIns = $pdo->prepare("INSERT INTO students (name, email, phone, role, password) VALUES (:name, :email, :phone, 'admin', :pass)");
            $stmtIns->execute([
                ':name'  => $adm['name'],
                ':email' => $adm['email'],
                ':phone' => $adm['phone'],
                ':pass'  => $defaultAdminHash
            ]);
        } else {
            // Ensure role is admin
            if ($existing['role'] !== 'admin') {
                $pdo->prepare("UPDATE students SET role = 'admin' WHERE student_id = :id")->execute([':id' => $existing['student_id']]);
            }
            // Ensure phone is set
            if (empty($existing['phone'])) {
                $pdo->prepare("UPDATE students SET phone = :phone WHERE student_id = :id")->execute([':phone' => $adm['phone'], ':id' => $existing['student_id']]);
            }
            // Ensure password matches admin123 if not already valid
            if (!password_verify('admin123', $existing['password']) && $existing['password'] !== 'admin123') {
                $pdo->prepare("UPDATE students SET password = :pass WHERE student_id = :id")->execute([
                    ':pass' => $defaultAdminHash,
                    ':id'   => $existing['student_id']
                ]);
            }
        }

        // Sync phone to matching instructors row
        try {
            $pdo->prepare("UPDATE instructors SET phone = :phone WHERE email = :email AND (phone IS NULL OR phone = '')")->execute([
                ':phone' => $adm['phone'],
                ':email' => $adm['email']
            ]);
        } catch (Exception $eSync) {}
    }
} catch (Exception $ex) {}

/**
 * Authentication & Helper Functions
 */

// Check if student is currently logged in
function isLoggedIn() {
    return isset($_SESSION['student_id']) && !empty($_SESSION['student_id']);
}

// Check if currently logged in user is an Administrator
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Check if logged-in user is an Administrator or registered Instructor
function isCurrentUserInstructorOrAdmin(PDO $pdo, $studentId) {
    if (isAdmin()) {
        return true;
    }
    $studentId = (int)$studentId;
    if ($studentId <= 0) {
        return false;
    }
    try {
        $stmtU = $pdo->prepare("SELECT name, email, role FROM students WHERE student_id = :id LIMIT 1");
        $stmtU->execute([':id' => $studentId]);
        $uRow = $stmtU->fetch();
        if (!$uRow) {
            return false;
        }
        if (($uRow['role'] ?? 'student') === 'admin') {
            return true;
        }
        $stmtI = $pdo->prepare("SELECT instructor_id FROM instructors WHERE email = :email OR name = :name LIMIT 1");
        $stmtI->execute([
            ':email' => $uRow['email'] ?? '',
            ':name'  => $uRow['name'] ?? ''
        ]);
        return (bool)$stmtI->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// Enforce Administrator access (Redirects non-admins with alert)
function requireAdmin($redirect = 'admin-login.php') {
    if (!isAdmin()) {
        setFlashMessage('danger', '<strong>Access Denied:</strong> Administrator privileges are required to access the Platform Management Center.');
        if (!isLoggedIn()) {
            $target = "{$redirect}?redirect=" . urlencode($_SERVER['REQUEST_URI']);
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
            header("Location: {$target}");
        } else {
            $target = "index.php";
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
            header("Location: {$target}");
        }
        exit;
    }
}

// Get logged-in student details (with latest profile data, role, phone and image)
function getCurrentStudent() {
    if (!isLoggedIn()) {
        return null;
    }
    global $pdo;
    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT student_id, name, email, phone, role, profile_image FROM students WHERE student_id = :id LIMIT 1");
            $stmt->execute([':id' => $_SESSION['student_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $_SESSION['student_name'] = $row['name'];
                $_SESSION['student_email'] = $row['email'];
                $_SESSION['student_phone'] = $row['phone'] ?? '';
                $_SESSION['role'] = $row['role'] ?? 'student';
                $_SESSION['student_profile_image'] = $row['profile_image'];
                return [
                    'id'            => $row['student_id'],
                    'name'          => $row['name'],
                    'email'         => $row['email'],
                    'phone'         => $row['phone'] ?? '',
                    'role'          => $row['role'] ?? 'student',
                    'profile_image' => $row['profile_image']
                ];
            }
        } catch (Exception $e) {}
    }
    return [
        'id'            => $_SESSION['student_id'],
        'name'          => $_SESSION['student_name'] ?? 'Student',
        'email'         => $_SESSION['student_email'] ?? '',
        'phone'         => $_SESSION['student_phone'] ?? '',
        'role'          => $_SESSION['role'] ?? 'student',
        'profile_image' => $_SESSION['student_profile_image'] ?? null
    ];
}

// Handle Google Quick Sign-In for localhost / demonstration
function handleGoogleAuth($pdo, $redirect = 'my_courses.php', $enrollCourseId = 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'google_auth') {
        $name = trim($_POST['google_name'] ?? '');
        $email = trim($_POST['google_email'] ?? '');
        $redirect = !empty($_POST['redirect']) ? $_POST['redirect'] : $redirect;
        $enrollCourseId = isset($_POST['enroll_course']) ? (int)$_POST['enroll_course'] : $enrollCourseId;

        if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage('danger', 'Please enter a valid Google Account Name and Gmail address.');
            return;
        }

        try {
            $stmt = $pdo->prepare("SELECT student_id, name, email, phone, role, profile_image FROM students WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $student = $stmt->fetch();

            if (!$student) {
                // Auto-create student account with random secure password
                $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $stmtInsert = $pdo->prepare("INSERT INTO students (name, email, role, password) VALUES (:name, :email, 'student', :password)");
                $stmtInsert->execute([
                    ':name'     => $name,
                    ':email'    => $email,
                    ':password' => $randomPass
                ]);
                $studentId = $pdo->lastInsertId();
                $profileImage = null;
                $phone = '';
                $role = 'student';
            } else {
                $role = $student['role'] ?? 'student';
                if ($role === 'admin') {
                    setFlashMessage('danger', '<strong>Administrator Account Protected:</strong> Admin accounts must sign in with a password on the <strong>Admin Login</strong> tab.');
                    return;
                }
                $studentId = $student['student_id'];
                $profileImage = $student['profile_image'];
                $phone = $student['phone'] ?? '';
                if ($student['name'] !== $name) {
                    $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name WHERE student_id = :id");
                    $stmtUpdate->execute([':name' => $name, ':id' => $studentId]);
                }
            }

            $_SESSION['student_id'] = $studentId;
            $_SESSION['student_name'] = $name;
            $_SESSION['student_email'] = $email;
            $_SESSION['student_phone'] = $phone;
            $_SESSION['role'] = $role;
            $_SESSION['student_profile_image'] = $profileImage;

            setFlashMessage('success', "🚀 Welcome! Successfully signed in via Google as <strong>" . htmlspecialchars($name) . "</strong> (" . htmlspecialchars($email) . ").");

            $safeRedirect = sanitizeRedirectPath($redirect, 'my_courses.php');
            $target = ($enrollCourseId > 0) ? "checkout.php?course_id={$enrollCourseId}" : $safeRedirect;
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
            header("Location: {$target}");
            exit;
        } catch (PDOException $e) {
            error_log('Google Auth error: ' . $e->getMessage());
            setFlashMessage('danger', 'Unable to complete Google sign-in right now. Please try again.');
        }
    }
}

// Set a flash notification message (e.g. 'success', 'danger', 'info', 'warning')
function setFlashMessage($type, $message) {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message
    ];
}

// Display and clear stored flash messages
function displayFlashMessages() {
    if (isset($_SESSION['flash_messages']) && !empty($_SESSION['flash_messages'])) {
        foreach ($_SESSION['flash_messages'] as $flash) {
            $type = htmlspecialchars($flash['type']);
            $msg  = $flash['message']; // Allow HTML like badges or bold tags if safely passed
            $icon = 'bi-info-circle-fill';
            if ($type === 'success') $icon = 'bi-check-circle-fill';
            elseif ($type === 'danger') $icon = 'bi-exclamation-triangle-fill';
            elseif ($type === 'warning') $icon = 'bi-exclamation-diamond-fill';

            echo "<div class='alert alert-{$type} alert-dismissible fade show d-flex align-items-center shadow-sm rounded-3' role='alert'>
                    <i class='bi {$icon} me-2 fs-5'></i>
                    <div>{$msg}</div>
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                  </div>";
        }
        unset($_SESSION['flash_messages']);
    }
}

// Sanitize local relative redirect paths to prevent open-redirect or malformed targets
function sanitizeRedirectPath($rawRedirect, $default = 'my_courses.php') {
    $path = trim((string)($rawRedirect ?? ''));
    if ($path === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $path) || preg_match('/[\r\n]/', $path)) {
        return $default;
    }
    return ltrim($path, '/');
}

// Safely format nullable database dates without PHP 8 deprecation warnings
function formatDateSafe($dateValue, $format = 'M d, Y', $fallback = 'N/A') {
    $str = trim((string)($dateValue ?? ''));
    if ($str === '') {
        return $fallback;
    }
    $ts = strtotime($str);
    return ($ts !== false) ? date($format, $ts) : $fallback;
}

// Sanitize user input strings
function sanitize($data) {
    return htmlspecialchars(trim((string)($data ?? '')), ENT_QUOTES, 'UTF-8');
}

// Format price with Bangladeshi Taka (৳) currency symbol
function formatPrice($price) {
    $num = floatval($price);
    if ($num <= 0) {
        return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">FREE</span>';
    }
    return '৳' . number_format($num);
}

/**
 * Calculate discounted price and offer details for a given course
 * 
 * @param float $originalPrice
 * @param string $discountBadge (e.g. "25% OFF", "৳500 OFF", "500", "20%")
 * @return array ['has_discount' => bool, 'discount_amount' => float, 'discounted_price' => float, 'badge' => string]
 */
function calculateCourseDiscount($originalPrice, $discountBadge, $storedDiscountPercent = null, $storedOfferPrice = null) {
    $originalPrice = (float)$originalPrice;
    $badge = trim((string)$discountBadge);

    if ($storedDiscountPercent !== null && (float)$storedDiscountPercent > 0 && (float)$storedDiscountPercent <= 100) {
        $pct = (float)$storedDiscountPercent;
        $finalPrice = ($storedOfferPrice !== null && (float)$storedOfferPrice >= 0 && (float)$storedOfferPrice < $originalPrice)
            ? (float)$storedOfferPrice
            : max(0, round($originalPrice - (($originalPrice * $pct) / 100), 2));
        $discountAmount = max(0, round($originalPrice - $finalPrice, 2));
        $pctLabel = (fmod($pct, 1.0) === 0.0) ? (int)$pct : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
        return [
            'has_discount'     => true,
            'discount_amount'  => $discountAmount,
            'discounted_price' => $finalPrice,
            'discount_percent' => $pct,
            'badge'            => $pctLabel . '% OFF'
        ];
    }

    if ($storedOfferPrice !== null && (float)$storedOfferPrice > 0 && (float)$storedOfferPrice < $originalPrice) {
        $finalPrice = (float)$storedOfferPrice;
        $discountAmount = round($originalPrice - $finalPrice, 2);
        return [
            'has_discount'     => true,
            'discount_amount'  => $discountAmount,
            'discounted_price' => $finalPrice,
            'discount_percent' => $originalPrice > 0 ? round(($discountAmount / $originalPrice) * 100, 2) : 0.0,
            'badge'            => !empty($badge) ? $badge : '৳' . number_format($discountAmount) . ' OFF'
        ];
    }
    
    if (empty($badge)) {
        return [
            'has_discount'     => false,
            'discount_amount'  => 0.0,
            'discounted_price' => $originalPrice,
            'discount_percent' => 0.0,
            'badge'            => ''
        ];
    }
    
    // Percentage pattern: "25% OFF", "20%", "30 %"
    if (preg_match('/(\d+(?:\.\d+)?)\s*%/i', $badge, $matches)) {
        $percent = (float)$matches[1];
        if ($percent > 0 && $percent <= 100) {
            $discountAmount = round(($originalPrice * $percent) / 100, 2);
            $finalPrice = max(0, $originalPrice - $discountAmount);
            return [
                'has_discount'     => true,
                'discount_amount'  => $discountAmount,
                'discounted_price' => $finalPrice,
                'discount_percent' => $percent,
                'badge'            => (int)$percent . '% OFF'
            ];
        }
    }
    
    // Taka amount pattern: "৳500 OFF", "500 OFF", "500"
    if (preg_match('/(?:৳|tk|taka)?\s*(\d+(?:\.\d+)?)\s*(?:off|cashback|tk|taka)?/i', $badge, $matches)) {
        $amount = (float)$matches[1];
        if ($amount > 0 && $amount < $originalPrice) {
            $finalPrice = max(0, $originalPrice - $amount);
            return [
                'has_discount'     => true,
                'discount_amount'  => $amount,
                'discounted_price' => $finalPrice,
                'discount_percent' => 0.0,
                'badge'            => '৳' . number_format($amount) . ' OFF'
            ];
        }
    }
    
    return [
        'has_discount'     => false,
        'discount_amount'  => 0.0,
        'discounted_price' => $originalPrice,
        'discount_percent' => 0.0,
        'badge'            => $badge
    ];
}

/**
 * Fetch all active course offers
 */
function getActiveCourseOffers($pdo) {
    if (!$pdo) return [];
    try {
        $stmt = $pdo->query("
            SELECT n.*,
                   COALESCE(i.name, ci.name, 'Golam Mortuza Shipu') AS instructor_name,
                   COALESCE(i.expertise, ci.expertise) AS instructor_expertise,
                   c.title AS course_title, c.category AS course_category, c.price AS course_price, c.description AS course_description, c.thumbnail AS course_thumbnail
            FROM notices n
            LEFT JOIN instructors i ON n.instructor_id = i.instructor_id
            LEFT JOIN courses c ON n.course_id = c.course_id
            LEFT JOIN instructors ci ON c.instructor_id = ci.instructor_id
            WHERE LOWER(n.type) = 'offer'
            ORDER BY n.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Match an offer to a specific course ID or title
 */
function getOfferForCourse($courseId, $courseTitle, $offersList) {
    $courseId = (int)$courseId;
    $courseTitleLower = strtolower(trim((string)$courseTitle));
    
    // 1. Direct match on course_id
    foreach ($offersList as $off) {
        if (!empty($off['course_id']) && (int)$off['course_id'] === $courseId) {
            return $off;
        }
    }
    
    // 2. Keyword/domain match (only for unlinked offers where course_id is not explicitly set to another course)
    foreach ($offersList as $off) {
        if (!empty($off['course_id'])) {
            continue;
        }
        $offText = strtolower(($off['title'] ?? '') . ' ' . ($off['description'] ?? ''));
        if ($courseId === 1 && (strpos($offText, 'database') !== false || strpos($offText, 'dbms') !== false || strpos($offText, 'sql') !== false)) {
            return $off;
        }
        if ($courseId === 2 && (strpos($offText, 'web') !== false || strpos($offText, 'php') !== false) && strpos($offText, 'security') === false) {
            return $off;
        }
        if ($courseId === 3 && (strpos($offText, 'data science') !== false || strpos($offText, 'python') !== false) && strpos($offText, 'deep learning') === false) {
            return $off;
        }
        if ($courseId === 4 && (strpos($offText, 'deep learning') !== false || strpos($offText, 'ai') !== false || strpos($offText, 'pytorch') !== false)) {
            return $off;
        }
        if ($courseId === 5 && (strpos($offText, 'cloud') !== false || strpos($offText, 'devops') !== false || strpos($offText, 'docker') !== false)) {
            return $off;
        }
        if ($courseId === 6 && (strpos($offText, 'security') !== false || strpos($offText, 'hacking') !== false)) {
            return $off;
        }
    }
    
    return null;
}

/**
 * Canonical 1280x720 course thumbnail filename map inside images/courses/.
 *
 * @return array<string, string>
 */
function getCourseThumbnailFilenameMap() {
    return [
        'database systems & sql mastery'              => 'images/courses/Database_Systems_SQL_Mastery_1280x720.png',
        'full-stack web development with php & mysql' => 'images/courses/Full-Stack_Web_Development_with_PHP_MySQL_1280x720.png',
        'python for data science & machine learning'  => 'images/courses/Python_for_Data_Science_Machine_Learning_1280x720.png',
        'deep learning & neural networks in pytorch'  => 'images/courses/Deep_Learning_Neural_Networks_in_PyTorch_1280x720.png',
        'cloud infrastructure & devops with docker'   => 'images/courses/Cloud_Infrastructure_DevOps_with_Docker_1280x720.png',
        'web application security & ethical hacking'  => 'images/courses/Web_Application_Security_Ethical_Hacking_1280x720.png',
    ];
}

/**
 * Resolve a verified relative filesystem path for a course thumbnail image.
 * Checks the exact 1280x720 mapping inside images/courses/ first, then a valid
 * stored DB path if present on disk, and never returns paths to deleted files.
 *
 * @param string      $courseTitle
 * @param string|null $dbThumbnail
 * @return string|null
 */
function resolveCourseThumbnailFilePath($courseTitle, $dbThumbnail = null) {
    $title = trim((string)$courseTitle);
    $exactMap = getCourseThumbnailFilenameMap();

    if ($title !== '') {
        $key = strtolower($title);
        if (isset($exactMap[$key])) {
            $mappedRelPath = $exactMap[$key];
            if (is_file(__DIR__ . '/' . $mappedRelPath)) {
                return $mappedRelPath;
            }
        }
    }

    // Check explicit DB thumbnail path only if it points to an existing local image file
    $stored = trim((string)$dbThumbnail);
    if ($stored !== '') {
        $stored = str_replace(['\\', '×', '&times;'], ['/', 'x', 'x'], $stored);
        $stored = ltrim($stored, './');
        if (strpos($stored, '..') === false) {
            $candidates = [$stored];
            if (strpos($stored, '/') === false) {
                $candidates[] = 'images/courses/' . $stored;
            }
            foreach ($candidates as $relCandidate) {
                if ((strpos($relCandidate, 'images/courses/') === 0 || strpos($relCandidate, 'uploads/') === 0) && is_file(__DIR__ . '/' . $relCandidate)) {
                    return $relCandidate;
                }
            }
        }
    }

    if ($title === '') {
        return null;
    }

    // Dynamic fallback inside images/courses/ for newly created courses
    $cleanSpaces = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[\\\\\/:*?"<>|&]/', ' ', $title)));
    $safeRaw = trim((string)preg_replace('/[\\\\\/:*?"<>|]/', '', $title));
    $bases = array_filter(array_unique([
        str_replace(' ', '_', $cleanSpaces) . '_1280x720',
        str_replace(' ', '_', $safeRaw) . '_1280x720',
        str_replace(' ', '_', $cleanSpaces),
        str_replace(' ', '-', $cleanSpaces),
        $safeRaw,
    ]));

    foreach ($bases as $base) {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $relPath = 'images/courses/' . $base . '.' . $ext;
            if (is_file(__DIR__ . '/' . $relPath)) {
                return $relPath;
            }
        }
    }

    return null;
}

/**
 * Resolve course thumbnail image URL (checks images/courses/<exact_1280x720_filename> first)
 * URL-encodes path segments cleanly so spaces and '&' load reliably.
 *
 * @param string      $courseTitle
 * @param string|null $dbThumbnail
 * @return string|null Relative URL path or null if no matching image exists
 */
function getCourseThumbnailUrl($courseTitle, $dbThumbnail = null) {
    $relPath = resolveCourseThumbnailFilePath($courseTitle, $dbThumbnail);
    if ($relPath === null) {
        return null;
    }
    return implode('/', array_map('rawurlencode', explode('/', $relPath)));
}

/**
 * Resolve instructor avatar URL from stored DB path or auto-matched file in images/instructors/.
 *
 * @param string      $instructorName
 * @param string|null $dbAvatar
 * @param int         $instructorId
 * @return string|null
 */
function getInstructorAvatarUrl($instructorName = '', $dbAvatar = null, $instructorId = 0) {
    $dbCandidates = is_array($dbAvatar) ? $dbAvatar : [$dbAvatar];
    foreach ($dbCandidates as $rawCandidate) {
        $stored = trim((string)$rawCandidate);
        if ($stored === '') {
            continue;
        }
        $stored = ltrim(str_replace('\\', '/', $stored), './');
        if (strpos($stored, '..') !== false) {
            continue;
        }
        $candidates = [$stored];
        if (strpos($stored, '/') === false) {
            $candidates[] = 'images/instructors/' . $stored;
            $candidates[] = 'uploads/' . $stored;
        }
        foreach ($candidates as $relCandidate) {
            if ((strpos($relCandidate, 'images/') === 0 || strpos($relCandidate, 'uploads/') === 0) && is_file(__DIR__ . '/' . $relCandidate)) {
                return implode('/', array_map('rawurlencode', explode('/', $relCandidate)));
            }
        }
    }

    $name = trim((string)$instructorName);
    $bases = [];
    if ((int)$instructorId > 0) {
        $bases[] = 'instructor_' . (int)$instructorId;
        $bases[] = (string)(int)$instructorId;
    }
    if ($name !== '') {
        $cleanSpaces = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[\\\\\/:*?"<>|&]/', ' ', $name)));
        $bases[] = str_replace(' ', '_', $cleanSpaces);
        $bases[] = str_replace(' ', '-', $cleanSpaces);
        $bases[] = $cleanSpaces;
        $bases[] = strtolower(str_replace(' ', '_', $cleanSpaces));
        $parts = array_values(array_filter(explode(' ', strtolower($cleanSpaces))));
        if (!empty($parts)) {
            $bases[] = end($parts);
            $bases[] = $parts[0];
        }
    }

    foreach (array_unique(array_filter($bases)) as $base) {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $relPath = 'images/instructors/' . $base . '.' . $ext;
            if (is_file(__DIR__ . '/' . $relPath)) {
                return implode('/', array_map('rawurlencode', explode('/', $relPath)));
            }
        }
    }

    return null;
}

/**
 * Resolve hero team group photo URL across expected filenames/locations
 * (handles double extensions like group_photo.jpg.png and URL-encodes spaces).
 *
 * @return string|null
 */
function getHeroGroupPhotoUrl() {
    $dirs = ['images/courses', 'images'];
    $names = [
        'group_photo.jpg',
        'group_photo.jpg.png',
        'group_photo.png',
        'group_photo.jpeg',
        'group_photo.webp',
        'group photo.jpg',
        'group photo.jpg.png',
        'group photo.png',
        'group photo.jpeg',
        'group photo.webp',
    ];

    foreach ($dirs as $dir) {
        foreach ($names as $filename) {
            $relPath = $dir . '/' . $filename;
            if (is_file(__DIR__ . '/' . $relPath)) {
                return implode('/', array_map('rawurlencode', explode('/', $relPath)));
            }
        }
    }

    return null;
}

/**
 * Resolve dedicated popup thumbnail image URL inside images/courses/course popup/,
 * matching both underscore and space/hyphen filename conventions with fallback to getCourseThumbnailUrl().
 *
 * @param string      $courseTitle
 * @param string|null $dbThumbnail
 * @return string|null
 */
function getCoursePopupThumbnailUrl($courseTitle, $dbThumbnail = null) {
    $title = trim((string)$courseTitle);
    if ($title === '') {
        return null;
    }

    $popupDirRel = 'images/courses/course popup';
    $popupDirUrl = 'images/courses/' . rawurlencode('course popup');

    $explicitPopupMap = [
        'database systems & sql mastery'              => 'Database_Systems_SQL_Mastery.png',
        'full-stack web development with php & mysql' => 'Full-Stack_Web_Development_with_PHP_MySQL.png',
        'python for data science & machine learning'  => 'Python_for_Data_Science_Machine_Learning.png',
        'deep learning & neural networks in pytorch'  => 'Deep_Learning_Neural_Networks_in_PyTorch.png',
        'cloud infrastructure & devops with docker'   => 'Cloud_Infrastructure_DevOps_with_Docker.png',
        'web application security & ethical hacking'  => 'Web_Application_Security_Ethical_Hacking.png',
    ];

    $candidates = [];
    $key = strtolower($title);
    if (isset($explicitPopupMap[$key])) {
        $candidates[] = $explicitPopupMap[$key];
    }

    // Generate underscore, space, and hyphen filename variants cleanly
    $cleanBase = preg_replace('/[\\\\\/:*?"<>|&]/', ' ', $title);
    $normalizedSpaces = trim(preg_replace('/\s+/', ' ', (string)$cleanBase));
    $safeRaw = trim((string)preg_replace('/[\\\\\/:*?"<>|]/', '', $title));

    $bases = array_filter(array_unique([
        str_replace(' ', '_', $normalizedSpaces),
        $normalizedSpaces,
        str_replace(' ', '-', $normalizedSpaces),
        str_replace(' ', '_', $safeRaw),
        $safeRaw,
    ]));

    foreach ($bases as $base) {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'avif'] as $ext) {
            $candidates[] = $base . '.' . $ext;
        }
    }

    foreach (array_unique($candidates) as $filename) {
        $fsPath = __DIR__ . '/' . $popupDirRel . '/' . $filename;
        if (is_file($fsPath)) {
            return $popupDirUrl . '/' . rawurlencode($filename);
        }
    }

    return getCourseThumbnailUrl($title, $dbThumbnail);
}

/**
 * Pick contextual fallback gradient class and Bootstrap icon based on category
 *
 * @param string $category
 * @return array [string $headerClass, string $iconClass]
 */
function getCourseCategoryVisual($category) {
    $cat = strtolower((string)$category);
    if (strpos($cat, 'database') !== false || strpos($cat, 'sql') !== false) return ['cat-database', 'bi-database-fill'];
    if (strpos($cat, 'web') !== false || strpos($cat, 'php') !== false) return ['cat-web', 'bi-code-slash'];
    if (strpos($cat, 'data') !== false || strpos($cat, 'intelligence') !== false || strpos($cat, 'ai') !== false || strpos($cat, 'machine') !== false) return ['cat-data', 'bi-cpu-fill'];
    if (strpos($cat, 'cloud') !== false || strpos($cat, 'devops') !== false || strpos($cat, 'docker') !== false) return ['cat-cloud', 'bi-cloud-check-fill'];
    if (strpos($cat, 'security') !== false || strpos($cat, 'cyber') !== false || strpos($cat, 'hacking') !== false) return ['cat-security', 'bi-shield-lock-fill'];
    return ['', 'bi-journal-code'];
}

/**
 * Provide structured rich overview paragraphs and practical lab takeaways for courses
 *
 * @param array $course
 * @return array{summary:string,takeaways:array<int,string>}
 */
function getCourseRichOverview(array $course) {
    $cid = (int)($course['course_id'] ?? 0);
    $title = (string)($course['title'] ?? '');
    $category = (string)($course['category'] ?? 'Computer Science');
    $tLower = strtolower($title . ' ' . $category);

    $catalogRichData = [
        1 => [
            'summary' => "Design production-grade relational schemas from scratch using Entity-Relationship diagrams, foreign-key integrity rules, and BCNF normalization. Across structured lectures and hands-on SQL labs, you will write multi-table JOINs, recursive CTEs, and window queries, inspect MySQL EXPLAIN plans, tune B+ Tree indexes, and reason through ACID isolation levels.",
            'takeaways' => [
                'ER modeling, 1NF–BCNF normalization, and lossless schema decomposition',
                'Complex SQL JOINs, correlated subqueries, CTEs, and analytical functions',
                'Query plan profiling (EXPLAIN), index tuning, and ACID transaction labs'
            ]
        ],
        2 => [
            'summary' => "Go from clean UI architecture to a complete full-stack web platform powered by PHP 8 and MySQL PDO. You will build responsive Bootstrap 5 glassmorphism interfaces, implement secure password hashing and session role guards, eliminate SQL injection with prepared statements, and ship transactional checkout & admin workflows.",
            'takeaways' => [
                'Responsive Bootstrap 5 components, dark/light themes, and modal workflows',
                'Session authentication, RBAC role guards, and password_hash security',
                'Parameterized PHP PDO CRUD, transactions, and file-upload hardening labs'
            ]
        ],
        3 => [
            'summary' => "Build a rigorous foundation in applied data science using Python's core scientific stack. You will vectorize numerical computations with NumPy, clean messy real-world datasets with Pandas, craft publication-ready Matplotlib charts, and train, cross-validate, and tune supervised machine learning models with Scikit-Learn.",
            'takeaways' => [
                'High-speed NumPy array broadcasting and vectorized matrix operations',
                'Exploratory data analysis, feature engineering, and Pandas aggregations',
                'End-to-end Scikit-Learn classification, regression, and tuning pipelines'
            ]
        ],
        4 => [
            'summary' => "Step inside modern deep learning architectures with PyTorch from tensors and automatic differentiation to ResNet vision models and attention transformers. Each unit pairs clear mathematical intuition with hands-on training notebooks covering CNN transfer learning, sequence tokenization, mixed precision, and inference export.",
            'takeaways' => [
                'PyTorch tensors, computational graphs, and custom autograd training loops',
                'Convolutional Neural Networks (CNNs) and ResNet image classification',
                'Self-attention transformers, LR scheduling, and production model serving'
            ]
        ],
        5 => [
            'summary' => "Package, orchestrate, and ship resilient multi-service applications using Docker and cloud DevOps workflows. You will author slim multi-stage Dockerfiles, network PHP/app and MySQL containers with Compose health checks, automate CI/CD build-and-test pipelines, and configure TLS proxies and observability on AWS.",
            'takeaways' => [
                'Multi-stage Dockerfiles, layer caching, and container security hardening',
                'Docker Compose networking, persistent volumes, and health checks',
                'Automated CI/CD pipelines, zero-downtime staging, and cloud logging'
            ]
        ],
        6 => [
            'summary' => "Think like both a security auditor and a defensive engineer to protect modern web applications against OWASP Top 10 attacks. Through practical exploit-vs-defense labs, you will remediate SQL injection, broken access control (IDOR), XSS, CSRF, and session hijacking while enforcing strict Content Security Policies.",
            'takeaways' => [
                'Attack surface mapping and OWASP Top 10 vulnerability assessment',
                'Defending SQLi, broken access control, XSS, CSRF, and session attacks',
                'Hands-on penetration testing lab and production hardening checklist'
            ]
        ]
    ];

    $track = null;
    if (isset($catalogRichData[$cid])) {
        $track = $catalogRichData[$cid];
    } else {
        $kwMap = [
            1 => ['database', 'sql', 'dbms'],
            2 => ['web', 'php', 'full-stack'],
            3 => ['data science', 'machine learning', 'python'],
            4 => ['deep learning', 'neural', 'pytorch'],
            5 => ['docker', 'devops', 'cloud'],
            6 => ['security', 'hacking', 'cyber']
        ];
        foreach ($kwMap as $tid => $kws) {
            foreach ($kws as $kw) {
                if (strpos($tLower, $kw) !== false) {
                    $track = $catalogRichData[$tid];
                    break 2;
                }
            }
        }
    }

    if ($track === null) {
        $track = [
            'summary' => "Gain structured theoretical grounding and hands-on implementation mastery in {$title}. Across guided lectures and applied exercises in {$category}, you will build real project artifacts and reinforce core engineering best practices.",
            'takeaways' => [
                "Core concepts, terminology, and architecture patterns in {$category}",
                "Guided practical labs and real-world problem solving for {$title}",
                'Structured lab assessments and capstone implementation checklist'
            ]
        ];
    }

    return [
        'summary' => $track['summary'],
        'takeaways' => $track['takeaways']
    ];
}

/**
 * Resolve badge class, icon, and logo image path for a payment method
 *
 * @param string $pm
 * @return array
 */
function getPaymentMethodBadgeMeta($pm) {
    $pm = (string)$pm;
    if ($pm === 'Nagad') {
        return ['class' => 'payment-tag-nagad', 'icon' => 'bi-lightning-charge-fill', 'logo' => 'images/nagad.png'];
    }
    if ($pm === 'Rocket') {
        return ['class' => 'payment-tag-rocket', 'icon' => 'bi-bank2', 'logo' => 'images/rocket.png'];
    }
    if ($pm === 'Debit / Credit Card') {
        return ['class' => 'payment-tag-card', 'icon' => 'bi-credit-card-2-front-fill', 'logo' => ''];
    }
    return ['class' => 'payment-tag-bkash', 'icon' => 'bi-phone-fill', 'logo' => 'images/bkash.png'];
}

/**
 * Fetch all pending enrollments with student and course details for admin verification
 *
 * @param PDO $pdo
 * @return array
 */
function getPendingEnrollments(PDO $pdo) {
    try {
        $sql = "SELECT e.enrollment_id, e.student_id, e.course_id, e.payment_method, e.wallet_number,
                       e.transaction_id, COALESCE(NULLIF(e.paid_amount, 0), c.price) AS paid_amount,
                       e.status, e.enrollment_date,
                       s.name AS student_name, s.email AS student_email, s.phone AS student_phone, s.profile_image AS student_profile_image,
                       c.title AS course_title, c.category AS course_category, c.price AS course_price,
                       i.name AS instructor_name
                FROM enrollments e
                JOIN students s ON e.student_id = s.student_id
                JOIN courses c ON e.course_id = c.course_id
                LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                WHERE LOWER(COALESCE(e.status, 'pending')) = 'pending'
                ORDER BY e.enrollment_date DESC";
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch complete enrollment and payment transaction audit history for Admins
 *
 * @param PDO $pdo
 * @return array
 */
function getAllEnrollmentHistory(PDO $pdo) {
    try {
        $sql = "SELECT e.enrollment_id, e.student_id, e.course_id, e.payment_method, e.wallet_number,
                       e.transaction_id, COALESCE(NULLIF(e.paid_amount, 0), c.price) AS paid_amount,
                       LOWER(COALESCE(e.status, 'approved')) AS status,
                       e.enrollment_date, e.verified_at, e.dropped_at,
                       s.name AS student_name, s.email AS student_email, s.phone AS student_phone, s.profile_image AS student_profile_image,
                       c.title AS course_title, c.category AS course_category, c.price AS course_price,
                       i.name AS instructor_name
                FROM enrollments e
                JOIN students s ON e.student_id = s.student_id
                JOIN courses c ON e.course_id = c.course_id
                LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
                ORDER BY e.enrollment_date DESC, e.enrollment_id DESC";
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Calculate total non-refundable collected platform revenue across approved and dropped enrollments
 *
 * @param PDO $pdo
 * @return float
 */
function getTotalNonRefundableRevenue(PDO $pdo) {
    try {
        $sql = "SELECT COALESCE(SUM(COALESCE(NULLIF(e.paid_amount, 0), c.price)), 0)
                FROM enrollments e
                JOIN courses c ON e.course_id = c.course_id
                WHERE LOWER(COALESCE(e.status, 'approved')) IN ('approved', 'dropped')";
        return (float)$pdo->query($sql)->fetchColumn();
    } catch (Exception $e) {
        return 0.0;
    }
}

/**
 * Approve or reject a pending enrollment and set appropriate flash notification
 *
 * @param PDO $pdo
 * @param string $decision ('approved'|'rejected')
 * @param int $enrollmentId
 * @return bool
 */
function processEnrollmentVerification(PDO $pdo, $decision, $enrollmentId) {
    $enrollmentId = (int)$enrollmentId;
    if ($enrollmentId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        setFlashMessage('danger', 'Invalid enrollment verification request.');
        return false;
    }

    try {
        $stmtInfo = $pdo->prepare("SELECT e.enrollment_id, e.transaction_id, s.name AS student_name, c.title AS course_title
                                   FROM enrollments e
                                   JOIN students s ON e.student_id = s.student_id
                                   JOIN courses c ON e.course_id = c.course_id
                                   WHERE e.enrollment_id = :id LIMIT 1");
        $stmtInfo->execute([':id' => $enrollmentId]);
        $row = $stmtInfo->fetch();

        if (!$row) {
            setFlashMessage('danger', 'Enrollment record could not be found.');
            return false;
        }

        if ($decision === 'approved') {
            $stmtUpd = $pdo->prepare("UPDATE enrollments SET status = 'approved', verified_at = NOW(), approval_seen = 0 WHERE enrollment_id = :id");
            $stmtUpd->execute([':id' => $enrollmentId]);
            setFlashMessage(
                'success',
                "Payment verified! Approved <strong>" . htmlspecialchars($row['student_name']) . "</strong> for <strong>" . htmlspecialchars($row['course_title']) . "</strong> (TrxID: <code>" . htmlspecialchars($row['transaction_id'] ?? '') . "</code>). Full course access is now active."
            );
        } else {
            $stmtUpd = $pdo->prepare("UPDATE enrollments SET status = 'rejected', verified_at = NULL, approval_seen = 0 WHERE enrollment_id = :id");
            $stmtUpd->execute([':id' => $enrollmentId]);
            setFlashMessage(
                'warning',
                "Enrollment payment for <strong>" . htmlspecialchars($row['student_name']) . "</strong> in <strong>" . htmlspecialchars($row['course_title']) . "</strong> (TrxID: <code>" . htmlspecialchars($row['transaction_id'] ?? '') . "</code>) has been marked as <strong>Rejected</strong>."
            );
        }
        return true;
    } catch (PDOException $e) {
        error_log('Verification update error: ' . $e->getMessage());
        setFlashMessage('danger', 'Unable to update verification status right now. Please try again.');
        return false;
    }
}

/**
 * Render the shared Admin Pending Enrollment Verifications panel
 *
 * @param array $pendingEnrollments
 * @param string $postActionUrl
 * @param string $sectionId
 * @return void
 */
function renderPendingVerificationsSection(array $pendingEnrollments, $postActionUrl, $sectionId = 'pending-verifications') {
    $pendingCount = count($pendingEnrollments);
    ?>
    <div id="<?= htmlspecialchars($sectionId) ?>" class="card overflow-hidden mb-4">
        <div class="card-header py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2 public-modal-header">
            <div class="d-flex align-items-center gap-2">
                <span class="p-2 rounded-circle bg-warning-subtle text-warning d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                    <i class="bi bi-shield-exclamation fs-6"></i>
                </span>
                <div>
                    <h5 class="fw-bold text-dark mb-0">Pending Enrollment Verifications</h5>
                    <small class="text-muted">Verify student payment Transaction IDs before unlocking course access</small>
                </div>
            </div>
            <span class="badge <?= $pendingCount > 0 ? 'bg-warning-subtle' : 'bg-success-subtle' ?> rounded-pill px-3 py-2 fw-bold">
                <i class="bi <?= $pendingCount > 0 ? 'bi-hourglass-split' : 'bi-check-circle-fill' ?> me-1"></i>
                <?= $pendingCount ?> <?= $pendingCount === 1 ? 'Pending Request' : 'Pending Requests' ?>
            </span>
        </div>

        <?php if ($pendingCount === 0): ?>
            <div class="p-4 text-center">
                <div class="d-inline-flex align-items-center gap-2 px-4 py-2 rounded-pill spatial-subpanel text-muted small">
                    <i class="bi bi-check2-all text-success fs-5"></i>
                    <span>All submitted enrollment payments have been reviewed. No pending verifications right now.</span>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table custom-table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th class="ps-4 py-3">Student</th>
                            <th class="py-3">Course Title</th>
                            <th class="py-3">Payment Method</th>
                            <th class="py-3">Wallet / Account No.</th>
                            <th class="py-3">Transaction ID</th>
                            <th class="py-3">Submitted Date</th>
                            <th class="text-end pe-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingEnrollments as $pe):
                            $pmMeta = getPaymentMethodBadgeMeta($pe['payment_method'] ?? 'bKash');
                            $walletNo = trim((string)($pe['wallet_number'] ?? ''));
                            if ($walletNo === '') {
                                $walletNo = trim((string)($pe['student_phone'] ?? ''));
                            }
                            $paidAmt = isset($pe['paid_amount']) ? (float)$pe['paid_amount'] : (float)($pe['course_price'] ?? 0);
                        ?>
                            <tr>
                                <td class="ps-4 py-3">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($pe['student_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.78rem;">
                                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($pe['student_email']) ?>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($pe['course_title']) ?></div>
                                    <span class="badge bg-primary-subtle mt-1" style="font-size: 0.7rem;">
                                        <?= htmlspecialchars($pe['course_category'] ?? 'Course') ?> &bull; ৳<?= number_format($paidAmt) ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <span class="badge <?= $pmMeta['class'] ?> enrolled-payment-badge">
                                        <?php if ($pmMeta['logo'] !== '' && file_exists(__DIR__ . '/' . $pmMeta['logo'])): ?>
                                            <img src="<?= htmlspecialchars($pmMeta['logo']) ?>" alt="<?= htmlspecialchars($pe['payment_method']) ?>" class="enrolled-payment-logo">
                                        <?php else: ?>
                                            <i class="bi <?= $pmMeta['icon'] ?>"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($pe['payment_method']) ?></span>
                                    </span>
                                </td>
                                <td class="py-3 font-monospace text-dark">
                                    <?= $walletNo !== '' ? htmlspecialchars($walletNo) : '<span class="text-muted">Not provided</span>' ?>
                                </td>
                                <td class="py-3">
                                    <span class="badge bg-secondary-subtle enrolled-trx-pill">
                                        <i class="bi bi-receipt text-muted"></i>
                                        <code><?= htmlspecialchars($pe['transaction_id'] ?? 'N/A') ?></code>
                                    </span>
                                </td>
                                <td class="py-3 text-muted">
                                    <i class="bi bi-clock me-1"></i><?= formatDateSafe($pe['enrollment_date'] ?? null, 'M d, Y h:i A') ?>
                                </td>
                                <td class="text-end pe-4 py-3">
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <form method="POST" action="<?= htmlspecialchars($postActionUrl) ?>" class="m-0">
                                            <input type="hidden" name="action" value="approve_enrollment">
                                            <input type="hidden" name="enrollment_id" value="<?= (int)$pe['enrollment_id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-check-circle-fill"></i><span>Approve</span>
                                            </button>
                                        </form>
                                        <form method="POST" action="<?= htmlspecialchars($postActionUrl) ?>" class="m-0">
                                            <input type="hidden" name="action" value="reject_enrollment">
                                            <input type="hidden" name="enrollment_id" value="<?= (int)$pe['enrollment_id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-x-circle"></i><span>Reject</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
