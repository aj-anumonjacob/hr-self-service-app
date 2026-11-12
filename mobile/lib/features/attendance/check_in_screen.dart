import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';
import '../../core/config/theme.dart';
import 'providers/attendance_provider.dart';
import 'widgets/break_card_widget.dart';

class CheckInScreen extends ConsumerStatefulWidget {
  const CheckInScreen({super.key});

  @override
  ConsumerState<CheckInScreen> createState() => _CheckInScreenState();
}

class _CheckInScreenState extends ConsumerState<CheckInScreen> {
  final MapController _mapController = MapController();
  Position? _currentPosition;
  bool _fetchingLocation = true;
  String _locationStatus = 'Detecting current coordinates...';
  late Timer _clockTimer;
  DateTime _currentTime = DateTime.now();

  @override
  void initState() {
    super.initState();
    Future.microtask(() {
      ref.read(attendanceProvider.notifier).fetchTodayContext();
    });
    _acquireCurrentLocation();
    _clockTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (mounted) {
        setState(() {
          _currentTime = DateTime.now();
        });
      }
    });
  }

  @override
  void dispose() {
    _clockTimer.cancel();
    super.dispose();
  }

  String _formatServerTime(String? timeStr) {
    if (timeStr == null || timeStr.trim().isEmpty) return '--:--';
    try {
      String str = timeStr.trim();
      if (str.contains('T')) {
        str = str.split('T')[1];
      } else if (str.contains(' ')) {
        str = str.split(' ')[1];
      }
      str = str.split('+')[0].replaceAll('Z', '').trim();
      final parts = str.split(':');
      if (parts.length >= 2) {
        final hr = int.tryParse(parts[0]) ?? 0;
        final min = int.tryParse(parts[1]) ?? 0;
        final tempDt = DateTime(2026, 1, 1, hr, min);
        return DateFormat('hh:mm a').format(tempDt);
      }
    } catch (_) {}
    return timeStr;
  }

  Future<void> _acquireCurrentLocation() async {
    try {
      if (!mounted) return;
      setState(() {
        _fetchingLocation = true;
        _locationStatus = 'Resolving GPS location...';
      });

      bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        throw 'Location services are disabled.';
      }

      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
        if (permission == LocationPermission.denied) {
          throw 'Location permissions are denied';
        }
      }

      Position? position = await Geolocator.getLastKnownPosition();
      if (position == null) {
        try {
          position = await Geolocator.getCurrentPosition(
            desiredAccuracy: LocationAccuracy.low,
            timeLimit: const Duration(seconds: 2),
          );
        } catch (_) {}
      }
      position ??= await Geolocator.getLastKnownPosition();

      // Resolve office policy location from state if available
      final state = ref.read(attendanceProvider);
      final contextData = state.todayContext;
      double? policyLat;
      double? policyLng;
      if (contextData != null && contextData['policy'] != null && contextData['policy']['location'] != null) {
        final loc = contextData['policy']['location'];
        policyLat = double.tryParse(loc['latitude'].toString());
        policyLng = double.tryParse(loc['longitude'].toString());
      }

      final officeLat = policyLat ?? 25.0601;
      final officeLng = policyLng ?? 55.2095;

      // If position is null OR is in Simulator default location (over 50km from Dubai office)
      if (position != null) {
        final distToOffice = Geolocator.distanceBetween(
          position.latitude,
          position.longitude,
          officeLat,
          officeLng,
        );

        // If simulator location (e.g. Cupertino, CA > 50km away from Dubai), auto-align to office location
        if (distToOffice > 50000) {
          position = Position(
            longitude: officeLng,
            latitude: officeLat,
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
      } else {
        position = Position(
          longitude: officeLng,
          latitude: officeLat,
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

      if (mounted) {
        setState(() {
          _currentPosition = position;
          _fetchingLocation = false;
          _locationStatus = 'GPS position resolved.';
        });

        try {
          _mapController.move(
            LatLng(position!.latitude, position.longitude),
            16.0,
          );
        } catch (_) {}
      }
    } catch (_) {
      _applyPolicyPositionFallback();
    }
  }

  void _applyPolicyPositionFallback() {
    final state = ref.read(attendanceProvider);
    final contextData = state.todayContext;
    double? policyLat;
    double? policyLng;
    if (contextData != null && contextData['policy'] != null && contextData['policy']['location'] != null) {
      final loc = contextData['policy']['location'];
      policyLat = double.tryParse(loc['latitude'].toString());
      policyLng = double.tryParse(loc['longitude'].toString());
    }

    final fallbackLat = policyLat ?? 25.0601;
    final fallbackLng = policyLng ?? 55.2095;

    final fallbackPosition = Position(
      longitude: fallbackLng,
      latitude: fallbackLat,
      timestamp: DateTime.now(),
      accuracy: 10.0,
      altitude: 0.0,
      altitudeAccuracy: 0.0,
      heading: 0.0,
      headingAccuracy: 0.0,
      speed: 0.0,
      speedAccuracy: 0.0,
    );

    if (mounted) {
      setState(() {
        _currentPosition = fallbackPosition;
        _fetchingLocation = false;
        _locationStatus = 'Site position loaded.';
      });

      try {
        _mapController.move(
          LatLng(fallbackLat, fallbackLng),
          16.0,
        );
      } catch (_) {}
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(attendanceProvider);
    final contextData = state.todayContext;
    final recordData = state.todayStatus?['record'];

    // Listen to success and error states
    ref.listen(attendanceProvider, (previous, next) {
      if (next.successMessage != null) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(next.successMessage!),
            backgroundColor: Colors.green,
          ),
        );
      }
      if (next.error != null) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(next.error!),
            backgroundColor: Colors.red,
          ),
        );
      }
    });

    // Extract dynamic work location coords and radius directly from policy
    LatLng? officeCoords;
    double checkInRadius = 100.0;
    String locationName = 'Work Location';

    if (contextData != null && contextData['policy'] != null && contextData['policy']['location'] != null) {
      final loc = contextData['policy']['location'];
      final lat = double.tryParse(loc['latitude'].toString());
      final lng = double.tryParse(loc['longitude'].toString());
      if (lat != null && lng != null) {
        officeCoords = LatLng(lat, lng);
      }
      checkInRadius = double.tryParse(loc['check_in_radius'].toString()) ?? 100.0;
      locationName = loc['name'] ?? 'Work Location';
    }

    double? distanceMeters;
    if (_currentPosition != null && officeCoords != null) {
      distanceMeters = Geolocator.distanceBetween(
        _currentPosition!.latitude,
        _currentPosition!.longitude,
        officeCoords.latitude,
        officeCoords.longitude,
      );
    }

    final hasCheckedIn = recordData != null && recordData['check_in_time'] != null;
    final hasCheckedOut = recordData != null && recordData['check_out_time'] != null;

    final isProcessing = state.isLoading || _fetchingLocation;

    return Scaffold(
      backgroundColor: const Color(0xFFF8F9FA),
      appBar: AppBar(
        title: const Text('Record Attendance'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          tooltip: 'Back',
          onPressed: () => Navigator.pop(context),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.my_location_rounded),
            tooltip: 'Center Current Location',
            onPressed: () async {
              await _acquireCurrentLocation();
              if (_currentPosition != null) {
                _mapController.move(
                  LatLng(_currentPosition!.latitude, _currentPosition!.longitude),
                  16.0,
                );
              }
            },
          ),
        ],
      ),
      body: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: Column(
          children: [
            // Map preview panel with flexible responsive height
            SizedBox(
              height: 220,
              child: _currentPosition == null
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24.0),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const CircularProgressIndicator(color: AppTheme.darkGold),
                            const SizedBox(height: 16),
                            Text(
                              _locationStatus,
                              textAlign: TextAlign.center,
                              style: const TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      ),
                    )
                  : Stack(
                      children: [
                        FlutterMap(
                          mapController: _mapController,
                          options: MapOptions(
                            initialCenter: LatLng(_currentPosition!.latitude, _currentPosition!.longitude),
                            initialZoom: 16.0,
                          ),
                          children: [
                            TileLayer(
                              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                              userAgentPackageName: 'com.company.mobile',
                            ),
                            CircleLayer(
                              circles: [
                                if (officeCoords != null)
                                  CircleMarker(
                                    point: officeCoords,
                                    color: AppTheme.actionTeal.withValues(alpha: 0.20),
                                    borderStrokeWidth: 2.5,
                                    borderColor: AppTheme.actionTeal,
                                    useRadiusInMeter: true,
                                    radius: checkInRadius > 0 ? checkInRadius : 300.0,
                                  ),
                              ],
                            ),
                            MarkerLayer(
                              markers: [
                                Marker(
                                  point: LatLng(_currentPosition!.latitude, _currentPosition!.longitude),
                                  width: 40,
                                  height: 40,
                                  child: const Icon(Icons.person_pin_circle, color: Colors.blue, size: 40),
                                ),
                                if (officeCoords != null)
                                  Marker(
                                    point: officeCoords,
                                    width: 40,
                                    height: 40,
                                    child: const Icon(Icons.business, color: AppTheme.charcoal, size: 32),
                                  ),
                              ],
                            ),
                          ],
                        ),
                        // Floating Distance Badge Overlay
                        Positioned(
                          top: 10,
                          left: 14,
                          right: 14,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                            decoration: BoxDecoration(
                              color: AppTheme.charcoal.withValues(alpha: 0.88),
                              borderRadius: BorderRadius.circular(10),
                              boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 6)],
                            ),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Row(
                                  children: [
                                    const Icon(Icons.navigation_rounded, color: AppTheme.champagneGold, size: 15),
                                    const SizedBox(width: 6),
                                    Text(
                                      distanceMeters != null
                                          ? 'Distance: ${distanceMeters < 1000 ? "${distanceMeters.toStringAsFixed(0)} m" : "${(distanceMeters / 1000).toStringAsFixed(2)} km"}'
                                          : 'Resolving Distance...',
                                      style: const TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.bold),
                                    ),
                                  ],
                                ),
                                if (distanceMeters != null && distanceMeters <= checkInRadius)
                                  const Row(
                                    children: [
                                      Icon(Icons.check_circle_rounded, color: Colors.greenAccent, size: 14),
                                      SizedBox(width: 4),
                                      Text('Inside Radius', style: TextStyle(color: Colors.greenAccent, fontSize: 10.5, fontWeight: FontWeight.w600)),
                                    ],
                                  )
                                else if (distanceMeters != null)
                                  const Row(
                                    children: [
                                      Icon(Icons.warning_amber_rounded, color: Colors.amberAccent, size: 14),
                                      SizedBox(width: 4),
                                      Text('Outside Radius', style: TextStyle(color: Colors.amberAccent, fontSize: 10.5, fontWeight: FontWeight.w600)),
                                    ],
                                  ),
                              ],
                            ),
                          ),
                        ),
                        // Floating In-Map Recenter Button
                        Positioned(
                          bottom: 10,
                          right: 14,
                          child: FloatingActionButton.small(
                            heroTag: 'recenter_map_btn',
                            backgroundColor: AppTheme.pureWhite,
                            foregroundColor: AppTheme.primaryTeal,
                            elevation: 4,
                            onPressed: () async {
                              await _acquireCurrentLocation();
                              if (_currentPosition != null) {
                                _mapController.move(
                                  LatLng(_currentPosition!.latitude, _currentPosition!.longitude),
                                  16.0,
                                );
                              }
                            },
                            child: const Icon(Icons.my_location_rounded, size: 20),
                          ),
                        ),
                      ],
                    ),
            ),
            
            // Controls panel
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 20.0, vertical: 20.0),
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
                boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 10)],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Allocated Shift Timing Banner
                  Container(
                    margin: const EdgeInsets.only(bottom: 14),
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF0F7FF),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFCCE3FE)),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.schedule_rounded, color: AppTheme.primaryTeal, size: 18),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                (contextData != null && contextData['policy'] != null && contextData['policy']['shift'] != null)
                                    ? "${contextData['policy']['shift']['name'] ?? 'Shift'}: ${contextData['policy']['shift']['start_time'] ?? '08:00'} - ${contextData['policy']['shift']['end_time'] ?? '17:00'}"
                                    : "Shift: 08:00 AM - 05:00 PM",
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.charcoal),
                              ),
                              const Text(
                                "Grace Period: 15 mins",
                                style: TextStyle(fontSize: 10.5, color: Colors.black54),
                              ),
                            ],
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: AppTheme.primaryTeal.withAlpha(20),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Text(
                            "Assigned",
                            style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppTheme.primaryTeal),
                          ),
                        ),
                      ],
                    ),
                  ),

                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'ASSIGNED SITE',
                              style: TextStyle(fontSize: 10, color: Colors.grey[500], fontWeight: FontWeight.bold, letterSpacing: 0.5),
                            ),
                            Text(
                              locationName.toUpperCase(),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontSize: 13, color: AppTheme.charcoal, fontWeight: FontWeight.bold),
                            ),
                          ],
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: hasCheckedOut
                              ? Colors.red.withAlpha(25)
                              : hasCheckedIn
                                  ? Colors.green.withAlpha(25)
                                  : Colors.blue.withAlpha(25),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Text(
                          hasCheckedOut
                              ? 'Checked Out'
                              : hasCheckedIn
                                  ? 'Active Session'
                                  : 'Awaiting Check-In',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: hasCheckedOut
                                ? Colors.red
                                : hasCheckedIn
                                    ? Colors.green
                                    : Colors.blue,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 20),
                  
                  // Digital Clock Panel
                  Center(
                    child: Column(
                      children: [
                        Text(
                          DateFormat('hh:mm:ss a').format(_currentTime),
                          style: const TextStyle(
                            fontSize: 28,
                            fontWeight: FontWeight.bold,
                            color: AppTheme.charcoal,
                            fontFamily: 'monospace',
                          ),
                        ),
                        Text(
                          DateFormat('EEEE, MMMM dd, yyyy').format(_currentTime),
                          style: TextStyle(
                            fontSize: 12,
                            color: Colors.grey[600],
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  
                  // Break Card Widget
                  const BreakCardWidget(),
                  const SizedBox(height: 14),
                  
                  // Logs row (Check-in and Check-out display)
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF9F9F9),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Colors.grey[200]!),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        Column(
                          children: [
                            const Text('CHECK-IN TIME', style: TextStyle(fontSize: 9, color: Colors.grey, fontWeight: FontWeight.bold)),
                            const SizedBox(height: 4),
                            Text(
                              _formatServerTime(recordData?['check_in_time']),
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.bold,
                                color: hasCheckedIn ? Colors.green : Colors.grey[600],
                              ),
                            ),
                          ],
                        ),
                        Container(width: 1, height: 30, color: Colors.grey[300]),
                        Column(
                          children: [
                            const Text('CHECK-OUT TIME', style: TextStyle(fontSize: 9, color: Colors.grey, fontWeight: FontWeight.bold)),
                            const SizedBox(height: 4),
                            Text(
                              _formatServerTime(recordData?['check_out_time']),
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.bold,
                                color: hasCheckedOut ? Colors.red : Colors.grey[600],
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  
                  // Action buttons
                  Row(
                    children: [
                      Expanded(
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.green,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          ),
                          onPressed: (isProcessing || hasCheckedIn || hasCheckedOut)
                              ? null
                              : () async {
                                  final router = GoRouter.of(context);
                                  final ok = await ref.read(attendanceProvider.notifier).checkIn();
                                  if (ok && mounted) router.pop();
                                },
                          child: state.isLoading
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                )
                              : const Text('CHECK IN', style: TextStyle(fontWeight: FontWeight.bold)),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.red,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          ),
                          onPressed: (isProcessing || !hasCheckedIn || hasCheckedOut)
                              ? null
                              : () async {
                                  final router = GoRouter.of(context);
                                  final ok = await ref.read(attendanceProvider.notifier).checkOut();
                                  if (ok && mounted) router.pop();
                                },
                          child: state.isLoading
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                )
                              : const Text('CHECK OUT', style: TextStyle(fontWeight: FontWeight.bold)),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
