import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/config/theme.dart';
import '../../core/services/quick_unlock_service.dart';
import '../../core/storage/secure_storage.dart';
import 'providers/auth_provider.dart';

/// Full-screen lock shown over the whole app (see [QuickUnlockGate]) whenever
/// Quick Unlock is enabled and there's a cached session to protect. Not a
/// routed screen — it's an overlay so it can interrupt the app on resume as
/// well as on cold start, without needing to know or restore "what route was
/// showing before".
class QuickUnlockScreen extends ConsumerStatefulWidget {
  final VoidCallback onUnlocked;

  const QuickUnlockScreen({super.key, required this.onUnlocked});

  @override
  ConsumerState<QuickUnlockScreen> createState() => _QuickUnlockScreenState();
}

class _QuickUnlockScreenState extends ConsumerState<QuickUnlockScreen> {
  final _service = QuickUnlockService();
  bool _authenticating = false;
  String? _error;
  String? _employeeName;

  @override
  void initState() {
    super.initState();
    SecureStorageService().getEmployeeName().then((name) {
      if (mounted) setState(() => _employeeName = name);
    });
    // Prompt immediately on show — the tap-to-unlock button is the fallback
    // if this is dismissed/cancelled or fails.
    WidgetsBinding.instance.addPostFrameCallback((_) => _attemptUnlock());
  }

  Future<void> _attemptUnlock() async {
    if (_authenticating) return;
    setState(() {
      _authenticating = true;
      _error = null;
    });

    final success = await _service.authenticate();

    if (!mounted) return;
    setState(() => _authenticating = false);

    if (success) {
      widget.onUnlocked();
    } else {
      setState(() => _error = 'Authentication failed or was cancelled. Try again.');
    }
  }

  Future<void> _logOut() async {
    await ref.read(authProvider.notifier).logout();
    // logout() clears the cached session, so the next shouldLock() check is
    // false and this overlay's parent (QuickUnlockGate) will hide itself;
    // the router's own auth-state handling takes it from there to /login.
    widget.onUnlocked();
  }

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppTheme.charcoal,
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 88,
                height: 88,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.08),
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  _authenticating ? Icons.lock_open_rounded : Icons.fingerprint_rounded,
                  size: 48,
                  color: AppTheme.lightGold,
                ),
              ),
              const SizedBox(height: 24),
              Text(
                _employeeName != null ? 'Welcome back, $_employeeName' : 'App Locked',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              const Text(
                'Unlock with your fingerprint, face, or device PIN to continue.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.white70, fontSize: 13),
              ),
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.redAccent, fontSize: 12.5),
                ),
              ],
              const SizedBox(height: 32),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: _authenticating ? null : _attemptUnlock,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.lightGold,
                    foregroundColor: AppTheme.charcoal,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  icon: _authenticating
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.charcoal),
                        )
                      : const Icon(Icons.lock_open_rounded, size: 20),
                  label: Text(_authenticating ? 'Verifying…' : 'Unlock'),
                ),
              ),
              const SizedBox(height: 12),
              TextButton(
                onPressed: _authenticating ? null : _logOut,
                child: const Text('Log Out Instead', style: TextStyle(color: Colors.white54)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
