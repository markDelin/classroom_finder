<div align="center">

# 🏫 Clarendon College — Classroom Finder

<p align="center">
  <strong>Next-generation campus classroom availability board, door QR code check-in engine, and academic timetable intelligence system.</strong>
</p>

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.1-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/database-MariaDB%20%7C%20MySQL%205.7%2B-003545?style=for-the-badge&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![PWA Ready](https://img.shields.io/badge/PWA-Installable-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)](manifest.json)
[![Security Hardened](https://img.shields.io/badge/Security-CSRF%20%2B%20CSP%20%2B%20Pessimistic%20Lock-10B981?style=for-the-badge&logo=shield&logoColor=white)](README.md#security-architecture)
[![License](https://img.shields.io/badge/license-Clarendon%20College-0F3B6E?style=for-the-badge)](LICENSE)

[🌐 Live Deployment](https://classrooom-finder.page.gd) • [📋 API Documentation](#api-specification) • [🚀 Quick Start](#installation--quick-start) • [📊 Analytics](#analytics--utilization-reports) • [🧪 Tests](#testing--qa)

</div>

---

> [!NOTE]
> **Clarendon College Classroom Finder** is an all-in-one institutional platform that eliminates physical room scouting across multi-floor academic facilities. It bridges real-time physical classroom state with digital scheduling, QR door check-ins, automated session expiry, and executive utilization analytics.

---

## 📑 Table of Contents

- [Key Capabilities](#-key-capabilities)
- [System Architecture](#-system-architecture)
- [Tech Stack & Dependencies](#-tech-stack--dependencies)
- [Directory Layout](#-directory-layout)
- [Availability State Engine](#-availability-state-engine)
- [Installation & Quick Start](#-installation--quick-start)
  - [Prerequisites](#prerequisites)
  - [Method A: Local Setup via Laragon / XAMPP (Recommended)](#method-a-local-setup-via-laragon--xampp-recommended)
  - [Method B: Linux / VPS / Production Web Server](#method-b-linux--vps--production-web-server)
- [Database Schema Reference](#-database-schema-reference)
- [API Specification](#-api-specification)
  - [`GET /api/classroom_status.php`](#1-get-apiclassroom_statusphp)
  - [`POST /api/scan_qr.php`](#2-post-apiscan_qrphp)
  - [`POST /api/force_open.php`](#3-post-apiforce_openphp)
  - [`POST /api/release_room.php`](#4-post-apirelease_roomphp)
  - [`GET /qr/generate.php`](#5-get-qrgeneratephp)
- [Schedule Import & Document Integration](#-schedule-import--document-integration)
- [Analytics & Utilization Reports](#-analytics--utilization-reports)
- [Role-Based Access Control (RBAC)](#-role-based-access-control-rbac)
- [Security & Concurrency Architecture](#-security--concurrency-architecture)
- [Automation & Background Workers](#-automation--background-workers)
- [Testing & QA](#-testing--qa)
- [Troubleshooting & FAQ](#-troubleshooting--faq)
- [License & Attribution](#-license--attribution)

---

## ⚡ Key Capabilities

### 🔍 For Students & Public Campus Visitors
- **Zero-Authentication Real-Time Grid:** View live availability status across every campus building and floor instantly without requiring login credentials.
- **Infinite Scrolling:** Smooth, zero-lag viewport browsing via `IntersectionObserver` with automatic fallback triggers.
- **Live Countdown Timers:** Precision millisecond-calculated countdowns showing exact remaining time (`Free in 15 min`, `Starts in 45 min`).
- **PWA Ready & Offline Resilient:** Installable to mobile home screens with cached asset bundles, custom splash screens, and offline fallback screens.

### 📱 For Faculty & Lecturers
- **Camera QR Door Scanning:** Point any smartphone camera at an entrance poster or enter an 8-character token to claim an available room.
- **Pessimistic Lock Concurrency:** Eliminates double-booking conflicts at the database transaction layer via row-level locking.
- **Force-Open Overrides:** When a scheduled class is cancelled or dismissed early, authorized lecturers can trigger an audited override to unlock the room immediately.
- **One-Tap Release:** Instant room liberation upon class conclusion with automatic audit trail logging.

### 🛠️ For Administrators & Facilities Officers
- **Inventory & Capacity Management:** Full CRUD management of classrooms, capacities, equipment types, buildings, and operational statuses (`available`, `maintenance`, `disabled`).
- **Dual-Mode Master Timetable Engine:**
  - **In-App Slot Manager:** Build and edit recurring weekly class blocks.
  - **External Ready-Printed Uploads:** Attach and display scanned or PDF/image schedule sheets directly from external registrar systems.
  - **Batch CSV Import:** Ingest complete school-wide semester schedules from CSV files with duplicate resolution.
- **Executive Utilization Reports:** Interactive donut charts, room utilization rankings, weekly hours metrics, and hairline-bordered exportable print formats.
- **Batch Door Poster Generator:** Render 6-per-page A4 door posters complete with high-resolution vector QR codes, typography, and institution seals.

---

## 🏛 System Architecture

```text
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           Client Devices & Browsers                             │
│       [ Public Visitor ]          [ Mobile Lecturer ]         [ Administrator ] │
└───────────────┬────────────────────────────┬─────────────────────────────┬──────┘
                │                            │                             │
                ▼                            ▼                             ▼
    ┌────────────────────────┐  ┌─────────────────────────┐  ┌────────────────────────┐
    │  Public Availability   │  │   Camera QR Scanner     │  │   Admin Portal Engine  │
    │  - Grid / Filter UI    │  │   - Html5Qrcode Reader  │  │   - Inventory / Users  │
    │  - Infinite Scroll     │  │   - Audio Feedback      │  │   - Master Timetables  │
    │  - Countdown Workers   │  │   - Token Submission    │  │   - Utilization Report │
    └───────────┬────────────┘  └────────────┬────────────┘  └────────────┬───────────┘
                │                            │                            │
                ▼                            ▼                            ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                                HTTP / REST Layer                                │
│   api/classroom_status.php   api/scan_qr.php   api/force_open.php   lecturer/*  │
└──────────────────────────────────────┬──────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                        Decoupled Domain Service Layer                           │
│  ┌───────────────────────┐ ┌───────────────────────┐ ┌───────────────────────┐  │
│  │     Room Service      │ │    Schedule Service   │ │    Session Service    │  │
│  │ - Availability Matrix │ │ - CSV Importer        │ │ - FOR UPDATE Locks    │  │
│  │ - Status Resolvers    │ │ - PDF / Image Uploads │ │ - Auto-Release Logic  │  │
│  └───────────────────────┘ └───────────────────────┘ └───────────────────────┘  │
└──────────────────────────────────────┬──────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                   Data Persistence & Background Automation                      │
│      ┌─────────────────────────┐              ┌───────────────────────────┐     │
│      │   MariaDB / MySQL 5.7+  │              │   cron/expire.php Worker  │     │
│      │   - 7 Normalized Tables │◄─────────────┤   - Scheduled Sweeper     │     │
│      │   - Cascade Constraints │              │   - CLI & Web Cron Ping   │     │
│      └─────────────────────────┘              └───────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────────────────┘
```

---

## 🧰 Tech Stack & Dependencies

| Layer | Technologies & Libraries |
|---|---|
| **Backend Runtime** | PHP 8.1+ (compatible up to PHP 8.4) • Strict Typing • Procedural Services |
| **Database** | MariaDB 10.4+ / MySQL 5.7+ (InnoDB, UTF8mb4, Parameterized PDO) |
| **Frontend Core** | Vanilla JavaScript (ES6+), CSS3 Variables, Semantic HTML5 |
| **Typography** | Self-hosted `@font-face` (Barlow Condensed, IBM Plex Sans, IBM Plex Mono) |
| **QR Engine** | Bundled `phpqrcode` (SVG / PNG stream generation) • `html5-qrcode` (camera capture) |
| **Notifications & Dialogs** | `sweetalert2` (modal confirmations) • `toastify-js` (asynchronous feedback) |
| **Audio Synthesis** | HTML5 Audio API (`success.mp3`, `error.mp3`) |
| **Offline & PWA** | Web App Manifest (`manifest.json`) • Service Worker (`sw.js`) |

---

## 📂 Directory Layout

```text
classroom_finder/
├── admin/                         # Administrative control center
│   ├── classrooms.php             # Room inventory, capacities, floor & building mappings
│   ├── dashboard.php              # Real-time KPI cards, active occupancy roster, quick actions
│   ├── history.php                # Complete historical occupancy audit log with CSV export
│   ├── logs.php                   # System-wide operational and security activity log
│   ├── qr_codes.php               # Batch door poster generator (6-up A4 printable cards)
│   ├── reports.php                # Classroom utilization & frequency analytics with charts
│   ├── schedules.php              # Weekly timetable editor, sheet uploader & CSV importer
│   ├── sessions.php               # Live session monitor with administrative revoke controls
│   ├── settings.php               # Institutional parameters, operating hours, durations
│   └── users.php                  # User account approvals, role elevations & access status
├── api/                           # Lightweight REST JSON endpoints
│   ├── classroom_status.php       # Live status calculation and dynamic HTML card pagination
│   ├── force_open.php             # Audited class schedule cancellation override
│   ├── release_room.php           # Active room claim termination
│   └── scan_qr.php                # Door QR token validation & scan authorization
├── assets/                        # Client-side static resources
│   ├── css/                       # Design system, responsive layouts & print stylesheets
│   ├── fonts/                     # Bundled WOFF2 self-hosted institutional typefaces
│   ├── img/                       # Brand seals, campus photography, and PWA icons
│   ├── js/                        # Modular frontend scripts (landing, scanner, modals)
│   └── sound/                     # Auditory feedback files (success/error chimes)
├── auth/                          # Access control & session verification middleware
│   ├── auth_check.php             # Role guards: require_login, require_admin, require_approved
│   └── login_process.php          # Credential authentication, rate-limiting & audit writes
├── config/                        # Global bootstrapping & configuration
│   ├── database.php               # Singleton PDO connection wrapper with UTF-8 enforcement
│   ├── helpers.php                # Security sanitizers, CSRF, flash toasts & asset resolvers
│   ├── icons.php                  # Inline SVG icon library
│   └── layout.php                 # Reusable UI component skeletons (header, nav, cards)
├── cron/                          # Automation scripts
│   └── expire.php                 # Background occupancy session expiration worker
├── database/                      # Migration and schema artifacts
│   ├── classroom_finder.sql       # Canonical database definition with initial seeds
│   └── seed_classrooms.php        # 30-room campus fixture generator
├── lecturer/                      # Mobile-first faculty portal
│   ├── dashboard.php              # Active occupancy status & fast-action release controls
│   ├── history.php                # Personal teaching room usage ledger
│   ├── occupy.php                 # Pessimistic lock room claiming transaction handler
│   ├── release.php                # Session release processor
│   └── scanner.php                # Full-screen camera QR scanner & manual code keypad
├── qr/                            # Server-side QR rendering service
│   ├── generate.php               # PNG/SVG image stream responder
│   └── lib/                       # Bundled phpqrcode rasterizer
├── services/                      # Decoupled business logic domain layer
│   ├── room_service.php           # Classroom CRUD, token generation & state calculation
│   ├── schedule_service.php       # Slot recurrence, CSV import parser & sheet file manager
│   ├── session_service.php        # Concurrency-safe occupancy transactions & auto-expiry
│   └── user_service.php           # Account lifecycle, password hashing & verification
├── tests/                         # Automated test suite
│   └── test_schedule_import.php   # Unit & integration checks for schedule uploader & CSV parser
├── 403.php, 404.php, 500.php      # Branded HTTP error pages
├── index.php                      # Public live availability board (landing page)
├── login.php, logout.php          # Authentication views
├── manifest.json, sw.js           # Progressive Web App assets
└── setup.php                      # Self-locking first-run administrator wizard
```

---

## 🚦 Availability State Engine

The operational status of every classroom is computed on-demand through an immutable evaluation cascade:

```text
                       [ Incoming Room Query ]
                                  │
                                  ▼
             Is classroom.status in ('maintenance', 'disabled')?
                                 / \
                         YES    /   \    NO
                               ▼     ▼
             ┌─────────────────┐     Is there an active session in classroom_sessions
             │   UNAVAILABLE   │     where status = 'active' AND end_time > NOW()?
             │     (Gray)      │                    / \
             └─────────────────┘            YES    /   \    NO
                                                   ▼     ▼
                                 ┌─────────────────┐     Is there a weekly class_schedule active
                                 │    OCCUPIED     │     for current day-of-week & current time?
                                 │     (Red)       │                    / \
                                 └─────────────────┘            YES    /   \    NO
                                                                       ▼     ▼
                                              Does an override exist in      ┌─────────────────┐
                                              schedule_force_open for today? │    AVAILABLE    │
                                                              / \            │     (Green)     │
                                                      YES    /   \    NO     └─────────────────┘
                                                            ▼     ▼
                                          ┌─────────────────┐     ┌─────────────────┐
                                          │    AVAILABLE    │     │    OCCUPIED     │
                                          │     (Green)     │     │     (Red)       │
                                          └─────────────────┘     └─────────────────┘
```

### State Indicators

| Status | Badge | CSS Token | Claimable? | Context |
|---|:---:|:---:|:---:|---|
| **AVAILABLE** | 🟢 Green | `pill--ok` / `st-available` | **Yes** | Room is physically unlocked and free to claim. |
| **OCCUPIED** | 🔴 Red | `pill--danger` / `st-occupied` | **No** | Active lecturer session or ongoing academic class. |
| **UNAVAILABLE** | ⚫ Gray | `pill--off` / `st-unavailable` | **No** | Facility under maintenance, offline, or decommissioned. |

---

## 🚀 Installation & Quick Start

### Prerequisites
- **PHP 8.1+** with extensions: `pdo_mysql`, `gd`, `mbstring`, `session`, `json`
- **MariaDB 10.4+** or **MySQL 5.7+**
- **Web Server:** Apache (with `mod_rewrite`) or Nginx with PHP-FPM

---

### Method A: Local Setup via Laragon / XAMPP (Recommended)

1. **Clone or Copy Repository:**
   Place the project into your local web root:
   ```bash
   cd C:\laragon\www
   git clone https://github.com/your-username/classroom_finder.git
   ```

2. **Initialize Database:**
   Start MySQL in Laragon/XAMPP, open your preferred client (phpMyAdmin, HeidiSQL, CLI), and run:
   ```sql
   CREATE DATABASE classroom_finder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Import the schema:
   ```bash
   mysql -u root -p classroom_finder < database/classroom_finder.sql
   ```

3. **Verify Database Configuration:**
   Inspect `config/database.php` and verify connection parameters:
   ```php
   const DB_HOST = '127.0.0.1';
   const DB_PORT = '3306';
   const DB_NAME = 'classroom_finder';
   const DB_USER = 'root';
   const DB_PASS = '';
   ```

4. **Launch Administrator Wizard:**
   Navigate to the first-run installation lock in your browser:
   ```text
   http://localhost/classroom_finder/setup.php
   ```
   Fill in your primary administrator profile (Full Name, Staff ID, Username, Password). Upon submission, `setup.php` permanently seals itself against tampering.

5. **Run Sanity Tests:**
   Verify schedule services and database connectivity:
   ```bash
   php tests/test_schedule_import.php
   ```

---

### Method B: Linux / VPS / Production Web Server

1. **Clone Repository & Set Permissions:**
   ```bash
   cd /var/www/html
   git clone https://github.com/your-username/classroom_finder.git
   cd classroom_finder
   chown -R www-data:www-data assets/uploads/ assets/img/
   chmod -R 775 assets/uploads/ assets/img/
   ```

2. **Configure Nginx (Optional if using Nginx + PHP-FPM):**
   ```nginx
   server {
       listen 80;
       server_name classrooms.yourcollege.edu;
       root /var/www/html/classroom_finder;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$args;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
       }

       location ~* \.(sql|lock|log)$ {
           deny all;
       }
   }
   ```

3. **Enforce SSL/TLS:**
   Camera QR scanning on mobile devices **requires a secure HTTPS origin**. Obtain a free certificate via Let's Encrypt:
   ```bash
   certbot --nginx -d classrooms.yourcollege.edu
   ```

---

## 🗄️ Database Schema Reference

The system relies on 7 tightly normalized tables engineered with strict foreign key constraints:

```text
┌──────────────┐         ┌──────────────────────┐         ┌────────────────────┐
│    users     │1       *│  classroom_sessions  │*       1│     classrooms     │
│──────────────┼─────────┼──────────────────────┼─────────┼────────────────────│
│ id (PK)      │         │ id (PK)              │         │ id (PK)            │
│ staff_id     │         │ classroom_id (FK)    │         │ room_number        │
│ username     │         │ user_id (FK)         │         │ building           │
│ role         │         │ start_time           │         │ floor              │
│ status       │         │ end_time             │         │ capacity           │
└──────────────┘         │ status               │         │ qr_token (UQ)      │
                         └──────────────────────┘         │ schedule_file      │
                                                          │ status             │
                                                          └──────────┬─────────┘
                                                                     │1
                                                                     │
                                                                     │*
┌──────────────────────┐         ┌──────────────────────┐            │
│ schedule_force_open  │*       1│   class_schedules    │*           │
│──────────────────────┼─────────┼──────────────────────┼────────────┘
│ id (PK)              │         │ id (PK)              │
│ classroom_id (FK)    │         │ classroom_id (FK)    │
│ schedule_id (FK)     │         │ day_of_week (0-6)    │
│ exc_date             │         │ start_time / end_time│
│ reason               │         │ subject / section    │
└──────────────────────┘         └──────────────────────┘
```

> [!TIP]
> Inspect the canonical SQL declarations directly in [database/classroom_finder.sql](database/classroom_finder.sql).

---

## 🔌 API Specification

All endpoints return JSON (`Content-Type: application/json; charset=utf-8`) unless explicitly requesting HTML payloads. Modifying requests mandate valid CSRF protection.

### 1. `GET /api/classroom_status.php`
Retrieves live calculated availability for rooms, including active occupant names, countdowns, and stats.

**Parameters:**
| Parameter | Type | In | Description |
|---|---|---|---|
| `q` | `string` | Query | Fulltext search query against room number, building, or type. |
| `status` | `string` | Query | Status filter: `available`, `occupied`, `unavailable`. |
| `building` | `string` | Query | Building name filter. |
| `floor` | `integer`| Query | Floor level integer. |
| `page` | `integer`| Query | Pagination index (default: `1`). |
| `with_html` | `integer`| Query | Set to `1` to receive pre-rendered HTML cards for infinite scrolling. |

**Example 200 OK Response:**
```json
{
  "ok": true,
  "count": 28,
  "page": 1,
  "stats": {
    "available": 18,
    "occupied": 8,
    "unavailable": 2
  },
  "server_now": "2026-10-15 14:30:00",
  "rooms": [
    {
      "id": 101,
      "room_number": "Main-101",
      "building": "Main Academic",
      "floor": 1,
      "capacity": 40,
      "computed": "occupied",
      "status_label": "Occupied",
      "session_lecturer": "Prof. Smith",
      "free_at": "2026-10-15 15:30:00"
    }
  ]
}
```

---

### 2. `POST /api/scan_qr.php`
Validates a door token before unlocking the occupancy modal.

**Authentication:** Required (`role = 'lecturer'`, `account_status = 'approved'`).

**Payload:**
```json
{
  "token": "A1B2C3D4",
  "csrf": "3f982d1..."
}
```

**Example 200 OK Response:**
```json
{
  "ok": true,
  "room": {
    "id": 101,
    "room_number": "Main-101",
    "building": "Main Academic",
    "capacity": 40,
    "token": "A1B2C3D4"
  },
  "duration_options": [30, 60, 90, 120],
  "default_duration": 60,
  "scan_day_end": "19:00"
}
```

---

### 3. `POST /api/force_open.php`
Submit an audited schedule cancellation override for a classroom blocked by a master timetable slot.

**Authentication:** Required (`lecturer` or `admin`).

**Payload:**
```json
{
  "token": "A1B2C3D4",
  "schedule_id": 42,
  "reason": "ended_early",
  "details": "Class completed lab exercises 45 minutes ahead of schedule.",
  "csrf": "3f982d1..."
}
```

---

### 4. `POST /api/release_room.php`
Terminates an ongoing occupancy session ahead of schedule.

**Authentication:** Required (session owner or administrator).

**Payload:**
```json
{
  "session_id": 85,
  "csrf": "3f982d1..."
}
```

---

### 5. `GET /qr/generate.php`
Dynamic server-side QR code rasterizer.

**Query Parameters:**
| Parameter | Type | Required | Description |
|---|---|:---:|---|
| `token` | `string` | **Yes** | 8-character classroom token or full claim URL. |
| `size` | `integer`| No | Module dimension (1–10, default: `4`). |
| `margin` | `integer`| No | Quiet zone border width (default: `2`). |

---

## 📅 Schedule Import & Document Integration

Clarendon College Classroom Finder supports flexible hybrid academic scheduling:

### 1. External Printed Schedule Integration
When classrooms use timetables generated by third-party institutional ERPs or registrar software, administrators can upload the exact scanned sheet or digital printout:
- Supported formats: **PDF**, **PNG**, **JPG**, **WEBP** (up to 12 MB).
- PDF files embed seamlessly via standard `<object>` elements with direct in-browser print triggers.
- High-resolution images are scaled and centered to prevent reflow.
- Managed directly inside **Admin > Print Schedules > Imported Schedule Sheets**.

### 2. Batch CSV Timetable Import
Import hundreds of recurring weekly class slots in a single click via **Admin > Schedules > Import CSV**:
- **Supported Columns:**
  - `Room` *(Required)*: Matches `room_number` in database.
  - `Day` *(Required)*: Day name (`Monday`, `Mon`, `1`).
  - `Start Time` *(Required)*: e.g. `08:00` or `8:00 AM`.
  - `End Time` *(Required)*: e.g. `10:00` or `10:00 AM`.
  - `Subject` *(Required)*: Course code or title.
  - `Building` *(Optional)*: Disambiguates duplicate room numbers.
  - `Section` *(Optional)*: e.g. `BSCS 3-A`.
  - `Instructor` *(Optional)*: Assigned faculty member.
- **Toggle Replace Mode:** Optionally clear existing database slots for imported classrooms or append without overwriting.

---

## 📊 Analytics & Utilization Reports

Located at [admin/reports.php](admin/reports.php), the administrative reporting suite delivers instant clarity on campus asset utilization:

- **Interactive Utilization Donut Chart:** Visual breakdown of Available vs. Occupied vs. Maintenance classrooms rendered with native SVG.
- **Key Utilization KPIs:**
  - Total Weekly Scheduled Hours across campus.
  - Average Room Load (hours per room).
  - Most Active Day and Peak Usage Window.
- **Hairline Table Styling:** Formatted with `border-collapse: separate; border-spacing: 0;` and calibrated `0.5px solid #000000` borders ensuring crisp, high-resolution rendering without double-stroke blurring on both displays and paper printouts.

---

## 🛡️ Role-Based Access Control (RBAC)

| System Action | Public Visitor | Pending Lecturer | Approved Lecturer | Administrator |
|---|:---:|:---:|:---:|:---:|
| View Live Availability Grid | ✅ | ✅ | ✅ | ✅ |
| Filter & Search Rooms | ✅ | ✅ | ✅ | ✅ |
| Scan Door QR Posters | ❌ | ❌ | ✅ | ✅ |
| Check In / Occupy Rooms | ❌ | ❌ | ✅ | ✅ |
| Submit Force-Open Overrides | ❌ | ❌ | ✅ | ✅ |
| Release Own Sessions | ❌ | ❌ | ✅ | ✅ |
| Terminate Any User's Session | ❌ | ❌ | ❌ | ✅ |
| Manage Classroom Inventory | ❌ | ❌ | ❌ | ✅ |
| Upload Schedule Sheets / CSV | ❌ | ❌ | ❌ | ✅ |
| Generate Batch Door Posters | ❌ | ❌ | ❌ | ✅ |
| Review Activity Audit Logs | ❌ | ❌ | ❌ | ✅ |
| System Settings & Scan Hours | ❌ | ❌ | ❌ | ✅ |

---

## 🔒 Security & Concurrency Architecture

1. **Pessimistic Concurrency Locking (`FOR UPDATE`):**
   ```sql
   SELECT id, status FROM classrooms WHERE id = ? FOR UPDATE;
   ```
   Room claims are encapsulated in ACID-compliant transactions. Simultaneous mobile scans queue safely; the first transaction secures the slot, and concurrent attempts are immediately rejected with informative user feedback.

2. **Prepared Statements & Parameter Binding:**
   100% of database interactions execute through PDO prepared statements, eliminating SQL injection vectors.

3. **Cross-Site Request Forgery (CSRF):**
   All mutation forms and asynchronous API payloads validate high-entropy 256-bit cryptographically secure tokens.

4. **Session & Cookie Hardening:**
   - `HttpOnly`: Session cookies are inaccessible to client-side scripts.
   - `SameSite=Lax`: Defends against cross-site timing and CSRF attacks.
   - `Content-Security-Policy`: Restricts inline injection vectors while whitelisting self-hosted fonts and assets.

5. **Filesystem Protection:**
   Direct HTTP access to `.sql`, `.lock`, `.log`, and sensitive directories (`config/`, `database/`) is blocked via `.htaccess` rules.

---

## ⏱️ Automation & Background Workers

Classroom Finder automatically cleans up completed room occupancies on-demand during routine query execution. For high-volume production deployments, an automated background cron worker is provided:

### Setting Up the Cron Worker
Schedule [cron/expire.php](cron/expire.php) to run every 1 to 5 minutes:

```bash
# Production Linux Crontab (crontab -e)
* * * * * php /var/www/html/classroom_finder/cron/expire.php > /dev/null 2>&1
```

```powershell
# Windows Task Scheduler (PowerShell)
schtasks /create /tn "ClassroomFinderExpire" /tr "php C:\laragon\www\classroom_finder\cron\expire.php" /sc minute /mo 1
```

---

## 🧪 Testing & QA

Classroom Finder includes lightweight, zero-dependency unit and integration checks verifying core domain logic, file uploads, and CSV parsers.

Run the test suite directly from your terminal:

```bash
php tests/test_schedule_import.php
```

**Expected Output:**
```text
Running schedule import tests...
ALL SCHEDULE IMPORT TESTS PASSED.
```

---

## ❓ Troubleshooting & FAQ

<details>
<summary><strong>1. Why won't the camera open when scanning a QR code?</strong></summary>

Mobile browsers (Chrome, Safari, Firefox) mandate a secure context (`HTTPS` or `localhost`) to grant hardware camera permissions. When testing over local network Wi-Fi (e.g. `192.168.1.50`), either create a local SSL certificate or forward through an HTTPS tunnel (e.g. `ngrok` or `Cloudflare Tunnel`).
</details>

<details>
<summary><strong>2. How do I reset a locked setup wizard?</strong></summary>

`setup.php` locks itself as soon as at least one administrator account exists in the `users` table. To re-run setup during development, either clear the `users` table or add an administrator manually via SQL.
</details>

<details>
<summary><strong>3. Why are table borders too thick when printing?</strong></summary>

Standard browsers snap `border-collapse: collapse` table lines to 1px or 2px. Classroom Finder's reporting engine utilizes `border-collapse: separate; border-spacing: 0;` paired with hairline `0.5px solid #000000` borders on table cell perimeters to guarantee ultra-sharp rendering.
</details>

---

## 📄 License & Attribution

Distributed under institutional license for **Clarendon College**.  
Copyright © 2026 **Clarendon College**. All rights reserved.

<div align="center">
  <sub>Built with ❤️ for the students, faculty, and administrators of Clarendon College.</sub>
</div>
