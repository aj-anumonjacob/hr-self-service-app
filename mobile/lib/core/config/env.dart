import 'dart:io';

class Env {
  final String baseUrl;
  final String environmentName;

  Env._({required this.baseUrl, required this.environmentName});

  static late final Env instance;

  static void init({required String baseUrl, required String environmentName}) {
    instance = Env._(
      baseUrl: baseUrl,
      environmentName: environmentName,
    );
  }

  static String get defaultDevBaseUrl {
    const envUrl = String.fromEnvironment('API_BASE_URL');
    if (envUrl.isNotEmpty) return envUrl;

    if (Platform.isAndroid) {
      return 'http://127.0.0.1:8000/v1';
    }

    return 'http://127.0.0.1:8000/v1';
  }

  static bool get isProduction => instance.environmentName.toLowerCase() == 'production';
  static bool get isStaging => instance.environmentName.toLowerCase() == 'staging';
  static bool get isDevelopment => instance.environmentName.toLowerCase() == 'development';
}
