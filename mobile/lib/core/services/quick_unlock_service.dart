import 'package:local_auth/local_auth.dart';
import '../storage/secure_storage.dart';

/// Biometric/PIN "Quick Unlock" for an already-restored offline session.
///
/// This deliberately does NOT re-implement authentication — the session
/// (access token + cached employee profile) is already sitting in
/// [SecureStorageService] and already restores with zero network calls (see
/// AuthRepository.getCachedSession()). That session could only ever have been
/// created by a successful ONLINE login on THIS specific device in the first
/// place — the backend enforces one-device-per-employee binding at login
/// time — so "a valid cached session exists" already IS the proof that this
/// is the employee's registered, activated device. Quick Unlock's only job is
/// to gate *local* access to that already-proven session using the phone's
/// own secure hardware (Android Keystore / iOS Secure Enclave via
/// BiometricPrompt/Face ID/device PIN), so a lost or stolen unlocked phone
/// can't be used to view attendance data or punch in as someone else.
///
/// Mandatory whenever the device is capable — there's no user-facing opt-out.
/// A device certificate proves the phone itself was vetted; leaving the lock
/// optional would let someone disable it and get straight into a recovered
/// offline session with no local authentication at all.
class QuickUnlockService {
  static final QuickUnlockService _instance = QuickUnlockService._internal();
  factory QuickUnlockService() => _instance;
  QuickUnlockService._internal();

  final LocalAuthentication _localAuth = LocalAuthentication();

  /// Whether this device's hardware + OS actually supports biometrics or a
  /// device PIN/pattern/password that `local_auth` can use.
  Future<bool> isDeviceCapable() async {
    try {
      final supported = await _localAuth.isDeviceSupported();
      final canCheck = await _localAuth.canCheckBiometrics;
      return supported || canCheck;
    } catch (_) {
      return false;
    }
  }

  /// True if there's a live session to protect — a normal cached login.
  Future<bool> hasCachedSession() async {
    final token = await SecureStorageService().getToken();
    return token != null && token.isNotEmpty;
  }

  /// True if there's no live session, but a still-valid device trust
  /// certificate exists (e.g. the user logged out, or the app was killed
  /// after logout) — see AuthRepository.getOfflineRecoverableSession(). Read
  /// directly from secure storage here (rather than depending on
  /// AuthRepository) to keep this service a small, self-contained local check.
  Future<bool> hasRecoverableCertificate() async {
    final storage = SecureStorageService();
    final cert = await storage.getDeviceCertificate();
    final expiresAtStr = await storage.getCertExpiresAt();
    if (cert == null || expiresAtStr == null) return false;

    final expiresAt = DateTime.tryParse(expiresAtStr);
    if (expiresAt == null) return false;
    return DateTime.now().isBefore(expiresAt);
  }

  /// Whether the lock screen should be shown right now — covers both a
  /// normal restored session AND offline certificate recovery, since both
  /// need the same biometric gate before revealing anything. Mandatory (no
  /// user preference to check) whenever the device can actually authenticate
  /// — on a device with no biometric/PIN/pattern enrolled there is nothing to
  /// gate with, so it's skipped rather than locking the user out entirely.
  Future<bool> shouldLock() async {
    if (!await isDeviceCapable()) return false;
    if (await hasCachedSession()) return true;
    return hasRecoverableCertificate();
  }

  /// Prompts the OS biometric/PIN UI. Returns true only on genuine success —
  /// any error (no hardware, user cancelled, too many attempts, biometrics
  /// not enrolled) returns false so the caller can offer a fallback.
  Future<bool> authenticate() async {
    try {
      return await _localAuth.authenticate(
        localizedReason: 'Unlock to access your attendance app',
        options: const AuthenticationOptions(
          stickyAuth: true,
          biometricOnly: false, // allow device PIN/pattern/password as a fallback
        ),
      );
    } catch (_) {
      return false;
    }
  }
}
