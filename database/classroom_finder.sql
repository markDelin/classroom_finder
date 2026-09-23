CREATE DATABASE IF NOT EXISTS classroom_finder
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE classroom_finder;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  full_name      VARCHAR(120)     NOT NULL,
  staff_id       VARCHAR(40)      NOT NULL,
  email          VARCHAR(120)     NOT NULL,
  username       VARCHAR(40)      NOT NULL,
  password       VARCHAR(255)     NOT NULL,
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

CREATE TABLE IF NOT EXISTS classrooms (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  room_number VARCHAR(20)   NOT NULL,
  building    VARCHAR(80)   NOT NULL,
  floor       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  capacity    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  room_type   VARCHAR(40)   NOT NULL DEFAULT 'Lecture Room',
  qr_token    VARCHAR(32)   NOT NULL,
  status      ENUM('available','maintenance','disabled')
                            NOT NULL DEFAULT 'available',
  note        VARCHAR(160)  DEFAULT NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_location (building, room_number),
  UNIQUE KEY uq_rooms_token (qr_token)
) ENGINE = InnoDB;

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

CREATE TABLE IF NOT EXISTS class_schedules (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  classroom_id INT UNSIGNED NOT NULL,
  day_of_week  TINYINT UNSIGNED NOT NULL,
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,
  subject      VARCHAR(120) NOT NULL,
  section      VARCHAR(80)  DEFAULT NULL,
  instructor   VARCHAR(120) DEFAULT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sched_room_day (classroom_id, day_of_week, is_active),
  CONSTRAINT fk_sched_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms (id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS schedule_force_open (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  classroom_id INT UNSIGNED NOT NULL,
  schedule_id  INT UNSIGNED NOT NULL,
  exc_date     DATE NOT NULL,
  reason       ENUM('lecturer_absent','emergency','ended_early','other') NOT NULL,
  details      VARCHAR(160) DEFAULT NULL,
  user_id      INT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_force_slot (schedule_id, exc_date),
  CONSTRAINT fk_force_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_force_sched FOREIGN KEY (schedule_id)
    REFERENCES class_schedules (id) ON DELETE CASCADE,
  CONSTRAINT fk_force_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED    DEFAULT NULL,
  classroom_id INT UNSIGNED    DEFAULT NULL,
  action       VARCHAR(40)     NOT NULL,
  details      VARCHAR(255)    DEFAULT NULL,
  timestamp    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logs_time (timestamp),
  KEY idx_logs_action (action),
  KEY idx_logs_user (user_id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(40)  NOT NULL,
  svalue VARCHAR(255) NOT NULL,
  PRIMARY KEY (skey)
) ENGINE = InnoDB;

INSERT INTO settings (skey, svalue) VALUES
  ('app_name',                 'Classroom Finder'),
  ('school_name',              ''),
  ('school_address',           ''),
  ('school_contact',           ''),
  ('min_duration_minutes',     '15'),
  ('max_duration_minutes',     '480'),
  ('duration_step_minutes',    '30'),
  ('landing_refresh_seconds',  '15'),
  ('scan_day_start',           '07:00'),
  ('scan_day_end',             '19:00')
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);

INSERT INTO classrooms (room_number, building, floor, capacity, room_type, qr_token, status, note) VALUES
  ('101', 'New Building',         1, 40, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('102', 'New Building',         1, 35, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('103', 'New Building',         1, 30, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('104', 'New Building',         1, 30, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('105', 'New Building',         1, 25, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('201', 'New Building',         2, 45, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('202', 'New Building',         2, 30, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('203', 'New Building',         2, 40, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('204', 'New Building',         2, 35, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('301', 'New Building',         3, 60, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('302', 'New Building',         3, 50, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('303', 'New Building',         3, 40, 'College Comlab',    SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'IT Multimedia Lab'),
  ('101', 'Main Building',        1, 45, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('102', 'Main Building',        1, 45, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('201', 'Main Building',        2, 40, 'College Comlab',    SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'College Computer Lab 1'),
  ('202', 'Main Building',        2, 40, 'College Comlab',    SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'College Computer Lab 2'),
  ('301', 'Main Building',        3, 50, 'Lecture Room',      SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', NULL),
  ('302', 'Main Building',        3, 80, 'Other',             SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'Audio-Visual Hall'),
  ('HS-101', 'High School Building', 1, 40, 'Highschool Room', SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'Grade 7 Section A'),
  ('HS-102', 'High School Building', 1, 40, 'Highschool Room', SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'Grade 8 Section A'),
  ('HS-201', 'High School Building', 2, 35, 'Highschool Comlab', SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'High School Computer Lab'),
  ('HS-202', 'High School Building', 2, 40, 'Highschool Room', SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'Grade 9 Section A'),
  ('HS-301', 'High School Building', 3, 40, 'Highschool Room', SUBSTRING(MD5(CONCAT('seed-', RAND(), UUID())), 1, 8), 'available', 'Grade 10 Section A');
