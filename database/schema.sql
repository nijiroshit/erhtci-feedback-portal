CREATE DATABASE IF NOT EXISTS erhtci_feedback
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE erhtci_feedback;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('Student', 'Staff', 'Instructor', 'Admin') NOT NULL,
  login_identifier VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  admin_slot TINYINT GENERATED ALWAYS AS (
    CASE WHEN role = 'Admin' THEN 1 ELSE NULL END
  ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login_identifier (login_identifier),
  UNIQUE KEY uq_users_single_admin (admin_slot)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(500) NOT NULL,
  category VARCHAR(60) NOT NULL,
  is_anonymous BOOLEAN NOT NULL DEFAULT TRUE,
  status ENUM('Pending', 'Under Review', 'Resolved') NOT NULL DEFAULT 'Pending',
  admin_response TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_feedback_user_created (user_id, created_at),
  KEY idx_feedback_status_created (status, created_at),
  CONSTRAINT fk_feedback_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  feedback_id BIGINT UNSIGNED NOT NULL,
  changed_by BIGINT UNSIGNED NOT NULL,
  old_status ENUM('Pending', 'Under Review', 'Resolved') NULL,
  new_status ENUM('Pending', 'Under Review', 'Resolved') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_history_feedback_created (feedback_id, created_at),
  CONSTRAINT fk_status_history_feedback
    FOREIGN KEY (feedback_id) REFERENCES feedback (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_status_history_user
    FOREIGN KEY (changed_by) REFERENCES users (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
  bucket_hash CHAR(64) NOT NULL,
  attempts INT UNSIGNED NOT NULL,
  window_started_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (bucket_hash),
  KEY idx_auth_rate_limits_window (window_started_at)
) ENGINE=InnoDB;
