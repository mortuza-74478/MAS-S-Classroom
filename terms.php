<?php
/**
 * Terms and Conditions - MAS'S Classroom
 * University DBMS Project
 */
$pageTitle = "Terms and Conditions";
require_once __DIR__ . '/header.php';
?>

<div class="container py-4 my-2">
    <div class="legal-shell">
        <div class="card legal-card p-4 p-md-5">
            <div class="legal-header d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
                <div>
                    <span class="legal-eyebrow mb-3">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Platform Terms</span>
                    </span>
                    <h1 class="h2 fw-bold text-dark mb-1">Terms and Conditions</h1>
                    <p class="text-muted small mb-0">
                        Effective Date: November 2026 &middot; MAS'S Classroom Relational Course Platform
                    </p>
                </div>
                <a href="privacy.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold flex-shrink-0">
                    <i class="bi bi-shield-check me-1"></i> Privacy Policy
                </a>
            </div>

            <section class="legal-section pt-0">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">1</span>
                    <span>Acceptance of Terms</span>
                </h2>
                <p class="legal-text mb-0">
                    By accessing, browsing, or creating an account on <strong>MAS'S Classroom</strong>, you agree to be bound by these Terms and Conditions and our <a href="privacy.php" class="fw-semibold">Privacy Policy</a>. If you do not agree with any part of these terms, please discontinue use of the platform.
                </p>
            </section>

            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">2</span>
                    <span>Account &amp; Role Responsibilities</span>
                </h2>
                <p class="legal-text">
                    Access to MAS'S Classroom is structured around distinct <strong>Student</strong> and <strong>Administrator</strong> roles to keep academic records and enrollment operations secure:
                </p>
                <ul class="legal-list">
                    <li><strong>Students:</strong> Must provide accurate name and email information during registration, keep login credentials confidential, and avoid sharing personal accounts with others.</li>
                    <li><strong>Administrators &amp; Instructors:</strong> Must handle course records, enrollment verifications, notices, and discount offers responsibly and strictly for academic platform management.</li>
                    <li><strong>Unauthorized Access:</strong> Attempting to bypass role checks, manipulate database requests, or access restricted administration views is strictly prohibited.</li>
                </ul>
            </section>

            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">3</span>
                    <span>Course Enrollment &amp; Platform Usage</span>
                </h2>
                <ul class="legal-list">
                    <li><strong>Payment Verification:</strong> Course enrollments submitted via bKash, Nagad, Rocket, or Card remain in <em>Pending Verification</em> until an administrator confirms the submitted Transaction ID (TrxID).</li>
                    <li><strong>Active Access &amp; Drops:</strong> Full course details unlock automatically upon verification. Dropping an active course removes course access, while collected tuition remains non-refundable and archived in enrollment history.</li>
                    <li><strong>Promotional Offers:</strong> Course discounts displayed in the Offers &amp; Discounts modal apply automatically at checkout while active.</li>
                </ul>
            </section>

            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">4</span>
                    <span>Content &amp; Academic Use</span>
                </h2>
                <p class="legal-text mb-0">
                    All course syllabi, descriptions, lab takeaways, and instructional materials published on MAS'S Classroom are provided solely for personal academic learning and university coursework. Reproducing, reselling, or redistributing platform materials outside authorized educational use is not permitted.
                </p>
            </section>

            <div class="pt-4 mt-4 border-top d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
                <span>Questions? Contact the MAS'S Classroom team via the footer links.</span>
                <a href="index.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i> Back to Home
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
