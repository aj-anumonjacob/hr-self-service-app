import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/config/theme.dart';
import '../../core/utils/device_info.dart';
import '../../core/utils/location_util.dart';
import '../../core/utils/post_auth_navigation.dart';
import 'providers/auth_provider.dart';
import 'domain/auth_state.dart';

class ActivationScreen extends ConsumerStatefulWidget {
  const ActivationScreen({super.key});

  @override
  ConsumerState<ActivationScreen> createState() => _ActivationScreenState();
}

class _ActivationScreenState extends ConsumerState<ActivationScreen> {
  final _employeeNumController = TextEditingController();
  final _activationCodeController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();

  bool _obscurePassword = true;
  bool _obscureConfirmPassword = true;
  bool _isRequestingCode = false;
  int _resendCountdown = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _employeeNumController.dispose();
    _activationCodeController.dispose();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  void _startResendTimer() {
    setState(() => _resendCountdown = 60);
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_resendCountdown == 0) {
        timer.cancel();
      } else {
        setState(() => _resendCountdown--);
      }
    });
  }

  Future<void> _handleRequestCode() async {
    final empNum = _employeeNumController.text.trim();
    if (empNum.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter your Employee Number first.')),
      );
      return;
    }

    if (_isRequestingCode || _resendCountdown > 0) return;

    setState(() => _isRequestingCode = true);

    final result = await ref.read(authProvider.notifier).requestActivationCode(empNum);

    if (!mounted) return;
    setState(() => _isRequestingCode = false);

    if (result == null) return;

    if (result['requires_email'] == true) {
      _showEmailRequiredDialog(empNum);
      return;
    }

    _startResendTimer();
    final msg = result['message']?.toString() ?? 'Activation code sent.';
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: const Color(0xFF0F5A6E),
      ),
    );
  }

  Future<void> _showEmailRequiredDialog(String employeeNumber) async {
    final emailController = TextEditingController();
    final codeController = TextEditingController(text: employeeNumber);
    bool isSubmitting = false;
    String? errorText;

    await showDialog<void>(
      context: context,
      barrierDismissible: !isSubmitting,
      builder: (dialogContext) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            Future<void> submit() async {
              final email = emailController.text.trim();
              final code = codeController.text.trim();

              if (email.isEmpty || !RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) {
                setDialogState(() => errorText = 'Please enter a valid email address.');
                return;
              }
              if (code.isEmpty) {
                setDialogState(() => errorText = 'Please enter your employee code.');
                return;
              }

              setDialogState(() {
                isSubmitting = true;
                errorText = null;
              });

              final position = await LocationUtil.captureSilently();

              final result = await ref.read(authProvider.notifier).setActivationEmail(
                    employeeNumber: employeeNumber,
                    employeeCode: code,
                    email: email,
                    latitude: position?.latitude,
                    longitude: position?.longitude,
                    accuracy: position?.accuracy,
                  );

              if (!context.mounted) return;

              if (result == null) {
                setDialogState(() => isSubmitting = false);
                return;
              }

              Navigator.of(dialogContext).pop();

              if (!mounted) return;
              _startResendTimer();
              final msg = result['message']?.toString() ?? 'Email updated and activation code sent.';
              ScaffoldMessenger.of(this.context).showSnackBar(
                SnackBar(
                  content: Text(msg),
                  backgroundColor: const Color(0xFF0F5A6E),
                ),
              );
            }

            return AlertDialog(
              title: const Text(
                'Email Address Required',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
              ),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'No email address is on file for this employee code. Enter your email and employee code below to receive the activation code.',
                      style: TextStyle(color: Color(0xFF64748B), fontSize: 13, height: 1.4),
                    ),
                    const SizedBox(height: 16),
                    TextField(
                      controller: emailController,
                      enabled: !isSubmitting,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(
                        labelText: 'Email Address',
                        prefixIcon: Icon(Icons.email_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: codeController,
                      enabled: !isSubmitting,
                      decoration: const InputDecoration(
                        labelText: 'Employee Code',
                        prefixIcon: Icon(Icons.badge_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    if (errorText != null) ...[
                      const SizedBox(height: 10),
                      Text(errorText!, style: const TextStyle(color: Colors.red, fontSize: 12.5)),
                    ],
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: isSubmitting ? null : () => Navigator.of(dialogContext).pop(),
                  child: const Text('Cancel'),
                ),
                ElevatedButton(
                  onPressed: isSubmitting ? null : submit,
                  style: ElevatedButton.styleFrom(backgroundColor: AppTheme.primaryTeal),
                  child: isSubmitting
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        )
                      : const Text('Submit', style: TextStyle(color: Colors.white)),
                ),
              ],
            );
          },
        );
      },
    );
  }

  Future<void> _handleActivation() async {
    final empNum = _employeeNumController.text.trim();
    final code = _activationCodeController.text.trim();
    final password = _passwordController.text;
    final confirmPassword = _confirmPasswordController.text;

    if (empNum.isEmpty || code.isEmpty || password.isEmpty || confirmPassword.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please fill in all required fields.')),
      );
      return;
    }

    if (password != confirmPassword) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('New password and confirm password do not match.')),
      );
      return;
    }

    if (code.length != 6 || int.tryParse(code) == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Activation code must be exactly 6 numeric digits.')),
      );
      return;
    }

    final deviceInfo = await DeviceInfoUtil().getDeviceInfo();
    final deviceName = deviceInfo['device_name'] ?? 'Mobile Device';

    final success = await ref.read(authProvider.notifier).activate(
          employeeNumber: empNum,
          activationCode: code,
          password: password,
          passwordConfirmation: confirmPassword,
          deviceName: deviceName,
        );

    if (success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Device activated and logged in successfully!'),
          backgroundColor: Color(0xFF10B981),
        ),
      );

      final state = ref.read(authProvider);
      if (state is Authenticated) {
        await navigateAfterAuth(context, isManager: state.employee.isManager);
      }
    } else if (mounted) {
      // Clear sensitive code & password fields on failure
      _activationCodeController.clear();
      _passwordController.clear();
      _confirmPasswordController.clear();
    }
  }

  Widget _buildInputField({
    required TextEditingController controller,
    required String labelText,
    required String hintText,
    required IconData prefixIcon,
    bool obscureText = false,
    Widget? suffixIcon,
    TextInputType keyboardType = TextInputType.text,
    int? maxLength,
    bool enabled = true,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: TextField(
        controller: controller,
        enabled: enabled,
        obscureText: obscureText,
        keyboardType: keyboardType,
        maxLength: maxLength,
        style: const TextStyle(
          color: Color(0xFF0F172A),
          fontSize: 15,
          fontWeight: FontWeight.w500,
        ),
        decoration: InputDecoration(
          labelText: labelText,
          hintText: hintText,
          labelStyle: TextStyle(color: Colors.grey[600], fontSize: 14),
          hintStyle: TextStyle(color: Colors.grey[400], fontSize: 14),
          counterText: '',
          prefixIcon: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: AppTheme.primaryTeal.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(prefixIcon, color: AppTheme.primaryTeal, size: 20),
            ),
          ),
          suffixIcon: suffixIcon,
          border: InputBorder.none,
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);
    final isLoading = authState is AuthLoading;

    ref.listen<AuthState>(authProvider, (previous, next) {
      if (next is AuthError) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(next.message),
            backgroundColor: Colors.red[600],
          ),
        );
      }
    });

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0.5,
        title: const Text(
          'Account Activation',
          style: TextStyle(
            color: Color(0xFF0F172A),
            fontSize: 18,
            fontWeight: FontWeight.bold,
          ),
        ),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Color(0xFF0F172A), size: 20),
          onPressed: () {
            if (context.canPop()) {
              context.pop();
            } else {
              context.go('/login');
            }
          },
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 28.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Hero Badge Icon & Title Header
              Center(
                child: Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: [
                        AppTheme.champagneGold.withValues(alpha: 0.15),
                        AppTheme.primaryTeal.withValues(alpha: 0.1),
                      ],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: AppTheme.champagneGold.withValues(alpha: 0.4),
                      width: 2,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: AppTheme.champagneGold.withValues(alpha: 0.15),
                        blurRadius: 16,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: const Icon(
                    Icons.shield_outlined,
                    size: 48,
                    color: AppTheme.primaryTeal,
                  ),
                ),
              ),
              const SizedBox(height: 20),
              const Text(
                'Activate Your Device',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF0F172A),
                  letterSpacing: -0.5,
                ),
              ),
              const SizedBox(height: 8),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16.0),
                child: Text(
                  'Enter the activation details provided by your HR or Administrator to register this phone.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: const Color(0xFF475569),
                    fontSize: 14,
                    height: 1.4,
                  ),
                ),
              ),
              const SizedBox(height: 32),

              // Form Card Container
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.04),
                      blurRadius: 14,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Column(
                  children: [
                    _buildInputField(
                      controller: _employeeNumController,
                      enabled: !isLoading,
                      labelText: 'Employee Number',
                      hintText: 'e.g. EMP-1042',
                      prefixIcon: Icons.badge_outlined,
                    ),
                    const SizedBox(height: 12),
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton.icon(
                        onPressed: (_isRequestingCode || _resendCountdown > 0 || isLoading)
                            ? null
                            : _handleRequestCode,
                        icon: _isRequestingCode
                            ? const SizedBox(
                                width: 14,
                                height: 14,
                                child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.primaryTeal),
                              )
                            : const Icon(Icons.mail_outline_rounded, size: 16, color: AppTheme.primaryTeal),
                        label: Text(
                          _resendCountdown > 0
                              ? 'Resend Code (${_resendCountdown}s)'
                              : 'Request Activation Code',
                          style: const TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                            color: AppTheme.primaryTeal,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    _buildInputField(
                      controller: _activationCodeController,
                      enabled: !isLoading,
                      labelText: '6-Digit Activation Code',
                      hintText: 'Enter 6-digit code',
                      prefixIcon: Icons.key_outlined,
                      keyboardType: TextInputType.number,
                      maxLength: 6,
                    ),
                    const SizedBox(height: 16),
                    _buildInputField(
                      controller: _passwordController,
                      enabled: !isLoading,
                      labelText: 'New Password',
                      hintText: 'Create a strong password',
                      prefixIcon: Icons.lock_outline_rounded,
                      obscureText: _obscurePassword,
                      suffixIcon: IconButton(
                        icon: Icon(
                          _obscurePassword ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                          color: Colors.grey[500],
                          size: 20,
                        ),
                        onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                      ),
                    ),
                    const SizedBox(height: 16),
                    _buildInputField(
                      controller: _confirmPasswordController,
                      enabled: !isLoading,
                      labelText: 'Confirm Password',
                      hintText: 'Re-enter new password',
                      prefixIcon: Icons.lock_reset_rounded,
                      obscureText: _obscureConfirmPassword,
                      suffixIcon: IconButton(
                        icon: Icon(
                          _obscureConfirmPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                          color: Colors.grey[500],
                          size: 20,
                        ),
                        onPressed: () => setState(() => _obscureConfirmPassword = !_obscureConfirmPassword),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 28),

              // Action Button
              Container(
                height: 52,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [
                      AppTheme.primaryTeal,
                      Color(0xFF0F5A6E),
                    ],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(14),
                  boxShadow: [
                    BoxShadow(
                      color: AppTheme.primaryTeal.withValues(alpha: 0.3),
                      blurRadius: 12,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Material(
                  color: Colors.transparent,
                  child: InkWell(
                    borderRadius: BorderRadius.circular(14),
                    onTap: isLoading ? null : _handleActivation,
                    child: Center(
                      child: isLoading
                          ? const SizedBox(
                              height: 22,
                              width: 22,
                              child: CircularProgressIndicator(
                                color: Colors.white,
                                strokeWidth: 2.5,
                              ),
                            )
                          : const Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Text(
                                  'ACTIVATE ACCOUNT',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                    letterSpacing: 0.8,
                                  ),
                                ),
                                SizedBox(width: 8),
                                Icon(Icons.arrow_forward_rounded, color: Colors.white, size: 20),
                              ],
                            ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Back to Login Link
              Center(
                child: TextButton.icon(
                  onPressed: isLoading
                      ? null
                      : () {
                          if (context.canPop()) {
                            context.pop();
                          } else {
                            context.go('/login');
                          }
                        },
                  icon: const Icon(Icons.arrow_back_rounded, size: 18, color: AppTheme.primaryTeal),
                  label: const Text(
                    'Back to Login',
                    style: TextStyle(
                      color: AppTheme.primaryTeal,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
