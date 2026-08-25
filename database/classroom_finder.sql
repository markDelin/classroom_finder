-- ============================================================
-- CLASSROOM FINDER — Database schema (MySQL / MariaDB)
--
-- Import via phpMyAdmin or:
--   mysql -u root < database/classroom_finder.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS classroom_finder
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE classroom_finder;

-- ------------------------------------------------------------
-- Users (admins + lecturers; students use the public page only)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  full_name      VARCHAR(120)     NOT NULL,
  staff_id       VARCHAR(40)      NOT NULL,
  email          VARCHAR(120)     NOT NULL,
  username       VARCHAR(40)      NOT NULL,
  password       VARCHAR(255)     NOT NULL,           -- password_hash()
  department     VARCHAR(80)      DEFAULT NULL,
  role           ENUM('admin','lecturer') NOT NULL DEFAULT 'lecturer',
  account_status ENUM('pending','approved','rejected','suspended')
                                  NOT NULL DEFAULT 'pending',
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_staff_id (staff_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- Classrooms (one unique QR token per room)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classrooms (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  room_number VARCHAR(20)   NOT NULL,
  building    VARCHAR(80)   NOT NULL,
  floor       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  capacity    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  room_type   VARCHAR(40)   NOT NULL DEFAULT 'Lecture Room',
  qr_token    CHAR(32)      NOT NULL,                -- secret stored in the QR code
  status      ENUM('available','maintenance','disabled')
                            NOT NULL DEFAULT 'available',
  note        VARCHAR(160)  DEFAULT NULL,            -- e.g. "Repainting until Friday"
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_location (building, room_number),
  UNIQUE KEY uq_rooms_token (qr_token)
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- Classroom sessions (a lecturer's occupancy of a room)
--   active    -> currently occupying
--   completed -> ran to its scheduled end time
--   released  -> lecturer (or admin) ended it early
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classroom_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  classroom_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  start_time   DATETIME     NOT NULL,
  end_time     DATETIME     NOT NULL,
  status       ENUM('active','completed','released') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_room_active (classroom_id, status),
  KEY idx_sessions_end (end_time),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_session_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- Reservations (future bookings -> 🟡 RESERVED on the landing page)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  classroom_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED DEFAULT NULL,              -- lecturer the slot is for
  purpose      VARCHAR(160) DEFAULT NULL,
  start_time   DATETIME     NOT NULL,
  end_time     DATETIME     NOT NULL,
  status       ENUM('active','cancelled','completed') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_res_room_active (classroom_id, status),
  KEY idx_res_start (start_time),
  CONSTRAINT fk_res_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_res_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- Activity logs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED    DEFAULT NULL,
  classroom_id INT UNSIGNED    DEFAULT NULL,
  action       VARCHAR(40)     NOT NULL,              -- OCCUPY_ROOM, RELEASE_ROOM, ...
  details      VARCHAR(255)    DEFAULT NULL,
  timestamp    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logs_time (timestamp),
  KEY idx_logs_action (action),
  KEY idx_logs_user (user_id)
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- System settings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(40)  NOT NULL,
  svalue VARCHAR(255) NOT NULL,
  PRIMARY KEY (skey)
) ENGINE = InnoDB;

INSERT INTO settings (skey, svalue) VALUES
  ('school_name',              'Classroom Finder'),
  ('reserve_window_minutes',   '45'),   -- how soon a booking turns a room RESERVED
  ('min_duration_minutes',     '15'),   -- shortest occupancy a lecturer may pick
  ('max_duration_minutes',     '480'),  -- longest occupancy
  ('duration_step_minutes',    '30'),   -- +/- step in the scanner's duration picker
  ('landing_refresh_seconds',  '15')    -- landing page auto-refresh interval
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);

-- ------------------------------------------------------------
-- Sample classrooms
-- Tokens are generated here; regenerate any of them from
-- Admin → QR Codes if needed.
-- ------------------------------------------------------------
INSERT INTO classrooms (room_number, building, floor, capacity, room_type, qr_token, status) VALUES
  ('101', 'New Building',   1, 40, 'Lecture Room',  MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('102', 'New Building',   1, 35, 'Lecture Room',  MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('201', 'New Building',   2, 45, 'Lecture Room',  MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('202', 'New Building',   2, 30, 'Lecture Room',  MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('301', 'New Building',   3, 60, 'Lecture Room',    MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('103',  'New Building', 1, 30, 'Lecture Room',    MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('104',  'New Building', 1, 30, 'Lecture Room',  MD5(CONCAT('seed-', RAND(), UUID())), 'available'),
  ('105', 'New Building',  1, 25, 'Lecture Room',  MD5(CONCAT('seed-', RAND(), UUID())), 'available');
