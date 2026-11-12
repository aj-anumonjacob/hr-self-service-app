import 'package:geolocator/geolocator.dart';

/// Best-effort, non-throwing GPS location capture used for background
/// reporting (e.g. login/activation audit trails). Returns null if location
/// services or permissions are unavailable instead of surfacing an error.
class LocationUtil {
  static Future<Position?> captureSilently() async {
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) return null;

      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        return null;
      }

      Position? position = await Geolocator.getLastKnownPosition();
      try {
        position ??= await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.medium,
          timeLimit: const Duration(seconds: 10),
        );
      } catch (_) {
        // A cold GPS fix can miss a tight deadline; fall back to a coarser,
        // faster fix rather than reporting no location at all.
        try {
          position ??= await Geolocator.getCurrentPosition(
            desiredAccuracy: LocationAccuracy.low,
            timeLimit: const Duration(seconds: 6),
          );
        } catch (_) {}
      }
      return position;
    } catch (_) {
      return null;
    }
  }
}
