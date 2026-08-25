# Classroom Finder

A PHP + MySQL web app that shows live classroom availability and lets
approved lecturers occupy rooms by scanning a QR code — students stop walking
around campus looking for a free room.

## Feature map (from the project brief)

| Brief section | Where it lives |
|---|---|
| Public landing page, search & filters | `index.php` + `assets/js/landing.js` |
| First-time admin setup | `setup.php` (locks itself after first run) |
| Lecturer registration → admin approval | `register.php`, `admin/users.php` |
| Login / roles / sessions | `login.php`, `auth/` |
| QR generation per classroom | `qr/generate.php` (+ bundled `qr/lib` phpqrcode) |
| QR scanning & duration dialog | `lecturer/scanner.php`, `assets/js/scanner.js`, vendored html5-qrcode |
| Occupying a room (server-validated) | `lecturer/occupy.php` — transaction + row lock |
| Conflict detection | same, plus overlapping-reservation check |
| Release early | `lecturer/release.php`, also `api/release_room.php` |
| Automatic session expiration | `expire_stale()` in `config/helpers.php` (lazy sweep) |
| Live status feed | `api/classroom_status.php` |
| Admin: users, classrooms, QR codes, sessions, reservations, history, logs, settings | `admin/*.php` |

Statuses are shown as inline [Lucide](https://lucide.dev) icons (see
`config/icons.php`): circle-check available · clock occupied · calendar-clock
reserved (upcoming booking inside the reserve window) · ban unavailable
(maintenance/disabled). Add new icons by dropping their `<svg>` inner markup
into `LUCIDE_ICONS`.

## Setup on XAMPP

1. Copy this folder into `C:\xampp\htdocs\classroom_finder`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Import the schema: open phpMyAdmin → *Import* → choose
   `database/classroom_finder.sql` → Go.
   (Or CLI: `mysql -u root < database/classroom_finder.sql`)
4. If your MySQL root has a password, edit `config/database.php`.
5. Open <http://localhost/classroom_finder/setup.php> and create the first
   administrator account. That page then locks itself permanently.
6. Log in at <http://localhost/classroom_finder/login.php>.

Sample classrooms are seeded so you can test immediately; delete them from
**Admin → Classrooms** when you add real ones.

### Printing room QR codes

Admin → **QR Codes** → *Print all* (or regenerate an individual token if a
poster leaks). The image is generated server-side by the bundled phpqrcode
library — PNG when the GD extension is enabled (stock XAMPP), otherwise SVG.

### Scanning

Open the lecturer dashboard on a phone (same Wi-Fi as the PC running XAMPP,
e.g. `http://<pc-ip>/classroom_finder`), log in, and use **Scan QR Code**.
Camera scanning needs HTTPS or localhost for camera permission in most
browsers — on plain HTTP over LAN, Chrome may block camera access, in which
case use the manual-token box printed under each QR poster.

## Security notes

- PHP sessions + `password_hash()` (bcrypt), prepared statements everywhere,
  CSRF tokens on every form and API call (`X-CSRF-Token` header for fetch),
  role guards re-checked server-side before every action.
- Occupy/release re-validate login, approval, QR token, room status,
  conflicting sessions and reservations **inside a transaction with a row
  lock**, so two lecturers can never both claim the same room.
- The frontend never decides permissions — it only displays what the server
  already verified.

## Timezone

`config/helpers.php` sets PHP to `Asia/Manila`; MySQL's session timezone is
synced from PHP on connect. Change the constant if your campus is elsewhere.

## Project layout

See `instructions.md` §27 — this codebase follows it exactly:
`config/ auth/ lecturer/ admin/ api/ assets/ qr/ database/`.
