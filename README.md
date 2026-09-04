# Classroom Finder

A real-time web app that helps students, lecturers, and staff find and manage available classrooms on campus using QR codes.

---

## What is Classroom Finder?

In busy schools and universities, people often walk from room to room just to see if a classroom is empty. **Classroom Finder** solves this:

- **Students** can immediately see which rooms are free, occupied, or reserved right from their phones—no login required.
- **Lecturers** walk up to a room, scan the QR code posted at the door, and claim the room for their class.
- **Administrators** set up rooms, manage lecturer accounts, schedule weekly classes, and monitor real-time campus room usage.

---

## How It Works in 3 Simple Steps

```text
┌─────────────────────────┐     ┌─────────────────────────┐     ┌─────────────────────────┐
│       1. Check          │     │        2. Scan          │     │        3. Free          │
│ Anyone checks the live  │ ──> │ Lecturer scans the door │ ──> │ Lecturer releases room  │
│ availability online     │     │ QR code to occupy it    │     │ or time runs out        │
└─────────────────────────┘     └─────────────────────────┘     └─────────────────────────┘
```

1. **Check Live Status**: The homepage lists all rooms by building, floor, type, and capacity. Live colors tell you what is happening right now:
   - 🟢 **Available**: Ready to use.
   - 🔴 **Occupied**: In use by a lecturer. Shows who is inside and until what time.
   - 🟡 **Reserved**: Booked for an upcoming class or event.
   - ⚪ **Unavailable**: Room is undergoing maintenance or closed.
2. **Scan to Occupy**: Each door has a printed QR poster. A lecturer logs in on their phone, scans the QR code with their camera (or types the short code), picks a duration, and the room status instantly turns red (Occupied) across the campus.
3. **Automatic / Manual Release**: When class ends, the lecturer taps "End Session". If they forget, the system automatically marks the room available again once the selected duration expires.

---

## Who Uses It?

| User | What They Do | Login Needed? |
|---|---|:---:|
| **Students & Public** | Browse rooms, search by building/floor/capacity, see live availability. | ❌ No |
| **Lecturers** | Scan door QR codes, start/end room sessions, view personal usage history. | ✅ Yes |
| **Administrators** | Manage rooms, print QR codes, create accounts, set fixed weekly timetables, view analytics. | ✅ Yes |

---

## Key Features

- **Live Campus Overview**: Automatically updates room status without manual page refreshes.
- **QR Door Posters**: Printable QR sheets generated directly from the admin panel for every door.
- **No Double-Booking**: Database transactions prevent two lecturers from claiming the same room at the exact same second.
- **Weekly Class Schedules**: Add recurring classes (e.g., *CS101 Mon/Wed 9:00 AM - 10:30 AM*). The system automatically marks rooms as occupied during scheduled hours.
- **Schedule Force-Open**: If a scheduled class is cancelled, a lecturer can override and claim the room on the spot.
- **Mobile Friendly & Offline Support (PWA)**: Works smoothly on mobile browsers with camera scanning and service-worker caching.

---

## Project Structure

```text
classroom_finder/
├── admin/                     # Administrator portal
│   ├── classrooms.php         # Add, edit, and delete classrooms
│   ├── dashboard.php          # Campus overview, usage stats & lecturer approvals
│   ├── history.php            # Past room usage records & filters
│   ├── logs.php               # System audit logs
│   ├── qr_codes.php           # QR code generator & printable door posters
│   ├── reservations.php       # Advance room reservations
│   ├── schedules.php          # Recurring weekly timetable manager
│   ├── sessions.php           # Live room sessions & force-end controls
│   ├── settings.php           # School name, address, and logo setup
│   └── users.php              # Lecturer account management
├── api/                       # Background JSON endpoints
│   ├── classroom_status.php   # Supplies real-time room data to the homepage
│   ├── force_open.php         # Handles schedule override requests
│   ├── release_room.php       # Handles ending active sessions
│   └── scan_qr.php            # Verifies scanned QR codes
├── assets/                    # Styling, fonts, icons, scripts & sounds
├── auth/                      # Login checks & session security
├── config/                    # Database connection, layout & helper functions
├── cron/                      # Background task for expiring finished sessions
├── database/                  # SQL setup script
├── lecturer/                  # Lecturer pages (dashboard, scanner, history)
├── qr/                        # Server-side QR generator library
├── change_password.php        # Password change page
├── index.php                  # Public homepage & live room finder
├── login.php                  # Login page for lecturers & admins
├── logout.php                 # Sign-out handler
├── manifest.json              # Mobile home-screen app configuration
├── setup.php                  # One-time first setup to create initial admin
└── sw.js                      # Offline caching service worker
```

---

## Getting Started (XAMPP / Local Setup)

### Requirements
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.4+
- Apache (e.g., standard [XAMPP](https://www.apachefriends.org/))

### Installation

1. Clone or copy this project into your web server folder:
   ```text
   C:\xampp\htdocs\classroom_finder
   ```
2. Start **Apache** and **MySQL** from your XAMPP Control Panel.
3. Create and import the database:
   - Open **phpMyAdmin** (`http://localhost/phpmyadmin/`).
   - Create a database named `classroom_finder`.
   - Click **Import**, choose `database/classroom_finder.sql`, and click **Import**.
4. Check your database settings in `config/database.php` (defaults to `root` with no password).
5. Open your browser and complete first-time setup:
   ```text
   http://localhost/classroom_finder/setup.php
   ```
   This creates your initial Administrator account. Once finished, `setup.php` locks itself automatically.
6. Sign in via `http://localhost/classroom_finder/login.php`.

---

## Daily Operations Quick Guide

- **Print Door QR Posters**: Go to **Admin** → **QR Codes** → click **Print all posters** (or print individual rooms). Post them beside room doors.
- **Create Lecturer Accounts**: Go to **Admin** → **Users** → **Add user**.
- **Scan via Mobile**: Connect your phone to the same local network or Wi-Fi as the server. Navigate to `http://<YOUR-PC-IP>/classroom_finder/` and log in as a lecturer to scan.

---

## Technical & Security Highlights

- **SQL Injection Safe**: 100% prepared PDO statements across all queries.
- **CSRF Protected**: Form and API requests require matching security tokens.
- **Anti-Race Condition**: Row-level locking (`SELECT ... FOR UPDATE`) prevents simultaneous room claims.
- **Secure Authentication**: Passwords hashed using industry-standard `bcrypt`.
- **Zero External UI Dependencies**: Fonts, icons, and libraries are self-hosted inside the repo for fast local loading.
