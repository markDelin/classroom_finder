# Classroom Finder

PHP + MySQL web application for live campus classroom availability and QR code-based room occupancy.

## Feature Map

| Feature | Components |
|---|---|
| Public landing & room search | [index.php](index.php), [assets/js/landing.js](assets/js/landing.js) |
| Live status polling | [api/classroom_status.php](api/classroom_status.php) |
| First-time admin setup | [setup.php](setup.php) (locks after initialization) |
| Lecturer account management | [admin/users.php](admin/users.php) |
| Authentication & sessions | [login.php](login.php), [auth/](auth/) |
| QR token generation & posters | [admin/qr_codes.php](admin/qr_codes.php), [qr/generate.php](qr/generate.php) |
| QR camera scan & manual entry | [lecturer/scanner.php](lecturer/scanner.php), [assets/js/scanner.js](assets/js/scanner.js) |
| Occupancy claim (row-level locked) | [lecturer/occupy.php](lecturer/occupy.php) |
| Conflict validation | Active sessions, reservations, and fixed schedules checked atomically |
| Fixed weekly schedules | [admin/schedules.php](admin/schedules.php) (`class_schedules` table) |
| Force-open override | Scanner prompt -> [api/force_open.php](api/force_open.php) (`schedule_force_open` table) |
| Early room release | [lecturer/release.php](lecturer/release.php), [api/release_room.php](api/release_room.php) |
| Stale session expiration | Lazy check via `expire_stale()` in [config/helpers.php](config/helpers.php), or CLI via [cron/expire.php](cron/expire.php) |
| Admin panel | [admin/](admin/) (dashboard, classrooms, users, schedules, sessions, reservations, history, logs, settings) |
| UI notifications & modals | Toastify.js, SweetAlert2, Lucide icons, responsive layout |
| PWA support | [manifest.json](manifest.json), [sw.js](sw.js) |

## Project Structure

```text
classroom_finder/
├── admin/                     # Administrator portal
│   ├── classrooms.php         # Classroom CRUD & capacity management
│   ├── dashboard.php          # Overview, analytics & lecturer approvals
│   ├── history.php            # Historical session logs & filters
│   ├── logs.php               # System activity audit trail
│   ├── qr_codes.php           # QR code generation & print posters
│   ├── reservations.php       # Room reservation bookings
│   ├── schedules.php          # Recurring weekly timetable matrix
│   ├── sessions.php           # Live room sessions monitoring & early release
│   ├── settings.php           # Institution info & app configurations
│   └── users.php              # Lecturer accounts & status control
├── api/                       # Internal JSON API endpoints
│   ├── classroom_status.php   # Real-time room availability poller
│   ├── force_open.php         # Schedule override exception handler
│   ├── release_room.php       # Active session termination
│   └── scan_qr.php            # QR token parser & validator
├── assets/                    # Static frontend resources
│   ├── css/                   # Stylesheets (style.css & vendor)
│   ├── fonts/                 # Self-hosted Barlow Condensed & IBM Plex
│   ├── js/                    # Client logic (scanner, landing, modals, UI)
│   ├── sound/                 # Scan audio feedback (success & error)
│   └── uploads/               # Institution logo storage
├── auth/                      # Authentication handlers
│   ├── auth_check.php         # Role & login guard middlewares
│   └── login_process.php      # Credential verification & session bootstrap
├── config/                    # Core configuration & layout helpers
│   ├── database.php           # PDO connection instance (db())
│   ├── helpers.php            # Global helper functions, CSRF, flash messages
│   ├── icons.php              # SVG Lucide icon library dictionary
│   └── layout.php             # HTML chrome renderer (header & footer)
├── cron/                      # CLI background workers
│   └── expire.php             # Scheduled session expiration runner
├── database/                  # Database schema & fixtures
│   └── classroom_finder.sql   # SQL structure & initial tables
├── lecturer/                  # Lecturer self-service portal
│   ├── dashboard.php          # Lecturer overview & current active session
│   ├── history.php            # Personal session occupancy history
│   ├── occupy.php             # Claim room flow via token/schedule check
│   ├── release.php            # End personal active session
│   └── scanner.php            # WebRTC camera scanner & manual token form
├── qr/                        # QR rendering library
│   ├── generate.php           # QR image stream endpoint
│   └── lib/                   # Bundled phpqrcode engine
├── change_password.php        # User self-service password update
├── index.php                  # Public classroom availability finder
├── login.php                  # Central sign-in page
├── logout.php                 # Session termination & CSRF logout
├── manifest.json              # PWA web app manifest
├── setup.php                  # Initial root admin account provisioning
└── sw.js                      # Offline service worker cache
```

## Setup (XAMPP)

1. Place repo in `C:\xampp\htdocs\classroom_finder`.
2. Start **Apache** and **MySQL** in XAMPP Control Panel.
3. Import database schema:
   - phpMyAdmin: Import -> select [database/classroom_finder.sql](database/classroom_finder.sql) -> Go.
   - CLI: `mysql -u root < database/classroom_finder.sql`
4. Configure DB credentials in [config/database.php](config/database.php) if MySQL uses a password.
5. Navigate to `http://localhost/classroom_finder/setup.php` to create root admin account.
6. Log in via `http://localhost/classroom_finder/login.php`.

## Background Expiration Worker

Optional CLI background runner for periodic session expiry:

```bash
php cron/expire.php
```

Can be scheduled via Windows Task Scheduler or crontab (e.g., every 1-5 minutes). Stale sessions also auto-expire on page requests via `expire_stale()`.

## QR Code & Camera Scanning

- **Generate & Print:** Navigate to **Admin -> QR Codes -> Print all**. Rendered server-side using bundled phpqrcode.
- **Scanning:** Open `http://<server-ip>/classroom_finder` on mobile, sign in as approved lecturer, open **Scanner**.
- **Browser Security:** WebRTC camera access requires `localhost` or HTTPS. For plain HTTP over LAN, use manual token entry printed on the QR poster.

## Security

- Prepared statements via PDO for all SQL queries.
- Password hashing with `PASSWORD_DEFAULT` (bcrypt).
- CSRF validation tokens on state-changing requests (`csrf_token` input or `X-CSRF-Token` header).
- Race-condition safe room claims using InnoDB transactions with row-level locks (`SELECT ... FOR UPDATE`).
- Server-side role and account approval enforcement on every guarded endpoint.
