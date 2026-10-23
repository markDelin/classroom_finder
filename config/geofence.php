<?php
declare(strict_types=1);

function geofence_distance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000.0;
    $p = M_PI / 180;
    $a = sin(($lat2 - $lat1) * $p / 2) ** 2 + cos($lat1 * $p) * cos($lat2 * $p) * sin(($lon2 - $lon1) * $p / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function geofence_validate_input(mixed $lat, mixed $lon, mixed $accuracy): ?array
{
    $lat = filter_var($lat, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($lon, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($accuracy, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lon === false || $accuracy === false || !is_finite((float)$lat) || !is_finite((float)$lon) || !is_finite((float)$accuracy) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || $accuracy < 0 || $accuracy > 10000) return null;
    return ['lat' => (float)$lat, 'lon' => (float)$lon, 'accuracy' => (float)$accuracy];
}

function geofence_check(mixed $lat, mixed $lon, mixed $accuracy): ?array
{
    $point = geofence_validate_input($lat, $lon, $accuracy);
    $campusLat = filter_var(function_exists('get_setting') ? get_setting('campus_latitude', '') : '', FILTER_VALIDATE_FLOAT);
    $campusLon = filter_var(function_exists('get_setting') ? get_setting('campus_longitude', '') : '', FILTER_VALIDATE_FLOAT);
    $radius = filter_var(function_exists('get_setting') ? get_setting('campus_radius_meters', '') : '', FILTER_VALIDATE_FLOAT);
    if (!$point || $campusLat === false || $campusLon === false || $radius === false || !is_finite((float)$campusLat) || !is_finite((float)$campusLon) || !is_finite((float)$radius) || $campusLat < -90 || $campusLat > 90 || $campusLon < -180 || $campusLon > 180 || $radius <= 0 || $radius > 10000) return null;
    $distance = geofence_distance($point['lat'], $point['lon'], (float)$campusLat, (float)$campusLon);
    return $distance <= $radius + $point['accuracy'] ? $point + ['distance' => $distance] : null;
}
