<?php
/**
 * Home Page - MAS'S Classroom
 * University DBMS Project
 */
$pageTitle = "Home - Explore Courses";
require_once __DIR__ . '/header.php';

// Retrieve student session details if logged in
$student = getCurrentStudent();
$studentId = $student ? $student['id'] : null;

// Fetch enrollment statuses for the logged-in student keyed by course_id
$enrollmentStatusByCourse = [];
if ($studentId) {
    try {
        $stmtEnrolled = $pdo->prepare("SELECT course_id, LOWER(COALESCE(status, 'approved')) AS status FROM enrollments WHERE student_id = :student_id");
        $stmtEnrolled->execute([':student_id' => $studentId]);
        foreach ($stmtEnrolled->fetchAll() as $erow) {
            $enrollmentStatusByCourse[(int)$erow['course_id']] = $erow['status'];
        }
    } catch (PDOException $e) {
        $enrollmentStatusByCourse = [];
    }
}

// Fetch filter options (instructors & categories for dropdowns)
try {
    $instructorsList = $pdo->query("SELECT instructor_id, name FROM instructors ORDER BY name ASC")->fetchAll();
    $categoriesList = $pdo->query("SELECT DISTINCT category FROM courses WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch system-wide DBMS counts for live stats bar & hero overview card (active/approved enrollments only)
    $totalCourses = $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    $totalInstructors = $pdo->query("SELECT COUNT(*) FROM instructors")->fetchColumn();
    $totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE role = 'student'")->fetchColumn();
    $totalEnrollments = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE LOWER(COALESCE(status, 'approved')) = 'approved'")->fetchColumn();
    $totalOffers = $pdo->query("SELECT COUNT(*) FROM notices WHERE LOWER(type) = 'offer'")->fetchColumn();
} catch (PDOException $e) {
    $instructorsList = [];
    $categoriesList = [];
    $totalCourses = 0;
    $totalInstructors = 0;
    $totalStudents = 0;
    $totalEnrollments = 0;
    $totalOffers = 0;
}

// Handle Search, Filtering, and Sorting
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Accept either 'instructor' or 'instructor_id'
$rawInstructor = trim((string)($_GET['instructor'] ?? ($_GET['instructor_id'] ?? '')));
$selectedInstructor = 0;
$selectedInstructorName = '';
if (is_numeric($rawInstructor) && (int)$rawInstructor > 0) {
    $selectedInstructor = (int)$rawInstructor;
} elseif ($rawInstructor !== '' && strtolower($rawInstructor) !== '0' && strtolower($rawInstructor) !== 'all' && strtolower($rawInstructor) !== 'all instructors') {
    $selectedInstructorName = $rawInstructor;
}

$selectedCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$selectedSort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

// Build Dynamic SQL Query with JOIN
$sql = "SELECT c.course_id, c.title, c.description, c.price, c.category, c.thumbnail, c.created_at,
               i.instructor_id, i.name AS instructor_name, i.expertise AS instructor_expertise, i.email AS instructor_email,
               COALESCE(
                   NULLIF(i.avatar, ''),
                   NULLIF(i.profile_image, ''),
                   NULLIF(u.profile_image, ''),
                   (SELECT s2.profile_image FROM students s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(i.name)) AND s2.profile_image IS NOT NULL AND s2.profile_image != '' ORDER BY CASE WHEN LOWER(s2.role) IN ('admin', 'instructor') THEN 0 ELSE 1 END, s2.student_id ASC LIMIT 1)
               ) AS instructor_avatar,
               i.profile_image AS instructor_profile_image,
               u.profile_image AS user_profile_image,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.course_id AND LOWER(COALESCE(e.status, 'approved')) = 'approved') AS total_enrolled
        FROM courses c
        LEFT JOIN instructors i ON c.instructor_id = i.instructor_id
        LEFT JOIN students u ON LOWER(TRIM(i.email)) = LOWER(TRIM(u.email))
        WHERE 1=1 ";

$params = [];

if ($searchQuery !== '') {
    $sql .= " AND (c.title LIKE :kw1 OR c.description LIKE :kw2 OR i.name LIKE :kw3 OR c.category LIKE :kw4) ";
    $params[':kw1'] = "%{$searchQuery}%";
    $params[':kw2'] = "%{$searchQuery}%";
    $params[':kw3'] = "%{$searchQuery}%";
    $params[':kw4'] = "%{$searchQuery}%";
}

if ($selectedInstructor > 0) {
    $sql .= " AND (c.instructor_id = :inst_id1 OR i.instructor_id = :inst_id2) ";
    $params[':inst_id1'] = $selectedInstructor;
    $params[':inst_id2'] = $selectedInstructor;
} elseif ($selectedInstructorName !== '') {
    $sql .= " AND (TRIM(LOWER(i.name)) = TRIM(LOWER(:inst_name)) OR LOWER(i.name) LIKE LOWER(:inst_like)) ";
    $params[':inst_name'] = $selectedInstructorName;
    $params[':inst_like'] = "%" . strtolower($selectedInstructorName) . "%";
}

if ($selectedCategory !== '' && strtolower($selectedCategory) !== 'all' && strtolower($selectedCategory) !== 'all categories') {
    $sql .= " AND LOWER(c.category) = LOWER(:category) ";
    $params[':category'] = $selectedCategory;
}

// Apply Sorting
switch ($selectedSort) {
    case 'price_asc':
    case 'price_low':
        $sql .= " ORDER BY c.price ASC, c.course_id DESC ";
        break;
    case 'price_desc':
    case 'price_high':
        $sql .= " ORDER BY c.price DESC, c.course_id DESC ";
        break;
    case 'popular':
        $sql .= " ORDER BY total_enrolled DESC, c.course_id DESC ";
        break;
    case 'title':
        $sql .= " ORDER BY c.title ASC ";
        break;
    case 'newest':
    default:
        $sql .= " ORDER BY c.course_id DESC ";
        break;
}

try {
    $stmtCourses = $pdo->prepare($sql);
    $stmtCourses->execute($params);
    $courses = $stmtCourses->fetchAll();
} catch (PDOException $e) {
    error_log('Catalog query error: ' . $e->getMessage());
    $courses = [];
    $queryError = 'Unable to load courses right now. Please refresh or try again shortly.';
}

// Retrieve active discount offers grouped by course_id
$activeCourseOffers = getActiveCourseOffers($pdo);

// Helper to pick contextual card styling based on category
function getCategoryHeaderClass($category) {
    $cat = strtolower($category);
    if (strpos($cat, 'database') !== false || strpos($cat, 'sql') !== false) return ['cat-database', 'bi-database-fill'];
    if (strpos($cat, 'web') !== false || strpos($cat, 'php') !== false) return ['cat-web', 'bi-code-slash'];
    if (strpos($cat, 'data') !== false || strpos($cat, 'intelligence') !== false || strpos($cat, 'ai') !== false) return ['cat-data', 'bi-cpu-fill'];
    if (strpos($cat, 'cloud') !== false || strpos($cat, 'devops') !== false) return ['cat-cloud', 'bi-cloud-check-fill'];
    if (strpos($cat, 'security') !== false || strpos($cat, 'cyber') !== false) return ['cat-security', 'bi-shield-lock-fill'];
    return ['', 'bi-journal-code'];
}
?>

<!-- Hero Stage Section -->
<div class="container mb-4">
    <section class="hero-section">
        <div class="position-relative" style="z-index: 2;">
            <div class="hero-3col-grid">
                <!-- Left Column: Intro Copy & CTAs -->
                <div class="hero-col-intro">
                    <div class="hero-micro-pill mb-3">
                        <span class="hero-live-dot"></span>
                        <span>Relational Course Platform &middot; Live MySQL</span>
                    </div>

                    <h1 class="hero-title mb-3">
                        Unlock Knowledge with <br><span class="hero-highlight">MAS'S Classroom</span>
                    </h1>

                    <p class="hero-lead mb-4">
                        Browse curated computer science and engineering courses, apply active semester discounts, and learn directly from top instructors.
                    </p>

                    <div class="d-flex flex-wrap align-items-center gap-2 gap-xl-3 hero-cta-group">
                        <a href="#courses" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold">
                            <i class="bi bi-book-half me-1"></i> Browse Catalog
                        </a>
                        <?php if (!$student): ?>
                            <a href="register.php" class="btn hero-glass-btn rounded-pill px-4 py-2 fw-semibold">
                                <i class="bi bi-person-plus me-1"></i> Join as Student
                            </a>
                        <?php else: ?>
                            <a href="my_courses.php?tab=courses#enrolled-courses" class="btn hero-glass-btn rounded-pill px-4 py-2 fw-semibold">
                                <i class="bi bi-person-workspace me-1"></i> My Dashboard
                            </a>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <a href="manage_courses.php" class="btn hero-glass-btn rounded-pill px-4 py-2 fw-semibold">
                                <i class="bi bi-sliders me-1"></i> Manage Platform
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Center Column: Spatial Glass Group Photo Showcase -->
                <?php
                $heroGroupPhotoUrl = getHeroGroupPhotoUrl();
                $heroFallbackCourseUrl = getCourseThumbnailUrl('Full-Stack Web Development with PHP & MySQL') ?? '';
                ?>
                <div class="hero-col-media">
                    <div class="hero-group-frame">
                        <div class="hero-group-glow" aria-hidden="true"></div>
                        <?php if ($heroGroupPhotoUrl): ?>
                            <img src="<?= htmlspecialchars($heroGroupPhotoUrl) ?>"
                                 alt="MAS'S Classroom Team — Golam Mortuza Shipu, Shahnewas Kabir Shezan, and Anik Rizvi Lam"
                                 class="hero-group-photo"
                                 data-fallback-src="<?= htmlspecialchars($heroFallbackCourseUrl) ?>"
                                 onerror="handleHeroGroupPhotoError(this)">
                        <?php else: ?>
                            <div class="hero-group-fallback">
                                <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                                    <span class="instructor-avatar">G</span>
                                    <span class="instructor-avatar">S</span>
                                    <span class="instructor-avatar">A</span>
                                </div>
                                <div class="fw-bold text-dark small">MAS'S Classroom Team</div>
                                <div class="text-muted" style="font-size: 0.78rem;">Shipu &bull; Shezan &bull; Lam</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Compact Platform Overview Card -->
                <div class="hero-col-stats">
                    <div class="hero-bento-panel">
                        <div class="d-flex align-items-center justify-content-between gap-2 pb-2 mb-2 border-bottom">
                            <div class="d-flex align-items-center gap-2 min-w-0">
                                <span class="brand-icon-box flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9px;">
                                    <i class="bi bi-bar-chart-line-fill" style="font-size: 0.85rem;"></i>
                                </span>
                                <h6 class="text-dark fw-bold mb-0 text-truncate" style="font-size: 0.96rem;">Platform Overview</h6>
                            </div>
                            <span class="hero-live-db-pill flex-shrink-0">
                                <span class="hero-live-dot" style="width: 6px; height: 6px;"></span>
                                <span>Live DB</span>
                            </span>
                        </div>

                        <div class="hero-stat-list">
                            <div class="hero-stat-row">
                                <span class="hero-stat-label">
                                    <span class="hero-stat-icon bg-primary-subtle text-primary">
                                        <i class="bi bi-journal-bookmark-fill"></i>
                                    </span>
                                    <span>Courses</span>
                                </span>
                                <span class="hero-stat-value"><?= number_format($totalCourses) ?></span>
                            </div>
                            <div class="hero-stat-row">
                                <span class="hero-stat-label">
                                    <span class="hero-stat-icon bg-success-subtle text-success">
                                        <i class="bi bi-person-workspace"></i>
                                    </span>
                                    <span>Instructors</span>
                                </span>
                                <span class="hero-stat-value"><?= number_format($totalInstructors) ?></span>
                            </div>
                            <div class="hero-stat-row">
                                <span class="hero-stat-label">
                                    <span class="hero-stat-icon bg-info-subtle text-info">
                                        <i class="bi bi-people-fill"></i>
                                    </span>
                                    <span>Students</span>
                                </span>
                                <span class="hero-stat-value"><?= number_format($totalStudents) ?></span>
                            </div>
                            <div class="hero-stat-row">
                                <span class="hero-stat-label">
                                    <span class="hero-stat-icon bg-warning-subtle text-warning">
                                        <i class="bi bi-tag-fill"></i>
                                    </span>
                                    <span>Active Offers</span>
                                </span>
                                <span class="hero-stat-value"><?= number_format($totalOffers) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="container">
    <!-- Live Platform KPI / Summary Bar -->
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="p-3 bg-primary-subtle text-primary me-3 fs-3" style="border-radius: 14px;">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <span class="d-block text-muted small fw-semibold text-uppercase">Total Courses</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalCourses) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="p-3 bg-success-subtle text-success me-3 fs-3" style="border-radius: 14px;">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <span class="d-block text-muted small fw-semibold text-uppercase">Instructors</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalInstructors) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="p-3 bg-info-subtle text-info me-3 fs-3" style="border-radius: 14px;">
                    <i class="bi bi-person-badge-fill"></i>
                </div>
                <div>
                    <span class="d-block text-muted small fw-semibold text-uppercase">Students</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalStudents) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="p-3 bg-warning-subtle text-warning-emphasis me-3 fs-3" style="border-radius: 14px;">
                    <i class="bi bi-card-checklist"></i>
                </div>
                <div>
                    <span class="d-block text-muted small fw-semibold text-uppercase">Enrollments</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalEnrollments) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Search, Filter & Catalog Header -->
    <div id="courses" class="mb-4">
        <span id="courses-catalog"></span>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h2 class="fw-bold text-dark mb-1">
                    <i class="bi bi-grid-fill text-primary me-2"></i>Available Courses
                </h2>
                <p class="text-muted mb-0">
                    Showing <?= count($courses) ?> <?= count($courses) === 1 ? 'course' : 'courses' ?> available for enrollment
                </p>
            </div>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div class="d-flex align-items-center gap-2">
                    <a href="manage_courses.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                        <i class="bi bi-plus-circle me-1"></i> Add Course / Instructor
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Form -->
        <div class="card filter-bar-card p-3 p-md-4 mb-4">
            <form method="GET" action="index.php#courses" id="courseFilterForm" class="filter-bar-form">
                <div class="filter-search-wrap">
                    <div class="filter-search-box">
                        <i class="bi bi-search filter-search-icon"></i>
                        <input type="text" name="search" class="form-control filter-search-input" placeholder="Search course title, description or instructor..." value="<?= htmlspecialchars($searchQuery) ?>">
                    </div>
                </div>
                <div class="filter-select-wrap filter-select-instructor">
                    <select name="instructor" id="filterInstructor" class="form-select filter-auto-submit" aria-label="Filter by Instructor">
                        <option value="0">All Instructors</option>
                        <?php foreach ($instructorsList as $inst): 
                            $isSelectedInst = ($selectedInstructor == $inst['instructor_id']) || 
                                              ($selectedInstructorName !== '' && (strcasecmp($selectedInstructorName, $inst['name']) === 0 || strcasecmp($selectedInstructorName, (string)$inst['instructor_id']) === 0 || stripos($inst['name'], $selectedInstructorName) !== false));
                        ?>
                            <option value="<?= $inst['instructor_id'] ?>" <?= $isSelectedInst ? 'selected' : '' ?>>
                                <?= htmlspecialchars($inst['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-select-wrap">
                    <select name="category" id="filterCategory" class="form-select filter-auto-submit" aria-label="Filter by Category">
                        <option value="">All Categories</option>
                        <?php foreach ($categoriesList as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= (strcasecmp($selectedCategory, $cat) === 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-select-wrap">
                    <select name="sort" id="filterSort" class="form-select filter-auto-submit" aria-label="Sort Courses">
                        <option value="newest" <?= ($selectedSort === 'newest') ? 'selected' : '' ?>>Newest First</option>
                        <option value="popular" <?= ($selectedSort === 'popular') ? 'selected' : '' ?>>Most Popular</option>
                        <option value="price_asc" <?= in_array($selectedSort, ['price_asc', 'price_low']) ? 'selected' : '' ?>>Price: Low to High</option>
                        <option value="price_desc" <?= in_array($selectedSort, ['price_desc', 'price_high']) ? 'selected' : '' ?>>Price: High to Low</option>
                        <option value="title" <?= ($selectedSort === 'title') ? 'selected' : '' ?>>Alphabetical (A-Z)</option>
                    </select>
                </div>
                <div class="filter-actions-wrap">
                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center justify-content-center gap-2 filter-submit-btn" title="Apply Filters">
                        <i class="bi bi-funnel-fill"></i>
                        <span class="small fw-semibold">Filter</span>
                    </button>
                    <?php if ($searchQuery !== '' || $selectedInstructor > 0 || $selectedInstructorName !== '' || ($selectedCategory !== '' && strtolower($selectedCategory) !== 'all') || !in_array($selectedSort, ['newest', ''])): ?>
                        <a href="index.php#courses" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center filter-clear-btn" title="Clear Filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Course Cards Grid -->
    <?php if (empty($courses)): ?>
        <div class="card p-5 text-center my-4">
            <div class="text-muted mb-3">
                <i class="bi bi-search" style="font-size: 3rem;"></i>
            </div>
            <h4 class="fw-bold text-dark">No Courses Found</h4>
            <p class="text-muted">No courses match your active search or filter criteria. Try resetting the filters or exploring another category.</p>
            <div>
                <a href="index.php#courses" class="btn btn-outline-primary rounded-pill px-4">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filters
                </a>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="manage_courses.php" class="btn btn-primary rounded-pill px-4 ms-2">
                        <i class="bi bi-plus-lg me-1"></i> Add Course
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4 mb-4">
            <?php foreach ($courses as $c): 
                list($headerClass, $iconClass) = getCategoryHeaderClass($c['category']);
                $thumbUrl = getCourseThumbnailUrl($c['title'], $c['thumbnail'] ?? null);
                $instAvatarUrl = getInstructorAvatarUrl($c['instructor_name'] ?? '', [$c['instructor_avatar'] ?? null, $c['instructor_profile_image'] ?? null, $c['user_profile_image'] ?? null], (int)($c['instructor_id'] ?? 0));
                $enrollStatus = $enrollmentStatusByCourse[(int)$c['course_id']] ?? null;
                $isEnrolled = ($enrollStatus === 'approved');
                $isPending = ($enrollStatus === 'pending');
                $cOffer = getOfferForCourse($c['course_id'], $c['title'], $activeCourseOffers);
                $cDiscount = $cOffer ? calculateCourseDiscount($c['price'], $cOffer['discount_badge'] ?? '', $cOffer['discount_percent'] ?? null, $cOffer['offer_price'] ?? null) : ['has_discount' => false, 'discount_amount' => 0, 'discounted_price' => $c['price'], 'badge' => ''];
            ?>
                <div class="col-lg-4 col-md-6">
                    <div class="card course-card h-100 position-relative">
                        <!-- Top Thumbnail / Fallback Gradient Banner -->
                        <div class="course-card-header <?= $headerClass ?> position-relative">
                            <?php if ($thumbUrl): ?>
                                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($c['title']) ?>" class="course-card-thumb" loading="lazy">
                                <div class="course-card-scrim"></div>
                            <?php else: ?>
                                <i class="bi <?= $iconClass ?> course-icon-bg"></i>
                            <?php endif; ?>
                            <span class="card-category-badge">
                                <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($c['category']) ?>
                            </span>
                            <?php if ($cDiscount['has_discount']): ?>
                                <span class="badge rounded-pill fw-bold position-absolute" style="top: 16px; right: 16px; z-index: 2; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; font-size: 0.72rem; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);">
                                    <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($cDiscount['badge']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body d-flex flex-column p-4">
                            <!-- Course Title -->
                            <h5 class="card-title fw-bold text-dark mb-2 line-clamp-2">
                                <?= htmlspecialchars($c['title']) ?>
                            </h5>

                            <!-- Instructor Info with SQL JOIN -->
                            <div class="d-flex align-items-center mb-3 text-muted small">
                                <div class="instructor-avatar me-2 overflow-hidden">
                                    <?php if ($instAvatarUrl): ?>
                                        <img src="<?= htmlspecialchars($instAvatarUrl) ?>" alt="<?= htmlspecialchars($c['instructor_name'] ?? 'Instructor') ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                    <?php else: ?>
                                        <?= strtoupper(substr($c['instructor_name'] ?? 'U', 0, 1)) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="text-truncate">
                                    <strong class="text-dark d-block text-truncate"><?= htmlspecialchars($c['instructor_name'] ?? 'Unassigned Instructor') ?></strong>
                                    <span class="text-muted small text-truncate d-block"><?= htmlspecialchars($c['instructor_expertise'] ?? 'Instructor') ?></span>
                                </div>
                            </div>

                            <!-- Description Snippet (clean 3-line CSS clamp without mid-word truncation) -->
                            <p class="card-text text-secondary small course-desc-clamp mb-4">
                                <?= htmlspecialchars($c['description']) ?>
                            </p>

                            <!-- Bottom Row: Price & Enrollment Button -->
                            <div class="course-card-footer">
                                <div class="min-w-0">
                                    <small class="text-muted d-block text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.05em;">Tuition</small>
                                    <?php if ($cDiscount['has_discount']): ?>
                                        <div class="d-flex align-items-baseline gap-1 flex-wrap">
                                            <span class="fw-bold course-price-bold" style="font-size: 1.15rem;">
                                                <?= formatPrice($cDiscount['discounted_price']) ?>
                                            </span>
                                            <del class="small strikethrough-old-price" style="font-size: 0.78rem;">
                                                <?= formatPrice($c['price']) ?>
                                            </del>
                                        </div>
                                    <?php else: ?>
                                        <span class="fw-bold course-price-bold" style="font-size: 1.15rem;">
                                            <?= formatPrice($c['price']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="course-card-actions">
                                    <!-- View Details Modal Trigger -->
                                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#courseModal<?= $c['course_id'] ?>" title="Quick View">
                                        <i class="bi bi-eye"></i><span>Details</span>
                                    </button>

                                    <?php if ($isEnrolled): ?>
                                        <a href="my_courses.php" class="btn btn-success btn-sm rounded-pill fw-semibold d-inline-flex align-items-center gap-1" title="You are verified and enrolled in this course">
                                            <i class="bi bi-check-circle-fill"></i><span>Enrolled</span>
                                        </a>
                                    <?php elseif ($isPending): ?>
                                        <a href="my_courses.php" class="btn btn-warning btn-sm rounded-pill fw-semibold d-inline-flex align-items-center gap-1" title="Payment submitted — awaiting admin verification">
                                            <i class="bi bi-hourglass-split"></i><span>Pending</span>
                                        </a>
                                    <?php elseif ($student): ?>
                                        <a href="checkout.php?course_id=<?= $c['course_id'] ?>" class="btn btn-enroll btn-sm rounded-pill fw-bold d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-mortarboard-fill"></i><span>Enroll Now</span>
                                        </a>
                                    <?php else: ?>
                                        <a href="login.php?redirect=<?= urlencode("checkout.php?course_id={$c['course_id']}") ?>&enroll_course=<?= $c['course_id'] ?>" class="btn btn-enroll btn-sm rounded-pill fw-bold d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-box-arrow-in-right"></i><span>Enroll Now</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal 1: Course Details (Wide Two-Column Spatial Glass Layout) -->
                <?php
                $richOverview = getCourseRichOverview($c);
                $popupThumbUrl = getCoursePopupThumbnailUrl($c['title'], $c['thumbnail'] ?? null) ?? $thumbUrl;
                ?>
                <div class="modal fade" id="courseModal<?= $c['course_id'] ?>" tabindex="-1" aria-labelledby="modalLabel<?= $c['course_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered course-detail-dialog">
                        <div class="modal-content course-detail-content">
                            <button type="button" class="modal-banner-close position-absolute" style="top: 14px; right: 14px; z-index: 25;" data-bs-dismiss="modal" aria-label="Close">
                                <i class="bi bi-x-lg"></i>
                            </button>

                            <div class="course-detail-layout">
                                <!-- Left Column: Full-Height Course Popup Image -->
                                <div class="course-detail-media">
                                    <div class="course-detail-thumb-frame <?= $headerClass ?> text-white">
                                        <?php if ($popupThumbUrl): ?>
                                            <img src="<?= htmlspecialchars($popupThumbUrl) ?>" alt="<?= htmlspecialchars($c['title']) ?>" class="course-detail-thumb-img">
                                        <?php else: ?>
                                            <i class="bi <?= $iconClass ?> course-icon-bg"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Right Column: Seamless Rounded Header + Scrollable Overview & Instructor + Fixed Bottom Action Bar -->
                                <div class="course-detail-main">
                                    <div class="course-detail-header">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" style="padding-right: 44px;">
                                            <span class="badge bg-primary-subtle px-3 py-1 d-inline-flex align-items-center gap-1" style="border-radius: 999px; font-size: 0.72rem; font-weight: 600;">
                                                <i class="bi bi-tag-fill"></i>
                                                <span><?= htmlspecialchars($c['category']) ?></span>
                                            </span>
                                            <span class="badge bg-secondary-subtle px-3 py-1 d-inline-flex align-items-center gap-1" style="border-radius: 999px; font-size: 0.72rem; font-weight: 600;">
                                                <i class="bi bi-people-fill"></i>
                                                <span><?= (int)$c['total_enrolled'] ?> Enrolled</span>
                                            </span>
                                        </div>
                                        <h4 class="modal-title fw-bold text-dark mb-0" id="modalLabel<?= $c['course_id'] ?>" style="padding-right: 44px; line-height: 1.3;">
                                            <?= htmlspecialchars($c['title']) ?>
                                        </h4>
                                    </div>

                                    <div class="course-detail-scroll">
                                        <!-- Course Overview + Key Learning Outcomes & Labs -->
                                        <div class="mb-3">
                                            <h6 class="fw-bold text-dark mb-2 small text-uppercase" style="display: inline-flex; align-items: center; gap: 8px; letter-spacing: 0.04em; font-size: 0.76rem;">
                                                <i class="bi bi-journal-richtext text-primary"></i>
                                                <span>Course Overview</span>
                                            </h6>
                                            <p class="text-secondary small mb-3" style="line-height: 1.7;"><?= nl2br(htmlspecialchars($c['description'])) ?></p>
                                            <p class="text-secondary small mb-3" style="line-height: 1.7;"><?= htmlspecialchars($richOverview['summary']) ?></p>

                                            <div class="p-3 spatial-subpanel course-detail-inner-panel mb-3">
                                                <div class="fw-bold text-dark small text-uppercase mb-2" style="display: inline-flex; align-items: center; gap: 8px; letter-spacing: 0.04em; font-size: 0.74rem;">
                                                    <i class="bi bi-stars text-primary"></i>
                                                    <span>Key Learning Outcomes &amp; Labs</span>
                                                </div>
                                                <ul class="course-takeaway-list">
                                                    <?php foreach ($richOverview['takeaways'] as $takeaway): ?>
                                                        <li>
                                                            <i class="bi bi-check-circle-fill"></i>
                                                            <span><?= htmlspecialchars($takeaway) ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>

                                            <!-- Instructor Details Panel -->
                                            <div class="p-3 spatial-subpanel course-detail-inner-panel">
                                                <div class="fw-bold text-dark small text-uppercase mb-2" style="display: inline-flex; align-items: center; gap: 8px; letter-spacing: 0.04em; font-size: 0.74rem;">
                                                    <i class="bi bi-person-workspace text-primary"></i>
                                                    <span>Instructor Details</span>
                                                </div>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="instructor-avatar flex-shrink-0 overflow-hidden" style="width: 46px; height: 46px; font-size: 1.05rem;">
                                                        <?php if ($instAvatarUrl): ?>
                                                            <img src="<?= htmlspecialchars($instAvatarUrl) ?>" alt="<?= htmlspecialchars($c['instructor_name'] ?? 'Instructor') ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                        <?php else: ?>
                                                            <?= strtoupper(substr($c['instructor_name'] ?? 'U', 0, 1)) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="fw-bold text-dark mb-1 text-truncate"><?= htmlspecialchars($c['instructor_name'] ?? 'Not Assigned') ?></div>
                                                        <div class="instructor-affiliation-line">
                                                            <i class="bi bi-mortarboard-fill flex-shrink-0"></i>
                                                            <span>Studying CSE at Varendra University</span>
                                                        </div>
                                                        <div class="text-muted small mb-1 text-truncate"><?= htmlspecialchars($c['instructor_expertise'] ?? 'Instructor') ?></div>
                                                        <?php if (!empty($c['instructor_email'])): ?>
                                                            <div class="text-primary small d-flex align-items-center gap-1 text-truncate">
                                                                <i class="bi bi-envelope flex-shrink-0"></i>
                                                                <span class="text-truncate"><?= htmlspecialchars($c['instructor_email']) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Fixed Bottom Tuition & Action Bar -->
                                    <div class="course-detail-footer">
                                        <div>
                                            <span class="text-muted small d-block text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.05em;">Tuition Fee</span>
                                            <?php if ($cDiscount['has_discount']): ?>
                                                <div class="d-flex align-items-baseline gap-2 flex-wrap">
                                                    <span class="fs-4 fw-bold text-primary"><?= formatPrice($cDiscount['discounted_price']) ?></span>
                                                    <del class="small strikethrough-old-price" style="font-size: 0.95rem;"><?= formatPrice($c['price']) ?></del>
                                                    <span class="badge bg-danger-subtle text-danger fw-bold"><?= htmlspecialchars($cDiscount['badge']) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="fs-4 fw-bold text-primary"><?= formatPrice($c['price']) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                            <?php if ($isEnrolled): ?>
                                                <a href="my_courses.php" class="btn btn-success rounded-pill px-4 fw-semibold">
                                                    <i class="bi bi-check-circle-fill me-1"></i> Already Enrolled
                                                </a>
                                            <?php elseif ($isPending): ?>
                                                <a href="my_courses.php" class="btn btn-warning rounded-pill px-4 fw-semibold">
                                                    <i class="bi bi-hourglass-split me-1"></i> Pending Verification
                                                </a>
                                            <?php elseif ($student): ?>
                                                <a href="checkout.php?course_id=<?= $c['course_id'] ?>" class="btn btn-enroll rounded-pill px-4 fw-bold">
                                                    <i class="bi bi-credit-card-2-front me-1"></i> Proceed to Checkout
                                                </a>
                                            <?php else: ?>
                                                <a href="login.php?redirect=<?= urlencode("checkout.php?course_id={$c['course_id']}") ?>&enroll_course=<?= $c['course_id'] ?>" class="btn btn-enroll rounded-pill px-4 fw-bold">
                                                    <i class="bi bi-box-arrow-in-right me-1"></i> Login to Enroll
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal 2: Bangladeshi Payment & Enrollment Modal -->
                <?php if ($student && !$isEnrolled && !$isPending): ?>
                    <div class="modal fade" id="enrollModal<?= $c['course_id'] ?>" tabindex="-1" aria-labelledby="enrollModalLabel<?= $c['course_id'] ?>" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="course-modal-banner <?= $headerClass ?> text-white" style="height: 165px;">
                                    <?php if ($thumbUrl): ?>
                                        <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($c['title']) ?>" class="course-card-thumb">
                                        <div class="course-card-scrim-strong"></div>
                                    <?php else: ?>
                                        <i class="bi <?= $iconClass ?> course-icon-bg"></i>
                                    <?php endif; ?>
                                    <button type="button" class="modal-banner-close position-absolute" style="top: 14px; right: 14px; z-index: 3;" data-bs-dismiss="modal" aria-label="Close">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                                    <div class="position-relative" style="z-index: 2;">
                                        <span class="card-category-badge mb-2">Enrollment &amp; Checkout</span>
                                        <h5 class="modal-title fw-bold text-white mb-0" id="enrollModalLabel<?= $c['course_id'] ?>">
                                            <?= htmlspecialchars($c['title']) ?>
                                        </h5>
                                    </div>
                                </div>

                                <form action="enroll.php" method="POST">
                                    <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">

                                    <div class="modal-body p-4">
                                        <!-- Price & Instructor Summary -->
                                        <div class="d-flex justify-content-between align-items-center p-3 spatial-subpanel mb-4">
                                            <div>
                                                <small class="text-muted d-block">TOTAL PAYABLE</small>
                                                <?php if ($cDiscount['has_discount']): ?>
                                                    <div class="d-flex align-items-baseline gap-2 flex-wrap">
                                                        <span class="fs-4 fw-bold text-primary"><?= formatPrice($cDiscount['discounted_price']) ?></span>
                                                        <del class="small strikethrough-old-price"><?= formatPrice($c['price']) ?></del>
                                                        <span class="badge bg-danger-subtle text-danger" style="font-size: 0.7rem; font-weight: 700;"><?= htmlspecialchars($cDiscount['badge']) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="fs-4 fw-bold text-primary"><?= formatPrice($c['price']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted d-block">Instructor</small>
                                                <span class="fw-semibold text-dark"><?= htmlspecialchars($c['instructor_name'] ?? 'Instructor') ?></span>
                                            </div>
                                        </div>

                                        <!-- Bangladeshi Payment Methods -->
                                        <h6 class="fw-bold text-dark mb-3">
                                            <i class="bi bi-credit-card-fill text-primary me-1"></i> Select Payment Method (Bangladesh)
                                        </h6>

                                        <div class="mb-3 d-flex flex-column gap-2">
                                            <!-- bKash -->
                                            <label class="payment-method-card selected" for="pm_bkash_<?= $c['course_id'] ?>">
                                                <input type="radio" name="payment_method" id="pm_bkash_<?= $c['course_id'] ?>" value="bKash" checked>
                                                <div class="payment-method-info">
                                                    <div class="payment-logo-box">
                                                        <img src="images/bkash.png" alt="bKash" class="payment-logo-img">
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">bKash</div>
                                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">Direct Mobile Payment</small>
                                                    </div>
                                                </div>
                                                <span class="badge bg-danger-subtle text-danger" style="font-size: 0.75rem;">Instant</span>
                                            </label>

                                            <!-- Nagad -->
                                            <label class="payment-method-card" for="pm_nagad_<?= $c['course_id'] ?>">
                                                <input type="radio" name="payment_method" id="pm_nagad_<?= $c['course_id'] ?>" value="Nagad">
                                                <div class="payment-method-info">
                                                    <div class="payment-logo-box">
                                                        <img src="images/nagad.png" alt="Nagad" class="payment-logo-img">
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">Nagad</div>
                                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">Post Office Digital Service</small>
                                                    </div>
                                                </div>
                                                <span class="badge bg-warning-subtle text-warning" style="font-size: 0.75rem;">Fast</span>
                                            </label>

                                            <!-- Rocket -->
                                            <label class="payment-method-card" for="pm_rocket_<?= $c['course_id'] ?>">
                                                <input type="radio" name="payment_method" id="pm_rocket_<?= $c['course_id'] ?>" value="Rocket">
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

                                            <!-- Debit / Credit Card -->
                                            <label class="payment-method-card" for="pm_card_<?= $c['course_id'] ?>">
                                                <input type="radio" name="payment_method" id="pm_card_<?= $c['course_id'] ?>" value="Debit / Credit Card">
                                                <div class="payment-method-info">
                                                    <div class="payment-logo-box payment-card-icon-box">
                                                        <i class="bi bi-credit-card-2-front fs-5"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="fw-bold text-dark mb-0" style="line-height: 1.25; font-size: 0.95rem;">Debit / Credit Card</div>
                                                        <small class="text-muted" style="font-size: 0.78rem; line-height: 1.2;">Visa, Mastercard, Nexus</small>
                                                    </div>
                                                </div>
                                                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.75rem;">Global</span>
                                            </label>
                                        </div>

                                        <!-- Required Transaction ID / TrxID field -->
                                        <div class="mb-2">
                                            <label class="form-label fw-semibold small text-muted">Transaction ID / TrxID <span class="text-danger">*</span></label>
                                            <input type="text" name="transaction_id" class="form-control" placeholder="e.g. BKX98273614" required>
                                            <div class="form-text small text-muted">Verified by MAS'S Classroom Admin before course access is unlocked.</div>
                                        </div>
                                    </div>

                                    <div class="modal-footer public-modal-footer p-3 px-4">
                                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-cta rounded-pill px-4 fw-bold">
                                            <i class="bi bi-shield-check me-1"></i> Pay <?= formatPrice($cDiscount['discounted_price']) ?> & Enroll
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
    <!-- END #courses / #courses-catalog -->
</div>
<!-- END .container -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Instant filtering: automatically submit form when any select dropdown changes
    document.querySelectorAll('.filter-auto-submit').forEach(function(selectEl) {
        selectEl.addEventListener('change', function() {
            var form = document.getElementById('courseFilterForm');
            if (form) {
                form.submit();
            }
        });
    });

    // Smooth scroll to courses section when arriving with #courses or #courses-catalog or after filter submit
    if (window.location.hash === '#courses' || window.location.hash === '#courses-catalog') {
        var targetSection = document.getElementById('courses') || document.getElementById('courses-catalog');
        if (targetSection) {
            setTimeout(function() {
                targetSection.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }
});
</script>

<script>
function handleHeroGroupPhotoError(img) {
    if (!img || img.dataset.fallbackAttempted === 'done') {
        return;
    }
    var step = parseInt(img.dataset.fallbackStep || '0', 10);
    var candidates = [
        'images/courses/group_photo.jpg',
        'images/courses/group_photo.jpg.png',
        'images/courses/group%20photo.jpg',
        'images/group_photo.jpg',
        'images/group%20photo.jpg'
    ];
    if (img.dataset.fallbackSrc) {
        candidates.push(img.dataset.fallbackSrc);
    }
    while (step < candidates.length && img.getAttribute('src') === candidates[step]) {
        step++;
    }
    if (step < candidates.length) {
        img.dataset.fallbackStep = String(step + 1);
        img.src = candidates[step];
        return;
    }
    img.dataset.fallbackAttempted = 'done';
    img.style.display = 'none';
    var frame = img.closest('.hero-group-frame');
    if (frame && !frame.querySelector('.hero-group-fallback')) {
        var fb = document.createElement('div');
        fb.className = 'hero-group-fallback';
        fb.innerHTML = '<div class="d-flex align-items-center justify-content-center gap-2 mb-2"><span class="instructor-avatar">G</span><span class="instructor-avatar">S</span><span class="instructor-avatar">A</span></div><div class="fw-bold text-dark small">MAS\'S Classroom Team</div><div class="text-muted" style="font-size: 0.78rem;">Shipu &bull; Shezan &bull; Lam</div>';
        frame.appendChild(fb);
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
