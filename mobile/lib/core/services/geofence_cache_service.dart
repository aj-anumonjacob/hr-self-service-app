import 'dart:convert';
import 'dart:math';
import 'package:sqflite/sqflite.dart';
import '../database/app_database.dart';

/// Result of checking a captured GPS fix against the cached geofence
/// configuration — mirrors the shape of the backend's
/// `GeofenceService::verifyGeofence()` response.
class GeofenceCheckResult {
  final bool inGeofence;
  final double distanceMeters;
  final int allowedRadiusMeters;
  final String? nearestLocationName;

  GeofenceCheckResult({
    required this.inGeofence,
    required this.distanceMeters,
    required this.allowedRadiusMeters,
    this.nearestLocationName,
  });
}

/// Caches the employee's currently-assigned work location(s) + geofence
/// radius (from `/me/today-context`'s `policy` object) so check-in/check-out
/// can be validated locally even with zero connectivity.
///
/// Cached on every successful `getTodayContext()` call (app launch, pull to
/// refresh, after every online check-in/out) — never a dedicated API call of
/// its own, so there is no extra network cost.
class GeofenceCacheService {
  static final GeofenceCacheService _instance = GeofenceCacheService._internal();
  factory GeofenceCacheService() => _instance;
  GeofenceCacheService._internal();

  Future<void> saveGeofenceConfig(Map<String, dynamic> policy) async {
    try {
      final db = await AppDatabase().database;
      await db.insert(
        'cached_geofence_config',
        {
          'id': 1,
          'policy_json': jsonEncode(policy),
          'cached_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    } catch (_) {
      // Best-effort cache — a failure here just means offline check-in/out
      // won't be available until the next successful online context fetch.
    }
  }

  Future<({Map<String, dynamic> policy, DateTime cachedAt})?> getCachedGeofenceConfig() async {
    try {
      final db = await AppDatabase().database;
      final rows = await db.query('cached_geofence_config', where: 'id = 1', limit: 1);
      if (rows.isEmpty) return null;

      final policy = jsonDecode(rows.first['policy_json'] as String) as Map<String, dynamic>;
      final cachedAt = DateTime.parse(rows.first['cached_at'] as String);
      return (policy: policy, cachedAt: cachedAt);
    } catch (_) {
      return null;
    }
  }

  /// Checks [lat]/[lng] against every location the employee is currently
  /// assigned to (matching the backend's own "try each assigned location,
  /// use the nearest" logic for the live check-in/out endpoints), using
  /// `check_in_radius` or `checkout_radius` depending on [isCheckOut].
  GeofenceCheckResult checkGeofence({
    required double lat,
    required double lng,
    required Map<String, dynamic> policy,
    required bool isCheckOut,
  }) {
    final singleLocation = policy['location'] as Map<String, dynamic>?;
    final rawLocations = policy['locations'] as List<dynamic>?;
    final candidates = (rawLocations != null && rawLocations.isNotEmpty)
        ? rawLocations.cast<Map<String, dynamic>>()
        : (singleLocation != null ? [singleLocation] : <Map<String, dynamic>>[]);

    if (candidates.isEmpty) {
      return GeofenceCheckResult(inGeofence: false, distanceMeters: 0, allowedRadiusMeters: 0);
    }

    GeofenceCheckResult? best;
    for (final candidate in candidates) {
      final locLat = _toDouble(candidate['latitude']);
      final locLng = _toDouble(candidate['longitude']);
      if (locLat == null || locLng == null) continue;
      final radiusField = isCheckOut ? 'checkout_radius' : 'check_in_radius';
      final radius = _toDouble(candidate[radiusField])?.toInt() ?? 100;

      final distance = _distanceMeters(lat, lng, locLat, locLng);
      final result = GeofenceCheckResult(
        inGeofence: distance <= radius,
        distanceMeters: distance,
        allowedRadiusMeters: radius,
        nearestLocationName: candidate['name'] as String?,
      );

      if (result.inGeofence) return result;
      if (best == null || result.distanceMeters < best.distanceMeters) {
        best = result;
      }
    }

    return best ?? GeofenceCheckResult(inGeofence: false, distanceMeters: 0, allowedRadiusMeters: 0);
  }

  /// Backend location coordinates are DECIMAL columns serialized as JSON
  /// strings (e.g. `"25.0610810"`), not numbers — this parses either form.
  double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value);
    return null;
  }

  double _distanceMeters(double lat1, double lon1, double lat2, double lon2) {
    const earthRadius = 6371000.0;
    final dLat = _degToRad(lat2 - lat1);
    final dLon = _degToRad(lon2 - lon1);
    final a = sin(dLat / 2) * sin(dLat / 2) +
        cos(_degToRad(lat1)) * cos(_degToRad(lat2)) * sin(dLon / 2) * sin(dLon / 2);
    final c = 2 * atan2(sqrt(a), sqrt(1 - a));
    return earthRadius * c;
  }

  double _degToRad(double deg) => deg * (pi / 180);
}
