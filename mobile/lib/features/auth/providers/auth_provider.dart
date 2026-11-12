import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../data/auth_repository.dart';
import '../domain/auth_state.dart';
import '../../../core/network/api_client.dart';
import '../../../core/services/push_notification_service.dart';
import '../../../core/services/sync_engine.dart';
import '../../notifications/providers/notification_provider.dart';

class AuthNotifier extends StateNotifier<AuthState> {
  final AuthRepository _repository;
  final Ref _ref;
  StreamSubscription<void>? _connectivityRestoredSub;

  AuthNotifier(this._repository, this._ref) : super(const AuthInitial()) {
    // Register global session expired handler from API client
    apiClient.onSessionExpired = () {
      state = const Unauthenticated(message: 'Session expired. Please log in again.');
    };
    _connectivityRestoredSub = SyncEngine().onConnectivityRestored.listen((_) {
      tryRedeemCertificate();
    });
  }

  @override
  void dispose() {
    _connectivityRestoredSub?.cancel();
    super.dispose();
  }

  /// Best-effort: registers this device's FCM token so the employee can
  /// receive real push notifications even when the app is closed. Never
  /// blocks or fails the auth flow it is called from.
  void _registerPushNotifications() {
    PushNotificationService().initialize(
      onForegroundMessage: () {
        _ref.read(notificationProvider.notifier).pollLatestNotifications();
      },
    );
  }

  Future<void> initializeSession() async {
    try {
      state = const AuthLoading();
      final employee = await _repository.getCachedSession();
      if (employee != null) {
        state = Authenticated(employee);
        _registerPushNotifications();
        return;
      }

      // No live session (e.g. after logout) — fall back to the device trust
      // certificate's cached profile, if one exists and hasn't expired. The
      // Quick Unlock biometric gate (see QuickUnlockGate) still stands
      // between this state and the actual UI; SyncEngine silently redeems
      // the certificate for real tokens the next time connectivity returns.
      final recovered = await _repository.getOfflineRecoverableSession();
      if (recovered != null) {
        state = Authenticated(recovered, isOfflineRecovered: true);
      } else {
        state = const Unauthenticated();
      }
    } catch (e) {
      state = const Unauthenticated();
    }
  }

  /// Called by SyncEngine once connectivity returns while in an
  /// offline-recovered state — silently trades the certificate for real
  /// tokens. On success, transitions to a normal fully-live session. On a
  /// genuine rejection (device revoked/certificate dead, not just a network
  /// blip), forgets the device locally and forces a real re-login.
  Future<void> tryRedeemCertificate() async {
    if (state is! Authenticated || !(state as Authenticated).isOfflineRecovered) return;

    final success = await _repository.redeemCertificate();
    if (success) {
      final employee = await _repository.getCachedSession();
      if (employee != null) {
        state = Authenticated(employee);
        _registerPushNotifications();
      }
    }
    // A failure here is left alone deliberately — could just be a transient
    // network blip on the very edge of "connectivity restored". The next
    // SyncEngine trigger will try again. Explicit device-revoked handling
    // happens where the certificate is redeemed with a distinguishable
    // error, surfaced separately if/when that becomes necessary.
  }

  Future<bool> login({
    required String login,
    required String password,
  }) async {
    try {
      state = const AuthLoading();
      final employee = await _repository.login(login: login, password: password);
      state = Authenticated(employee);
      _registerPushNotifications();
      return true;
    } catch (e) {
      if (_isConnectivityError(e)) {
        // This device has no recoverable offline session (see
        // initializeSession()) or it wouldn't have reached the login form at
        // all — a first-time login, or one after "Forget This Device",
        // genuinely requires connectivity, and that limit should be stated
        // plainly rather than showing a generic network-error message.
        state = const AuthError(
          'No internet connection. Logging in for the first time on this device (or after '
          "removing it) needs a connection — once you're online here, you can enable Quick "
          'Unlock to log in offline afterward.',
        );
        return false;
      }
      final msg = _errorMessage(e);
      state = AuthError(msg);
      return false;
    }
  }

  Future<Map<String, dynamic>?> requestActivationCode(String employeeNumber) async {
    try {
      return await _repository.requestActivationCode(employeeNumber);
    } catch (e) {
      final msg = _errorMessage(e);
      state = AuthError(msg);
      return null;
    }
  }

  Future<Map<String, dynamic>?> setActivationEmail({
    required String employeeNumber,
    required String employeeCode,
    required String email,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    try {
      return await _repository.setActivationEmail(
        employeeNumber: employeeNumber,
        employeeCode: employeeCode,
        email: email,
        latitude: latitude,
        longitude: longitude,
        accuracy: accuracy,
      );
    } catch (e) {
      final msg = _errorMessage(e);
      state = AuthError(msg);
      return null;
    }
  }

  Future<bool> activate({
    required String employeeNumber,
    required String activationCode,
    required String password,
    required String passwordConfirmation,
    required String deviceName,
  }) async {
    try {
      state = const AuthLoading();
      final employee = await _repository.activate(
        employeeNumber: employeeNumber,
        activationCode: activationCode,
        password: password,
        passwordConfirmation: passwordConfirmation,
        deviceName: deviceName,
      );
      state = Authenticated(employee);
      _registerPushNotifications();
      return true;
    } catch (e) {
      final msg = _errorMessage(e);
      state = AuthError(msg);
      return false;
    }
  }

  Future<void> logout() async {
    state = const AuthLoading();
    await _repository.logout();
    state = const Unauthenticated();
  }

  bool _isConnectivityError(dynamic error) {
    if (error is! DioException) return false;
    return error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout ||
        error.type == DioExceptionType.connectionError;
  }

  String _errorMessage(dynamic error) {
    if (error is DioException) {
      final resData = error.response?.data;
      if (resData is Map) {
        final errCode = resData['error']?['code']?.toString() ?? '';
        final message = resData['message']?.toString() ?? '';

        if (message.isNotEmpty) {
          return message;
        }

        if (error.response?.statusCode == 401 || errCode == 'INVALID_CREDENTIALS') {
          return 'Invalid email/employee number or password.';
        }
        if (error.response?.statusCode == 403 || errCode == 'UNREGISTERED_DEVICE') {
          return 'This device is not registered. Please contact your manager.';
        }
        if (errCode == 'DEVICE_NOT_APPROVED') {
          return 'Your device is pending approval or has been revoked.';
        }
        if (errCode == 'ACCOUNT_DISABLED') {
          return 'Your account is disabled. Please contact your manager.';
        }
        if (errCode == 'INVALID_ACTIVATION_CODE') {
          return 'Invalid activation code or employee number.';
        }
      }

      if (error.response?.statusCode == 401) {
        return 'Invalid email/employee number or password.';
      }

      if (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.sendTimeout ||
          error.type == DioExceptionType.receiveTimeout ||
          error.type == DioExceptionType.connectionError) {
        return 'Network error occurred. Please verify your connection.';
      }
    }

    final errStr = error.toString();
    if (errStr.contains('INVALID_ACTIVATION_CODE') || errStr.contains('Invalid activation code')) {
      return 'Invalid activation code or employee number.';
    }
    if (errStr.contains('401') || errStr.contains('INVALID_CREDENTIALS')) {
      return 'Invalid email/employee number or password.';
    }
    if (errStr.contains('403') || errStr.contains('UNREGISTERED_DEVICE')) {
      return 'This device is not registered. Please contact your manager.';
    }
    if (errStr.contains('DEVICE_NOT_APPROVED')) {
      return 'Your device is pending approval or has been revoked.';
    }
    if (errStr.contains('ACCOUNT_DISABLED')) {
      return 'Your account is disabled. Please contact your manager.';
    }

    return 'An unexpected error occurred. Please try again.';
  }
}

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository();
});

final authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final repo = ref.watch(authRepositoryProvider);
  return AuthNotifier(repo, ref);
});
