import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureStorageService {
  static const _tokenKey = 'auth_token';
  static const _refreshTokenKey = 'refresh_token';
  static const _employeeUuidKey = 'emp_uuid';
  static const _employeeNameKey = 'emp_name';
  static const _employeeEmailKey = 'emp_email';
  static const _rememberedLoginKey = 'remembered_login';
  static const _rememberedPasswordKey = 'remembered_password';

  // Device trust certificate — deliberately separate from the live-session
  // keys above so a normal logout (clear()) does NOT wipe these. Only an
  // explicit forgetDevice() call (or overwriting them with a fresh
  // certificate on the next successful online login) removes them.
  static const _deviceCertificateKey = 'device_certificate';
  static const _certExpiresAtKey = 'device_certificate_expires_at';
  static const _certEmployeeUuidKey = 'cert_emp_uuid';
  static const _certEmployeeNameKey = 'cert_emp_name';
  static const _certEmployeeEmailKey = 'cert_emp_email';

  static final SecureStorageService _instance = SecureStorageService._internal();
  factory SecureStorageService() => _instance;
  SecureStorageService._internal();

  final FlutterSecureStorage _storage = const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  Future<void> saveToken(String token) async {
    await _storage.write(key: _tokenKey, value: token);
  }

  Future<String?> getToken() async {
    return await _storage.read(key: _tokenKey);
  }

  Future<void> saveRefreshToken(String token) async {
    await _storage.write(key: _refreshTokenKey, value: token);
  }

  Future<String?> getRefreshToken() async {
    return await _storage.read(key: _refreshTokenKey);
  }

  Future<void> saveEmployee({
    required String uuid,
    required String name,
    required String email,
  }) async {
    await _storage.write(key: _employeeUuidKey, value: uuid);
    await _storage.write(key: _employeeNameKey, value: name);
    await _storage.write(key: _employeeEmailKey, value: email);
  }

  Future<String?> getEmployeeName() async {
    return await _storage.read(key: _employeeNameKey);
  }

  Future<String?> getEmployeeUuid() async {
    return await _storage.read(key: _employeeUuidKey);
  }

  Future<String?> getEmployeeEmail() async {
    return await _storage.read(key: _employeeEmailKey);
  }

  Future<void> clear() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _refreshTokenKey);
    await _storage.delete(key: _employeeUuidKey);
    await _storage.delete(key: _employeeNameKey);
    await _storage.delete(key: _employeeEmailKey);
    // Remembered login/password intentionally survive logout — "Remember Me"
    // is meant to persist across sign-out, not just across app restarts.
  }

  /// "Remember Me": persists the login credentials in encrypted storage
  /// (Keychain / EncryptedSharedPreferences) so the login form can be
  /// pre-filled next time, including after a logout.
  Future<void> saveRememberedCredentials(String login, String password) async {
    await _storage.write(key: _rememberedLoginKey, value: login);
    await _storage.write(key: _rememberedPasswordKey, value: password);
  }

  Future<String?> getRememberedLogin() async {
    return await _storage.read(key: _rememberedLoginKey);
  }

  Future<String?> getRememberedPassword() async {
    return await _storage.read(key: _rememberedPasswordKey);
  }

  Future<void> clearRememberedCredentials() async {
    await _storage.delete(key: _rememberedLoginKey);
    await _storage.delete(key: _rememberedPasswordKey);
  }

  /// Saves the device trust certificate + a minimal profile snapshot,
  /// alongside (but independent of) the live session — this is what
  /// survives [clear] (logout) and powers offline login recovery.
  Future<void> saveDeviceCertificate({
    required String certificate,
    required String expiresAt,
    required String employeeUuid,
    required String employeeName,
    required String employeeEmail,
  }) async {
    await _storage.write(key: _deviceCertificateKey, value: certificate);
    await _storage.write(key: _certExpiresAtKey, value: expiresAt);
    await _storage.write(key: _certEmployeeUuidKey, value: employeeUuid);
    await _storage.write(key: _certEmployeeNameKey, value: employeeName);
    await _storage.write(key: _certEmployeeEmailKey, value: employeeEmail);
  }

  Future<String?> getDeviceCertificate() async {
    return await _storage.read(key: _deviceCertificateKey);
  }

  Future<String?> getCertExpiresAt() async {
    return await _storage.read(key: _certExpiresAtKey);
  }

  Future<String?> getCertEmployeeUuid() async {
    return await _storage.read(key: _certEmployeeUuidKey);
  }

  Future<String?> getCertEmployeeName() async {
    return await _storage.read(key: _certEmployeeNameKey);
  }

  Future<String?> getCertEmployeeEmail() async {
    return await _storage.read(key: _certEmployeeEmailKey);
  }

  /// "Forget this device": explicitly removes offline-recovery ability —
  /// used when an employee is leaving, or handing off a shared device.
  /// Does NOT touch the live session keys (that's [clear]'s job).
  Future<void> forgetDevice() async {
    await _storage.delete(key: _deviceCertificateKey);
    await _storage.delete(key: _certExpiresAtKey);
    await _storage.delete(key: _certEmployeeUuidKey);
    await _storage.delete(key: _certEmployeeNameKey);
    await _storage.delete(key: _certEmployeeEmailKey);
  }
}
