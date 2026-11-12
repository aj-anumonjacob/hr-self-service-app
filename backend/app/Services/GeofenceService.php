<?php

namespace App\Services;

class GeofenceService
{
    /**
     * Calculate distance between two coordinates in meters using Haversine formula
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
             
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    /**
     * Verify if coordinates are within the location geofence boundary
     */
    public function verifyGeofence(float $empLat, float $empLon, float $locLat, float $locLon, int $radiusMeters): array
    {
        $distance = $this->calculateDistance($empLat, $empLon, $locLat, $locLon);
        return [
            'in_geofence' => $distance <= $radiusMeters,
            'distance_meters' => round($distance, 2),
            'allowed_radius_meters' => $radiusMeters
        ];
    }
}
