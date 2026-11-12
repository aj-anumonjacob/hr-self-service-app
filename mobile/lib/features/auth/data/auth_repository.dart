import '../domain/employee_model.dart';
import 'auth_api.dart';
import '../../../core/storage/secure_storage.dart';
import '../../../core/utils/device_info.dart';
import '../../../core/utils/location_util.dart';

class AuthRepository {
  final AuthApi _api = AuthApi();
  final SecureStorageService _storage = SecureStorageService();
  final DeviceInfoUtil _deviceInfo = DeviceInfoUtil();

  Future<EmployeeModel?> getCachedSession() async {
    final token = await _storage.getToken();
    final name = await _storage.getEmployeeName();
    final uuid = await _storage.getEmployeeUuid();
    final email = await _storage.getEmployeeEmail();

    if (token != null && name != null && uuid != null && email != null) {
      return EmployeeModel(
        uuid: uuid,
        employeeNumber: '', // Cached number not critical for UI
        fullName: name,
        email: email,
      );
    }
    return null;
  }

  /// Falls back to the device trust certificate's cached profile snapshot
  /// when there's no live session (e.g. after logout) — this is what lets a
  /// previously-activated device recover access via Quick Unlock's biometric
  /// gate instead of the network-dependent login form. Returns null if no
  /// certificate was ever issued, or if its locally-stored expiry has passed
  /// (a final online redemption before that date would have refreshed it).
  Future<EmployeeModel?> getOfflineRecoverableSession() async {
    final cert = await _storage.getDeviceCertificate();
    final expiresAtStr = await _storage.getCertExpiresAt();
    final uuid = await _storage.getCertEmployeeUuid();
    final name = await _storage.getCertEmployeeName();
    final email = await _storage.getCertEmployeeEmail();

    if (cert == null || expiresAtStr == null || uuid == null || name == null || email == null) {
      return null;
    }

    final expiresAt = DateTime.tryParse(expiresAtStr);
    if (expiresAt == null || DateTime.now().isAfter(expiresAt)) {
      return null;
    }

    return EmployeeModel(uuid: uuid, employeeNumber: '', fullName: name, email: email);
  }

  /// Silently trades the stored certificate for a fresh access/refresh token
  /// — only meaningful (and only ever called) once connectivity is back.
  /// Never throws: any failure (network or server rejection) just returns
  /// false, leaving the cached certificate untouched so a transient blip
  /// doesn't destroy offline-recovery ability. The one exception the caller
  /// should watch for is a DEVICE_REVOKED/CERTIFICATE_INVALID rejection,
  /// which means the certificate is genuinely dead and forgetDevice() should
  /// be called — callers can detect this via the thrown DioException's error
  /// code if they need to distinguish it from a plain network failure.
  Future<bool> redeemCertificate() async {
    try {
      final cert = await _storage.getDeviceCertificate();
      if (cert == null) return false;

      final deviceInfo = await _deviceInfo.getDeviceInfo();
      final deviceId = deviceInfo['device_id'] ?? 'unknown_device';

      final response = await _api.redeemCertificate(certificate: cert, platformDeviceId: deviceId);
      final data = response.data['data'];
      final token = data['token'] as String;
      final refreshToken = data['refresh_token'] as String;
      final empJson = data['employee'] as Map<String, dynamic>;
      final employee = EmployeeModel.fromJson(empJson);

      await _storage.saveToken(token);
      await _storage.saveRefreshToken(refreshToken);
      await _storage.saveEmployee(uuid: employee.uuid, name: employee.fullName, email: employee.email);
      await _saveCertificateFromResponse(data, employee);

      return true;
    } catch (_) {
      return false;
    }
  }

  Future<void> _saveCertificateFromResponse(Map<String, dynamic> data, EmployeeModel employee) async {
    final certificate = data['certificate'] as String?;
    final expiresAt = data['certificate_expires_at'] as String?;
    if (certificate == null || expiresAt == null) return;

    await _storage.saveDeviceCertificate(
      certificate: certificate,
      expiresAt: expiresAt,
      employeeUuid: employee.uuid,
      employeeName: employee.fullName,
      employeeEmail: employee.email,
    );
  }

  Future<EmployeeModel> login({
    required String login,
    required String password,
  }) async {
    final deviceInfo = await _deviceInfo.getDeviceInfo();
    final deviceId = deviceInfo['device_id'] ?? 'unknown_device';
    final position = await LocationUtil.captureSilently();

    final response = await _api.login(
      login: login,
      password: password,
      platformDeviceId: deviceId,
      device: deviceInfo,
      latitude: position?.latitude,
      longitude: position?.longitude,
      accuracy: position?.accuracy,
    );

    final data = response.data['data'];
    final token = data['token'] as String;
    final refreshToken = data['refresh_token'] as String;
    final empJson = data['employee'] as Map<String, dynamic>;

    final employee = EmployeeModel.fromJson(empJson);

    // Save tokens and employee profile securely
    await _storage.saveToken(token);
    await _storage.saveRefreshToken(refreshToken);
    await _storage.saveEmployee(
      uuid: employee.uuid,
      name: employee.fullName,
      email: employee.email,
    );
    await _saveCertificateFromResponse(data, employee);

    return employee;
  }

  Future<Map<String, dynamic>> requestActivationCode(String employeeNumber) async {
    final deviceInfo = await _deviceInfo.getDeviceInfo();
    final deviceId = deviceInfo['device_id'] ?? 'unknown_device';

    final response = await _api.requestActivationCode(
      employeeNumber: employeeNumber,
      platformDeviceId: deviceId,
    );

    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> setActivationEmail({
    required String employeeNumber,
    required String employeeCode,
    required String email,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    final deviceInfo = await _deviceInfo.getDeviceInfo();

    final response = await _api.setActivationEmail(
      employeeNumber: employeeNumber,
      employeeCode: employeeCode,
      email: email,
      device: deviceInfo,
      latitude: latitude,
      longitude: longitude,
      accuracy: accuracy,
    );

    return response.data as Map<String, dynamic>;
  }

  Future<EmployeeModel> activate({
    required String employeeNumber,
    required String activationCode,
    required String password,
    required String passwordConfirmation,
    required String deviceName,
  }) async {
    final deviceInfo = await _deviceInfo.getDeviceInfo();
    final deviceId = deviceInfo['device_id'] ?? 'unknown_device';

    final response = await _api.activate(
      employeeNumber: employeeNumber,
      activationCode: activationCode,
      password: password,
      passwordConfirmation: passwordConfirmation,
      deviceName: deviceName,
      platformDeviceId: deviceId,
      platform: deviceInfo['platform'],
      osVersion: deviceInfo['os_version'],
      appVersion: deviceInfo['app_version'],
    );

    final data = response.data['data'];
    final token = data['token'] as String;
    final refreshToken = data['refresh_token'] as String;
    final empJson = data['employee'] as Map<String, dynamic>;

    final employee = EmployeeModel.fromJson(empJson);

    // Save credentials
    await _storage.saveToken(token);
    await _storage.saveRefreshToken(refreshToken);
    await _storage.saveEmployee(
      uuid: employee.uuid,
      name: employee.fullName,
      email: employee.email,
    );
    await _saveCertificateFromResponse(data, employee);

    return employee;
  }

  Future<void> logout() async {
    try {
      await _api.logout();
    } catch (_) {
      // Allow logout to proceed offline or if API fails
    } finally {
      await _storage.clear();
    }
  }

  Future<Map<String, dynamic>> requestPasswordResetOtp(String employeeCode, {String? email}) async {
    final response = await _api.requestPasswordResetOtp(employeeCode, email: email);
    return response.data as Map<String, dynamic>;
  }

  Future<String> verifyPasswordResetOtp(String employeeCode, String otp) async {
    final response = await _api.verifyPasswordResetOtp(employeeCode, otp);
    final data = response.data;
    return data['reset_token'] as String;
  }

  Future<void> resetPassword({
    required String employeeCode,
    required String resetToken,
    required String password,
    required String passwordConfirmation,
  }) async {
    await _api.resetPassword(
      employeeCode: employeeCode,
      resetToken: resetToken,
      password: password,
      passwordConfirmation: passwordConfirmation,
    );
  }
}
