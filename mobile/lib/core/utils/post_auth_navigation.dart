import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../features/auth/permissions_screen.dart';

/// Routes to the right screen right after a successful login or device
/// activation: the one-time permissions primer on the very first sign-in,
/// or straight to the employee's dashboard on every sign-in after that.
Future<void> navigateAfterAuth(BuildContext context, {required bool isManager}) async {
  final destination = isManager ? '/manager-dashboard' : '/dashboard';
  final primed = await PermissionsScreen.alreadyPrimed();

  if (!context.mounted) return;

  if (primed) {
    context.go(destination);
  } else {
    context.go('/permissions', extra: destination);
  }
}
