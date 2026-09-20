<?php
declare(strict_types=1);

/**
 * Verification test for room scanning daily time limit.
 * Tests helper boundaries, custom setting overrides, and outside hours logic.
 */

// Initialize cache so DB is not called during testing
$GLOBALS['settings_cache'] = [
    'scan_day_start' => '07:00',
    'scan_day_end'   => '19:00',
];

require_once __DIR__ . '/../config/helpers.php';

// 1. Default hours boundary tests
[$defStart, $defEnd] = get_scan_hours();
assert($defStart === '07:00', "Default start should be 07:00, got: {$defStart}");
assert($defEnd === '19:00', "Default end should be 19:00, got: {$defEnd}");

$baseDate = '2026-09-20 ';
$tBeforeOpen  = strtotime($baseDate . '06:59:59');
$tAtOpen      = strtotime($baseDate . '07:00:00');
$tMidday      = strtotime($baseDate . '12:00:00');
$tBeforeClose = strtotime($baseDate . '18:59:59');
$tAtClose     = strtotime($baseDate . '19:00:00');
$tAfterClose  = strtotime($baseDate . '19:30:00');
$tMidnight    = strtotime($baseDate . '00:00:00');

assert(!is_within_scan_hours($tBeforeOpen), '06:59:59 must be outside scan hours');
assert(is_within_scan_hours($tAtOpen), '07:00:00 must be within scan hours');
assert(is_within_scan_hours($tMidday), '12:00:00 must be within scan hours');
assert(is_within_scan_hours($tBeforeClose), '18:59:59 must be within scan hours');
assert(!is_within_scan_hours($tAtClose), '19:00:00 must be outside scan hours');
assert(!is_within_scan_hours($tAfterClose), '19:30:00 must be outside scan hours');
assert(!is_within_scan_hours($tMidnight), '00:00:00 must be outside scan hours');

// 2. Custom setting override test
$GLOBALS['settings_cache']['scan_day_start'] = '08:00';
$GLOBALS['settings_cache']['scan_day_end']   = '18:00';

[$custStart, $custEnd] = get_scan_hours();
assert($custStart === '08:00', "Custom start should be 08:00, got: {$custStart}");
assert($custEnd === '18:00', "Custom end should be 18:00, got: {$custEnd}");

assert(!is_within_scan_hours(strtotime($baseDate . '07:30:00')), '07:30 must be outside 08:00-18:00');
assert(is_within_scan_hours(strtotime($baseDate . '08:00:00')), '08:00 must be inside 08:00-18:00');
assert(is_within_scan_hours(strtotime($baseDate . '17:59:00')), '17:59 must be inside 08:00-18:00');
assert(!is_within_scan_hours(strtotime($baseDate . '18:00:00')), '18:00 must be outside 08:00-18:00');

// 3. Fallback when invalid setting provided
$GLOBALS['settings_cache']['scan_day_start'] = 'invalid';
$GLOBALS['settings_cache']['scan_day_end']   = '';
[$fbStart, $fbEnd] = get_scan_hours();
assert($fbStart === '07:00', "Fallback start should be 07:00, got: {$fbStart}");
assert($fbEnd === '19:00', "Fallback end should be 19:00, got: {$fbEnd}");

// Reset back to 07:00 - 19:00
$GLOBALS['settings_cache']['scan_day_start'] = '07:00';
$GLOBALS['settings_cache']['scan_day_end']   = '19:00';

echo "All scan day limit assertions passed!\n";
