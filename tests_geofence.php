<?php
require __DIR__ . '/config/geofence.php';
assert(abs(geofence_distance(0, 0, 0, 0) - 0) < 0.001);
assert(abs(geofence_distance(0, 0, 0, 0.001) - 111.2) < 1);
assert(geofence_validate_input(91, 0, 1) === null);
assert(geofence_validate_input(0, 0, 10001) === null);
echo "geofence ok\n";
