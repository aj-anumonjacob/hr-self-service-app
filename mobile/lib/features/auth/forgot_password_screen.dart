import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dio/dio.dart';
import '../../../core/config/theme.dart';
import 'data/auth_repository.dart';

final authRepositoryProvider = Provider<AuthRepository>((ref) => AuthRepository());

class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen>
    with SingleTickerProviderStateMixin {
  late AnimationController _animController;
  late Animation<double> _fadeAnim;

  // Wizard Step: 0 = Request OTP, 1 = Verify OTP, 2 = New Password, 3 = Success
  int _currentStep = 0;

  final _formKeyStep1 = GlobalKey<FormState>();
  final _formKeyStep2 = GlobalKey<FormState>();
  final _formKeyStep3 = GlobalKey<FormState>();

  final _empCodeController = TextEditingController();
  final _emailController = TextEditingController();
  final _otpController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();

  bool _isLoading = false;
  bool _obscurePassword = true;
  bool _obscureConfirmPassword = true;
  bool _requiresEmail = false;
  String? _errorMessage;
  String? _maskedEmail;
  String? _resetToken;

  // Timer for Resend OTP
  Timer? _resendTimer;
  int _resendCountdown = 60;
  bool _canResend = false;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    );
    _fadeAnim = CurvedAnimation(parent: _animController, curve: Curves.easeOut);
    _animController.forward();
  }

  @override
  void dispose() {
    _animController.dispose();
    _resendTimer?.cancel();
    _empCodeController.dispose();
    _emailController.dispose();
    _otpController.dispose();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  void _startResendTimer() {
    _resendTimer?.cancel();
    setState(() {
      _resendCountdown = 60;
      _canResend = false;
    });
    _resendTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_resendCountdown <= 1) {
        timer.cancel();
        setState(() {
          _canResend = true;
        });
      } else {
        setState(() {
          _resendCountdown--;
        });
      }
    });
  }

  String _extractErrorMessage(dynamic error) {
    if (error is DioException) {
      final res = error.response?.data;
      if (res is Map && res['message'] != null) {
        return res['message'].toString();
      }
    }
    return error.toString().replaceAll('Exception: ', '');
  }

  Future<void> _handleRequestOtp() async {
    if (_currentStep == 0 && _formKeyStep1.currentState != null) {
      if (!_formKeyStep1.currentState!.validate()) return;
    }

    final code = _empCodeController.text.trim();
    if (code.isEmpty) {
      setState(() => _errorMessage = 'Please enter your Employee Code');
      return;
    }

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final repo = ref.read(authRepositoryProvider);
      final email = _requiresEmail ? _emailController.text.trim() : null;

      final res = await repo.requestPasswordResetOtp(code, email: email);

      if (res['requires_email'] == true) {
        setState(() {
          _isLoading = false;
          _requiresEmail = true;
          _errorMessage = res['message']?.toString() ?? 'No registered email found. Please enter your email address.';
        });
        return;
      }

      setState(() {
        _isLoading = false;
        _requiresEmail = false;
        _maskedEmail = res['email_masked']?.toString() ?? 'your email address';
        _otpController.clear();
        _currentStep = 1;
      });

      _startResendTimer();
      _animController.reset();
      _animController.forward();
    } catch (e) {
      setState(() {
        _isLoading = false;
        _errorMessage = _extractErrorMessage(e);
      });
    }
  }

  Future<void> _handleVerifyOtp() async {
    if (!_formKeyStep2.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final repo = ref.read(authRepositoryProvider);
      final token = await repo.verifyPasswordResetOtp(
        _empCodeController.text.trim(),
        _otpController.text.trim(),
      );

      setState(() {
        _isLoading = false;
        _resetToken = token;
        _currentStep = 2;
      });

      _animController.reset();
      _animController.forward();
    } catch (e) {
      setState(() {
        _isLoading = false;
        _errorMessage = _extractErrorMessage(e);
      });
    }
  }

  Future<void> _handleResetPassword() async {
    if (!_formKeyStep3.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final repo = ref.read(authRepositoryProvider);
      await repo.resetPassword(
        employeeCode: _empCodeController.text.trim(),
        resetToken: _resetToken!,
        password: _passwordController.text,
        passwordConfirmation: _confirmPasswordController.text,
      );

      setState(() {
        _isLoading = false;
        _currentStep = 3;
      });

      _animController.reset();
      _animController.forward();
    } catch (e) {
      setState(() {
        _isLoading = false;
        _errorMessage = _extractErrorMessage(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
          onPressed: () => context.pop(),
        ),
        title: const Text(
          'Password Recovery',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18),
        ),
        centerTitle: true,
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
          child: FadeTransition(
            opacity: _fadeAnim,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: 10),
                Center(
                  child: Container(
                    width: 76,
                    height: 76,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: LinearGradient(
                        colors: [
                          AppTheme.champagneGold,
                          AppTheme.primaryTeal,
                        ],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: AppTheme.primaryTeal.withValues(alpha: 0.4),
                          blurRadius: 20,
                          offset: const Offset(0, 8),
                        ),
                      ],
                    ),
                    child: Icon(
                      _currentStep == 3
                          ? Icons.check_circle_rounded
                          : (_currentStep == 1 ? Icons.mark_chat_unread_rounded : Icons.lock_reset_rounded),
                      size: 38,
                      color: Colors.white,
                    ),
                  ),
                ),
                const SizedBox(height: 18),
                Text(
                  _currentStep == 0
                      ? 'Forgot Password?'
                      : (_currentStep == 1
                          ? 'Enter Verification Code'
                          : (_currentStep == 2 ? 'Create New Password' : 'Password Reset Successful!')),
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 0.3,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  _currentStep == 0
                      ? 'Enter your Employee Code to receive an email OTP code from support@example.com.'
                      : (_currentStep == 1
                          ? 'We sent a 6-digit OTP code to ${_maskedEmail ?? 'your email address'}'
                          : (_currentStep == 2
                              ? 'Your OTP was verified. Set your new account password below.'
                              : 'Your password has been updated. You can now log in to your account.')),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.grey.shade300,
                    fontSize: 13.5,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 24),
                if (_currentStep < 3)
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: List.generate(3, (index) {
                      final isActive = index <= _currentStep;
                      return AnimatedContainer(
                        duration: const Duration(milliseconds: 300),
                        margin: const EdgeInsets.symmetric(horizontal: 4),
                        width: index == _currentStep ? 28 : 10,
                        height: 8,
                        decoration: BoxDecoration(
                          color: isActive ? AppTheme.champagneGold : Colors.white.withValues(alpha: 0.2),
                          borderRadius: BorderRadius.circular(4),
                        ),
                      );
                    }),
                  ),
                const SizedBox(height: 24),
                if (_errorMessage != null)
                  Container(
                    margin: const EdgeInsets.only(bottom: 20),
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.red.shade900.withValues(alpha: 0.6),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: Colors.red.shade400.withValues(alpha: 0.5)),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.error_outline_rounded, color: Colors.white, size: 20),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            _errorMessage!,
                            style: const TextStyle(color: Colors.white, fontSize: 13),
                          ),
                        ),
                      ],
                    ),
                  ),
                Container(
                  padding: const EdgeInsets.all(24),
                  decoration: BoxDecoration(
                    color: AppTheme.pureWhite,
                    borderRadius: BorderRadius.circular(24),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.25),
                        blurRadius: 25,
                        offset: const Offset(0, 10),
                      ),
                    ],
                  ),
                  child: _buildCurrentStepForm(),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildCurrentStepForm() {
    switch (_currentStep) {
      case 0:
        return _buildStep1RequestOtp();
      case 1:
        return _buildStep2VerifyOtp();
      case 2:
        return _buildStep3NewPassword();
      case 3:
      default:
        return _buildStep4Success();
    }
  }

  Widget _buildStep1RequestOtp() {
    return Form(
      key: _formKeyStep1,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Employee Code',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F172A)),
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: _empCodeController,
            textCapitalization: TextCapitalization.characters,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            decoration: InputDecoration(
              hintText: 'e.g. E00199',
              prefixIcon: Container(
                margin: const EdgeInsets.all(8),
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppTheme.primaryTeal.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.badge_rounded, color: AppTheme.primaryTeal, size: 20),
              ),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
                borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
              ),
            ),
            validator: (value) {
              if (value == null || value.trim().isEmpty) {
                return 'Please enter your Employee Code';
              }
              return null;
            },
          ),
          if (_requiresEmail) ...[
            const SizedBox(height: 16),
            const Text(
              'Email Address',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F172A)),
            ),
            const SizedBox(height: 8),
            TextFormField(
              controller: _emailController,
              keyboardType: TextInputType.emailAddress,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
              decoration: InputDecoration(
                hintText: 'e.g. employee@domain.com',
                prefixIcon: Container(
                  margin: const EdgeInsets.all(8),
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppTheme.primaryTeal.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.email_rounded, color: AppTheme.primaryTeal, size: 20),
                ),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
                ),
              ),
              validator: (value) {
                if (value == null || value.trim().isEmpty) {
                  return 'Please enter your email address';
                }
                if (!value.contains('@') || !value.contains('.')) {
                  return 'Please enter a valid email address';
                }
                return null;
              },
            ),
          ],
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: _isLoading ? null : _handleRequestOtp,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primaryTeal,
              padding: const EdgeInsets.symmetric(vertical: 16),
            ),
            child: _isLoading
                ? const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5),
                  )
                : const Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('Send Verification Code', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.white)),
                      SizedBox(width: 8),
                      Icon(Icons.send_rounded, size: 18, color: Colors.white),
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildStep2VerifyOtp() {
    return Form(
      key: _formKeyStep2,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Enter 6-Digit OTP',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F172A)),
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: _otpController,
            keyboardType: TextInputType.number,
            maxLength: 6,
            textAlign: TextAlign.center,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            style: const TextStyle(
              fontWeight: FontWeight.bold,
              fontSize: 26,
              letterSpacing: 12,
              color: AppTheme.primaryTeal,
            ),
            decoration: InputDecoration(
              counterText: '',
              hintText: '• • • • • •',
              hintStyle: TextStyle(letterSpacing: 8, color: Colors.grey.shade400, fontSize: 22),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
                borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
              ),
            ),
            validator: (value) {
              if (value == null || value.trim().length != 6) {
                return 'Please enter the 6-digit OTP code';
              }
              return null;
            },
          ),
          const SizedBox(height: 16),
          InkWell(
            onTap: (_canResend && !_isLoading)
                ? () async {
                    await _handleRequestOtp();
                    if (mounted && _errorMessage == null) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: const Row(
                            children: [
                              Icon(Icons.check_circle_rounded, color: Colors.white, size: 20),
                              SizedBox(width: 8),
                              Expanded(child: Text('New 6-digit OTP code sent to your email address.')),
                            ],
                          ),
                          backgroundColor: AppTheme.primaryTeal,
                          behavior: SnackBarBehavior.floating,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        ),
                      );
                    }
                  }
                : null,
            borderRadius: BorderRadius.circular(12),
            child: Container(
              padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 16),
              decoration: BoxDecoration(
                color: _canResend
                    ? AppTheme.primaryTeal.withValues(alpha: 0.08)
                    : Colors.grey.shade100,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: _canResend
                      ? AppTheme.primaryTeal.withValues(alpha: 0.3)
                      : Colors.grey.shade300,
                ),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    _canResend ? Icons.refresh_rounded : Icons.timer_outlined,
                    size: 18,
                    color: _canResend ? AppTheme.primaryTeal : Colors.grey.shade600,
                  ),
                  const SizedBox(width: 8),
                  Text(
                    _canResend
                        ? "Didn't receive OTP? Resend Code"
                        : "Resend code in ${_resendCountdown}s",
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: _canResend ? AppTheme.primaryTeal : Colors.grey.shade600,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: _isLoading ? null : _handleVerifyOtp,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primaryTeal,
              padding: const EdgeInsets.symmetric(vertical: 16),
            ),
            child: _isLoading
                ? const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5),
                  )
                : const Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('Verify OTP', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.white)),
                      SizedBox(width: 8),
                      Icon(Icons.verified_rounded, size: 18, color: Colors.white),
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildStep3NewPassword() {
    return Form(
      key: _formKeyStep3,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'New Password',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F172A)),
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: _passwordController,
            obscureText: _obscurePassword,
            decoration: InputDecoration(
              hintText: 'Min 6 characters',
              prefixIcon: Container(
                margin: const EdgeInsets.all(8),
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppTheme.primaryTeal.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.lock_rounded, color: AppTheme.primaryTeal, size: 20),
              ),
              suffixIcon: IconButton(
                icon: Icon(
                  _obscurePassword ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                  color: Colors.grey.shade600,
                ),
                onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
              ),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
                borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
              ),
            ),
            validator: (value) {
              if (value == null || value.length < 6) {
                return 'Password must be at least 6 characters';
              }
              return null;
            },
          ),
          const SizedBox(height: 16),
          const Text(
            'Confirm New Password',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F172A)),
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: _confirmPasswordController,
            obscureText: _obscureConfirmPassword,
            decoration: InputDecoration(
              hintText: 'Re-enter new password',
              prefixIcon: Container(
                margin: const EdgeInsets.all(8),
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppTheme.primaryTeal.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.lock_outline_rounded, color: AppTheme.primaryTeal, size: 20),
              ),
              suffixIcon: IconButton(
                icon: Icon(
                  _obscureConfirmPassword ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                  color: Colors.grey.shade600,
                ),
                onPressed: () => setState(() => _obscureConfirmPassword = !_obscureConfirmPassword),
              ),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
                borderSide: const BorderSide(color: AppTheme.primaryTeal, width: 2),
              ),
            ),
            validator: (value) {
              if (value != _passwordController.text) {
                return 'Passwords do not match';
              }
              return null;
            },
          ),
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: _isLoading ? null : _handleResetPassword,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primaryTeal,
              padding: const EdgeInsets.symmetric(vertical: 16),
            ),
            child: _isLoading
                ? const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5),
                  )
                : const Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('Reset Password', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.white)),
                      SizedBox(width: 8),
                      Icon(Icons.check_circle_rounded, size: 18, color: Colors.white),
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildStep4Success() {
    return Column(
      children: [
        const Icon(Icons.stars_rounded, size: 54, color: AppTheme.champagneGold),
        const SizedBox(height: 14),
        const Text(
          'Password Updated Successfully!',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontSize: 18,
            fontWeight: FontWeight.bold,
            color: Color(0xFF0F172A),
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Your account password has been reset. You can now log in using your Employee Code and new password.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 13.5, color: Colors.grey.shade600, height: 1.4),
        ),
        const SizedBox(height: 24),
        ElevatedButton(
          onPressed: () => context.pop(),
          style: ElevatedButton.styleFrom(
            backgroundColor: AppTheme.primaryTeal,
            minimumSize: const Size(double.infinity, 50),
          ),
          child: const Text('Back to Login', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.white)),
        ),
      ],
    );
  }
}
