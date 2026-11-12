import 'package:go_router/go_router.dart';
import '../../features/auth/activation_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/forgot_password_screen.dart';
import '../../features/auth/permissions_screen.dart';
import '../../features/dashboard/dashboard_screen.dart';
import '../../features/attendance/check_in_screen.dart';
import '../../features/attendance/calendar_screen.dart';
import '../../features/attendance/recents_screen.dart';
import '../../features/manager/manager_screen.dart';
import '../../features/manager/break_policy_management_screen.dart';
import '../../features/notifications/notification_centre_screen.dart';
import '../../features/sync/screens/sync_history_screen.dart';
import '../../features/requests/screens/requests_screen.dart';
import '../../features/requests/screens/leave_screen.dart';
import '../../features/requests/screens/manager_requests_screen.dart';
import '../../features/maintenance/screens/maintenance_requests_screen.dart';
import '../../features/maintenance/screens/maintenance_request_detail_screen.dart';
import '../../features/maintenance/screens/request_material_form_screen.dart';

final GoRouter appRouter = GoRouter(
  initialLocation: '/splash',
  routes: [
    GoRoute(
      path: '/splash',
      builder: (context, state) => const SplashScreen(),
    ),
    GoRoute(
      path: '/activation',
      builder: (context, state) => const ActivationScreen(),
    ),
    GoRoute(
      path: '/login',
      builder: (context, state) => const LoginScreen(),
    ),
    GoRoute(
      path: '/forgot-password',
      builder: (context, state) => const ForgotPasswordScreen(),
    ),
    GoRoute(
      path: '/permissions',
      builder: (context, state) => PermissionsScreen(destination: state.extra as String? ?? '/dashboard'),
    ),
    GoRoute(
      path: '/dashboard',
      builder: (context, state) => const DashboardScreen(),
    ),
    GoRoute(
      path: '/check-in',
      builder: (context, state) => const CheckInScreen(),
    ),
    GoRoute(
      path: '/calendar',
      builder: (context, state) => const CalendarScreen(),
    ),
    GoRoute(
      path: '/recents',
      builder: (context, state) => const RecentsScreen(),
    ),
    GoRoute(
      path: '/requests',
      builder: (context, state) => const RequestsScreen(),
    ),
    GoRoute(
      path: '/maintenance/requests',
      builder: (context, state) => const MaintenanceRequestsScreen(),
    ),
    GoRoute(
      path: '/maintenance/requests/:id',
      builder: (context, state) {
        final id = int.tryParse(state.pathParameters['id'] ?? '0') ?? 0;
        return MaintenanceRequestDetailScreen(requestId: id);
      },
    ),
    GoRoute(
      path: '/maintenance/requests/:id/material-request',
      builder: (context, state) {
        final id = int.tryParse(state.pathParameters['id'] ?? '0') ?? 0;
        return RequestMaterialFormScreen(requestId: id);
      },
    ),
    GoRoute(
      path: '/leave',
      builder: (context, state) => const LeaveScreen(),
    ),
    GoRoute(
      path: '/calendar',
      builder: (context, state) => const CalendarScreen(),
    ),
    GoRoute(
      path: '/manager-dashboard',
      builder: (context, state) => const ManagerScreen(),
    ),
    GoRoute(
      path: '/manager/requests',
      builder: (context, state) => const ManagerRequestsScreen(),
    ),
    GoRoute(
      path: '/manager/break-policies',
      builder: (context, state) => const BreakPolicyManagementScreen(),
    ),
    GoRoute(
      path: '/notifications',
      builder: (context, state) => const NotificationCentreScreen(),
    ),
    GoRoute(
      path: '/sync-history',
      builder: (context, state) => const SyncHistoryScreen(),
    ),
  ],
);
