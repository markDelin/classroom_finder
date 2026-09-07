# CLAUDE.md

Classroom availability tracker and QR check-in system built with vanilla PHP, MySQL/MariaDB, and JavaScript.

## Project map

- `admin/` - Admin dashboard, classroom CRUD, user management, audit logs
- `api/` - JSON API endpoints (`classroom_status.php`, `scan_qr.php`, `force_open.php`, `release_room.php`)
- `assets/` - Static assets (CSS, vanilla JS, custom fonts, audio cues)
- `auth/` - Session verification and login processing
- `config/` - Database singleton (`database.php`), helpers, layout templates
- `cron/` - Background expiry worker (`expire.php`)
- `database/` - Database schema (`classroom_finder.sql`)
- `lecturer/` - Lecturer portal and scanner interface
- `qr/` - QR generator and embedded phpqrcode library

<important if="you need to run, test, or maintain the project">

Stack runs on XAMPP (Apache + MariaDB).

| Command / Target | What it does |
|---|---|
| `http://localhost/classroom_finder` | Access web app |
| `php -l <file.php>` | Syntax check PHP file |
| `php cron/expire.php` | Run schedule expiration cron worker |
| `mysql -u root -p classroom_finder < database/classroom_finder.sql` | Import baseline database schema |
</important>

<important if="you are modifying database queries or schema">
- Access DB exclusively via `db()` PDO singleton in `config/database.php`
- Use prepared statements with parameter binding for all user input
- Avoid direct external access; keep `database/.htaccess` intact
</important>

<important if="you are creating or editing API endpoints">
- Return JSON headers: `header('Content-Type: application/json')`
- Standard response format: `{"success": bool, "message": string, "data": ...}`
- Sanitize and validate all `POST`/`GET` parameters before processing
</important>

<important if="you are touching session or authentication logic">
- Require `auth/auth_check.php` for protected pages
- Check role permissions (`admin` vs `lecturer`) before executing restricted operations
</important>
