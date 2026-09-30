# Clarendon College — Classroom Finder

> Real-time campus classroom availability board, door QR code check-in engine, and academic scheduling system for Clarendon College.

**Live Demo / Production:** [https://classrooom-finder.page.gd](https://classrooom-finder.page.gd)  
**Target Environment:** PHP 8.1+ | MySQL 5.7+ / MariaDB 10.4+ | Any Web Server (Apache / Nginx / Shared Hosting / Laragon / XAMPP)

---

## Table of Contents

1. [Overview & Core Capabilities](#overview--core-capabilities)
2. [Visual Identity & Brand Architecture](#visual-identity--brand-architecture)
3. [System Architecture & Lifecycle](#system-architecture--lifecycle)
4. [Availability State Engine](#availability-state-engine)
5. [Database Schema Reference](#database-schema-reference)
6. [API Specification](#api-specification)
7. [User Roles & Security Hierarchy](#user-roles--security-hierarchy)
8. [Installation & Deployment Guide](#installation--deployment-guide)
9. [System Configuration Reference](#system-configuration-reference)
10. [Administrative Runbooks](#administrative-runbooks)
11. [Background Workers & Automation](#background-workers--automation)
12. [Security Architecture](#security-architecture)
13. [Progressive Web App (PWA) & Offline Support](#progressive-web-app-pwa--offline-support)
14. [Troubleshooting & FAQ](#troubleshooting--faq)

---

## Overview & Core Capabilities

Clarendon College Classroom Finder eliminates physical room scouting across multi-floor campus facilities.

- **Students & Campus Visitors:** Instantly check live room availability across all buildings and floors without logging in. View countdown timers showing when currently occupied classrooms will become free.
- **Faculty & Lecturers:** Walk up to any classroom, scan the door poster using a mobile camera (or type the 8-character token), choose a duration, and claim the room. When a scheduled class is cancelled or dismissed early, authorized lecturers can trigger an audited "Force-Open" override to make the room immediately available.
- **Campus Administrators & Facilities Staff:** Manage the physical inventory of rooms, configure weekly recurring class timetables, review live and past room sessions, audit system security events, approve lecturer registrations, and batch-print door QR posters.

---

## Visual Identity & Brand Architecture

The system features the official identity of **Clarendon College**:

- **Hardcoded Institution Seal:** The official Clarendon College seal (`assets/img/logo.png`) is permanently embedded into the sticky navigation bar, landing hero banner, administrative interfaces, and timetable printouts.
- **Aerial Campus View:** The public room finder displays an aerial drone perspective of the Clarendon College campus (`assets/img/campus-bg.webp`) as a fixed-attachment background beneath a calibrated, high-contrast frosted scrim.
- **Clarendon Navy Palette:** Built on deep Clarendon blue (`#0F3B6E`), slate gray (`#0F172A`), clean white surfaces (`#FFFFFF`), and semantic status indicators (emerald green for available, crimson red for occupied, amber for warnings, slate for maintenance).
- **Self-Hosted Typography:**
  - **Barlow Condensed** (500/600/700) for door-plate numerals, room numbers, and hero titles.
  - **IBM Plex Sans** (400/500/600) for readable body typography and interface navigation.
  - **IBM Plex Mono** (400/500/600) for tabular timestamps, QR tokens, session counters, and metadata chips.

---

## System Architecture & Lifecycle

```text
[ Browser / Mobile Device ]
       │
       ├─► (Public Visitor) ──► GET index.php ──────► Real-Time Room Grid (Auto-polls /api/classroom_status.php)
       │
       ├─► (Lecturer Camera) ─► POST api/scan_qr.php ─► Token Validation & Schedule Conflict Check
       │                                                      │
       │                                                      ▼
       │                                             POST lecturer/occupy.php
       │                                             (SELECT ... FOR UPDATE transaction)
       │                                                      │
       │                                                      ▼
       ├─► (Cron / Scheduler) ─► CLI cron/expire.php ─► Transitions expired sessions to 'completed'
       │
       └─► (Administrator) ──► Admin Portal (admin/*) ─► CRUD Classrooms, Timetables, Users, Print Sheets
```

### Directory Structure

```text
classroom_finder/
├── admin/                         # Administrative control panel
│   ├── classrooms.php             # Room inventory (numbers, buildings, floors, capacities, types)
│   ├── dashboard.php              # Real-time KPIs, active sessions list, room status breakdown
│   ├── history.php                # Comprehensive occupancy log with CSV export
│   ├── logs.php                   # Security & operational audit trail
│   ├── qr_codes.php               # QR code directory, token regeneration, 6-per-page printable sheets
│   ├── schedules.php              # Weekly master timetable manager and force-open audit
│   ├── sessions.php               # Live session monitor with administrative termination override
│   ├── settings.php               # Operational hours and system duration parameters
│   └── users.php                  # User verification, role assignment, and password resets
├── api/                           # JSON endpoints for async UI updates
│   ├── classroom_status.php       # Live status polling and HTML card rendering
│   ├── force_open.php             # Schedule cancellation override handler
│   ├── release_room.php           # Active session early termination
│   └── scan_qr.php                # QR code token validation and eligibility check
├── assets/                        # Static client-side assets
│   ├── css/
│   │   ├── style.css              # Core design system and responsive layout rules
│   │   └── vendor/toastify.min.css# Notification toaster styles
│   ├── fonts/                     # Bundled woff2 font files (IBM Plex Sans, IBM Plex Mono, Barlow)
│   ├── img/                       # Hardcoded brand identity assets
│   │   ├── campus-bg.webp         # Clarendon College aerial campus background (lightweight WebP)
│   │   └── logo.png               # Official Clarendon College crest
│   ├── js/
│   │   ├── admin-modals.js        # Admin dialogs and confirmation helpers
│   │   ├── landing.js             # Live polling engine and client-side filter coordinator
│   │   ├── scanner.js             # Html5Qrcode camera driver, token submission, audio feedback
│   │   ├── ui.js                  # Navigation drawer, toast alerts, theme initializers
│   │   └── vendor/                # html5-qrcode, sweetalert2, toastify
│   ├── sound/                     # Audio cues (success.mp3, error.mp3)
│   └── uploads/                   # Runtime image storage and fallback logos
├── auth/                          # Authentication subsystems
│   ├── auth_check.php             # Session validators (require_login, require_admin, require_approved_lecturer)
│   └── login_process.php          # Credential verification, rate limits, audit logging
├── config/                        # Core configuration & framework helpers
│   ├── database.php               # Singleton PDO connection manager
│   ├── helpers.php                # CSRF tokens, session bootstrap, flash messaging, asset initializers
│   ├── icons.php                  # Optimized inline SVG icon library
│   └── layout.php                 # Shared headers, navigation topbar/sidebar, footers, room cards
├── cron/                          # Automation scripts
│   └── expire.php                 # CLI-only background session expiration worker
├── database/                      # SQL definitions and migration scripts
│   ├── classroom_finder.sql       # Complete MariaDB/MySQL database schema and default seeds
│   └── seed_classrooms.php        # Initial 30-room campus fixture generator
├── lecturer/                      # Lecturer mobile-first self-service portal
│   ├── dashboard.php              # Active occupancy status and end-session control
│   ├── history.php                # Personal teaching room usage history
│   ├── occupy.php                 # Pessimistic lock session creation handler
│   ├── release.php                # Session release processing
│   └── scanner.php                # Camera QR scanner and manual 8-character token entry
├── qr/                            # Dynamic QR rendering service
│   ├── generate.php               # PNG stream QR generator endpoint (?token=...)
│   └── lib/                       # Bundled phpqrcode generation engine
├── services/                      # Decoupled business logic domain layer
│   ├── room_service.php           # Status calculation, token extraction, room CRUD
│   ├── schedule_service.php       # Weekly recurring timetable slots and force-open logic
│   ├── session_service.php        # Occupancy creation, release, expiration, duration clamping
│   └── user_service.php           # Account authentication, status transitions, password management
├── 403.php                        # HTTP 403 Forbidden template
├── 404.php                        # HTTP 404 Not Found template
├── 500.php                        # HTTP 500 Server Error template
├── change_password.php            # Authenticated user password update
├── error.php                      # Generic application error template
├── index.php                      # Public live availability board
├── login.php                      # User authentication gateway
├── logout.php                     # Session termination handler
├── manifest.json                  # PWA installation manifest
├── schema.sql                     # Canonical database schema mirror
├── setup.php                      # First-run locked administrator setup wizard
└── sw.js                          # Service Worker for asset caching and offline resiliency
```

---

## Availability State Engine

Every classroom's computed status is dynamically evaluated in real time:

```text
Classroom Status Hierarchy:
1. Is classroom.status in ('maintenance', 'disabled')?
   └─► YES: Output UNAVAILABLE (Gray) with administrator note.
2. Is there an active classroom_session (status='active' AND end_time > NOW())?
   └─► YES: Output OCCUPIED (Red) with lecturer name, end time, and countdown progress bar.
3. Is there a weekly class_schedule active right now (matching current day-of-week and time)?
   └─► Check schedule_force_open for today's date:
       ├─► Override exists: Skip schedule block.
       └─► No override: Output OCCUPIED (Red) with subject, section, and instructor.
4. Default:
   └─► Output AVAILABLE (Green) with capacity and location metadata.
```

### State Definitions

| State | Visual Badge | CSS Class | Booking Allowed? | Description |
|---|---|---|:---:|---|
| **AVAILABLE** | Green (`circle-check`) | `st-available` / `pill--ok` | Yes | Room is completely free. Can be claimed immediately. |
| **OCCUPIED** | Red (`clock`) | `st-occupied` / `pill--danger` | No | In use by a lecturer or an active weekly timetable class. |
| **UNAVAILABLE** | Gray (`ban`) | `st-unavailable` / `pill--off` | No | Under facility repair or temporarily taken out of rotation. |

---

## Database Schema Reference

The system uses 7 normalized InnoDB tables with foreign key cascade constraints:

### 1. `users`
Stores system accounts for lecturers and administrators.
```sql
CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name      VARCHAR(120) NOT NULL,
  staff_id       VARCHAR(40) NOT NULL,
  email          VARCHAR(120) NOT NULL,
  username       VARCHAR(40) NOT NULL,
  password       VARCHAR(255) NOT NULL,
  department     VARCHAR(80) DEFAULT NULL,
  role           ENUM('admin','lecturer') NOT NULL DEFAULT 'lecturer',
  account_status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_staff_id (staff_id),
  UNIQUE KEY uq_users_email (email)
);
```

### 2. `classrooms`
Physical room registry.
```sql
CREATE TABLE classrooms (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(20) NOT NULL,
  building    VARCHAR(80) NOT NULL,
  floor       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  capacity    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  room_type   VARCHAR(40) NOT NULL DEFAULT 'Other',
  qr_token    VARCHAR(32) NOT NULL,
  status      ENUM('available','maintenance','disabled') NOT NULL DEFAULT 'available',
  note        VARCHAR(160) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rooms_location (building, room_number),
  UNIQUE KEY uq_rooms_token (qr_token)
);
```

### 3. `classroom_sessions`
Live and historical room occupancy sessions.
```sql
CREATE TABLE classroom_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  start_time   DATETIME NOT NULL,
  end_time     DATETIME NOT NULL,
  status       ENUM('active','completed','released') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at  DATETIME DEFAULT NULL,
  KEY idx_sessions_room_active (classroom_id, status),
  KEY idx_sessions_end (end_time),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_session_room FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
```

### 4. `class_schedules`
Weekly recurring academic timetables.
```sql
CREATE TABLE class_schedules (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT UNSIGNED NOT NULL,
  day_of_week  TINYINT UNSIGNED NOT NULL, -- 0=Sunday, 1=Monday, ..., 6=Saturday
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,
  subject      VARCHAR(120) NOT NULL,
  section      VARCHAR(80) DEFAULT NULL,
  instructor   VARCHAR(120) DEFAULT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sched_room_day (classroom_id, day_of_week, is_active),
  CONSTRAINT fk_sched_room FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE
);
```

### 5. `schedule_force_open`
Single-day overrides for cancelled or early-dismissed scheduled slots.
```sql
CREATE TABLE schedule_force_open (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT UNSIGNED NOT NULL,
  schedule_id  INT UNSIGNED NOT NULL,
  exc_date     DATE NOT NULL,
  reason       ENUM('lecturer_absent','emergency','ended_early','other') NOT NULL,
  details      VARCHAR(160) DEFAULT NULL,
  user_id      INT UNSIGNED NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_force_slot (schedule_id, exc_date),
  CONSTRAINT fk_force_room FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_force_sched FOREIGN KEY (schedule_id) REFERENCES class_schedules (id) ON DELETE CASCADE,
  CONSTRAINT fk_force_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
```

### 6. `activity_logs`
Immutable audit trail of authentication, room check-in, release, and config updates.
```sql
CREATE TABLE activity_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED DEFAULT NULL,
  classroom_id INT UNSIGNED DEFAULT NULL,
  action       VARCHAR(40) NOT NULL,
  details      VARCHAR(255) DEFAULT NULL,
  timestamp    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_logs_time (timestamp),
  KEY idx_logs_action (action),
  KEY idx_logs_user (user_id)
);
```

### 7. `settings`
Key-value runtime configuration store.
```sql
CREATE TABLE settings (
  skey   VARCHAR(40) NOT NULL PRIMARY KEY,
  svalue VARCHAR(255) NOT NULL
);
```

---

## API Specification

All endpoints return JSON (`Content-Type: application/json; charset=utf-8`) unless `format=html` is specified. State-modifying requests require a valid CSRF token header (`X-CSRF-Token`) or parameter (`csrf`).

### 1. `GET /api/classroom_status.php`
Retrieve live availability for one or more classrooms.

**Query Parameters:**
| Parameter | Type | Required | Description |
|---|---|:---:|---|
| `id` | integer | No | Single classroom ID. If omitted, returns all filtered classrooms. |
| `q` | string | No | Search query (matches room number, building, or type). |
| `status` | string | No | Status filter: `available`, `occupied`, `unavailable`. |
| `building` | string | No | Filter by building name. |
| `floor` | integer | No | Filter by floor level. |
| `type` | string | No | Filter by room type (e.g. `Lecture Hall`, `Laboratory`). |
| `mincap` | integer | No | Minimum seat capacity filter. |
| `page` | integer | No | Pagination page index (default: `1`). |
| `format` | string | No | Set to `html` to retrieve rendered HTML card components for the public grid. |

**Success Response (200 OK):**
```json
{
  "ok": true,
  "stats": {
    "available": 18,
    "occupied": 8,
    "unavailable": 4
  },
  "total": 30,
  "page": 1,
  "rooms": [
    {
      "id": 101,
      "room_number": "Main-101",
      "building": "Main Academic Building",
      "floor": 1,
      "capacity": 45,
      "room_type": "Lecture Hall",
      "computed": "available",
      "session_id": null,
      "session_lecturer": null,
      "session_start": null,
      "session_end": null,
      "available_at": null
    }
  ]
}
```

---

### 2. `POST /api/scan_qr.php`
Validate a scanned QR code or manual token before presenting the check-in modal.

**Authentication:** Required (`lecturer` with `approved` status).

**Payload:**
```json
{
  "token": "A1B2C3D4",
  "csrf": "4f9a7d8e6c..."
}
```

**Success Response (200 OK):**
```json
{
  "ok": true,
  "room": {
    "id": 101,
    "room_number": "Main-101",
    "building": "Main Academic Building",
    "floor": 1,
    "capacity": 45,
    "room_type": "Lecture Hall",
    "token": "A1B2C3D4"
  },
  "duration_options": [30, 60, 90, 120],
  "default_duration": 60,
  "scan_day_end": "19:00"
}
```

**Error Responses:**
- `401 Unauthorized`: User not signed in.
- `403 Forbidden`: Account pending approval, outside campus scan hours, or invalid CSRF.
- `400 Bad Request`: Invalid token, room already occupied, or user already holds an active session.

---

### 3. `POST /api/force_open.php`
Submit an audited schedule cancellation override for a classroom blocked by a weekly timetable slot.

**Authentication:** Required (`lecturer` or `admin`).

**Payload:**
```json
{
  "token": "A1B2C3D4",
  "schedule_id": 42,
  "reason": "lecturer_absent",
  "details": "Instructor advised class cancellation via departmental notice.",
  "csrf": "4f9a7d8e6c..."
}
```

**Success Response (200 OK):**
```json
{
  "ok": true,
  "message": "Classroom schedule has been cleared for today. You may now check in."
}
```

---

### 4. `POST /api/release_room.php`
End an active occupancy session before its scheduled expiration.

**Authentication:** Required (Lecturer who owns the session, or any Administrator).

**Payload:**
```json
{
  "session_id": 85,
  "csrf": "4f9a7d8e6c..."
}
```

**Success Response (200 OK):**
```json
{
  "ok": true,
  "message": "Classroom Main-101 has been released and is now available."
}
```

---

### 5. `GET /qr/generate.php`
Stream a dynamic PNG QR code image for a classroom token or printable URL.

**Query Parameters:**
| Parameter | Type | Required | Description |
|---|---|:---:|---|
| `token` | string | Yes | 8-character classroom token or full claim URL. |
| `size` | integer | No | QR pixel module size (1 to 10, default: `4`). |
| `margin` | integer | No | Quiet zone border width (default: `2`). |

**Response:** `image/png` binary stream.

---

## User Roles & Security Hierarchy

| Privilege / Action | Public Visitor | Pending Lecturer | Approved Lecturer | Administrator |
|---|:---:|:---:|:---:|:---:|
| View Live Room Board | Yes | Yes | Yes | Yes |
| Search & Filter Rooms | Yes | Yes | Yes | Yes |
| Inspect Timetable Details | Yes | Yes | Yes | Yes |
| Access Mobile QR Scanner | No | No | Yes | Yes |
| Occupy Classrooms via Token | No | No | Yes | Yes |
| Force-Open Cancelled Slots | No | No | Yes | Yes |
| Release Own Sessions | No | No | Yes | Yes |
| Release Any User's Session | No | No | No | Yes |
| Classroom CRUD Inventory | No | No | No | Yes |
| Batch-Print Door Posters | No | No | No | Yes |
| Master Schedule Editor | No | No | No | Yes |
| User Approvals & Role Grants | No | No | No | Yes |
| View System Audit Logs | No | No | No | Yes |
| Edit System Operating Hours | No | No | No | Yes |

---

## Installation & Deployment Guide

### System Requirements
- **PHP:** 8.1.0 or newer
  - Required Extensions: `pdo_mysql`, `gd`, `mbstring`, `session`, `json`
- **Database:** MariaDB 10.4+ or MySQL 5.7+
- **Web Server:** Apache 2.4+ (with `mod_rewrite` and `.htaccess` support) or Nginx with PHP-FPM
- **Browser Compatibility:** Chrome 90+, Safari 14+, Firefox 88+, Edge 90+ (Requires HTTPS in production for camera access)

---

### Method A: Local Setup via Laragon (Recommended)

1. Place the project directory into Laragon's `www` root:
   ```text
   C:\laragon\www\classroom_finder
   ```
2. Start **All Services** in the Laragon Control Panel (Apache & MySQL).
3. Open MySQL client or phpMyAdmin and create database:
   ```sql
   CREATE DATABASE classroom_finder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. Import schema:
   - Import `database/classroom_finder.sql` via phpMyAdmin or MySQL CLI:
     ```bash
     mysql -u root -p classroom_finder < database/classroom_finder.sql
     ```
5. Check database credentials in `config/database.php`:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'classroom_finder');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
6. Open your browser and navigate to the first-run administrator wizard:
   ```text
   http://localhost/classroom_finder/setup.php
   ```
7. Enter initial administrator credentials (e.g., Full Name, Staff ID, Email, Username, Password). Upon completion, `setup.php` locks itself permanently.
8. Sign in at `http://localhost/classroom_finder/login.php`.

---

### Method B: Web / Shared Hosting Setup (cPanel / InfinityFree / VPS)

1. Upload the codebase to your web root (e.g. `public_html` or `htdocs`).
2. Create a MySQL database and user in your hosting control panel.
3. Import `database/classroom_finder.sql` using phpMyAdmin.
4. Update database credentials in `config/database.php`:
   ```php
   const DB_HOST = 'sqlxxx.yourhost.com'; // or '127.0.0.1' / 'localhost'
   const DB_PORT = '3306';
   const DB_NAME = 'your_db_name';
   const DB_USER = 'your_db_user';
   const DB_PASS = 'your_db_password';
   ```
5. Ensure `assets/uploads/` and `assets/img/` have write permissions (755 or 775).
6. Complete initial administrator creation via `setup.php` in your browser.
7. Enforce HTTPS in your domain settings to allow camera QR scanning on mobile devices.

---

## System Configuration Reference

Configurable in **Admin > Settings** or directly in the `settings` database table:

| Setting Key | Default | Type | Description |
|---|---|:---:|---|
| `app_name` | `Classroom Finder` | string | Application brand name displayed in top bar and window titles. |
| `school_name` | `Clarendon College` | string | Formal institution title displayed on hero banners and timetable sheets. |
| `school_address` | *(Empty)* | string | Street address printed under the institution header on printed schedules. |
| `school_contact` | *(Empty)* | string | Telephone number or registrar email printed on printed schedule sheets. |
| `min_duration_minutes`| `15` | integer | Minimum allowable room occupancy duration in minutes (range: 5–480). |
| `max_duration_minutes`| `480` | integer | Maximum single-session occupancy duration in minutes (range: 15–1440). |
| `duration_step_minutes`| `30` | integer | Step interval for the duration selector modal buttons. |
| `landing_refresh_seconds`| `15` | integer | Live availability board polling frequency in seconds. |
| `scan_day_start` | `07:00` | string | Daily campus scan opening time in 24-hour format (`HH:MM`). |
| `scan_day_end` | `19:00` | string | Daily campus scan closing time in 24-hour format (`HH:MM`). |

---

## Administrative Runbooks

### Runbook 1: Printing Door QR Posters
1. Navigate to **Admin > QR Codes** (`admin/qr_codes.php`).
2. Filter rooms by building or floor if printing by facility section.
3. Select rooms using row checkboxes, or click the master checkbox to select all rooms on the page.
4. Click **Print Selected Posters**.
5. The print dialog renders 6 standardized door cards per A4/Letter page containing:
   - Institution name and crest.
   - Large, high-contrast room number and location metadata.
   - High-resolution SVG-rendered QR code targeting the instant check-in URL.
   - Human-readable 8-character fallback code.
   - Quick instructions for camera check-in.
6. Print and affix next to each classroom entrance door at eye level.

---

### Runbook 2: Weekly Schedule Timetable Setup
1. Navigate to **Admin > Schedules** (`admin/schedules.php`).
2. Click **Add Schedule Slot**.
3. Select the classroom, day of the week (Monday through Sunday), start time, end time, subject title, section code, and instructor name.
4. Save slot. The system automatically validates against conflicting overlaps.
5. The public room finder and scanner will now mark the room as `Occupied` during those recurring intervals unless a force-open override is invoked.

---

### Runbook 3: Approving Lecturer Accounts
1. Direct new instructors to click **Sign in > Need an account? Contact an administrator** or visit `register.php` (if self-registration enabled).
2. Navigate to **Admin > Users** (`admin/users.php`).
3. Click the **Pending Approval** filter tab.
4. Review the lecturer's Full Name, Department, and official Staff ID.
5. Click **Approve**. The lecturer can now log in and operate the camera check-in scanner.

---

## Background Workers & Automation

### Automated Session Expiration Worker
The system automatically performs lazy session cleanup whenever room queries execute. Optionally, you can trigger `cron/expire.php` periodically:

- **Web Cron / Scheduled Ping (cPanel / InfinityFree / cron-job.org):**
  Schedule an HTTP GET request to `https://your-domain.com/cron/expire.php` every 1–5 minutes.
- **Local Task Scheduler (Windows / Laragon):**
  Run `php cron/expire.php` on a 1-minute interval.
- **CLI Scheduler:**
  ```text
  * * * * * php /path/to/htdocs/cron/expire.php > /dev/null 2>&1
  ```

The worker automatically transitions expired active sessions to `completed` and logs each completion event to `activity_logs`.

---

## Security Architecture

1. **Pessimistic Concurrency Control:**
   Room check-ins use `SELECT id, status FROM classrooms WHERE id = ? FOR UPDATE` inside an isolated database transaction. If two lecturers attempt to check into the same room simultaneously, the second transaction is queued and safely rejected with an informative error rather than causing double-booking.
2. **SQL Injection Defense:**
   All queries utilize PDO prepared statements with parameterized input bindings. No direct string interpolation is performed on SQL statements.
3. **Cross-Site Request Forgery (CSRF):**
   State-altering actions generate and validate cryptographically secure 256-bit random tokens via `csrf_token()` and `check_csrf()`.
4. **Session Security & Hardening:**
   - `HttpOnly`: Session cookies are inaccessible to JavaScript.
   - `SameSite=Lax`: Defends against cross-site timing and CSRF attacks.
   - `Strict-Transport-Security`: Enforces HTTPS in production.
   - `Content-Security-Policy`: Restricts inline injection vectors while whitelisting self-hosted fonts and assets.
5. **Directory Protection (.htaccess):**
   Direct URL requests to `config/`, `database/`, `.sql`, `.log`, and `.lock` files are rejected with HTTP 403 Forbidden.
6. **Rate-Limiting & Scan Hours:**
   Check-in attempts outside calibrated operating hours (`scan_day_start` to `scan_day_end`) are blocked at the controller layer.

---

## Progressive Web App (PWA) & Offline Support

Clarendon College Classroom Finder operates as an installable Progressive Web App:

- **Manifest (`manifest.json`):** Defines standalone viewport display, `#0F3B6E` theme bar styling, and high-DPI Clarendon College launcher icons.
- **Service Worker (`sw.js`):** Intercepts network requests to cache offline styles, self-hosted web fonts (`Barlow Condensed`, `IBM Plex Sans`, `IBM Plex Mono`), icons, and audio feedback cues.
- **Mobile Camera Support:** Uses `html5-qrcode` with automatic camera selection, torch support where available, and low-latency audio feedback (`assets/sound/success.mp3` on claim, `error.mp3` on failure).

---

## Troubleshooting & FAQ

### 1. Camera does not start in QR Scanner
- **Cause:** Mobile browsers require a secure origin to access camera hardware.
- **Solution:** Access the site over `https://` (or `http://localhost` during local development). If testing from a phone on a local Wi-Fi IP (e.g. `192.168.1.x`), generate a self-signed certificate in Laragon or test using a tunneling service like ngrok.

### 2. "First-time setup: no administrator exists yet" banner persists
- **Cause:** No accounts exist in the `users` table with `role = 'admin'`.
- **Solution:** Visit `http://localhost/classroom_finder/setup.php` in your browser and complete the initial account creation form.

### 3. Background image or logo does not render
- **Cause:** File permissions or missing image files in `assets/img/`.
- **Solution:** `config/helpers.php` contains a self-initializing bootstrap that automatically copies `assets/img/campus-bg.webp` and `assets/img/logo.png` upon any HTTP request. Ensure the web server user has write permissions to `assets/img/`.

### 4. Scheduled classes show as available
- **Cause:** Server timezone mismatch or active force-open override.
- **Solution:** Verify `date_default_timezone_set('Asia/Manila');` in `config/helpers.php`. Check **Admin > Schedules** to see if an instructor submitted a force-open exception for today's date.

---

## License & Attribution

Copyright © 2026 **Clarendon College**. All rights reserved.  
Built for campus facilities and academic scheduling management.
