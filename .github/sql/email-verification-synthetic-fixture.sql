-- Synthetic records only. Execute only through the guarded disposable rehearsal helper.
INSERT INTO users (id,name,email,password,role,status,email_verified_at,created_at,updated_at) VALUES
 (1,'Synthetic applicant','applicant@example.test',SHA2(UUID(),256),'applicant','active',NULL,'2026-01-01','2026-01-01'),
 (2,'Synthetic pending employer','pending@example.test',SHA2(UUID(),256),'employer','active','2026-01-01','2026-01-01','2026-01-01'),
 (3,'Synthetic administrator','admin@example.test',SHA2(UUID(),256),'admin','active','2026-01-01','2026-01-01','2026-01-01'),
 (4,'Synthetic approved employer','approved@example.test',SHA2(UUID(),256),'employer','active',NULL,'2026-01-01','2026-01-01'),
 (5,'Synthetic suspended applicant','suspended@example.test',SHA2(UUID(),256),'applicant','suspended',NULL,'2026-01-01','2026-01-01');
INSERT INTO applicant_profiles (id,user_id,summary,created_at,updated_at)
 VALUES (1,1,'Synthetic profile only','2026-01-01','2026-01-01');
INSERT INTO employers (id,user_id,company_name,verification_status,created_at,updated_at) VALUES
 (1,2,'Synthetic pending company','pending','2026-01-01','2026-01-01'),
 (2,4,'Synthetic approved company','verified','2026-01-01','2026-01-01');
INSERT INTO job_listings (id,employer_id,title,location,employment_type,work_mode,description,status,moderation_status,created_at,updated_at)
 VALUES (1,2,'Synthetic job','Synthetic location','Full Time','Remote','Synthetic description','open','approved','2026-01-01','2026-01-01');
INSERT INTO applications (id,user_id,job_listing_id,status,cover_letter,created_at,updated_at)
 VALUES (1,1,1,'hired','Synthetic application','2026-01-01','2026-01-01');
INSERT INTO saved_jobs (id,user_id,job_listing_id,created_at,updated_at) VALUES (1,1,1,'2026-01-01','2026-01-01');
INSERT INTO user_notifications (id,user_id,title,body,created_at,updated_at)
 VALUES (1,1,'Synthetic notification','Synthetic body','2026-01-01','2026-01-01');
INSERT INTO sessions (id,user_id,payload,last_activity) VALUES
 ('synthetic-session-applicant',1,TO_BASE64('synthetic payload A'),100),
 ('synthetic-session-admin',3,TO_BASE64('synthetic payload B'),200);
