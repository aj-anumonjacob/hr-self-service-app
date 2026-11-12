import 'package:flutter/material.dart';
import '../services/quick_unlock_service.dart';
import '../../features/auth/quick_unlock_screen.dart';

/// Wraps the whole app (see main.dart) and shows [QuickUnlockScreen] as a
/// full-screen overlay — over the splash screen, login, dashboard, whatever
/// is currently showing — whenever Quick Unlock is enabled and there's a
/// cached session to protect. This overlay approach (rather than a routed
/// screen) means it can interrupt the app on resume-from-background too,
/// without needing to track/restore "what route was showing before".
class QuickUnlockGate extends StatefulWidget {
  final Widget child;
  const QuickUnlockGate({super.key, required this.child});

  @override
  State<QuickUnlockGate> createState() => _QuickUnlockGateState();
}

class _QuickUnlockGateState extends State<QuickUnlockGate> with WidgetsBindingObserver {
  final _service = QuickUnlockService();
  bool _locked = false;
  DateTime? _pausedAt;

  // Brief backgrounding (switching to check a notification, a system
  // permission dialog, etc.) shouldn't force a re-unlock — only a genuine
  // "left the app for a while" gap does.
  static const _reLockThreshold = Duration(seconds: 30);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _checkLock();
  }

  Future<void> _checkLock() async {
    final shouldLock = await _service.shouldLock();
    if (mounted && shouldLock) {
      setState(() => _locked = true);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused) {
      _pausedAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed) {
      final pausedAt = _pausedAt;
      _pausedAt = null;
      if (pausedAt != null && DateTime.now().difference(pausedAt) >= _reLockThreshold) {
        _checkLock();
      }
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        widget.child,
        if (_locked)
          QuickUnlockScreen(onUnlocked: () {
            if (mounted) setState(() => _locked = false);
          }),
      ],
    );
  }
}
