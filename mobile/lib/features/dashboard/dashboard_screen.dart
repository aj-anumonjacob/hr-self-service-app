import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../auth/providers/auth_provider.dart';
import '../auth/domain/auth_state.dart';
import '../auth/domain/employee_model.dart';
import '../attendance/providers/attendance_provider.dart';
import '../attendance/widgets/missed_attendance_card.dart';
import '../notifications/providers/notification_provider.dart';
import '../../core/widgets/notification_bell.dart';
import '../../core/widgets/connectivity_badge.dart';
import '../../core/widgets/portal_bottom_nav_bar.dart';
import '../../core/services/quick_unlock_service.dart';

class DashboardScreen extends ConsumerStatefulWidget {
  const DashboardScreen({super.key});

  @override
  ConsumerState<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends ConsumerState<DashboardScreen>
    with TickerProviderStateMixin {
  bool _isEmployeeCardExpanded = false;
  bool _isButtonPressed = false;
  bool _quickUnlockCapable = false;
  final _quickUnlockService = QuickUnlockService();

  late AnimationController _entranceController;
  late AnimationController _lightSweepController;

  @override
  void initState() {
    super.initState();
    Future.microtask(() {
      ref.read(attendanceProvider.notifier).fetchTodayContext();
      ref.read(notificationProvider.notifier).fetchUnreadCount();
    });
    _loadQuickUnlockState();

    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 650),
    );

    _lightSweepController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1100),
    );

    _entranceController.forward().then((_) {
      if (mounted) {
        _lightSweepController.forward();
      }
    });
  }

  Future<void> _loadQuickUnlockState() async {
    final capable = await _quickUnlockService.isDeviceCapable();
    if (mounted) {
      setState(() {
        _quickUnlockCapable = capable;
      });
    }
  }

  @override
  void dispose() {
    _entranceController.dispose();
    _lightSweepController.dispose();
    super.dispose();
  }

  String _getGreeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'Good Morning';
    if (hour < 17) return 'Good Afternoon';
    return 'Good Evening';
  }

  String _getFormattedDate() {
    return DateFormat('EEEE · d MMMM yyyy').format(DateTime.now());
  }

  String _formatTime(String? timeStr) {
    if (timeStr == null || timeStr.trim().isEmpty) return '--:--';
    try {
      String str = timeStr.trim();
      if (str.contains('T')) {
        str = str.split('T')[1];
      } else if (str.contains(' ')) {
        str = str.split(' ')[1];
      }
      str = str.split('+')[0].replaceAll('Z', '').trim();
      final parts = str.split(':');
      if (parts.length >= 2) {
        final hr = int.tryParse(parts[0]) ?? 0;
        final min = int.tryParse(parts[1]) ?? 0;
        final tempDt = DateTime(2026, 1, 1, hr, min);
        return DateFormat('hh:mm a').format(tempDt);
      }
    } catch (_) {}
    return timeStr;
  }

  Animation<double> _createStaggeredAnimation(double begin, double end) {
    return CurvedAnimation(
      parent: _entranceController,
      curve: Interval(begin, end, curve: Curves.easeOutCubic),
    );
  }

  @override
  Widget build(BuildContext context) {
    final disableAnimations = MediaQuery.of(context).disableAnimations;

    final authState = ref.watch(authProvider);
    final attendanceState = ref.watch(attendanceProvider);

    final contextData = attendanceState.todayContext;
    final recordData = attendanceState.todayStatus?['record'];

    EmployeeModel? authEmp;
    if (authState is Authenticated) {
      authEmp = authState.employee;
    }

    final empJson = contextData?['employee'] as Map<String, dynamic>?;
    EmployeeModel? effectiveEmp;
    if (empJson != null) {
      try {
        effectiveEmp = EmployeeModel.fromJson(empJson);
      } catch (_) {}
    }
    effectiveEmp ??= authEmp;

    final String fullName = empJson?['full_name'] as String? ?? effectiveEmp?.fullName ?? 'Employee';
    final String? nickname = (empJson?['nickname'] as String?) ?? (empJson?['display_name'] as String?);
    final String empCode = empJson?['employee_number'] as String? ?? effectiveEmp?.employeeNumber ?? '';
    final String designation = empJson?['designation'] as String? ?? effectiveEmp?.designation ?? '';
    final String department = empJson?['department'] as String? ?? effectiveEmp?.department ?? '';
    final String company = empJson?['company'] as String? ?? effectiveEmp?.company ?? '';
    final String userEmail = empJson?['email'] as String? ?? effectiveEmp?.email ?? '';
    final String userPhone = empJson?['mobile_number'] as String? ?? effectiveEmp?.mobileNumber ?? '';
    final String? photoUrl = empJson?['photo_url'] as String? ?? effectiveEmp?.photoUrl;

    final String greetingName = (nickname != null && nickname.trim().isNotEmpty)
        ? nickname.trim()
        : fullName.split(' ').first;

    final List<String> subtitleParts = [
      if (designation.isNotEmpty) designation,
      if (department.isNotEmpty) department,
    ];
    final String jobDeptSubtitle = subtitleParts.join(' · ');

    final shiftData = contextData?['policy']?['shift'];
    final shiftName = shiftData?['name'] as String? ?? 'Standard Shift';
    final startTime = shiftData?['start_time'] as String? ?? '08:00';
    final endTime = shiftData?['end_time'] as String? ?? '17:00';
    final String shiftTimeFormatted = '${_formatTime(startTime)}–${_formatTime(endTime)}';

    final empLocName = (contextData?['employee']?['location_name'] as String?)
        ?? (contextData?['employee']?['location'] as String?);
    final primaryLocName = contextData?['policy']?['location']?['name'] as String?;

    final String assignedSitesText = (empLocName != null && empLocName.trim().isNotEmpty)
        ? empLocName.trim()
        : (primaryLocName != null && primaryLocName.trim().isNotEmpty ? primaryLocName.trim() : 'Head Office');

    final hasCheckedIn = recordData != null && recordData['check_in_time'] != null;
    final hasCheckedOut = recordData != null && recordData['check_out_time'] != null;

    final headerAnim = disableAnimations
        ? const AlwaysStoppedAnimation(1.0)
        : _createStaggeredAnimation(0.0, 0.4);
    final card1Anim = disableAnimations
        ? const AlwaysStoppedAnimation(1.0)
        : _createStaggeredAnimation(0.2, 0.6);

    return Scaffold(
      backgroundColor: const Color(0xFF043743),
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            // ── TOP SECTION (Deep Teal Header + Attendance Card) ───────────────────────
            AnimatedBuilder(
              animation: headerAnim,
              builder: (context, child) {
                final double opacity = headerAnim.value;
                final double dy = (1.0 - headerAnim.value) * -14.0;
                return Transform.translate(
                  offset: Offset(0, dy),
                  child: Opacity(
                    opacity: opacity,
                    child: child,
                  ),
                );
              },
              child: Container(
                padding: const EdgeInsets.fromLTRB(20, 10, 20, 16),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    colors: [Color(0xFF032D37), Color(0xFF074E5B)],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Top App Title & Quick Action Icons
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Employee Portal',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            letterSpacing: 0.3,
                          ),
                        ),
                        Row(
                          children: [
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: 0.12),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: const Center(child: ConnectivityBadge()),
                            ),
                            const SizedBox(width: 6),
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: 0.12),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: const Center(child: NotificationBell()),
                            ),
                            const SizedBox(width: 8),
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: 0.12),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: IconButton(
                                padding: EdgeInsets.zero,
                                icon: const Icon(Icons.logout_rounded, color: Colors.white, size: 18),
                                tooltip: 'Log Out',
                                onPressed: () async {
                                  ref.read(notificationProvider.notifier).clearState();
                                  await ref.read(authProvider.notifier).logout();
                                  if (context.mounted) context.go('/login');
                                },
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),

                    // Greeting Header & Date Row
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          _getGreeting(),
                          style: const TextStyle(
                            color: Color(0xFFB0D6D4),
                            fontSize: 13,
                            fontWeight: FontWeight.w400,
                          ),
                        ),
                        Text(
                          _getFormattedDate(),
                          style: const TextStyle(
                            color: Color(0xFF86B7B5),
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 2),
                    Text(
                      greetingName,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 24,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 0.2,
                      ),
                    ),
                    const SizedBox(height: 8),

                    // Today's Attendance Card (Integrated Glassmorphic View)
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                          color: Colors.white.withValues(alpha: 0.16),
                          width: 1,
                        ),
                        boxShadow: const [
                          BoxShadow(
                            color: Color(0x1A000000),
                            blurRadius: 10,
                            offset: Offset(0, 4),
                          ),
                        ],
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // Top Status Indicator Row
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              const Text(
                                "Today's attendance",
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 13.5,
                                  fontWeight: FontWeight.bold,
                                  letterSpacing: 0.2,
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                decoration: BoxDecoration(
                                  color: hasCheckedOut
                                      ? const Color(0x35E65100)
                                      : (hasCheckedIn
                                          ? const Color(0x3500C853)
                                          : Colors.white.withValues(alpha: 0.15)),
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(
                                    color: hasCheckedOut
                                        ? const Color(0xFFFF9800)
                                        : (hasCheckedIn ? const Color(0xFF00E676) : Colors.white24),
                                    width: 1,
                                  ),
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Container(
                                      width: 6,
                                      height: 6,
                                      decoration: BoxDecoration(
                                        shape: BoxShape.circle,
                                        color: hasCheckedOut
                                            ? const Color(0xFFFF9800)
                                            : (hasCheckedIn ? const Color(0xFF00E676) : Colors.white60),
                                      ),
                                    ),
                                    const SizedBox(width: 6),
                                    Text(
                                      hasCheckedOut
                                          ? 'Checked Out'
                                          : (hasCheckedIn ? 'Checked in' : 'Not Checked in'),
                                      style: TextStyle(
                                        color: hasCheckedOut
                                            ? const Color(0xFFFFCC80)
                                            : (hasCheckedIn ? const Color(0xFFB9F6CA) : Colors.white70),
                                        fontSize: 11,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 14),

                          // Check-in & Check-out Summary Row
                          Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'CHECK-IN',
                                      style: TextStyle(
                                        color: Color(0xFF8DC0BD),
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 0.5,
                                      ),
                                    ),
                                    const SizedBox(height: 3),
                                    Text(
                                      _formatTime(recordData?['check_in_time']),
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 18,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 0.3,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              Container(
                                width: 1,
                                height: 32,
                                color: Colors.white.withValues(alpha: 0.2),
                              ),
                              const SizedBox(width: 16),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'CHECK-OUT',
                                      style: TextStyle(
                                        color: Color(0xFF8DC0BD),
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 0.5,
                                      ),
                                    ),
                                    const SizedBox(height: 3),
                                    Text(
                                      _formatTime(recordData?['check_out_time']),
                                      style: TextStyle(
                                        color: hasCheckedOut ? Colors.white : Colors.white54,
                                        fontSize: 18,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 0.3,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            '$shiftName · $shiftTimeFormatted',
                            style: const TextStyle(
                              color: Color(0xFFB0D6D4),
                              fontSize: 11,
                              fontWeight: FontWeight.w400,
                            ),
                          ),
                          const SizedBox(height: 12),

                          // ── Dedicated Break Toggle Row Inside Today's Attendance Card ──
                          Builder(builder: (context) {
                            final breakStateModel = attendanceState.breakState;
                            final isOnBreak = breakStateModel?.activeBreak != null;
                            final isBreakLoading = attendanceState.isBreakLoading;
                            final remainingBreaks = breakStateModel?.remainingBreaksCount ?? 1;
                            final maxBreaks = breakStateModel?.effectivePolicy?.maxBreaksPerShift ?? 1;
                            final usedBreaks = breakStateModel?.usedBreakCount ?? 0;
                            final canStart = breakStateModel?.canStartBreak ?? true;

                            final bool isCheckInRequired = !isOnBreak && !hasCheckedIn;
                            final bool isLimitReached = !isOnBreak && !isCheckInRequired && (remainingBreaks <= 0 || (usedBreaks >= maxBreaks && maxBreaks > 0) || (!canStart && breakStateModel != null));
                            final bool isDisabled = isCheckInRequired || isLimitReached;

                            return Container(
                              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: isOnBreak ? 0.16 : (isDisabled ? 0.04 : 0.08)),
                                borderRadius: BorderRadius.circular(14),
                                border: Border.all(
                                  color: isOnBreak
                                      ? const Color(0xFFFDD571).withValues(alpha: 0.6)
                                      : (isDisabled ? Colors.white.withValues(alpha: 0.08) : Colors.white.withValues(alpha: 0.14)),
                                  width: 1,
                                ),
                              ),
                              child: Row(
                                children: [
                                  Container(
                                    padding: const EdgeInsets.all(7),
                                    decoration: BoxDecoration(
                                      color: isOnBreak
                                          ? const Color(0xFFFDD571).withValues(alpha: 0.25)
                                          : (isDisabled ? Colors.white.withValues(alpha: 0.05) : Colors.white.withValues(alpha: 0.1)),
                                      shape: BoxShape.circle,
                                    ),
                                    child: Icon(
                                      Icons.coffee_rounded,
                                      color: isOnBreak
                                          ? const Color(0xFFFDD571)
                                          : (isDisabled ? Colors.white38 : const Color(0xFFB0D6D4)),
                                      size: 18,
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          children: [
                                            Flexible(
                                              child: Text(
                                                isOnBreak
                                                    ? '☕ On Break'
                                                    : (isCheckInRequired
                                                        ? 'Check In Required'
                                                        : (isLimitReached ? 'Break Limit Reached' : 'Take a Break')),
                                                style: TextStyle(
                                                  color: isDisabled ? Colors.white60 : Colors.white,
                                                  fontSize: 13,
                                                  fontWeight: FontWeight.bold,
                                                ),
                                                overflow: TextOverflow.ellipsis,
                                                maxLines: 1,
                                              ),
                                            ),
                                            if (isOnBreak) ...[
                                              const SizedBox(width: 8),
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                decoration: BoxDecoration(
                                                  color: const Color(0xFFFDD571),
                                                  borderRadius: BorderRadius.circular(6),
                                                ),
                                                child: const Text(
                                                  'ACTIVE',
                                                  style: TextStyle(
                                                    color: Color(0xFF073B4C),
                                                    fontSize: 9.5,
                                                    fontWeight: FontWeight.w900,
                                                  ),
                                                ),
                                              ),
                                            ] else if (isDisabled) ...[
                                              const SizedBox(width: 8),
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                decoration: BoxDecoration(
                                                  color: Colors.white.withValues(alpha: 0.15),
                                                  borderRadius: BorderRadius.circular(6),
                                                ),
                                                child: Text(
                                                  isCheckInRequired ? 'LOCKED' : 'EXHAUSTED',
                                                  style: const TextStyle(
                                                    color: Colors.white70,
                                                    fontSize: 9.5,
                                                    fontWeight: FontWeight.bold,
                                                  ),
                                                ),
                                              ),
                                            ],
                                          ],
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          isOnBreak
                                              ? 'Break mode active · Turn OFF to end'
                                              : (isCheckInRequired
                                                  ? 'Check in first to enable break actions'
                                                  : (isLimitReached
                                                      ? 'Allocated break limit reached ($usedBreaks/$maxBreaks used)'
                                                      : 'Flexible break · Turn ON to start')),
                                          style: TextStyle(
                                            color: isOnBreak
                                                ? const Color(0xFFFDD571)
                                                : (isDisabled ? Colors.white38 : const Color(0xFF8DC0BD)),
                                            fontSize: 11,
                                            fontWeight: FontWeight.w400,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                  if (isBreakLoading)
                                    const SizedBox(
                                      width: 22,
                                      height: 22,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        valueColor: AlwaysStoppedAnimation<Color>(Color(0xFFFDD571)),
                                      ),
                                    )
                                  else
                                    Transform.scale(
                                      scale: 0.85,
                                      child: Switch.adaptive(
                                        value: isOnBreak,
                                        activeColor: const Color(0xFF073B4C),
                                        activeTrackColor: const Color(0xFFFDD571),
                                        inactiveThumbColor: isDisabled ? Colors.white24 : Colors.white70,
                                        inactiveTrackColor: isDisabled ? Colors.white10 : Colors.white.withValues(alpha: 0.2),
                                        onChanged: isDisabled
                                            ? null
                                            : (bool newValue) async {
                                                if (isBreakLoading) return;
                                                if (newValue) {
                                                  final success = await ref.read(attendanceProvider.notifier).startBreak();
                                                  if (!context.mounted) return;
                                                  ScaffoldMessenger.of(context).showSnackBar(
                                                    SnackBar(
                                                      content: Text(success ? '☕ Break started successfully!' : 'Failed to start break.'),
                                                      backgroundColor: success ? const Color(0xFF0F8790) : Colors.red,
                                                    ),
                                                  );
                                                } else {
                                                  final success = await ref.read(attendanceProvider.notifier).endBreak();
                                                  if (!context.mounted) return;
                                                  ScaffoldMessenger.of(context).showSnackBar(
                                                    SnackBar(
                                                      content: Text(success ? '▶️ Break ended. Welcome back!' : 'Failed to end break.'),
                                                      backgroundColor: success ? const Color(0xFF0F8790) : Colors.red,
                                                    ),
                                                  );
                                                }
                                              },
                                      ),
                                    ),
                                ],
                              ),
                            );
                          }),
                          const SizedBox(height: 14),

                          // Main Attendance Action Pill Button
                          GestureDetector(
                            onTapDown: (_) => setState(() => _isButtonPressed = true),
                            onTapUp: (_) => setState(() => _isButtonPressed = false),
                            onTapCancel: () => setState(() => _isButtonPressed = false),
                            onTap: () => context.push('/check-in'),
                            child: AnimatedScale(
                              scale: _isButtonPressed ? 0.98 : 1.0,
                              duration: const Duration(milliseconds: 100),
                              child: Container(
                                width: double.infinity,
                                height: 48,
                                decoration: BoxDecoration(
                                  gradient: const LinearGradient(
                                    colors: [Color(0xFFFDD571), Color(0xFFF7B844)],
                                    begin: Alignment.topCenter,
                                    end: Alignment.bottomCenter,
                                  ),
                                  borderRadius: BorderRadius.circular(24),
                                  boxShadow: const [
                                    BoxShadow(
                                      color: Color(0x45F7B844),
                                      blurRadius: 12,
                                      offset: Offset(0, 4),
                                    ),
                                  ],
                                ),
                                child: Stack(
                                  children: [
                                    Center(
                                      child: Row(
                                        mainAxisAlignment: MainAxisAlignment.center,
                                        children: [
                                          const Icon(Icons.gps_fixed_rounded, color: Color(0xFF073B4C), size: 18),
                                          const SizedBox(width: 8),
                                          const Text(
                                            'RECORD ATTENDANCE',
                                            style: TextStyle(
                                              color: Color(0xFF073B4C),
                                              fontSize: 13.5,
                                              fontWeight: FontWeight.w900,
                                              letterSpacing: 0.6,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    if (!disableAnimations)
                                      AnimatedBuilder(
                                        animation: _lightSweepController,
                                        builder: (context, child) {
                                          if (_lightSweepController.isDismissed) {
                                            return const SizedBox.shrink();
                                          }
                                          final sweepPos = (_lightSweepController.value * 2.5) - 0.7;
                                          return Positioned.fill(
                                            child: IgnorePointer(
                                              child: FractionalTranslation(
                                                translation: Offset(sweepPos, 0),
                                                child: Container(
                                                  width: 80,
                                                  decoration: BoxDecoration(
                                                    gradient: LinearGradient(
                                                      colors: [
                                                        Colors.white.withValues(alpha: 0.0),
                                                        Colors.white.withValues(alpha: 0.35),
                                                        Colors.white.withValues(alpha: 0.0),
                                                      ],
                                                    ),
                                                  ),
                                                ),
                                              ),
                                            ),
                                          );
                                        },
                                      ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(height: 8),
                          const Center(
                            child: Text(
                              'Location will be verified securely',
                              style: TextStyle(
                                color: Color(0xFF81B8B5),
                                fontSize: 10.5,
                                fontWeight: FontWeight.w400,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // ── BOTTOM CONTAINER (White Rounded Card Container) ────────────────────
            Expanded(
              child: Container(
                decoration: const BoxDecoration(
                  color: Color(0xFFEFF5F5),
                  borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
                ),
                child: RefreshIndicator(
                  color: const Color(0xFF0F8790),
                  onRefresh: () async {
                    await ref.read(attendanceProvider.notifier).fetchTodayContext();
                  },
                  child: SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 90),
                    child: AnimatedBuilder(
                      animation: card1Anim,
                      builder: (context, child) {
                        final double opacity = card1Anim.value;
                        final double dy = (1.0 - card1Anim.value) * 14.0;
                        return Transform.translate(
                          offset: Offset(0, dy),
                          child: Opacity(
                            opacity: opacity,
                            child: child,
                          ),
                        );
                      },
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          // 1. Employee Info Tile (Collapsible)
                          Container(
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(16),
                              boxShadow: const [
                                BoxShadow(
                                  color: Color(0x0C073B4C),
                                  blurRadius: 8,
                                  offset: Offset(0, 3),
                                ),
                              ],
                            ),
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(
                                children: [
                                  Row(
                                    children: [
                                      // Avatar Circle
                                      Container(
                                        width: 44,
                                        height: 44,
                                        decoration: BoxDecoration(
                                          color: const Color(0xFFE2F3F3),
                                          shape: BoxShape.circle,
                                          border: Border.all(
                                            color: const Color(0xFFF7B844),
                                            width: 1.5,
                                          ),
                                        ),
                                        child: (photoUrl != null && photoUrl.trim().isNotEmpty)
                                            ? ClipRRect(
                                                borderRadius: BorderRadius.circular(22),
                                                child: Image.network(
                                                  photoUrl,
                                                  fit: BoxFit.cover,
                                                  errorBuilder: (context, error, stackTrace) =>
                                                      _buildInitialAvatar(fullName),
                                                ),
                                              )
                                            : _buildInitialAvatar(fullName),
                                      ),
                                      const SizedBox(width: 12),

                                      // Employee Name & Designation
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              fullName,
                                              style: const TextStyle(
                                                color: Color(0xFF073B4C),
                                                fontSize: 14.5,
                                                fontWeight: FontWeight.bold,
                                              ),
                                            ),
                                            if (jobDeptSubtitle.isNotEmpty) ...[
                                              const SizedBox(height: 2),
                                              Text(
                                                jobDeptSubtitle,
                                                style: const TextStyle(
                                                  color: Color(0xFF637D87),
                                                  fontSize: 11.5,
                                                  fontWeight: FontWeight.w500,
                                                ),
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ],
                                          ],
                                        ),
                                      ),

                                      // Expand/Collapse Chevron
                                      InkWell(
                                        onTap: () {
                                          setState(() {
                                            _isEmployeeCardExpanded = !_isEmployeeCardExpanded;
                                          });
                                        },
                                        borderRadius: BorderRadius.circular(8),
                                        child: Container(
                                          padding: const EdgeInsets.all(4),
                                          child: AnimatedRotation(
                                            turns: _isEmployeeCardExpanded ? 0.5 : 0.0,
                                            duration: const Duration(milliseconds: 250),
                                            curve: Curves.easeInOut,
                                            child: const Icon(
                                              Icons.keyboard_arrow_down_rounded,
                                              color: Color(0xFF0F8790),
                                              size: 24,
                                            ),
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),

                                  // Expandable Details Section
                                  AnimatedCrossFade(
                                    firstChild: const SizedBox.shrink(),
                                    secondChild: Column(
                                      children: [
                                        const SizedBox(height: 10),
                                        const Divider(color: Color(0xFFE2EEEE), height: 1),
                                        const SizedBox(height: 10),
                                        _buildDetailRow(Icons.business_rounded, 'Location', assignedSitesText),
                                        if (empCode.isNotEmpty) ...[
                                          const SizedBox(height: 6),
                                          _buildDetailRow(Icons.badge_outlined, 'Employee ID', empCode),
                                        ],
                                        if (userEmail.isNotEmpty) ...[
                                          const SizedBox(height: 6),
                                          _buildDetailRow(Icons.email_outlined, 'Email', userEmail),
                                        ],
                                        if (userPhone.isNotEmpty) ...[
                                          const SizedBox(height: 6),
                                          _buildDetailRow(Icons.phone_android_outlined, 'Phone', userPhone),
                                        ],
                                        if (company.isNotEmpty) ...[
                                          const SizedBox(height: 6),
                                          _buildDetailRow(Icons.domain_rounded, 'Company', company),
                                        ],
                                        if (_quickUnlockCapable) ...[
                                          const SizedBox(height: 10),
                                          const Divider(color: Color(0xFFE2EEEE), height: 1),
                                          const SizedBox(height: 10),
                                          Row(
                                            children: [
                                              const Icon(Icons.fingerprint_rounded, size: 16, color: Color(0xFF0F8790)),
                                              const SizedBox(width: 8),
                                              const Expanded(
                                                child: Text(
                                                  'Quick Unlock (fingerprint/PIN)',
                                                  style: TextStyle(
                                                    color: Color(0xFF073B4C),
                                                    fontSize: 12,
                                                    fontWeight: FontWeight.w600,
                                                  ),
                                                ),
                                              ),
                                              const Text(
                                                'Required',
                                                style: TextStyle(
                                                  color: Color(0xFF0F8790),
                                                  fontSize: 11,
                                                  fontWeight: FontWeight.w700,
                                                ),
                                              ),
                                            ],
                                          ),
                                        ],
                                      ],
                                    ),
                                    crossFadeState: _isEmployeeCardExpanded
                                        ? CrossFadeState.showSecond
                                        : CrossFadeState.showFirst,
                                    duration: const Duration(milliseconds: 250),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          if (attendanceState.pendingSyncCount > 0)
                            InkWell(
                              borderRadius: BorderRadius.circular(12),
                              onTap: () => context.push('/sync-history'),
                              child: Container(
                              margin: const EdgeInsets.only(top: 10),
                              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                              decoration: BoxDecoration(
                                color: const Color(0xFFEFF6FF),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: const Color(0xFFBFDBFE)),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.cloud_off_rounded, color: Color(0xFF1D4ED8), size: 18),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Text(
                                      '${attendanceState.pendingSyncCount} action${attendanceState.pendingSyncCount == 1 ? '' : 's'} pending sync — tap to view.',
                                      style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF1D4ED8)),
                                    ),
                                  ),
                                ],
                              ),
                              ),
                            ),
                          const MissedAttendanceCard(),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: const PortalBottomNavBar(currentTab: NavTab.home),
    );
  }

  Widget _buildInitialAvatar(String name) {
    return Center(
      child: Text(
        name.isNotEmpty ? name[0].toUpperCase() : 'A',
        style: const TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.bold,
          color: Color(0xFF073B4C),
        ),
      ),
    );
  }

  Widget _buildDetailRow(IconData icon, String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: const Color(0xFFEAF5F5),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(icon, size: 14, color: const Color(0xFF0F8790)),
          const SizedBox(width: 8),
          Text(
            '$label: ',
            style: const TextStyle(
              color: Color(0xFF637D87),
              fontSize: 11.5,
              fontWeight: FontWeight.w500,
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                color: Color(0xFF073B4C),
                fontSize: 11.5,
                fontWeight: FontWeight.w600,
              ),
              overflow: TextOverflow.ellipsis,
              maxLines: 1,
            ),
          ),
        ],
      ),
    );
  }
}
