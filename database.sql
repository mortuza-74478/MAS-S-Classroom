-- ====================================================================
-- Database Schema for MAS'S Classroom - Online Course Platform
-- University DBMS Project
-- Database Name: course_db
-- ====================================================================

-- 1. Create the database if it doesn't already exist
CREATE DATABASE IF NOT EXISTS `course_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `course_db`;

-- Drop existing tables in reverse dependency order to avoid FK constraint issues during resets
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `notices`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `instructors`;
DROP TABLE IF EXISTS `students`;

-- ====================================================================
-- Table 1: instructors
-- Contains instructor profiles and their academic/industry expertise
-- ====================================================================
CREATE TABLE `instructors` (
    `instructor_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `phone` VARCHAR(20) DEFAULT NULL,
    `expertise` VARCHAR(150) NOT NULL,
    `bio` TEXT DEFAULT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `profile_image` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table 2: courses
-- Contains course catalog details linked to instructors via FK
-- ====================================================================
CREATE TABLE `courses` (
    `course_id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 2000.00,
    `instructor_id` INT DEFAULT NULL,
    `category` VARCHAR(60) NOT NULL DEFAULT 'Computer Science',
    `thumbnail` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_courses_instructor` 
        FOREIGN KEY (`instructor_id`) 
        REFERENCES `instructors` (`instructor_id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table 3: students
-- Stores registered students with hashed passwords
-- ====================================================================
CREATE TABLE `students` (
    `student_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `phone` VARCHAR(20) DEFAULT NULL,
    `role` VARCHAR(20) NOT NULL DEFAULT 'student',
    `password` VARCHAR(255) NOT NULL,
    `profile_image` VARCHAR(255) DEFAULT NULL,
    `reset_token` VARCHAR(64) DEFAULT NULL,
    `reset_expires` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table 4: enrollments
-- Junction table representing student course enrollments (Many-to-Many)
-- Supports Bangladeshi Payment Methods (bKash, Nagad, Rocket, Card)
-- ====================================================================
CREATE TABLE `enrollments` (
    `enrollment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `course_id` INT NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'bKash',
    `wallet_number` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `paid_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending', 'approved', 'rejected', 'dropped') NOT NULL DEFAULT 'pending',
    `enrollment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `verified_at` DATETIME DEFAULT NULL,
    `dropped_at` DATETIME DEFAULT NULL,
    `approval_seen` TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT `fk_enrollments_student` 
        FOREIGN KEY (`student_id`) 
        REFERENCES `students` (`student_id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    CONSTRAINT `fk_enrollments_course` 
        FOREIGN KEY (`course_id`) 
        REFERENCES `courses` (`course_id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    CONSTRAINT `unique_student_course` 
        UNIQUE (`student_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table 5: notices
-- Stores administrative notices and discount offers for students
-- ====================================================================
CREATE TABLE `notices` (
    `notice_id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `type` ENUM('Notice', 'Offer') NOT NULL DEFAULT 'Notice',
    `discount_badge` VARCHAR(50) DEFAULT NULL,
    `discount_percent` DECIMAL(5, 2) DEFAULT NULL,
    `offer_price` DECIMAL(10, 2) DEFAULT NULL,
    `instructor_id` INT DEFAULT NULL,
    `course_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notices_instructor` 
        FOREIGN KEY (`instructor_id`) 
        REFERENCES `instructors` (`instructor_id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
    CONSTRAINT `fk_notices_course` 
        FOREIGN KEY (`course_id`) 
        REFERENCES `courses` (`course_id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Table 6: audit_logs
-- ====================================================================
CREATE TABLE `audit_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(80) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `performed_by` VARCHAR(120) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- DUMMY DATA INSERTIONS
-- ====================================================================

-- Insert 3 Instructors
INSERT INTO `instructors` (`instructor_id`, `name`, `email`, `phone`, `expertise`, `bio`) VALUES
(1, 'Golam Mortuza Shipu', 'gmortuza2003@gmail.com', '01709016144', 'Full-Stack Web Development & Database Architecture', 'Studying CSE at Varendra University. Full-Stack Engineer and educator specializing in relational database systems, PHP architecture, and modern web application development.'),
(2, 'Shahnewas Kabir Shezan', 'shahnewaskabir79@gmail.com', '01850288594', 'Data Science & Machine Learning Systems', 'Studying CSE at Varendra University. Data science practitioner and software engineer specializing in predictive analytics, machine learning pipelines, and Python data engineering.'),
(3, 'Anik Rizvi Lam', 'anikrizvilam123@gmail.com', '01782264569', 'Cloud Infrastructure & Web Security', 'Studying CSE at Varendra University. Cloud engineer and cybersecurity researcher specializing in containerization, DevOps workflows, and web application security.');

-- Insert 6 Courses (All prices strictly between ৳2,000 and ৳3,000)
INSERT INTO `courses` (`course_id`, `title`, `description`, `price`, `instructor_id`, `category`, `thumbnail`) VALUES
(1, 'Database Systems & SQL Mastery', 'Master relational database modeling, normalization (1NF to BCNF), B+ Tree indexing, complex SQL JOINs, CTEs, query execution plan optimization, and ACID transaction isolation with hands-on MySQL schema design labs.', 2500.00, 1, 'Database Systems', 'images/courses/Database_Systems_SQL_Mastery_1280x720.png'),
(2, 'Full-Stack Web Development with PHP & MySQL', 'Build dynamic, secure, production-ready web applications using responsive HTML5, CSS3, Bootstrap 5, session-based role guards, prepared PHP PDO statements, and transactional MySQL architecture.', 2800.00, 1, 'Web Development', 'images/courses/Full-Stack_Web_Development_with_PHP_MySQL_1280x720.png'),
(3, 'Python for Data Science & Machine Learning', 'Comprehensive end-to-end training in numerical computing with NumPy, data cleaning and EDA with Pandas, statistical visualization with Matplotlib, and supervised ML pipelines using Scikit-Learn.', 2600.00, 2, 'Data Science', 'images/courses/Python_for_Data_Science_Machine_Learning_1280x720.png'),
(4, 'Deep Learning & Neural Networks in PyTorch', 'Hands-on exploration of PyTorch tensors, autograd, convolutional neural networks (CNNs), attention transformers, and mixed-precision model training with real classification and NLP labs.', 3000.00, 2, 'Artificial Intelligence', 'images/courses/Deep_Learning_Neural_Networks_in_PyTorch_1280x720.png'),
(5, 'Cloud Infrastructure & DevOps with Docker', 'Master modern containerization with multi-stage Dockerfiles, multi-service Docker Compose orchestration, automated CI/CD release pipelines, TLS reverse proxies, and AWS cloud observability.', 2200.00, 3, 'DevOps & Cloud', 'images/courses/Cloud_Infrastructure_DevOps_with_Docker_1280x720.png'),
(6, 'Web Application Security & Ethical Hacking', 'Defend web platforms against OWASP Top 10 threats through hands-on SQL injection prevention, RBAC/IDOR auditing, XSS/CSRF hardening, Content Security Policy, and penetration testing labs.', 2400.00, 3, 'Cybersecurity', 'images/courses/Web_Application_Security_Ethical_Hacking_1280x720.png');

-- Insert Default Administrators & Sample Student
-- Admin password is 'admin123'
-- Student password is 'password123'
INSERT INTO `students` (`student_id`, `name`, `email`, `phone`, `role`, `password`, `profile_image`) VALUES
(1, 'Golam Mortuza Shipu', 'gmortuza2003@gmail.com', '01709016144', 'admin', '$2y$12$LqAcKFAWS22627zeC8WXGuEm5h3P7c5Fkolp6FdGyE6vjDmdWY20m', NULL),
(2, 'Shahnewas Kabir Shezan', 'shahnewaskabir79@gmail.com', '01850288594', 'admin', '$2y$12$LqAcKFAWS22627zeC8WXGuEm5h3P7c5Fkolp6FdGyE6vjDmdWY20m', NULL),
(3, 'Anik Rizvi Lam', 'anikrizvilam123@gmail.com', '01782264569', 'admin', '$2y$12$LqAcKFAWS22627zeC8WXGuEm5h3P7c5Fkolp6FdGyE6vjDmdWY20m', NULL),
(4, 'John Doe', 'student@test.com', '01712345678', 'student', '$2y$12$KQ9gUhtQabSYAsnz7h7X/.4fbV0xNQTpm8Pck8fJEyDy1kL1MEDy.', NULL);

-- Insert Sample Enrollments for student (with Bangladeshi payment methods)
INSERT INTO `enrollments` (`enrollment_id`, `student_id`, `course_id`, `payment_method`, `wallet_number`, `transaction_id`, `paid_amount`, `status`, `enrollment_date`, `verified_at`, `dropped_at`, `approval_seen`) VALUES
(1, 4, 1, 'bKash', '01712345678', 'BKX98273614', 2500.00, 'approved', '2026-11-01 10:30:00', '2026-11-01 10:35:00', NULL, 1),
(2, 4, 2, 'Nagad', '01712345678', 'NGD54129873', 2800.00, 'approved', '2026-11-05 14:15:00', '2026-11-05 14:20:00', NULL, 1);

-- Insert Sample Notices & Discount Offers
INSERT INTO `notices` (`notice_id`, `title`, `description`, `type`, `discount_badge`, `instructor_id`, `course_id`, `created_at`) VALUES
(1, 'DBMS & SQL Mastery Special Discount - 25% OFF', 'Enroll in Database Systems & SQL Mastery at MAS''S Classroom this semester and get an instant 25% discount using coupon code MAS25 during bKash/Nagad checkout.', 'Offer', '25% OFF', 1, 1, '2026-11-15 10:00:00'),
(2, 'Web Development Bundle Offer - 20% OFF', 'Special student offer available on Full-Stack Web Development with PHP & MySQL courses in MAS''S Classroom. Complete enrollment this week for an instant 20% discount.', 'Offer', '20% OFF', 1, 2, '2026-11-15 12:00:00'),
(3, 'AI & Deep Learning Mastery Bundle - Flat ৳500 Cashback', 'Join Deep Learning & Neural Networks in PyTorch at MAS''S Classroom. Complete enrollment within this week to receive ৳500 instant course fee concession.', 'Offer', '৳500 OFF', 2, 4, '2026-11-16 11:30:00'),
(4, 'Live Workshop: Hands-on Cloud DevOps & Docker CI/CD', 'An interactive 3-hour live workshop will be held this Friday at 8:00 PM for all enrolled MAS''S Classroom students. Please ensure Docker Desktop is installed on your workstation.', 'Notice', 'Workshop', 3, 5, '2026-11-18 14:00:00'),
(5, 'Final Term Project Submission Guidelines & Rubric Published', 'The final DBMS schema normalization project requirements and evaluation matrix for Database Systems & SQL Mastery are now live. Submit your normalized ER diagrams and SQL scripts by the 30th.', 'Notice', 'Notice', 1, 1, '2026-11-20 09:15:00');
