import 'employee_model.dart';

abstract class AuthState {
  const AuthState();
}

class AuthInitial extends AuthState {
  const AuthInitial();
}

class AuthLoading extends AuthState {
  const AuthLoading();
}

class Authenticated extends AuthState {
  final EmployeeModel employee;
  /// True when this session came from offline certificate recovery (no live
  /// access token yet) rather than a normal login/cached-session restore —
  /// live API calls will fail until [SyncEngine] silently redeems the
  /// certificate for real tokens once connectivity returns.
  final bool isOfflineRecovered;
  const Authenticated(this.employee, {this.isOfflineRecovered = false});
}

class Unauthenticated extends AuthState {
  final String? message;
  const Unauthenticated({this.message});
}

class AuthError extends AuthState {
  final String message;
  const AuthError(this.message);
}
