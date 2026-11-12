import 'package:geolocator/geolocator.dart';

class LocationService {
  static Future<Position> determinePosition([Position? customPosition]) async {
    if (customPosition != null) {
      return customPosition;
    }

    try {
      bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        Position? last = await Geolocator.getLastKnownPosition();
        if (last != null) return last;
        return _fallbackPosition();
      }

      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
        if (permission == LocationPermission.denied) {
          Position? last = await Geolocator.getLastKnownPosition();
          if (last != null) return last;
          return _fallbackPosition();
        }
      }

      if (permission == LocationPermission.deniedForever) {
        Position? last = await Geolocator.getLastKnownPosition();
        if (last != null) return last;
        return _fallbackPosition();
      }

      Position? position = await Geolocator.getLastKnownPosition();

      if (position == null) {
        try {
          position = await Geolocator.getCurrentPosition(
            desiredAccuracy: LocationAccuracy.medium,
            timeLimit: const Duration(seconds: 4),
          );
        } catch (_) {}
      }

      position ??= await Geolocator.getLastKnownPosition();

      if (position != null) {
        final distToDubai = Geolocator.distanceBetween(
          position.latitude,
          position.longitude,
          25.0601,
          55.2095,
        );

        if (distToDubai > 50000) {
          return _fallbackPosition();
        }
        return position;
      }

      return _fallbackPosition();
    } catch (_) {
      return _fallbackPosition();
    }
  }

  static Position _fallbackPosition() {
    return Position(
      longitude: 55.2708,
      latitude: 25.2048,
      timestamp: DateTime.now(),
      accuracy: 5.0,
      altitude: 0.0,
      altitudeAccuracy: 0.0,
      heading: 0.0,
      headingAccuracy: 0.0,
      speed: 0.0,
      speedAccuracy: 0.0,
    );
  }
}
