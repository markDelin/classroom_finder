<?php
declare(strict_types=1);

/**
 * Classroom Finder — authentication & role-based access control.
 *
 * The frontend is never trusted: every protected page calls one of these
 * guards first, and every API/action re-checks on the server (Security: Server-Side RBAC Enforcement).
 */

require_once __DIR__ . '/../config/helpers.php';

// current_user() / is_logged_in() are defined in config/helpers.php; this
// file adds the role-based page guards on top of them.

/** Any authenticated user; otherwise bounce to the login page. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Please log in first.');
        redirect('../login.php');
    }
    return $u;
}

/** Admin-only pages. */
function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        flash('error', 'Administrator access required.');
        redirect('../index.php');
    }
    return $u;
}

/**
 * Approved lecturers only (admins may also pass through to demo the scanner).
 * Pending accounts are rejected until an administrator approves them (Feature: Lecturer Approval Workflow).
 */
function require_approved_lecturer(): array
{
    $u = require_login();
    $ok = $u['account_status'] === 'approved'
        && ($u['role'] === 'lecturer' || $u['role'] === 'admin');
    if (!$ok) {
        // pending / rejected lecturers get a clear explanation
        flash(
            'error',
            $u['account_status'] === 'pending'
                ? 'Your account is still awaiting administrator approval.'
                : 'Your account is not active. Please contact an administrator.'
        );
        redirect('../index.php');
    }
    return $u;
}
