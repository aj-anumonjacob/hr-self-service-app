import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/config/theme.dart';

/// Shown once, right after an employee's first successful login or device
/// activation, so the OS permission dialogs are triggered explicitly (with a
/// clear reason) instead of silently, mid-request, where they are easy to
/// miss or dismiss without granting.
class PermissionsScreen extends StatefulWidget {
  final String destination;

  const PermissionsScreen({super.key, required this.destination});

  static const _primedKey = 'permissions_primed';

  static Future<bool> alreadyPrimed() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_primedKey) ?? false;
  }

  static Future<void> markPrimed() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_primedKey, true);
  }

  @override
  State<PermissionsScreen> createState() => _PermissionsScreenState();
}

class _PermissionsScreenState extends State<PermissionsScreen> {
  bool _requesting = false;
  String _locationStatus = 'Not requested yet';

  Future<void> _requestAllPermissions() async {
    setState(() {
      _requesting = true;
      _locationStatus = 'Requesting...';
    });

    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!mounted) return;
      if (!serviceEnabled) {
        setState(() => _locationStatus = 'Location services are turned off on this device.');
      } else {
        LocationPermission permission = await Geolocator.checkPermission();
        if (permission == LocationPermission.denied) {
          permission = await Geolocator.requestPermission();
        }
        if (!mounted) return;

        setState(() {
          _locationStatus = switch (permission) {
            LocationPermission.always || LocationPermission.whileInUse => 'Location access granted.',
            LocationPermission.deniedForever => 'Location access permanently denied. You can enable it later from device Settings.',
            _ => 'Location access denied.',
          };
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() => _locationStatus = 'Could not request location permission.');
      }
    }

    await PermissionsScreen.markPrimed();

    if (!mounted) return;
    setState(() => _requesting = false);

    // Briefly show the result, then proceed automatically — the permission
    // dialog itself already required an explicit tap from the user.
    await Future.delayed(const Duration(milliseconds: 700));
    _continue();
  }

  void _continue() {
    if (mounted) {
      context.go(widget.destination);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 28.0, vertical: 32.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Spacer(),
              Center(
                child: Container(
                  padding: const EdgeInsets.all(22),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: [
                        AppTheme.champagneGold.withValues(alpha: 0.15),
                        AppTheme.primaryTeal.withValues(alpha: 0.1),
                      ],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    shape: BoxShape.circle,
                    border: Border.all(color: AppTheme.champagneGold.withValues(alpha: 0.4), width: 2),
                  ),
                  child: const Icon(Icons.location_on_rounded, size: 52, color: AppTheme.primaryTeal),
                ),
              ),
              const SizedBox(height: 24),
              const Text(
                'Enable Location Access',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
              ),
              const SizedBox(height: 10),
              const Text(
                'This app uses your location to verify attendance check-in/check-out and to keep your account secure. '
                'We\'ll ask for this once now so you won\'t be interrupted later.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 14, color: Color(0xFF64748B), height: 1.5),
              ),
              const SizedBox(height: 22),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFE2E8F0)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.my_location_rounded, size: 18, color: AppTheme.primaryTeal),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        _locationStatus,
                        style: const TextStyle(fontSize: 12.5, color: Color(0xFF475569), fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
              ),
              const Spacer(),
              SizedBox(
                height: 52,
                child: ElevatedButton(
                  onPressed: _requesting ? null : _requestAllPermissions,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.primaryTeal,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                  child: _requesting
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5))
                      : const Text('Allow Location Access', style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold)),
                ),
              ),
              const SizedBox(height: 12),
              TextButton(
                onPressed: _requesting ? null : _continue,
                child: const Text(
                  'Continue',
                  style: TextStyle(color: AppTheme.primaryTeal, fontSize: 14, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
