<?php ob_start();
/**
 * Header Component
 * MAS'S Classroom - University DBMS Project
 */
require_once __DIR__ . '/db.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle . " - MAS'S Classroom" : "MAS'S Classroom | Online Course Platform";
$student = getCurrentStudent();

// Fetch active public offers and notices for navbar pop-up modals (visible to ALL visitors)
$publicOffers = [];
$publicNotices = [];
if (isset($pdo)) {
    try {
        $stmtPubOffers = $pdo->query("
            SELECT n.*,
                   COALESCE(i.name, ci.name, 'Golam Mortuza Shipu') AS instructor_name,
                   COALESCE(i.expertise, ci.expertise) AS instructor_expertise,
                   c.title AS course_title, c.category AS course_category,
                   c.price AS course_price, c.description AS course_description, c.thumbnail AS course_thumbnail
            FROM notices n
            LEFT JOIN instructors i ON n.instructor_id = i.instructor_id
            LEFT JOIN courses c ON n.course_id = c.course_id
            LEFT JOIN instructors ci ON c.instructor_id = ci.instructor_id
            WHERE LOWER(n.type) = 'offer'
            ORDER BY n.created_at DESC
        ");
        $publicOffers = $stmtPubOffers->fetchAll();

        $stmtPubNotices = $pdo->query("
            SELECT n.*,
                   COALESCE(i.name, ci.name, 'Golam Mortuza Shipu') AS instructor_name,
                   COALESCE(i.expertise, ci.expertise) AS instructor_expertise,
                   c.title AS course_title, c.category AS course_category
            FROM notices n
            LEFT JOIN instructors i ON n.instructor_id = i.instructor_id
            LEFT JOIN courses c ON n.course_id = c.course_id
            LEFT JOIN instructors ci ON c.instructor_id = ci.instructor_id
            WHERE LOWER(n.type) = 'notice'
            ORDER BY n.created_at DESC
        ");
        $publicNotices = $stmtPubNotices->fetchAll();
    } catch (Exception $e) {}
}
$totalOffersCount = count($publicOffers);
$totalNoticesCount = count($publicNotices);
$publicNoticeIds = array_map(function($n) { return (int)$n['notice_id']; }, $publicNotices);
$publicOfferIds  = array_map(function($o) { return (int)$o['notice_id']; }, $publicOffers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <!-- Google Fonts: Poppins (600, 700) and Inter (400, 500, 600, 700) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Custom CSS with cache-busting -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : time() ?>">

    <!-- Immediate Theme Application (Avoids flicker on page load) -->
    <script>
        (function() {
            var savedTheme = localStorage.getItem('sas_theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', savedTheme);
        })();
    </script>
</head>
<body>

<!-- Floating Spatial Navigation Bar -->
<header class="spatial-nav-sticky">
    <div class="container">
        <nav class="navbar navbar-expand-lg spatial-navbar">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <span class="brand-icon-box me-2">
                    <i class="bi bi-mortarboard-fill fs-5"></i>
                </span>
                <span>MAS'S <span class="brand-highlight">Classroom</span></span>
                <span class="brand-badge d-none d-sm-inline-block">DBMS</span>
            </a>

            <!-- Mobile 3-Dot Quick Navigation & Navbar Toggler -->
            <div class="d-flex align-items-center gap-2 d-lg-none flex-shrink-0">
                <button type="button" class="btn spatial-icon-btn position-relative d-flex align-items-center justify-content-center p-0" data-bs-toggle="offcanvas" data-bs-target="#quickNavDrawer" aria-controls="quickNavDrawer" title="Offers, Notices & Quick Navigation">
                    <i class="bi bi-three-dots-vertical fs-5"></i>
                    <span class="sas-drawer-alert-dot d-none"></span>
                </button>
                <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>

            <div class="collapse navbar-collapse min-w-0" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-2 align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage === 'index.php') ? 'active' : '' ?>" href="index.php">
                            <i class="bi bi-house-door"></i>
                            <span>Home</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php#courses">
                            <i class="bi bi-grid-fill"></i>
                            <span>Courses</span>
                        </a>
                    </li>
                    <?php if ($student): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($currentPage === 'my_courses.php') ? 'active' : '' ?>" href="my_courses.php?tab=courses#enrolled-courses">
                                <i class="bi bi-journal-bookmark-fill"></i>
                                <span>My Dashboard</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-2 mt-2 mt-lg-0 flex-shrink-0">
                    <!-- 3-Dot Quick Navigation Trigger (Desktop: Offers, Notices & Admin Actions) -->
                    <button type="button" class="btn spatial-icon-btn position-relative d-none d-lg-inline-flex align-items-center justify-content-center p-0 flex-shrink-0" data-bs-toggle="offcanvas" data-bs-target="#quickNavDrawer" aria-controls="quickNavDrawer" title="Offers, Notices & Quick Navigation">
                        <i class="bi bi-three-dots-vertical fs-5"></i>
                        <span class="sas-drawer-alert-dot d-none"></span>
                    </button>

                    <!-- Sun / Moon Light & Dark Mode Toggle -->
                    <button type="button" class="btn spatial-icon-btn d-inline-flex align-items-center justify-content-center p-0 flex-shrink-0" id="themeToggleBtn" title="Toggle Light / Dark Mode">
                        <i class="bi bi-sun-fill fs-5 theme-icon-light d-none" style="color: #FBBF24;"></i>
                        <i class="bi bi-moon-stars-fill fs-5 theme-icon-dark" style="color: #475569;"></i>
                    </button>

                    <?php if ($student): ?>
                        <div class="dropdown navbar-user-dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle rounded-pill navbar-user-pill" type="button" id="userMenuBtn" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if (!empty($student['profile_image']) && file_exists(__DIR__ . '/' . $student['profile_image'])): ?>
                                    <img src="<?= htmlspecialchars($student['profile_image']) ?>" alt="<?= htmlspecialchars($student['name']) ?>" class="rounded-circle object-fit-cover border flex-shrink-0" style="width: 26px; height: 26px;">
                                <?php else: ?>
                                    <i class="bi bi-person-circle fs-5 flex-shrink-0"></i>
                                <?php endif; ?>
                                <span class="fw-semibold navbar-user-name"><?= htmlspecialchars($student['name']) ?></span>
                                <?php if (isAdmin()): ?>
                                    <span class="badge bg-warning-subtle navbar-admin-badge">Admin</span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end profile-dropdown-menu mt-2" aria-labelledby="userMenuBtn">
                                <li class="px-3 py-2.5 border-bottom mb-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($student['profile_image']) && file_exists(__DIR__ . '/' . $student['profile_image'])): ?>
                                            <img src="<?= htmlspecialchars($student['profile_image']) ?>" alt="<?= htmlspecialchars($student['name']) ?>" class="rounded-circle object-fit-cover border flex-shrink-0" style="width: 38px; height: 38px;">
                                        <?php else: ?>
                                            <div class="brand-icon-box text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                                                <i class="bi bi-person-fill fs-5"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex align-items-center justify-content-between gap-2 flex-nowrap">
                                                <span class="fw-bold text-dark lh-sm profile-dropdown-name"><?= htmlspecialchars($student['name']) ?></span>
                                                <?php if (isAdmin()): ?>
                                                    <span class="badge bg-warning-subtle flex-shrink-0" style="font-size: 0.66rem; font-weight: 700;">Admin</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle flex-shrink-0" style="font-size: 0.66rem;">Student</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block mt-1 text-break" style="font-size: 0.78rem;"><?= htmlspecialchars($student['email']) ?></small>
                                            <?php if (!empty($student['phone'])): ?>
                                                <small class="text-muted d-inline-flex align-items-center gap-1 mt-1" style="font-size: 0.76rem;"><i class="bi bi-telephone-fill text-primary flex-shrink-0" style="font-size: 0.65rem;"></i><span><?= htmlspecialchars($student['phone']) ?></span></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                                <?php if (isAdmin()): ?>
                                    <li>
                                        <a class="dropdown-item fw-semibold" href="manage_courses.php">
                                            <i class="bi bi-shield-lock-fill text-primary"></i>
                                            <span>Platform Management (Admin)</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="my_courses.php?tab=notices#notices-section">
                                            <i class="bi bi-megaphone-fill text-warning"></i>
                                            <span>Post Notice / Offer</span>
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li>
                                    <a class="dropdown-item" href="my_courses.php?tab=courses#enrolled-courses">
                                        <i class="bi bi-journal-check text-primary"></i>
                                        <span><?= isAdmin() ? 'Instructor Dashboard' : 'My Enrolled Courses' ?></span>
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="my_courses.php?tab=profile#edit-profile">
                                        <i class="bi bi-person-gear text-info"></i>
                                        <span>Update Profile</span>
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger js-confirm-logout" href="logout.php" data-confirm-logout="true">
                                        <i class="bi bi-box-arrow-right"></i>
                                        <span>Log Out</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-primary rounded-pill px-3 py-1.5 fw-semibold">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Login
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </div>
</header>

<!-- Offcanvas Slide-out Drawer for Quick Navigation (3-Dot / 3-Line Menu) -->
<div class="offcanvas offcanvas-end spatial-offcanvas" tabindex="-1" id="quickNavDrawer" aria-labelledby="quickNavDrawerLabel" style="width: 340px;">
    <div class="offcanvas-header border-bottom py-3 px-4">
        <div class="d-flex align-items-center">
            <span class="brand-icon-box me-2" style="width: 34px; height: 34px;">
                <i class="bi bi-mortarboard-fill fs-6"></i>
            </span>
            <div>
                <h6 class="offcanvas-title fw-bold mb-0" id="quickNavDrawerLabel" style="font-size: 1.05rem;">
                    MAS'S <span class="text-primary">Classroom</span>
                </h6>
                <small class="text-muted" style="font-size: 0.72rem;">Quick Navigation Menu</small>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column justify-content-between p-3 px-4">
        <div>
            <!-- User Status Header inside Drawer (if logged in) -->
            <?php if ($student): ?>
                <div class="p-3 mb-3 spatial-subpanel d-flex align-items-center">
                    <?php if (!empty($student['profile_image']) && file_exists(__DIR__ . '/' . $student['profile_image'])): ?>
                        <img src="<?= htmlspecialchars($student['profile_image']) ?>" alt="<?= htmlspecialchars($student['name']) ?>" class="rounded-circle object-fit-cover me-3 border" style="width: 40px; height: 40px;">
                    <?php else: ?>
                        <div class="brand-icon-box text-white rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width: 40px; height: 40px;">
                            <i class="bi bi-person-fill fs-5"></i>
                        </div>
                    <?php endif; ?>
                    <div class="overflow-hidden">
                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.92rem;"><?= htmlspecialchars($student['name']) ?></div>
                        <div class="small text-muted text-truncate" style="font-size: 0.76rem;"><?= htmlspecialchars($student['email']) ?></div>
                        <span class="badge mt-1 <?= isAdmin() ? 'bg-warning-subtle' : 'bg-primary-subtle' ?>" style="font-size: 0.66rem;">
                            <?= isAdmin() ? '<i class="bi bi-shield-fill-check me-1"></i>Administrator' : '<i class="bi bi-mortarboard-fill me-1"></i>Student' ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Navigation Links -->
            <div class="text-uppercase small fw-bold text-muted mb-2 px-1" style="font-size: 0.72rem; letter-spacing: 0.08em;">
                <i class="bi bi-compass me-1 text-primary"></i> Quick Navigation
            </div>

            <div class="list-group list-group-flush rounded-3 border mb-3">
                <a href="index.php" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3">
                    <i class="bi bi-house-door-fill text-primary me-3 fs-5"></i>
                    <div>
                        <div class="fw-semibold small">Home</div>
                        <div class="text-muted" style="font-size: 0.75rem;">Landing page &amp; overview</div>
                    </div>
                </a>

                <a href="index.php#courses-catalog" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3">
                    <i class="bi bi-grid-fill text-success me-3 fs-5"></i>
                    <div>
                        <div class="fw-semibold small">Course Catalog</div>
                        <div class="text-muted" style="font-size: 0.75rem;">Browse all courses &amp; enroll</div>
                    </div>
                </a>

                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3 w-100 text-start" data-quick-modal="#publicOffersModal">
                    <i class="bi bi-tag-fill text-warning me-3 fs-5"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small d-flex align-items-center justify-content-between">
                            <span>Offers &amp; Discounts</span>
                            <span class="badge bg-warning text-dark rounded-pill sas-offers-badge d-none"><?= $totalOffersCount ?></span>
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem;">Course discounts &amp; coupon deals</div>
                    </div>
                </button>

                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3 w-100 text-start" data-quick-modal="#publicNoticesModal">
                    <i class="bi bi-megaphone-fill text-primary me-3 fs-5"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small d-flex align-items-center justify-content-between">
                            <span>Notices</span>
                            <span class="badge bg-primary text-white rounded-pill sas-notices-badge d-none"><?= $totalNoticesCount ?></span>
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem;">Important instructor updates &amp; class news</div>
                    </div>
                </button>

                <?php if ($student): ?>
                    <a href="my_courses.php?tab=courses#enrolled-courses" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3">
                        <i class="bi bi-journal-bookmark-fill text-info me-3 fs-5"></i>
                        <div>
                            <div class="fw-semibold small"><?= isAdmin() ? 'Instructor Dashboard' : 'Student Dashboard' ?></div>
                            <div class="text-muted" style="font-size: 0.75rem;"><?= isAdmin() ? 'Course overview &amp; student directory' : 'Enrolled courses &amp; profile' ?></div>
                        </div>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($student && isAdmin()): ?>
                <!-- Admin Controls (Admins Only) -->
                <div class="text-uppercase small fw-bold text-muted mb-2 px-1" style="font-size: 0.72rem; letter-spacing: 0.08em;">
                    <i class="bi bi-shield-lock-fill me-1 text-warning"></i> Admin Controls
                </div>
                <div class="list-group list-group-flush rounded-3 border mb-3">
                    <a href="manage_courses.php" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3 <?= ($currentPage === 'manage_courses.php') ? 'active' : '' ?>" style="background-color: rgba(245, 158, 11, 0.08);">
                        <i class="bi bi-sliders text-warning me-3 fs-5"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small d-flex align-items-center justify-content-between">
                                <span class="text-warning">Manage Platform</span>
                                <span class="badge bg-warning-subtle navbar-admin-badge">Admin</span>
                            </div>
                            <div class="text-muted" style="font-size: 0.75rem;">Courses, offers, verifications &amp; CRUD</div>
                        </div>
                    </a>

                    <a href="manage_courses.php#notices-tab" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3">
                        <i class="bi bi-megaphone-fill text-info me-3 fs-5"></i>
                        <div>
                            <div class="fw-semibold small">Compose Notice / Offer</div>
                            <div class="text-muted" style="font-size: 0.75rem;">Publish announcements &amp; discounts</div>
                        </div>
                    </a>

                    <a href="setup.php" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3 text-danger">
                        <i class="bi bi-arrow-clockwise text-danger me-3 fs-5"></i>
                        <div>
                            <div class="fw-semibold small text-danger">Reset Database (Setup)</div>
                            <div class="text-muted" style="font-size: 0.75rem;">Re-seed schema &amp; sample data</div>
                        </div>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Quick Access / Account Links -->
            <div class="text-uppercase small fw-bold text-muted mb-2 px-1" style="font-size: 0.72rem; letter-spacing: 0.08em;">
                <i class="bi bi-key me-1 text-primary"></i> Account &amp; Access
            </div>
            <div class="list-group list-group-flush rounded-3 border">
                <?php if ($student): ?>
                    <a href="logout.php" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3 text-danger js-confirm-logout" data-confirm-logout="true">
                        <i class="bi bi-box-arrow-right me-3 fs-5"></i>
                        <span class="small fw-semibold">Log Out</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="list-group-item list-group-item-action d-flex align-items-center py-2.5 px-3">
                        <i class="bi bi-box-arrow-in-right text-primary me-3 fs-5"></i>
                        <span class="small fw-semibold">Login</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Academic Project Note -->
        <div class="pt-3 border-top mt-3 text-center small text-muted" style="font-size: 0.78rem;">
            <div><strong>MAS'S Classroom</strong> &bull; DBMS Academic Project</div>
            <div class="text-secondary opacity-75">Shipu &bull; Shezan &bull; Lam</div>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- PUBLIC POP-UP MODAL 1: COURSE OFFERS & DISCOUNTS                     -->
<!-- Visible to ALL visitors (guests, students, and admins)               -->
<!-- ==================================================================== -->
<div class="modal fade" id="publicOffersModal" tabindex="-1" aria-labelledby="publicOffersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header public-modal-header align-items-start py-3 px-4 gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(245, 158, 11, 0.16); color: #F59E0B; border-radius: 14px; border: 1px solid rgba(245, 158, 11, 0.3);">
                        <i class="bi bi-tag-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="publicOffersModalLabel">Course Discounts & Special Offers</h5>
                        <small class="text-muted">Active tuition discounts, limited-time deals, and coupon codes</small>
                    </div>
                </div>
                <button type="button" class="btn-close ms-auto mt-1 flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php if (empty($publicOffers)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-tag text-muted fs-1 mb-2 d-block"></i>
                        <h6 class="fw-bold text-dark">No Active Discount Offers</h6>
                        <p class="text-muted small mb-0">There are currently no active course discount codes. Please check back soon!</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($publicOffers as $off): 
                            $offerCourseId = !empty($off['course_id']) ? (int)$off['course_id'] : 0;
                            $offerTargetUrl = ($offerCourseId > 0) ? "checkout.php?course_id={$offerCourseId}" : "index.php#courses-catalog";
                            $instructorName = !empty($off['instructor_name']) ? $off['instructor_name'] : 'Golam Mortuza Shipu';
                            $offCoursePrice = !empty($off['course_price']) ? (float)$off['course_price'] : 0;
                            $offerBadgeText = !empty($off['discount_badge']) ? $off['discount_badge'] : 'Special Offer';
                            $offDiscount = ($offCoursePrice > 0) ? calculateCourseDiscount($offCoursePrice, $offerBadgeText, $off['discount_percent'] ?? null, $off['offer_price'] ?? null) : ['has_discount' => false, 'discount_amount' => 0, 'discounted_price' => $offCoursePrice];
                        ?>
                            <div class="card public-notice-card p-3 p-md-4" style="border-left: 4px solid #F59E0B !important;">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge offer-discount-badge rounded-pill">
                                            <i class="bi bi-tag-fill"></i><span><?= htmlspecialchars($offerBadgeText) ?></span>
                                        </span>
                                        <?php if (!empty($off['course_title'])): ?>
                                            <span class="badge rounded-pill px-3 py-1 small offer-course-pill d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-book-half"></i><span>Course: <?= htmlspecialchars($off['course_title']) ?></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill px-3 py-1 small offer-course-pill d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-tags-fill"></i><span>All Eligible Courses</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-muted small d-inline-flex align-items-center gap-1">
                                        <i class="bi bi-clock"></i><span><?= formatDateSafe($off['created_at'] ?? null) ?></span>
                                    </span>
                                </div>
                                <h6 class="fw-bold text-dark mb-2" style="font-size: 1.05rem;">
                                    <?= htmlspecialchars($off['title']) ?>
                                </h6>
                                <p class="text-secondary small mb-3" style="line-height: 1.6; white-space: pre-line;">
                                    <?= htmlspecialchars($off['description']) ?>
                                </p>
                                
                                <?php if ($offDiscount['has_discount'] && $offCoursePrice > 0): ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 px-3 spatial-subpanel">
                                        <small class="text-muted fw-semibold" style="font-size: 0.76rem;">SPECIAL TUITION:</small>
                                        <span class="fw-bold text-primary fs-6"><?= formatPrice($offDiscount['discounted_price']) ?></span>
                                        <del class="small strikethrough-old-price" style="font-size: 0.82rem;"><?= formatPrice($offCoursePrice) ?></del>
                                        <span class="badge bg-danger-subtle text-danger ms-auto" style="font-size: 0.68rem; font-weight: 700;"><?= htmlspecialchars($offerBadgeText) ?> APPLIED</span>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-wrap align-items-center justify-content-between pt-2 border-top gap-2 small">
                                    <div class="modal-instructor-meta text-muted">
                                        <i class="bi bi-person-badge-fill text-warning modal-instructor-icon"></i>
                                        <span>Instructor: <strong class="instructor-name-highlight text-dark"><?= htmlspecialchars($instructorName) ?></strong></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" data-switch-modal="#singleOfferModal_<?= $off['notice_id'] ?>">
                                            <i class="bi bi-info-circle me-1"></i> Details
                                        </button>
                                        <a href="<?= htmlspecialchars($offerTargetUrl) ?>" class="btn btn-sm btn-warning rounded-pill px-3 fw-bold offer-enroll-btn" style="pointer-events: auto !important; position: relative !important; z-index: 1060 !important;">
                                            <i class="bi bi-arrow-right-circle me-1"></i> Enroll Now
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer public-modal-footer py-3 px-4">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Close</button>
                <a href="index.php#courses-catalog" class="btn btn-warning rounded-pill px-3 btn-sm fw-bold offer-enroll-btn" style="pointer-events: auto !important; position: relative !important; z-index: 1060 !important;">
                    <i class="bi bi-book-half me-1"></i> Browse Course Catalog
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- DEDICATED POP-UP MODALS: SINGLE COURSE-SPECIFIC OFFER DETAILS        -->
<!-- ==================================================================== -->
<?php foreach ($publicOffers as $off): 
    $singleCourseId = !empty($off['course_id']) ? (int)$off['course_id'] : 0;
    $singleTargetUrl = ($singleCourseId > 0) ? "checkout.php?course_id={$singleCourseId}" : "index.php#courses-catalog";
    $singleInstName = !empty($off['instructor_name']) ? $off['instructor_name'] : 'Golam Mortuza Shipu';
    $singleCPrice = !empty($off['course_price']) ? (float)$off['course_price'] : 0;
    $singleBadgeText = !empty($off['discount_badge']) ? $off['discount_badge'] : 'Special Offer';
    $singleDiscount = ($singleCPrice > 0) ? calculateCourseDiscount($singleCPrice, $singleBadgeText, $off['discount_percent'] ?? null, $off['offer_price'] ?? null) : ['has_discount' => false, 'discount_amount' => 0, 'discounted_price' => $singleCPrice];
    $singleThumbUrl = !empty($off['course_title']) ? getCourseThumbnailUrl($off['course_title'], $off['course_thumbnail'] ?? null) : null;
    list($singleHeaderClass, $singleIconClass) = getCourseCategoryVisual($off['course_category'] ?? '');
?>
<div class="modal fade" id="singleOfferModal_<?= $off['notice_id'] ?>" tabindex="-1" aria-labelledby="singleOfferModalLabel_<?= $off['notice_id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header public-modal-header align-items-start py-3 px-4 gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(245, 158, 11, 0.16); color: #F59E0B; border-radius: 14px; border: 1px solid rgba(245, 158, 11, 0.3);">
                        <i class="bi bi-tag-fill fs-5"></i>
                    </div>
                    <div>
                        <span class="badge offer-discount-badge rounded-pill mb-1">
                            <i class="bi bi-tag-fill"></i><span><?= htmlspecialchars($singleBadgeText) ?></span>
                        </span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="singleOfferModalLabel_<?= $off['notice_id'] ?>">
                            <?= htmlspecialchars($off['title']) ?>
                        </h5>
                    </div>
                </div>
                <button type="button" class="btn-close ms-auto mt-1 flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Linked Course Highlight Box -->
                <div class="p-3 p-md-4 spatial-subpanel mb-4">
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-start mb-3">
                        <?php if ($singleThumbUrl || !empty($off['course_title'])): ?>
                            <div class="course-thumb-wrap offer-course-thumb <?= $singleHeaderClass ?>">
                                <?php if ($singleThumbUrl): ?>
                                    <img src="<?= htmlspecialchars($singleThumbUrl) ?>" alt="<?= htmlspecialchars($off['course_title'] ?? $off['title']) ?>" class="course-card-thumb">
                                    <div class="course-card-scrim"></div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center h-100 text-white-50">
                                        <i class="bi <?= $singleIconClass ?> fs-2"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1">
                                    <i class="bi bi-book-half me-1"></i><?= htmlspecialchars($off['course_category'] ?? 'Course Special') ?>
                                </span>
                                <span class="text-muted small">
                                    <i class="bi bi-calendar3 me-1"></i>Active Offer &bull; <?= formatDateSafe($off['created_at'] ?? null) ?>
                                </span>
                            </div>
                            <h5 class="fw-bold text-dark mb-1">
                                <?= htmlspecialchars($off['course_title'] ?? $off['title']) ?>
                            </h5>
                            <?php if (!empty($off['course_description'])): ?>
                                <p class="text-secondary small mb-0">
                                    <?= htmlspecialchars(mb_strimwidth((string)$off['course_description'], 0, 180, '...')) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between pt-3 border-top gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-person-badge-fill text-warning fs-5 flex-shrink-0"></i>
                            <div>
                                <small class="text-muted d-block" style="font-size: 0.72rem;">INSTRUCTOR</small>
                                <span class="fw-bold text-dark small instructor-name-highlight"><?= htmlspecialchars($singleInstName) ?></span>
                            </div>
                        </div>
                        <?php if ($singleCPrice > 0): ?>
                            <div>
                                <small class="text-muted d-block text-end" style="font-size: 0.72rem;">DISCOUNTED TUITION</small>
                                <div class="d-flex align-items-baseline gap-2">
                                    <span class="fs-4 fw-bold text-primary"><?= formatPrice($singleDiscount['discounted_price']) ?></span>
                                    <del class="small strikethrough-old-price"><?= formatPrice($singleCPrice) ?></del>
                                    <span class="badge bg-danger-subtle text-danger fw-bold"><?= htmlspecialchars($singleBadgeText) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Offer Terms / Details -->
                <h6 class="fw-bold text-dark mb-2">
                    <i class="bi bi-file-earmark-text-fill text-warning me-1"></i> Offer Terms & Information
                </h6>
                <div class="text-secondary small mb-4" style="line-height: 1.7; white-space: pre-line;">
                    <?= htmlspecialchars($off['description']) ?>
                </div>

                <div class="alert spatial-alert-amber d-flex align-items-center p-3 mb-0 gap-2">
                    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
                    <div class="small">
                        This discount offer will be automatically applied to your tuition when you proceed to enrollment checkout using bKash, Nagad, or Debit/Credit Card.
                    </div>
                </div>
            </div>
            <div class="modal-footer public-modal-footer py-3 px-4 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm fw-semibold" data-switch-modal="#publicOffersModal">
                    <i class="bi bi-arrow-left me-1"></i> Back to All Offers
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Close</button>
                    <a href="<?= htmlspecialchars($singleTargetUrl) ?>" class="btn btn-warning rounded-pill px-4 btn-sm fw-bold offer-enroll-btn" style="pointer-events: auto !important; position: relative !important; z-index: 1060 !important;">
                        <i class="bi bi-mortarboard-fill me-1"></i> <?= ($singleDiscount['has_discount']) ? 'Enroll with Discount (' . formatPrice($singleDiscount['discounted_price']) . ')' : 'Enroll in this Course' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ==================================================================== -->
<!-- PUBLIC POP-UP MODAL 2: INSTRUCTOR NOTICES                             -->
<!-- Visible to ALL visitors (guests, students, and admins)               -->
<!-- ==================================================================== -->
<div class="modal fade" id="publicNoticesModal" tabindex="-1" aria-labelledby="publicNoticesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header public-modal-header align-items-start py-3 px-4 gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: var(--primary-tint); color: var(--primary-brand); border-radius: 14px; border: 1px solid var(--primary-tint-border);">
                        <i class="bi bi-megaphone-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="publicNoticesModalLabel">Instructor Notices</h5>
                        <small class="text-muted">Important updates and announcements from MAS'S Classroom instructors</small>
                    </div>
                </div>
                <button type="button" class="btn-close ms-auto mt-1 flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php if (empty($publicNotices)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-megaphone text-muted fs-1 mb-2 d-block"></i>
                        <h6 class="fw-bold text-dark">No Active Announcements</h6>
                        <p class="text-muted small mb-0">There are currently no instructor notices posted. Please check back soon!</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($publicNotices as $ntc): 
                            $noticeCourseId = !empty($ntc['course_id']) ? (int)$ntc['course_id'] : 0;
                            $noticeTargetUrl = ($noticeCourseId > 0) ? "checkout.php?course_id={$noticeCourseId}" : "index.php#courses-catalog";
                            $instName = !empty($ntc['instructor_name']) ? $ntc['instructor_name'] : 'Golam Mortuza Shipu';
                        ?>
                            <div class="card public-notice-card p-3 p-md-4" style="border-left: 4px solid #4F46E5 !important;">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-bell-fill"></i><span><?= htmlspecialchars($ntc['discount_badge'] ?? 'Notice') ?></span>
                                        </span>
                                        <?php if (!empty($ntc['course_title'])): ?>
                                            <span class="badge rounded-pill px-3 py-1 small notice-course-pill d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-book-half"></i><span>Course: <?= htmlspecialchars($ntc['course_title']) ?></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill px-3 py-1 small notice-course-pill d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-megaphone"></i><span>Instructor Notice</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-muted small d-inline-flex align-items-center gap-1">
                                        <i class="bi bi-calendar3"></i><span><?= formatDateSafe($ntc['created_at'] ?? null) ?></span>
                                    </span>
                                </div>
                                <h6 class="fw-bold text-dark mb-2" style="font-size: 1.05rem;">
                                    <?= htmlspecialchars($ntc['title']) ?>
                                </h6>
                                <p class="text-secondary small mb-3" style="line-height: 1.6; white-space: pre-line;">
                                    <?= htmlspecialchars($ntc['description']) ?>
                                </p>
                                <div class="pt-2 border-top d-flex flex-wrap align-items-center justify-content-between small text-muted gap-2">
                                    <div class="modal-instructor-meta">
                                        <i class="bi bi-person-badge-fill text-primary modal-instructor-icon"></i>
                                        <span>Instructor: <strong class="instructor-name-highlight text-dark"><?= htmlspecialchars($instName) ?></strong></span>
                                    </div>
                                    <?php if ($noticeCourseId > 0): ?>
                                        <a href="<?= htmlspecialchars($noticeTargetUrl) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-semibold offer-enroll-btn" style="font-size: 0.78rem; pointer-events: auto !important; position: relative !important; z-index: 1060 !important;">
                                            <i class="bi bi-arrow-right-circle me-1"></i>View Course & Enroll
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer public-modal-footer py-3 px-4">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Raw Metadata for Client-Side Unseen Badge Counter Tracking -->
<script id="sas-notices-data" type="application/json">
<?= json_encode([
    'noticeIds' => $publicNoticeIds,
    'offerIds'  => $publicOfferIds
]) ?>
</script>

<!-- Main Container for Flash Messages & Content -->
<main class="py-4">
    <div class="container">
        <?php displayFlashMessages(); ?>
    </div>
