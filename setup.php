<?php
/**
 * Automated Database Installer & Reset Tool
 * MAS'S Classroom - University DBMS Project
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$sqlFileName = file_exists(__DIR__ . '/database_setup.sql') ? 'database_setup.sql' : 'database.sql';
$sqlFile = __DIR__ . '/' . $sqlFileName;

$status = null;
$message = '';
$tablesCreated = [];

$isCli = (php_sapi_name() === 'cli');
if (($isCli && in_array('auto', $argv ?? [])) || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') || isset($_GET['auto'])) {
    try {
        // Connect to MySQL server without selecting a database
        $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        if (!file_exists($sqlFile)) {
            throw new Exception("{$sqlFileName} file was not found in " . __DIR__);
        }

        $sqlContent = file_get_contents($sqlFile);

        // Split by semicolon and run queries
        // Remove comments or handle multi-query
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `course_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `course_db`;");

        // Execute queries in database.sql
        $pdo->exec($sqlContent);

        // Verify tables and record counts
        $tables = ['instructors', 'courses', 'students', 'enrollments', 'notices'];
        foreach ($tables as $t) {
            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM `{$t}`");
            $tablesCreated[$t] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        }

        // Ensure uploads folder exists
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $status = 'success';
        $message = "Database 'course_db' and all relational tables have been successfully initialized with sample data!";
    } catch (Exception $e) {
        $status = 'danger';
        $message = "Error initializing database: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - MAS'S Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="card shadow-lg border-0 rounded-4">
                    <div class="card-header bg-primary text-white text-center py-4 rounded-top-4">
                        <i class="bi bi-database-fill-gear fs-1 d-block mb-2"></i>
                        <h3 class="fw-bold mb-1">MAS'S Classroom Setup</h3>
                        <p class="mb-0 text-white-50">1-Click MySQL Database Initializer & Reset Tool</p>
                    </div>
                    <div class="card-body p-4 p-md-5">
                        <?php if ($status === 'success'): ?>
                            <div class="alert alert-success d-flex align-items-center mb-4">
                                <i class="bi bi-check-circle-fill fs-3 me-3"></i>
                                <div>
                                    <h5 class="alert-heading mb-1 fw-bold">Installation Successful!</h5>
                                    <div><?= htmlspecialchars($message) ?></div>
                                </div>
                            </div>

                            <h6 class="fw-bold text-dark mb-3">Database Verification:</h6>
                            <div class="row g-3 mb-4">
                                <?php foreach ($tablesCreated as $tbl => $cnt): ?>
                                    <div class="col-6 col-sm-4 col-md">
                                        <div class="p-3 bg-light border rounded text-center">
                                            <span class="d-block text-muted small text-uppercase fw-semibold"><?= $tbl ?></span>
                                            <span class="fs-4 fw-bold text-primary"><?= $cnt ?></span>
                                            <span class="d-block text-muted small">rows</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="p-3 bg-primary-subtle text-primary-emphasis rounded mb-4 small">
                                <strong><i class="bi bi-info-circle me-1"></i> Verified Demo Credentials:</strong><br>
                                <strong>Admins:</strong> <code>gmortuza2003@gmail.com</code>, <code>shahnewaskabir79@gmail.com</code>, <code>anikrizvilam123@gmail.com</code> &bull; Password: <code>admin123</code><br>
                                <strong>Student:</strong> <code>student@test.com</code> &bull; Password: <code>password123</code>
                            </div>

                            <div class="d-flex gap-2 justify-content-center">
                                <a href="index.php" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm">
                                    <i class="bi bi-house-door-fill me-1"></i> Go to Homepage
                                </a>
                                <a href="manage_courses.php" class="btn btn-outline-dark px-4 py-2 rounded-pill fw-semibold">
                                    <i class="bi bi-sliders me-1"></i> Admin / Live CRUD
                                </a>
                            </div>

                        <?php elseif ($status === 'danger'): ?>
                            <div class="alert alert-danger mb-4">
                                <h5 class="alert-heading fw-bold"><i class="bi bi-x-circle-fill me-2"></i> Installation Failed</h5>
                                <p class="mb-0 small"><?= htmlspecialchars($message) ?></p>
                            </div>
                            <div class="text-center">
                                <form method="POST">
                                    <button type="submit" class="btn btn-danger px-4 py-2 rounded-pill fw-semibold">
                                        <i class="bi bi-arrow-clockwise me-1"></i> Retry Installation
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-4">
                                This automated installer will create the <code>course_db</code> database in your XAMPP MySQL server, create all 5 relational tables (<code>instructors</code>, <code>courses</code>, <code>students</code>, <code>enrollments</code>, <code>notices</code>), and populate them with sample data.
                            </p>

                            <div class="card bg-light border-0 p-3 mb-4 rounded-3">
                                <h6 class="fw-bold mb-2"><i class="bi bi-shield-check text-success me-1"></i> Configuration Target:</h6>
                                <ul class="list-unstyled mb-0 small text-muted">
                                    <li><strong>Host:</strong> <?= htmlspecialchars($host) ?></li>
                                    <li><strong>User:</strong> <?= htmlspecialchars($user) ?> (XAMPP default)</li>
                                    <li><strong>Database:</strong> course_db</li>
                                    <li><strong>SQL Source:</strong> <?= htmlspecialchars($sqlFileName) ?></li>
                                </ul>
                            </div>

                            <div class="d-grid gap-2">
                                <form method="POST">
                                    <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
                                        <i class="bi bi-lightning-charge-fill me-1"></i> Click to Initialize / Reset Database
                                    </button>
                                </form>
                                <a href="index.php" class="btn btn-link text-muted text-center mt-2">Skip and go to Homepage</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
