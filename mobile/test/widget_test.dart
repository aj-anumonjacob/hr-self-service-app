import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile/core/config/env.dart';
import 'package:mobile/features/auth/login_screen.dart';

void main() {
  testWidgets('Login screen smoke test - renders form elements', (WidgetTester tester) async {
    // Initialize Env for API Client configuration
    Env.init(
      baseUrl: 'http://127.0.0.1:8000/api/v1',
      environmentName: 'development',
    );

    // Build the login screen directly
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          home: LoginScreen(),
        ),
      ),
    );

    // Verify that our login screen contains "ATTENDANCE SYSTEM" text
    expect(find.text('ATTENDANCE SYSTEM'), findsOneWidget);
    
    // Verify that Segmented Role selector elements exist
    expect(find.text('Employee'), findsOneWidget);
    expect(find.text('Manager'), findsOneWidget);

    // Verify that our login screen contains "LOG IN" button text
    expect(find.text('LOG IN'), findsOneWidget);
  });
}
