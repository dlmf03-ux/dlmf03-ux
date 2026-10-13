CREATE TABLE IF NOT EXISTS survey_forms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) NOT NULL UNIQUE,
  yandex_form_id VARCHAR(64) NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  department VARCHAR(128) NOT NULL,
  question_count TINYINT UNSIGNED NOT NULL DEFAULT 5,
  nps_question_number TINYINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_id INT UNSIGNED NOT NULL,
  question_number TINYINT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  is_nps TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_question (form_id, question_number),
  CONSTRAINT fk_questions_form FOREIGN KEY (form_id) REFERENCES survey_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_responses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_id INT UNSIGNED NOT NULL,
  external_answer_id VARCHAR(64) NULL,
  external_form_id VARCHAR(64) NULL,
  source_email VARCHAR(255) NULL,
  response_date DATE NULL,
  room VARCHAR(64) NULL,
  contact VARCHAR(255) NULL,
  comment TEXT NULL,
  raw_body MEDIUMTEXT NOT NULL,
  imported_via ENUM('manual','imap','site') NOT NULL DEFAULT 'manual',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_external_answer (external_answer_id),
  KEY idx_response_date (response_date),
  KEY idx_form_date (form_id, response_date),
  CONSTRAINT fk_responses_form FOREIGN KEY (form_id) REFERENCES survey_forms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_scores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id BIGINT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_response_question (response_id, question_id),
  KEY idx_question_score (question_id, score),
  CONSTRAINT fk_scores_response FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
  CONSTRAINT fk_scores_question FOREIGN KEY (question_id) REFERENCES survey_questions(id),
  CONSTRAINT chk_score_range CHECK (score BETWEEN 0 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source ENUM('manual','imap') NOT NULL,
  message_uid VARCHAR(128) NULL,
  status ENUM('imported','duplicate','skipped','error') NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_import_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO survey_forms (slug, yandex_form_id, title, department, question_count, nps_question_number) VALUES
('service', 'demo-form-0001', 'Service feedback', 'Service', 3, 3),
('staff', 'demo-form-0002', 'Staff feedback', 'Staff', 3, 3),
('facility', 'demo-form-0003', 'Facility feedback', 'Facility', 3, 3),
('activities', 'demo-form-0004', 'Activities feedback', 'Activities', 3, 3),
('overall', 'demo-form-0005', 'Overall feedback', 'General', 3, 3),
('quick', 'demo-form-0006', 'Quick feedback', 'General', 1, 1)
ON DUPLICATE KEY UPDATE yandex_form_id = VALUES(yandex_form_id), title = VALUES(title), department = VALUES(department), question_count = VALUES(question_count), nps_question_number = VALUES(nps_question_number);

INSERT INTO survey_questions (form_id, question_number, question_text, is_nps)
SELECT f.id, q.question_number, q.question_text, q.is_nps
FROM survey_forms f
JOIN (
  SELECT 'service' slug, 1 question_number, 'How satisfied are you with the service quality?' question_text, 0 is_nps UNION ALL
  SELECT 'service', 2, 'How easy was it to get the help you needed?', 0 UNION ALL
  SELECT 'service', 3, 'How likely are you to recommend this service?', 1 UNION ALL
  SELECT 'staff', 1, 'How helpful was the staff?', 0 UNION ALL
  SELECT 'staff', 2, 'How clearly was information explained?', 0 UNION ALL
  SELECT 'staff', 3, 'How likely are you to recommend this team?', 1 UNION ALL
  SELECT 'facility', 1, 'How clean and comfortable was the facility?', 0 UNION ALL
  SELECT 'facility', 2, 'Were the available amenities sufficient?', 0 UNION ALL
  SELECT 'facility', 3, 'How likely are you to recommend this facility?', 1 UNION ALL
  SELECT 'activities', 1, 'How engaging were the available activities?', 0 UNION ALL
  SELECT 'activities', 2, 'Did the schedule meet your needs?', 0 UNION ALL
  SELECT 'activities', 3, 'How likely are you to recommend these activities?', 1 UNION ALL
  SELECT 'overall', 1, 'How satisfied were you overall?', 0 UNION ALL
  SELECT 'overall', 2, 'Did the experience meet your expectations?', 0 UNION ALL
  SELECT 'overall', 3, 'How likely are you to recommend this organization?', 1 UNION ALL
  SELECT 'quick', 1, 'Rate the service from 0 to 10', 1
) q ON q.slug = f.slug
ON DUPLICATE KEY UPDATE question_text = VALUES(question_text), is_nps = VALUES(is_nps);
