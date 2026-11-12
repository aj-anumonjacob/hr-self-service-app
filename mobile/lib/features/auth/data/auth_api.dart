import 'package:dio/dio.dart';
import '../../../core/network/api_client.dart';

class AuthApi {
  Future<Response> login({
    required String login,
    required String password,
    required String platformDeviceId,
    Map<String, String?>? device,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    return await apiClient.dio.post('/auth/login', data: {
      'login': login,
      'password': password,
      'platform_device_id': platformDeviceId,
      if (device != null) 'device_name': device['device_name'],
      if (device != null) 'device_model': device['device_model'],
      if (device != null) 'os_version': device['os_version'],
      if (device != null) 'app_version': device['app_version'],
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      if (accuracy != null) 'accuracy': accuracy,
    });
  }

  Future<Response> requestActivationCode({
    required String employeeNumber,
    String? platformDeviceId,
  }) async {
    return await apiClient.dio.post('/auth/request-activation-code', data: {
      'employee_number': employeeNumber,
      if (platformDeviceId != null) 'platform_device_id': platformDeviceId,
    });
  }

  Future<Response> setActivationEmail({
    required String employeeNumber,
    required String employeeCode,
    required String email,
    required Map<String, String?> device,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    return await apiClient.dio.post('/auth/request-activation-code/set-email', data: {
      'employee_number': employeeNumber,
      'employee_code': employeeCode,
      'email': email,
      'platform_device_id': device['device_id'],
      'device_name': device['device_name'],
      'device_model': device['device_model'],
      'platform': device['platform'],
      'os_version': device['os_version'],
      'app_version': device['app_version'],
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      if (accuracy != null) 'accuracy': accuracy,
    });
  }

  Future<Response> activate({
    required String employeeNumber,
    required String activationCode,
    required String password,
    required String passwordConfirmation,
    required String deviceName,
    required String platformDeviceId,
    String? platform,
    String? osVersion,
    String? appVersion,
    String? publicKey,
  }) async {
    return await apiClient.dio.post('/auth/activate', data: {
      'employee_number': employeeNumber,
      'activation_code': activationCode,
      'password': password,
      'password_confirmation': passwordConfirmation,
      'device_name': deviceName,
      'platform_device_id': platformDeviceId,
      if (platform != null) 'platform': platform,
      if (osVersion != null) 'os_version': osVersion,
      if (appVersion != null) 'app_version': appVersion,
      if (publicKey != null) 'public_key': publicKey,
    });
  }

  Future<Response> logout() async {
    return await apiClient.dio.post('/auth/logout');
  }

  /// Redeems a long-lived device trust certificate for a fresh access +
  /// refresh token, without a password — see DeviceCertificateService on the
  /// backend. Only ever called when connectivity is available.
  Future<Response> redeemCertificate({
    required String certificate,
    required String platformDeviceId,
  }) async {
    return await apiClient.dio.post('/auth/redeem-certificate', data: {
      'certificate': certificate,
      'platform_device_id': platformDeviceId,
    });
  }

  Future<Response> requestPasswordResetOtp(String employeeCode, {String? email}) async {
    final data = <String, dynamic>{'employee_code': employeeCode};
    if (email != null && email.trim().isNotEmpty) {
      data['email'] = email.trim();
    }
    return await apiClient.dio.post('/auth/forgot-password/request-otp', data: data);
  }

  Future<Response> verifyPasswordResetOtp(String employeeCode, String otp) async {
    return await apiClient.dio.post('/auth/forgot-password/verify-otp', data: {
      'employee_code': employeeCode,
      'otp': otp,
    });
  }

  Future<Response> resetPassword({
    required String employeeCode,
    required String resetToken,
    required String password,
    required String passwordConfirmation,
  }) async {
    return await apiClient.dio.post('/auth/forgot-password/reset', data: {
      'employee_code': employeeCode,
      'reset_token': resetToken,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
  }
}
