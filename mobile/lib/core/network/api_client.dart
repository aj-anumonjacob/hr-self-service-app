import 'package:dio/dio.dart';
import '../config/env.dart';
import '../storage/secure_storage.dart';

class ApiClient {
  late final Dio dio;
  final SecureStorageService _secureStorage = SecureStorageService();
  void Function()? onSessionExpired;

  ApiClient() {
    dio = Dio(
      BaseOptions(
        baseUrl: Env.instance.baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 15),
      ),
    );

    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _secureStorage.getToken();
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          options.headers['Accept'] = 'application/json';
          return handler.next(options);
        },
        onError: (DioException error, handler) async {
          // Handle 401 Session Expired/Token Expiration
          if (error.response?.statusCode == 401 &&
              error.requestOptions.path != '/auth/login' &&
              error.requestOptions.path != '/auth/refresh') {
            final refreshToken = await _secureStorage.getRefreshToken();
            if (refreshToken != null) {
              try {
                // Attempt to refresh the token
                final refreshResponse =
                    await Dio(BaseOptions(baseUrl: Env.instance.baseUrl)).post(
                      '/auth/refresh',
                      data: {'refresh_token': refreshToken},
                      options: Options(headers: {'Accept': 'application/json'}),
                    );

                if (refreshResponse.statusCode == 200 &&
                    refreshResponse.data['success'] == true) {
                  final data = refreshResponse.data['data'];
                  final newToken = data['token'];
                  final newRefreshToken = data['refresh_token'];

                  // Save new tokens
                  await _secureStorage.saveToken(newToken);
                  if (newRefreshToken != null) {
                    await _secureStorage.saveRefreshToken(newRefreshToken);
                  }

                  // Retry original request with new token
                  final options = error.requestOptions;
                  options.headers['Authorization'] = 'Bearer $newToken';
                  final cloneReq = await dio.fetch(options);
                  return handler.resolve(cloneReq);
                }
              } catch (e) {
                // Refresh failed, trigger logout/session expiration
                await _secureStorage.clear();
                if (onSessionExpired != null) {
                  onSessionExpired!();
                }
              }
            } else {
              // No refresh token available, session expired
              await _secureStorage.clear();
              if (onSessionExpired != null) {
                onSessionExpired!();
              }
            }
          }
          return handler.next(error);
        },
      ),
    );
  }

  Future<Response<T>> get<T>(
    String path, {
    Object? data,
    Map<String, dynamic>? queryParameters,
    Options? options,
    CancelToken? cancelToken,
    ProgressCallback? onReceiveProgress,
  }) {
    return dio.get<T>(
      path,
      data: data,
      queryParameters: queryParameters,
      options: options,
      cancelToken: cancelToken,
      onReceiveProgress: onReceiveProgress,
    );
  }

  Future<Response<T>> post<T>(
    String path, {
    Object? data,
    Map<String, dynamic>? queryParameters,
    Options? options,
    CancelToken? cancelToken,
    ProgressCallback? onSendProgress,
    ProgressCallback? onReceiveProgress,
  }) {
    return dio.post<T>(
      path,
      data: data,
      queryParameters: queryParameters,
      options: options,
      cancelToken: cancelToken,
      onSendProgress: onSendProgress,
      onReceiveProgress: onReceiveProgress,
    );
  }
}

final apiClient = ApiClient();
