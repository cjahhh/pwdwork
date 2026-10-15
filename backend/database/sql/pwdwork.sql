-- PWDWork MySQL schema for phpMyAdmin (XAMPP).
-- Import: phpMyAdmin -> Import -> choose this file -> Go
-- Demo password for all seeded users: password
--
-- Prefer Laravel when possible:
--   1. Create empty database `pwdwork` in phpMyAdmin (or let this file create it)
--   2. cd backend && copy .env.example .env && php artisan key:generate
--   3. php artisan migrate:fresh --seed
-- If you import this SQL, skip migrate:fresh (tables already exist).

CREATE DATABASE IF NOT EXISTS `pwdwork`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `pwdwork`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `user_notifications`;
DROP TABLE IF EXISTS `saved_jobs`;
DROP TABLE IF EXISTS `applications`;
DROP TABLE IF EXISTS `job_listing_accommodation`;
DROP TABLE IF EXISTS `applicant_accommodation`;
DROP TABLE IF EXISTS `job_listings`;
DROP TABLE IF EXISTS `employers`;
DROP TABLE IF EXISTS `applicant_profiles`;
DROP TABLE IF EXISTS `accommodations`;
DROP TABLE IF EXISTS `personal_access_tokens`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `migrations`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `migrations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'applicant',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`, `tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accommodations` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `icon` varchar(16) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accommodations_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `applicant_profiles` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `preferred_work_mode` varchar(32) DEFAULT NULL,
  `summary` text,
  `resume_path` varchar(255) DEFAULT NULL,
  `pwd_id_number` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `applicant_profiles_user_id_unique` (`user_id`),
  CONSTRAINT `applicant_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_logo` varchar(8) DEFAULT NULL,
  `company_color` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `description` text,
  `verification_status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employers_user_id_unique` (`user_id`),
  CONSTRAINT `employers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_listings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `employer_id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `employment_type` varchar(32) NOT NULL,
  `work_mode` varchar(32) NOT NULL,
  `description` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_listings_employer_id_foreign` (`employer_id`),
  CONSTRAINT `job_listings_employer_id_foreign` FOREIGN KEY (`employer_id`) REFERENCES `employers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `applicant_accommodation` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_profile_id` bigint UNSIGNED NOT NULL,
  `accommodation_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `applicant_accommodation_unique` (`applicant_profile_id`, `accommodation_id`),
  KEY `applicant_accommodation_accommodation_id_foreign` (`accommodation_id`),
  CONSTRAINT `applicant_accommodation_applicant_profile_id_foreign` FOREIGN KEY (`applicant_profile_id`) REFERENCES `applicant_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `applicant_accommodation_accommodation_id_foreign` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_listing_accommodation` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_listing_id` bigint UNSIGNED NOT NULL,
  `accommodation_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_listing_accommodation_unique` (`job_listing_id`, `accommodation_id`),
  KEY `job_listing_accommodation_accommodation_id_foreign` (`accommodation_id`),
  CONSTRAINT `job_listing_accommodation_job_listing_id_foreign` FOREIGN KEY (`job_listing_id`) REFERENCES `job_listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_listing_accommodation_accommodation_id_foreign` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `applications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `job_listing_id` bigint UNSIGNED NOT NULL,
  `cover_letter` text,
  `status` varchar(32) NOT NULL DEFAULT 'submitted',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `applications_user_id_job_listing_id_unique` (`user_id`, `job_listing_id`),
  KEY `applications_job_listing_id_foreign` (`job_listing_id`),
  CONSTRAINT `applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `applications_job_listing_id_foreign` FOREIGN KEY (`job_listing_id`) REFERENCES `job_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `saved_jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `job_listing_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `saved_jobs_user_id_job_listing_id_unique` (`user_id`, `job_listing_id`),
  KEY `saved_jobs_job_listing_id_foreign` (`job_listing_id`),
  CONSTRAINT `saved_jobs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saved_jobs_job_listing_id_foreign` FOREIGN KEY (`job_listing_id`) REFERENCES `job_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_notifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_notifications_user_id_foreign` (`user_id`),
  CONSTRAINT `user_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_cache_table', 1),
('0001_01_01_000002_create_jobs_table', 1),
('2026_10_08_064215_create_personal_access_tokens_table', 1),
('2026_10_15_140000_create_accommodations_table', 1),
('2026_10_15_140001_create_applicant_profiles_table', 1),
('2026_10_15_140002_create_employers_table', 1),
('2026_10_15_140003_create_job_listings_table', 1),
('2026_10_15_140004_create_pivot_and_activity_tables', 1);

-- bcrypt of "password"
INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `status`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'PWDWork Admin', 'admin@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', NULL, NOW(), NOW()),
(2, 'Jordan Cruz', 'applicant@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'applicant', 'active', NULL, NOW(), NOW()),
(3, 'Alex Rivera', 'inclusive@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employer', 'active', NULL, NOW(), NOW()),
(4, 'Sam Ortega', 'abledev@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employer', 'active', NULL, NOW(), NOW()),
(5, 'Mia Santos', 'caredirect@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employer', 'active', NULL, NOW(), NOW()),
(6, 'Noah Reyes', 'equaldata@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employer', 'active', NULL, NOW(), NOW()),
(7, 'Leah Tan', 'horizon@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employer', 'active', NULL, NOW(), NOW()),
(8, 'Chris Lim', 'openaccess@pwdwork.org', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employer', 'active', NULL, NOW(), NOW());

INSERT INTO `accommodations` (`id`, `slug`, `label`, `icon`, `created_at`, `updated_at`) VALUES
(1, 'remote', 'Remote', '🌐', NOW(), NOW()),
(2, 'onsite', 'Onsite', '🏢', NOW(), NOW()),
(3, 'wheelchair-access', 'Wheelchair Access', '♿', NOW(), NOW()),
(4, 'flexible-hours', 'Flexible Hours', '⏰', NOW(), NOW()),
(5, 'sign-language-support', 'Sign Language Support', '🤟', NOW(), NOW()),
(6, 'screen-reader-optimized', 'Screen Reader Optimized', '🎧', NOW(), NOW());

INSERT INTO `applicant_profiles` (`id`, `user_id`, `phone`, `location`, `preferred_work_mode`, `summary`, `pwd_id_number`, `created_at`, `updated_at`) VALUES
(1, 2, '+63 900 555 0101', 'Metro Manila', 'Remote', 'Accessibility-focused jobseeker looking for inclusive remote or hybrid roles.', 'PWD-000123', NOW(), NOW());

INSERT INTO `applicant_accommodation` (`applicant_profile_id`, `accommodation_id`, `created_at`, `updated_at`) VALUES
(1, 1, NOW(), NOW()),
(1, 4, NOW(), NOW()),
(1, 6, NOW(), NOW());

INSERT INTO `employers` (`id`, `user_id`, `company_name`, `company_logo`, `company_color`, `location`, `description`, `verification_status`, `created_at`, `updated_at`) VALUES
(1, 3, 'InclusiveTech Labs', 'IT', 'bg-blue-600', 'Remote (Global)', 'Inclusive product studio specializing in WCAG-compliant software.', 'verified', NOW(), NOW()),
(2, 4, 'AbleDev Solutions', 'AD', 'bg-teal-600', 'Remote (PH & Asia)', 'Frontend engineering team building ARIA-first component libraries.', 'verified', NOW(), NOW()),
(3, 5, 'CareDirect Hub', 'CD', 'bg-indigo-600', 'Metro Manila', 'Accessible customer support campus with tactile paving and ramps.', 'verified', NOW(), NOW()),
(4, 6, 'EqualData Inc.', 'ED', 'bg-emerald-600', 'Remote (Global)', 'Workforce inclusion analytics and accessible dashboards.', 'verified', NOW(), NOW()),
(5, 7, 'Horizon Media', 'HM', 'bg-purple-600', 'Cebu City', 'Plain-language educational content studio.', 'verified', NOW(), NOW()),
(6, 8, 'OpenAccess Labs', 'OA', 'bg-sky-600', 'Remote', 'Assistive-technology QA and accessibility testing lab.', 'verified', NOW(), NOW());

INSERT INTO `job_listings` (`id`, `employer_id`, `title`, `location`, `employment_type`, `work_mode`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Senior UX/UI Accessibility Designer', 'Remote (Global)', 'Full Time', 'Remote', 'Lead the design of inclusive web applications adhering strictly to WCAG 2.1 AAA standards. Collaborate with user researchers with lived disability experience.', 'open', NOW(), NOW()),
(2, 2, 'Frontend Developer (Accessibility)', 'Remote (PH & Asia)', 'Full Time', 'Remote', 'Build semantic, ARIA-compliant component libraries. Work alongside sign language interpreters and team members using voice interface tools.', 'open', NOW(), NOW()),
(3, 3, 'Customer Support Specialist', 'Metro Manila / Onsite', 'Full Time', 'Onsite', 'Handle incoming customer inquiries via live chat, email, and video calls. Fully equipped campus with tactile paving and wheelchair ramps.', 'open', NOW(), NOW()),
(4, 4, 'Data Analyst', 'Remote (Global)', 'Part Time', 'Remote', 'Analyze workforce inclusion metrics and construct accessible dashboards using screen-reader friendly data visualization frameworks.', 'open', NOW(), NOW()),
(5, 5, 'Content Specialist', 'Cebu City / Onsite', 'Full Time', 'Onsite', 'Write clear, plain-language text and craft comprehensive alt-text captions for digital educational campaigns.', 'open', NOW(), NOW()),
(6, 6, 'Software QA Engineer', 'Remote', 'Contract', 'Remote', 'Perform automated and manual accessibility testing across assistive technology screen readers, switch devices, and eye-tracking software.', 'open', NOW(), NOW());

INSERT INTO `job_listing_accommodation` (`job_listing_id`, `accommodation_id`, `created_at`, `updated_at`) VALUES
(1, 1, NOW(), NOW()), (1, 3, NOW(), NOW()), (1, 6, NOW(), NOW()), (1, 4, NOW(), NOW()),
(2, 1, NOW(), NOW()), (2, 4, NOW(), NOW()), (2, 5, NOW(), NOW()),
(3, 2, NOW(), NOW()), (3, 3, NOW(), NOW()), (3, 5, NOW(), NOW()),
(4, 1, NOW(), NOW()), (4, 4, NOW(), NOW()), (4, 6, NOW(), NOW()),
(5, 2, NOW(), NOW()), (5, 3, NOW(), NOW()), (5, 4, NOW(), NOW()),
(6, 1, NOW(), NOW()), (6, 5, NOW(), NOW()), (6, 3, NOW(), NOW());

INSERT INTO `applications` (`user_id`, `job_listing_id`, `cover_letter`, `status`, `created_at`, `updated_at`) VALUES
(2, 1, 'I would like to apply. This workplace lists the accommodations I need.', 'reviewing', NOW(), NOW());

INSERT INTO `saved_jobs` (`user_id`, `job_listing_id`, `created_at`, `updated_at`) VALUES
(2, 1, NOW(), NOW());

INSERT INTO `user_notifications` (`user_id`, `title`, `body`, `read_at`, `created_at`, `updated_at`) VALUES
(2, 'Application received', 'InclusiveTech Labs is reviewing your application for Senior UX/UI Accessibility Designer.', NULL, NOW(), NOW()),
(1, 'Employer verification queue', 'Six inclusive employers are marked verified in the demo seed.', NULL, NOW(), NOW());
