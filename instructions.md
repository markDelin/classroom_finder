Classroom Finder System — Full Project Brief

[Unverified] This is a proposed system design based on the brainstorming requirements. Implementation details may change during development.

---

1. Project Overview

Classroom Finder is a PHP-based web application designed to help students, lecturers, and school administrators quickly determine the current availability and usage of classrooms.

The system will run on XAMPP using PHP, MySQL/MariaDB, HTML5, CSS, and JavaScript. Lecturers will be able to scan a system-generated classroom QR code using html5-qrcode. After scanning, the lecturer specifies how long the classroom will be used. Once confirmed, the system records the session and displays the classroom as Occupied on the main landing page.

Students and lecturers can use the landing page to find available classrooms without needing to physically check each room.

---

2. Main Problem

Finding an available classroom can be difficult when:

· Classrooms are being used unexpectedly.
· Students have to walk around campus searching for rooms.
· There is no centralized classroom availability information.
· Lecturers may occupy rooms without an easily visible record.
· Administrators have difficulty tracking classroom usage.
· There is no simple way to determine when an occupied classroom should become available again.

The system addresses these problems through a centralized classroom availability platform.

---

3. Main Objective

To develop a web-based classroom management and finder system that allows authorized lecturers to register classroom usage through QR scanning while providing students, lecturers, and administrators with up-to-date classroom availability information.

---

4. Specific Objectives

The system should:

1. Display classroom availability on a central landing page.
2. Allow lecturers to register for an account.
3. Require administrator approval for new lecturer accounts.
4. Provide secure login and role-based access.
5. Generate a unique QR code for each classroom.
6. Allow authorized lecturers to scan classroom QR codes.
7. Confirm classroom usage before changing its status.
8. Allow lecturers to specify the intended usage duration.
9. Record classroom usage in the database.
10. Display occupied classrooms and their expected end time.
11. Allow lecturers to release classrooms early.
12. Automatically recognize expired classroom sessions.
13. Allow administrators to manage classrooms and users.
14. Maintain classroom usage history and activity logs.
15. Provide search and filtering for finding classrooms.

---

5. User Roles

Student

Students primarily use the public landing page.

They can:

· View classrooms
· Search for classrooms
· Filter available/occupied rooms
· See classroom location
· See room capacity
· See current occupancy
· See expected availability time

They cannot:

· Scan classroom QR codes
· Occupy classrooms through the system
· Manage users
· Modify classroom information

---

Lecturer

Lecturers require an approved account.

They can:

· Log in
· Scan classroom QR codes
· Select usage duration
· Occupy available classrooms
· View their current classroom
· Release a classroom early
· View their own classroom usage history

A lecturer cannot occupy a classroom if the system determines that it is currently unavailable.

---

Administrator

The administrator manages the system.

Admin functions include:

· Approve/reject lecturer registrations
· Create lecturer accounts
· Manage users
· Add classrooms
· Edit classrooms
· Disable classrooms
· Generate classroom QR codes
· Regenerate QR codes
· View classroom sessions
· View usage history
· View activity logs
· Mark classrooms as unavailable/maintenance
· Manage system settings

---

6. New System / First-Time Setup

When the system is installed for the first time, the database will contain no administrator.

The system can detect this and display:

Initial System Setup

No administrator account exists.

Create the first administrator account.

The first administrator creates the initial admin account.

After setup, normal users cannot simply register themselves as administrators.

---

7. Lecturer Registration

New lecturers can register through a registration page.

Suggested information:

· Full name
· Staff/Employee ID
· Institutional email
· Department
· Username
· Password
· Confirm password

New accounts initially have:

```text
Role: Lecturer
Status: Pending
```

The lecturer cannot access classroom scanning functions until an administrator approves the account.

After approval:

```text
Role: Lecturer
Status: Approved
```

---

8. Login System

The system will use PHP sessions for authentication.

Login:

```text
Username
Password

[ LOGIN ]
```

After login, the system determines the user's role.

```text
Admin    → Admin Dashboard
Lecturer → Lecturer Dashboard
Student  → Student/Public Interface
```

Passwords should be stored using PHP password hashing rather than plain text.

---

9. Classroom QR System

Each registered classroom receives a unique QR code.

Example:

```text
ROOM 201

[ QR CODE ]

Classroom ID: ROOM-201
```

The QR code should contain a unique identifier/token associated with the classroom.

The system should not rely solely on the visible room number.

When scanned:

```text
QR Code
   ↓
Find classroom
   ↓
Validate QR token
   ↓
Check lecturer authentication
   ↓
Check lecturer permission
   ↓
Check classroom status
```

---

10. Lecturer QR Scanning

The lecturer dashboard contains a scanner using:

HTML5 QR Code / html5-qrcode

The lecturer points their phone camera at the classroom QR code.

After successful scanning, the system displays a confirmation dialog.

Example:

```text
ROOM 201

Classroom is available.

How long will you use this classroom?

[-]  1 Hour  [+]

Starting: 2:00 PM
Ending:   3:00 PM

[CANCEL] [CONFIRM]
```

Possible duration options:

· 30 minutes
· 1 hour
· 1.5 hours
· 2 hours
· Custom duration

---

11. Occupying a Classroom

When the lecturer presses Confirm, the PHP backend performs another validation.

It checks:

1. Is the lecturer logged in?
2. Is the account approved?
3. Does the QR code exist?
4. Does the QR token match?
5. Is the classroom available?
6. Is the requested duration valid?

If everything is valid, a classroom session is created.

Example:

```text
Room: 201
Lecturer: Mr. Santos
Start: 2:00 PM
End: 3:30 PM
Status: Active
```

The classroom then appears as:

🔴 OCCUPIED

---

12. Classroom Landing Page

The landing page is the main feature for students and lecturers who want to find rooms.

Example:

```text
CLASSROOM FINDER

Search classroom...

[ ALL ] [ AVAILABLE ] [ OCCUPIED ]

ROOM 201
🔴 OCCUPIED

Mr. Santos
2:00 PM - 3:30 PM

ROOM 202
🟢 AVAILABLE

ROOM 203
🟡 RESERVED

ROOM 204
⚫ UNAVAILABLE
Maintenance
```

Each classroom can display:

· Room number
· Building
· Floor
· Capacity
· Room type
· Current status
· Current usage period
· Expected availability

---

13. Classroom Statuses

The system can use four primary statuses:

🟢 Available

Nobody is currently using the classroom.

🔴 Occupied

A lecturer has an active classroom session.

🟡 Reserved

The classroom has a future reservation.

⚫ Unavailable

The classroom cannot currently be used, such as during maintenance.

This is more useful than having only "Available" and "Occupied."

---

14. Classroom Release

Lecturers should be able to release a classroom before their scheduled end time.

Example:

```text
MY CURRENT CLASSROOM

ROOM 201
Occupied
2:00 PM - 4:00 PM

[ RELEASE CLASSROOM ]
```

The system asks for confirmation.

After release:

```text
ROOM 201
🟢 AVAILABLE
```

The session is recorded as completed/released.

---

15. Automatic Session Expiration

If a lecturer selects:

```text
2:00 PM → 4:00 PM
```

the system should recognize that the session has ended after 4:00 PM.

The classroom can then be treated as available.

This avoids classrooms remaining displayed as occupied after their recorded usage period.

---

16. Conflict Detection

The system should check for conflicts.

Example:

```text
ROOM 201
Current session:
2:00 PM → 4:00 PM
```

Another lecturer scans the same QR at 3:00 PM.

The system displays:

```text
⚠️ Classroom Unavailable

Room 201 is currently occupied.

Current session:
2:00 PM - 4:00 PM
```

The second lecturer cannot create a conflicting active session.

---

17. Database Design

A suggested MySQL database is:

users

```sql
id
full_name
staff_id
email
username
password
department
role
account_status
created_at
```

classrooms

```sql
id
room_number
building
floor
capacity
room_type
qr_token
status
created_at
```

classroom_sessions

```sql
id
classroom_id
user_id
start_time
end_time
status
created_at
```

activity_logs

```sql
id
user_id
classroom_id
action
details
timestamp
```

Optional future table: reservations

```sql
id
classroom_id
user_id
start_time
end_time
status
created_at
```

---

18. Admin Dashboard

The administrator dashboard could display:

```text
CLASSROOM FINDER ADMIN

Total Classrooms     24
Available            17
Occupied              5
Unavailable           2

Pending Lecturers     3
```

Admin menu:

```text
Dashboard
Users
Lecturers
Classrooms
QR Codes
Active Sessions
Reservations
Usage History
Activity Logs
Settings
```

---

19. Classroom Management

Admin can add a classroom:

```text
Room Number: 201
Building: Main Building
Floor: 2
Capacity: 40
Room Type: Lecture Room
```

After saving, the system generates a QR token associated with that classroom.

Admin can then print or otherwise distribute the classroom's QR code and place it outside the room.

---

20. QR Code Security

The QR code should contain a unique token rather than sensitive information.

Conceptually:

```text
ROOM-201
TOKEN: unique-random-token
```

The PHP backend verifies the token against the database.

Administrators should also have a Regenerate QR function if a QR code becomes compromised or needs to be replaced.

---

21. Usage History

Administrators can see previous classroom usage.

Example:

```text
ROOM 201 — USAGE HISTORY

August 24, 2026

08:00 - 09:30
Mr. Cruz

10:00 - 11:00
Ms. Reyes

01:00 - 03:00
Mr. Santos
```

This provides a historical record of classroom utilization.

---

22. Activity Logs

The system can record important actions:

```text
Mr. Santos
OCCUPIED ROOM 201
2:00 PM

Mr. Santos
RELEASED ROOM 201
3:15 PM

Admin
APPROVED NEW LECTURER
3:20 PM
```

This makes it easier for administrators to review system activity.

---

23. Future Reservation Feature

A later version could allow lecturers to reserve classrooms in advance.

Example:

```text
ROOM 201

Tomorrow
9:00 AM - 11:00 AM

Reserved by:
Ms. Reyes
```

The landing page would show:

🟡 RESERVED

This feature can be developed after the basic QR occupancy system is working.

---

24. Search and Filtering

Students should be able to search:

```text
🔎 Search room...
```

And filter:

```text
Status
☑ Available
☐ Occupied
☐ Reserved
☐ Unavailable
```

Additional filters:

· Building
· Floor
· Capacity
· Room type

For example, a student needing a room for 40 people could search for classrooms with at least 40 seats.

---

25. Recommended Security

The PHP application should use:

· PHP sessions
· Password hashing
· Prepared SQL statements
· Server-side authorization checks
· Input validation
· CSRF protection for important actions
· Unique QR tokens
· Role-based access control

Most importantly, the frontend should not be trusted to determine whether someone is allowed to scan a QR code. The PHP backend should perform the authorization check.

---

26. Suggested Technology Stack

Component Technology
Backend PHP
Database MySQL/MariaDB
Local Server XAMPP
Frontend HTML5
Styling CSS3
Client-side logic JavaScript
QR scanning html5-qrcode
Authentication PHP Sessions
Database access PDO/MySQLi
QR generation PHP QR-code library
Development VS Code / similar editor

---

27. Suggested Project Structure

```text
classroom-finder/
│
├── index.php
├── login.php
├── register.php
├── logout.php
│
├── config/
│   └── database.php
│
├── auth/
│   ├── login_process.php
│   └── auth_check.php
│
├── lecturer/
│   ├── dashboard.php
│   ├── scanner.php
│   ├── occupy.php
│   ├── release.php
│   └── history.php
│
├── admin/
│   ├── dashboard.php
│   ├── users.php
│   ├── classrooms.php
│   ├── qr_codes.php
│   ├── sessions.php
│   ├── reservations.php
│   └── logs.php
│
├── api/
│   ├── classroom_status.php
│   ├── scan_qr.php
│   └── release_room.php
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│
├── qr/
│
└── database/
    └── classroom_finder.sql
```

---

28. Recommended Development Phases

Phase 1 — Foundation

· XAMPP setup
· MySQL database
· Database connection
· First admin setup
· Login/logout
· User roles

Phase 2 — Classroom Management

· Add classrooms
· Edit classrooms
· Delete/disable classrooms
· Classroom status
· QR generation

Phase 3 — Lecturer System

· Lecturer registration
· Admin approval
· Lecturer login
· Lecturer dashboard

Phase 4 — QR Occupancy

· HTML5 QR scanner
· QR validation
· Duration selection
· Confirmation modal
· Occupancy database record
· Conflict checking

Phase 5 — Classroom Finder

· Public landing page
· Available/occupied cards
· Search
· Filters
· Building/floor information
· Expected availability

Phase 6 — Management & History

· Release classroom
· Automatic expiration
· Usage history
· Activity logs
· Admin statistics

Phase 7 — Advanced Features

· Reservations
· Maintenance status
· Classroom usage analytics
· Notifications
· Export reports

---

29. Complete System Flow

```text
                    ┌───────────────┐
                    │    SYSTEM     │
                    │  FIRST SETUP  │
                    └───────┬───────┘
                            ↓
                     Create Admin
                            ↓
              ┌─────────────┴─────────────┐
              ↓                           ↓
       Lecturer Register             Public User
              ↓                           ↓
       Pending Account              View Classrooms
              ↓
       Admin Approval
              ↓
       Approved Lecturer
              ↓
           Login
              ↓
        Lecturer Dashboard
              ↓
         Scan Classroom QR
              ↓
       Validate QR + Account
              ↓
       Check Room Availability
              ↓
       ┌──────YES──────┐
       ↓               │
 Select Duration       │
       ↓               │
   Confirmation        │
       ↓               │
 Create Session        │
       ↓               │
 Classroom = OCCUPIED  │
       ↓               │
 Landing Page Updates  │
       ↓               │
 Session Ends          │
       ↓               │
 Classroom = AVAILABLE │
```

---

30. Core System Concept

The central workflow is:

Lecturer scans QR → system verifies lecturer and classroom → lecturer chooses duration → confirmation → database records session → classroom becomes occupied → landing page reflects the status → session expires or lecturer releases the room → classroom becomes available again.

This creates a practical school-based system combining:

· PHP web development
· MySQL database management
· Authentication
· Role-based access control
· QR technology
· Classroom management
· Usage tracking
· Search and filtering
· Administrative reporting

The recommended approach is to build the core QR occupancy system first, then add reservations, analytics, notifications, and other advanced features in later phases.
