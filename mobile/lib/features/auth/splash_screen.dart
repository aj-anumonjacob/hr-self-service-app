import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/config/theme.dart';
import 'providers/auth_provider.dart';
import 'domain/auth_state.dart';

class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _scaleAnimation;
  late Animation<double> _fadeAnimation;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1500),
    );

    _scaleAnimation = Tween<double>(begin: 0.8, end: 1.0).animate(
      CurvedAnimation(parent: _controller, curve: Curves.easeOutBack),
    );

    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _controller, curve: Curves.easeIn),
    );

    _controller.forward();

    // Deferred to a microtask (rather than called directly) because
    // initializeSession() is an async function whose body runs synchronously
    // up to its first await — calling it inline from initState() would set
    // provider state while the widget tree is still building and Riverpod
    // throws ("Tried to modify a provider while the widget tree was
    // building"). Deferring lets the current build finish first.
    Future.microtask(_bootstrap);
  }

  // Waits for BOTH the minimum splash animation time AND the actual
  // completion of initializeSession() (storage reads — including the device
  // certificate — can take well over a second on first access, e.g. Android
  // Keystore/EncryptedSharedPreferences overhead) before navigating. A fixed
  // delay alone raced against that read and could navigate to /login before
  // an offline-recoverable session had finished loading.
  Future<void> _bootstrap() async {
    final sessionFuture = ref.read(authProvider.notifier).initializeSession();
    final minDelay = Future.delayed(const Duration(milliseconds: 2500));
    await Future.wait([sessionFuture, minDelay]);
    if (mounted) {
      _navigateByAuthState();
    }
  }

  void _navigateByAuthState() {
    final state = ref.read(authProvider);
    if (state is Authenticated) {
      if (state.employee.isManager) {
        context.go('/manager-dashboard');
      } else {
        context.go('/dashboard');
      }
    } else {
      context.go('/login');
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.charcoal,
      body: Center(
        child: AnimatedBuilder(
          animation: _controller,
          builder: (context, child) {
            return FadeTransition(
              opacity: _fadeAnimation,
              child: ScaleTransition(
                scale: _scaleAnimation,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Image.asset(
                      'assets/images/logo.png',
                      height: 180,
                      fit: BoxFit.contain,
                    ),
                    const SizedBox(height: 40),
                    const SizedBox(
                      width: 24,
                      height: 24,
                      child: CircularProgressIndicator(
                        valueColor: AlwaysStoppedAnimation<Color>(AppTheme.lightGold),
                        strokeWidth: 2.5,
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}
