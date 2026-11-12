import 'dart:io';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:package_info_plus/package_info_plus.dart';

class DeviceInfoUtil {
  static final DeviceInfoUtil _instance = DeviceInfoUtil._internal();
  factory DeviceInfoUtil() => _instance;
  DeviceInfoUtil._internal();

  final DeviceInfoPlugin _deviceInfo = DeviceInfoPlugin();
  late final PackageInfo _packageInfo;

  Future<void> init() async {
    _packageInfo = await PackageInfo.fromPlatform();
  }

  String get appVersion => '${_packageInfo.version}+${_packageInfo.buildNumber}';

  Future<Map<String, String>> getDeviceInfo() async {
    final Map<String, String> info = {
      'app_version': appVersion,
    };

    if (Platform.isAndroid) {
      final androidInfo = await _deviceInfo.androidInfo;
      info['device_id'] = androidInfo.id; // Unique hardware ID or fingerprint
      info['device_name'] = '${androidInfo.brand} ${androidInfo.model}';
      info['device_model'] = androidInfo.model;
      info['os_version'] = 'Android ${androidInfo.version.release} (SDK ${androidInfo.version.sdkInt})';
    } else if (Platform.isIOS) {
      final iosInfo = await _deviceInfo.iosInfo;
      info['device_id'] = iosInfo.identifierForVendor ?? 'unknown_ios_device';
      info['device_name'] = iosInfo.name;
      info['device_model'] = iosInfo.model;
      info['os_version'] = 'iOS ${iosInfo.systemVersion}';
    } else {
      info['device_id'] = 'unknown_platform';
      info['device_name'] = Platform.operatingSystem;
      info['device_model'] = Platform.operatingSystem;
      info['os_version'] = Platform.operatingSystemVersion;
    }

    return info;
  }
}
