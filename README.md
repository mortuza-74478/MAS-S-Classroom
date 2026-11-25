# MAS'S Classroom — Online Course Platform

A full-stack relational course management and student enrollment platform built with **Core PHP (PDO)**, **MySQL**, **JavaScript**, **Bootstrap 5.3**, and a custom **Spatial Glassmorphism** UI (supporting both Light and Dark modes).

---

## Tech Stack

- **Backend:** Core PHP 8+ with PDO prepared statements & session-based authentication
- **Database:** MySQL / MariaDB (`course_db`)
- **Frontend:** HTML5, Vanilla JavaScript, Bootstrap 5.3, Bootstrap Icons
- **Styling:** Custom Spatial Glass CSS (`assets/css/style.css`) with Light/Dark theme persistence

---

## Quick Local Setup (XAMPP)

1. **Place the Project in `htdocs`:**
   - Copy or clone this repository into your XAMPP web root:
     ```text
     C:\xampp\htdocs\course_platform
     ```
2. **Start Local Servers:**
   - Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
3. **Import the Database (`course_db`):**
   - **Option A — phpMyAdmin (Recommended):**
     1. Open `http://localhost/phpmyadmin/`
     2. Click **Import** at the top
     3. Select `database_setup.sql` from the project root and click **Import**
   - **Option B — 1-Click Browser Installer:**
     - Visit `http://localhost/course_platform/setup.php` and click **Click to Initialize / Reset Database**.
4. **Configure Credentials (if not using XAMPP defaults):**
   - Default connection values in `db.php` are `localhost`, database `course_db`, user `root`, empty password `''`. Edit `$db_host`, `$db_name`, `$db_user`, and `$db_pass` at the top of `db.php` if your local MySQL configuration differs.
5. **Run the Application:**
   - Open `http://localhost/course_platform/` in your browser.

---

## Demo Accounts

- **Student Demo:** `student@test.com` / Password: `password123`
- **Admin Demo Accounts:**
  - `gmortuza2003@gmail.com` / Password: `admin123`
  - `shahnewaskabir79@gmail.com` / Password: `admin123`
  - `anikrizvilam123@gmail.com` / Password: `admin123`

*(Use the **Student Login | Admin Login** switcher on `login.php`.)*

---

## Key Features

- **Role-Based Authentication:** Separate **Student Login** and **Admin Login** tabs with role verification, self-registration (`register.php`), password recovery (`forgot_password.php`), and logout confirmation safeguards.
- **Course Catalog & Checkout:** Live search, instructor/category filters, sorting, two-column Course Details modal, Bangladeshi checkout gateways (`bKash`, `Nagad`, `Rocket`, `Debit / Credit Card` in BDT `৳`), and discount offer application.
- **Admin CRUD & Verification Center (`manage_courses.php`):** Full `INSERT`, `SELECT JOIN`, `UPDATE`, `DELETE` management for courses, instructors, students, pending enrollment verifications, audit history, and single/bulk notice & offer management.
- **Profile Sync & Client-Side Image Cropper:** Updating a user profile photo syncs directly to matching instructor avatars on course cards and modals, using a built-in pan/zoom cropper with 3x3 alignment guide lines before upload.
- **Legal & Theme Support:** Responsive Light & Dark theme toggle, plus dedicated `terms.php` and `privacy.php` pages linked in the footer.

---

## Database Schema (`database_setup.sql`)

- `instructors` — Instructor profiles, expertise, bios, and synced `avatar` paths
- `courses` — Catalog courses linked via FK `instructor_id` (`ON DELETE SET NULL`)
- `students` — Student and admin accounts (`role` column), profile images, and reset tokens
- `enrollments` — Student-course enrollments with payment metadata, `status`, and `UNIQUE (student_id, course_id)`
- `notices` — Announcements (`Notice`) and course discounts (`Offer`) linked to instructors/courses
- `audit_logs` — Administrative notice/offer deletion audit log

---

## Project Structure

```text
course_platform/
├── database_setup.sql     # Complete MySQL schema & sample seed data (phpMyAdmin ready)
├── database.sql           # Legacy/mirror SQL seed kept compatible with setup tools
├── db.php                 # Portable PDO config, auto-migrations & shared helpers
├── setup.php              # Browser 1-click database initializer & reset tool
├── index.php              # Public hero, search/filter catalog & Course Details modals
├── login.php              # Student / Admin switcher login & demo Google sign-in
├── admin-login.php        # Direct Administrator sign-in portal
├── register.php           # Student registration (redirects to login after creation)
├── forgot_password.php    # 6-digit OTP password reset flow
├── checkout.php           # Course enrollment checkout & payment submission
├── enroll.php             # Enrollment controller
├── my_courses.php         # Student / Instructor dashboard & Update Profile handler
├── manage_courses.php     # Admin CRUD dashboard, verifications, notices & offers
├── header.php             # Shared navbar, 3-dot quick drawer, Notices & Offers modals
├── footer.php             # Footer, Legal links, Logout modal & Image Cropper engine
├── terms.php / privacy.php# Terms and Conditions & Privacy Policy pages
├── assets/css/style.css   # Spatial Glassmorphism Light & Dark theme stylesheet
└── images/                # Course thumbnails, popup banners & payment method logos
```

---

## Project Team

- **Golam Mortuza Shipu** — `gmortuza2003@gmail.com` (`01709016144`)
- **Shahnewas Kabir Shezan** — `shahnewaskabir79@gmail.com` (`01850288594`)
- **Anik Rizvi Lam** — `anikrizvilam123@gmail.com` (`01782264569`)
