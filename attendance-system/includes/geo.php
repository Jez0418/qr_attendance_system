<?php
/**
 * ------------------------------------------------------------
 * includes/geo.php
 * Server-side geolocation math for GPS geofencing. The client
 * (browser) only ever supplies raw latitude/longitude/accuracy —
 * this file is what actually decides whether a student is close
 * enough to a laboratory to be allowed to check in.
 * ------------------------------------------------------------
 */

/**
 * Great-circle distance between two coordinates using the Haversine
 * formula. Returns the distance in METERS.
 */
function haversine_distance_meters($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // meters

    $lat1Rad = deg2rad((float) $lat1);
    $lat2Rad = deg2rad((float) $lat2);
    $deltaLat = deg2rad((float) $lat2 - (float) $lat1);
    $deltaLon = deg2rad((float) $lon2 - (float) $lon1);

    $a = sin($deltaLat / 2) ** 2
        + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Convenience wrapper: is the reported point within $radiusMeters of
 * the target point? Returns [bool withinRadius, float distanceMeters].
 */
function is_within_geofence($studentLat, $studentLon, $targetLat, $targetLon, $radiusMeters) {
    $distance = haversine_distance_meters($studentLat, $studentLon, $targetLat, $targetLon);
    return [$distance <= (float) $radiusMeters, round($distance, 2)];
}
