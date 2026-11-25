<?php
/**
 * Privacy Policy - MAS'S Classroom
 * University DBMS Project
 */
$pageTitle = "Privacy Policy";
require_once __DIR__ . '/header.php';
?>

<div class="container py-4 my-2">
    <div class="legal-shell">
        <div class="card legal-card p-4 p-md-5">
            <div class="legal-header d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
                <div>
                    <span class="legal-eyebrow mb-3">
                        <i class="bi bi-shield-check"></i>
                        <span>Data &amp; Privacy</span>
                    </span>
                    <h1 class="h2 fw-bold text-dark mb-1">Privacy Policy</h1>
                    <p class="text-muted small mb-0">
                        Effective Date: November 2026 &middot; MAS'S Classroom Relational Course Platform
                    </p>
                </div>
                <a href="terms.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold flex-shrink-0">
                    <i class="bi bi-file-earmark-text me-1"></i> Terms &amp; Conditions
                </a>
            </div>

            <section class="legal-section pt-0">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">1</span>
                    <span>Information We Collect</span>
                </h2>
                <p class="legal-text">
                    MAS'S Classroom collects only the information necessary to run course enrollment, user profiles, and relational academic records:
                </p>
                <ul class="legal-list">
                    <li><strong>Account Details:</strong> Full name, email address, optional phone number, optional profile image, account role, and a securely hashed password.</li>
                    <li><strong>Enrollment &amp; Activity Records:</strong> Selected courses, payment method, wallet/account reference, Transaction ID (TrxID), paid amount, and enrollment verification status.</li>
                    <li><strong>Session &amp; UI Preferences:</strong> Server-side PHP session state for authenticated access and local browser preferences such as Light/Dark theme selection.</li>
                </ul>
            </section>

            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">2</span>
                    <span>How Information Is Used</span>
                </h2>
                <ul class="legal-list">
                    <li>Authenticate your Student or Administrator session and enforce role-based access controls.</li>
                    <li>Process course enrollments, verify submitted payment references, and display your active or pending courses in your dashboard.</li>
                    <li>Deliver relevant course announcements, notices, and active tuition discount offers.</li>
                </ul>
            </section>

            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">3</span>
                    <span>Data Protection &amp; Session Security</span>
                </h2>
                <p class="legal-text mb-0">
                    Passwords are stored using cryptographic hashing (<code>password_hash</code>), and all database operations use prepared PHP PDO statements to prevent SQL injection. Login pages include cache-control protections and logout confirmation safeguards so active sessions are not accidentally terminated or exposed on shared browsers.
                </p>
            </section>

            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">4</span>
                    <span>Contact &amp; Policy Updates</span>
                </h2>
                <p class="legal-text mb-0">
                    We may update this Privacy Policy as platform features evolve. For account corrections or privacy inquiries, please contact the MAS'S Classroom project team at <a href="mailto:gmortuza2003@gmail.com" class="fw-semibold">gmortuza2003@gmail.com</a>.
                </p>
            </section>

            <div class="pt-4 mt-4 border-top d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
                <span>Your data stays inside the course platform database.</span>
                <a href="index.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i> Back to Home
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
