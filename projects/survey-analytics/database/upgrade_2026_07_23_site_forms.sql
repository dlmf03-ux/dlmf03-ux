ALTER TABLE survey_responses
  MODIFY imported_via ENUM('manual','imap','site') NOT NULL DEFAULT 'manual';
