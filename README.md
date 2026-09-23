# Classroom Finder

Real-time campus classroom availability board and door QR check-in system for schools and universities.

**Live Deployment:** [https://classrooom-finder.page.gd](https://classrooom-finder.page.gd)

---

## Overview

Classroom Finder eliminates manual room checks across campus buildings.

- **Students & Public:** View real-time room availability across buildings and floors without logging in.
- **Lecturers:** Walk up to any classroom, scan the door poster with a smartphone camera (or enter the 8-character token), and check in for their lecture.
- **Administrators:** Manage classrooms, print door QR posters in batches, register lecturer accounts, schedule weekly classes, and monitor live room occupancy.

---

## Room State Engine

Classroom Finder computes real-time room availability through a three-tier hierarchy:

| Status | Visual Indicator | Meaning | Conditions |
|---|---|---|---|
| **Available** | Green dot | Ready for use | No active session, no active schedule slot, status is `available`. |
| **Occupied** | Red dot | In use | Active lecturer session running, OR active weekly timetable slot in progress without an approved force-open override. |
| **Unavailable** | Gray dot | Out of service | Classroom set to `maintenance` or `disabled` by an administrator. |

---

## Operational Workflows

### 1. Room Check-In Flow
1. **Locate Classroom:** The lecturer views the public board or approaches a physical classroom.
2. **Scan or Type Token:** The lecturer logs in on mobile, opens the scanner, and points the camera at the door poster. Alternatively, they can manually type the 8-character token printed on the poster.
3. **Select Duration:** The lecturer picks a session duration (such as 30, 60, or 90 minutes) constrained by system limits and daily campus scanning hours (e.g. 07:00 to 19:00).
4. **Instant Claim:** The room turns red (`Occupied`) on all campus boards immediately. Database row locks (`SELECT ... FOR UPDATE`) prevent race conditions if two lecturers scan simultaneously.

### 2. Room Release & Expiration
- **Manual Release:** The lecturer taps **End Session** on their dashboard when class concludes early.
- **Automated Expiry:** When the chosen duration elapses, the session transitions to `completed`. Expired sessions are cleared by the background CLI worker (`cron/expire.php`) and lazy cleanup triggers during status queries.

### 3. Schedule Force-Open Override
When a weekly scheduled class does not meet (due to instructor absence, class suspension, or early dismissal), approved lecturers can trigger a **Force-Open** from the scanner.
- The lecturer selects a verified reason: Lecturer absent, Emergency / class suspended, Class ended early, or Other.
- The system logs an entry in `schedule_force_open` for today's date only, clearing the block and allowing immediate occupancy.
- Administrators can review, audit, or revert force-open events from the Admin Schedules console.

---

## User Roles & Permissions

| Role | Target Audience | Access Scope | Authentication |
|---|---|---|:---:|
| **Public** | Students, visitors, general staff | View live room grid, search by building/floor/capacity, filter by status, view timetable details. | None required |
| **Lecturer** | Faculty members, instructors | Camera QR scanner, manual token check-in, active session controls, force-open overrides, personal usage history. | Required (Approved account) |
| **Administrator** | Campus registrar, facility managers | Classroom CRUD, batch printable QR posters, weekly timetable manager, user account approval/reset, audit logs, system configuration. | Required (Admin account) |

---

## System Configuration Reference

Configurable from **Admin > Settings** or stored in the `settings` table:

| Setting Key | Default | Description |
|---|---|---|
| `app_name` | `Classroom Finder` | Application title shown on top navigation and page titles. |
| `school_name` | *(Empty)* | Institution name printed on QR posters and landing page header. |
| `school_address` | *(Empty)* | Campus street address printed under institution name on posters. |
| `school_contact` | *(Empty)* | Contact number or official email printed on poster footers. |
| `school_logo` | *(Empty)* | Uploaded institution seal or logo for branding. |
| `min_duration_minutes` | `15` | Minimum occupancy duration selectable in scanner (5 to 480 mins). |
| `max_duration_minutes` | `480` | Maximum occupancy duration selectable in scanner (15 to 1440 mins). |
| `duration_step_minutes` | `30` | Increment step for stepper buttons in the duration modal. |
| `landing_refresh_seconds` | `15` | Polling interval for public availability board updates. |
| `scan_day_start` | `07:00` | Earliest time of day room check-ins are permitted (HH:MM). |
| `scan_day_end` | `19:00` | Latest time of day room check-ins are permitted (HH:MM). |

---

## Project Structure

```text
classroom_finder/
├── admin/                     # Administrative portal
│   ├── classrooms.php         # Classroom inventory, capacity, floor, and types
│   ├── dashboard.php          # Real-time room metrics and system statistics
│   ├── history.php            # Historical occupancy logs and CSV export
│   ├── logs.php               # System activity and security audit trail
│   ├── qr_codes.php           # QR table view, batch poster printing, token regen
│   ├── schedules.php          # Weekly class timetable manager and override log
│   ├── sessions.php           # Active room sessions and force-end actions
│   ├── settings.php           # Campus settings, scan hours, duration limits
│   └── users.php              # Lecturer account verification and password resets
├── api/                       # Lightweight JSON endpoints
│   ├── classroom_status.php   # Real-time room status polling endpoint
│   ├── force_open.php         # Schedule override handler
│   ├── release_room.php       # Active session termination endpoint
│   └── scan_qr.php            # Token verification and room eligibility checker
├── assets/                    # Static frontend resources
│   ├── css/style.css          # Design system stylesheet
│   ├── js/                    # UI logic, QR camera engine, and modal handlers
│   ├── sound/                 # Audio feedback cues for scan success and errors
│   └── uploads/               # Stored institution logo
├── auth/                      # Authentication engine
│   ├── auth_check.php         # Role guards (require_admin, require_approved_lecturer)
│   └── login_process.php      # Password validation and session initialization
├── config/                    # Core configuration and helpers
│   ├── database.php           # PDO database connection singleton
│   ├── helpers.php            # CSRF, flash notifications, sanitization helpers
│   ├── icons.php              # Inline SVG icon definitions
│   └── layout.php             # Unified page headers, navigation drawer, and footers
├── cron/                      # Maintenance automation
│   └── expire.php             # CLI session expiration worker
├── database/                  # Schema definition and seeding
│   ├── classroom_finder.sql   # MariaDB/MySQL database schema and defaults
│   └── seed_classrooms.php    # Sample classrooms dataset
├── lecturer/                  # Lecturer self-service portal
│   ├── dashboard.php          # Personal active sessions and quick actions
│   ├── history.php            # Personal occupancy history
│   ├── occupy.php             # Session creation with row-level locking
│   ├── release.php            # Manual session release handler
│   └── scanner.php            # Camera QR scanner and manual token entry
├── qr/                        # Dynamic QR poster generator
│   ├── generate.php           # QR image generator endpoint
│   └── lib/                   # Bundled phpqrcode generation library
├── services/                  # Business logic and query services
│   ├── room_service.php       # Availability computation and classroom CRUD
│   ├── schedule_service.php   # Weekly recurring timetables and overrides
│   ├── session_service.php    # Room session management and expiration
│   └── user_service.php       # Account authentication and status management
├── 403.php                    # Forbidden error page
├── 404.php                    # Not found error page
├── 500.php                    # Server error page
├── change_password.php        # Authenticated password update
├── index.php                  # Public availability board
├── login.php                  # Account authentication page
├── logout.php                 # Secure session destruction
├── manifest.json              # Progressive Web App manifest
├── setup.php                  # First-run locked administrator setup
└── sw.js                      # Service worker for offline caching
```

---

## Local Installation Guide

### Prerequisites
- PHP 8.1 or higher with `pdo_mysql` and `gd` extensions enabled
- MySQL 5.7+ or MariaDB 10.4+
- Apache Web Server (such as standard XAMPP)

### Installation Steps

1. **Clone or Copy Repository:**
   Place the project directory inside your local web server root:
   ```text
   C:\xampp\htdocs\classroom_finder
   ```

2. **Start Services:**
   Launch **Apache** and **MySQL** from your XAMPP Control Panel.

3. **Import Database:**
   - Open phpMyAdmin at `http://localhost/phpmyadmin/`.
   - Create a new database named `classroom_finder`.
   - Select **Import**, choose `database/classroom_finder.sql`, and run the import.
   - *(Command-line alternative: `mysql -u root -p classroom_finder < database/classroom_finder.sql`)*

4. **Verify Database Configuration:**
   Check `config/database.php` and update credentials if using a non-default database user or password:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'classroom_finder');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

5. **Seed Classrooms (Optional):**
   Populate initial sample rooms and 8-character tokens:
   ```bash
   php database/seed_classrooms.php
   ```

6. **Initialize First Administrator Account:**
   Navigate to the first-run setup wizard in your browser:
   ```text
   http://localhost/classroom_finder/setup.php
   ```
   Fill in your administrator credentials. Upon successful submission, `setup.php` creates an `installed.lock` file and permanently disables itself.

7. **Sign In:**
   Access the system via `http://localhost/classroom_finder/login.php`.

8. **Automate Expiration Worker:**
   Schedule `cron/expire.php` to run once every minute via Windows Task Scheduler or Linux crontab:
   ```bash
   * * * * * php /var/www/html/classroom_finder/cron/expire.php > /dev/null 2>&1
   ```

---

## Administration & Daily Operations

### Printing Door QR Posters
1. Navigate to **Admin > QR Codes**.
2. Select individual rooms via checkboxes or check the header to select all rooms on page.
3. Click **Print Selected** to open the responsive 6-per-sheet print layout.
4. Alternatively, click the QR icon in any table row to preview and print an individual door poster.
5. Print and mount the posters adjacent to classroom entry doors.

### Managing Daily Scanning Hours
To prevent lecturers from occupying rooms outside building operating hours:
1. Navigate to **Admin > Settings**.
2. Set **Campus Operating Start Time** (e.g. `07:00`) and **Campus Operating End Time** (e.g. `19:00`).
3. Click **Save Settings**. Scans attempted outside these hours will be rejected with an informative notice.

---

## Security Architecture

- **Strict Type Enforcement:** `declare(strict_types=1);` declared across all PHP modules.
- **SQL Injection Prevention:** 100% prepared PDO statements with bound parameter arrays.
- **CSRF Token Validation:** Every state-altering HTTP request validates an anti-CSRF token (`check_csrf()`).
- **Race Condition Prevention:** Room occupancy transactions use row-level pessimistic locking (`SELECT ... FOR UPDATE`).
- **First-Run Lockout:** `setup.php` requires a clean database and self-terminates with `installed.lock`.
- **Directory Hardening:** Subdirectory `.htaccess` files block direct URL access to `config/`, `database/`, `.sql`, `.log`, and `.lock` files.
- **Secure Password Hashing:** User passwords securely hashed with `PASSWORD_DEFAULT` (bcrypt).
- **CLI-Only Cron Execution:** `cron/expire.php` verifies `PHP_SAPI === 'cli'` to prevent unauthorized web execution.
