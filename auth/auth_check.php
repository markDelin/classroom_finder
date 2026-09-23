<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Please log in first.');
        redirect('../login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        flash('error', 'Administrator access required.');
        redirect('../index.php');
    }
    return $u;
}

function require_approved_lecturer(): array
{
    $u = require_login();
    $ok = $u['account_status'] === 'approved'
        && ($u['role'] === 'lecturer' || $u['role'] === 'admin');
    if (!$ok) {
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
