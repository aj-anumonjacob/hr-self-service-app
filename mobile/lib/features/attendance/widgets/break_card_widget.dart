import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../providers/attendance_provider.dart';
import '../../../core/config/theme.dart';

// Break timer states
enum _BreakTimerState { normal, endingSoon, expired, overdue }

class BreakCardWidget extends ConsumerStatefulWidget {
  const BreakCardWidget({super.key});

  @override
  ConsumerState<BreakCardWidget> createState() => _BreakCardWidgetState();
}

class _BreakCardWidgetState extends ConsumerState<BreakCardWidget> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _startLocalTimer();
  }

  void _startLocalTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  // ── Time formatting ────────────────────────────────────────────────────────

  /// Format a positive duration: MM:SS or H:MM:SS
  String _formatPositive(Duration d) {
    final abs = d.abs();
    String two(int n) => n.toString().padLeft(2, '0');
    final h = abs.inHours;
    final m = abs.inMinutes.remainder(60);
    final s = abs.inSeconds.remainder(60);
    if (h > 0) return '$h:${two(m)}:${two(s)}';
    return '${two(m)}:${two(s)}';
  }

  /// Format a signed duration: -MM:SS or -H:MM:SS (for overdue)
  String _formatSigned(Duration d) {
    if (!d.isNegative) return _formatPositive(d);
    return '-${_formatPositive(d)}';
  }

  String _formatTimeStr(String? isoStr) {
    if (isoStr == null || isoStr.trim().isEmpty) return '--:--';
    try {
      String str = isoStr.trim();
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
    return isoStr;
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final attendanceState = ref.watch(attendanceProvider);
    final breakState = attendanceState.breakState;
    final activeBreak = breakState?.activeBreak;
    final policy = breakState?.effectivePolicy;

    if (breakState == null && !attendanceState.isBreakLoading) {
      return const SizedBox.shrink();
    }

    final isBreakActive = activeBreak != null && activeBreak.status == 'active';
    final expectedEndAtStr = breakState?.expectedEndAt ?? activeBreak?.expectedEndAt;
    final allowedMins = policy?.allowedDurationMinutes ?? 60;

    Duration elapsedDuration = Duration.zero;
    Duration difference = Duration.zero;

    if (isBreakActive) {
      DateTime now = DateTime.now();

      DateTime? startDt;
      if (activeBreak.startedAt != null && activeBreak.startedAt!.isNotEmpty) {
        try {
          startDt = DateTime.parse(activeBreak.startedAt!).toLocal();
        } catch (_) {
          String cleanStr = activeBreak.startedAt!.trim();
          if (cleanStr.contains('T')) cleanStr = cleanStr.split('T')[1];
          else if (cleanStr.contains(' ')) cleanStr = cleanStr.split(' ')[1];
          cleanStr = cleanStr.split('+')[0].replaceAll('Z', '').trim();
          final parts = cleanStr.split(':');
          if (parts.length >= 2) {
            final hr = int.tryParse(parts[0]) ?? 0;
            final min = int.tryParse(parts[1]) ?? 0;
            final sec = parts.length >= 3 ? (int.tryParse(parts[2].split('.')[0]) ?? 0) : 0;
            startDt = DateTime(now.year, now.month, now.day, hr, min, sec);
          }
        }
      }

      if (startDt != null) {
        elapsedDuration = now.difference(startDt);
        if (elapsedDuration.isNegative) {
          elapsedDuration = Duration.zero;
        }
      }

      final allowedDuration = Duration(minutes: allowedMins);
      difference = allowedDuration - elapsedDuration;
    }

    // Determine timer state
    final _BreakTimerState timerState;
    if (!isBreakActive) {
      timerState = _BreakTimerState.normal;
    } else if (difference.isNegative) {
      timerState = _BreakTimerState.overdue;
    } else if (difference.inSeconds == 0) {
      timerState = _BreakTimerState.expired;
    } else if (difference.inMinutes <= 10) {
      timerState = _BreakTimerState.endingSoon;
    } else {
      timerState = _BreakTimerState.normal;
    }

    // Overdue elapsed for descriptive text
    final overdueElapsed = difference.isNegative ? difference.abs() : Duration.zero;

    // ── Colors & styling per state ──────────────────────────────────────────
    final Color borderColor;
    final Color iconColor;
    final Color labelColor;
    final Color panelBgColor;
    final Color panelBorderColor;
    final Color timerColor;
    final IconData headerIcon;
    final String headerLabel;
    final String badgeLabel;
    final Color badgeColor;

    if (isBreakActive) {
      switch (timerState) {
        case _BreakTimerState.overdue:
          borderColor = Colors.red[400]!;
          iconColor = Colors.red;
          labelColor = Colors.red[900]!;
          panelBgColor = Colors.red[50]!;
          panelBorderColor = Colors.red[200]!;
          timerColor = Colors.red[900]!;
          headerIcon = Icons.warning_amber_rounded;
          headerLabel = 'BREAK OVERDUE';
          badgeLabel = 'OVERDUE';
          badgeColor = Colors.red[100]!;
        case _BreakTimerState.expired:
          borderColor = Colors.red[300]!;
          iconColor = Colors.red[700]!;
          labelColor = Colors.red[800]!;
          panelBgColor = Colors.red[50]!;
          panelBorderColor = Colors.red[200]!;
          timerColor = Colors.red[900]!;
          headerIcon = Icons.timer_off;
          headerLabel = 'BREAK TIME EXPIRED';
          badgeLabel = 'EXPIRED';
          badgeColor = Colors.red[100]!;
        case _BreakTimerState.endingSoon:
          borderColor = AppTheme.champagneGold;
          iconColor = AppTheme.champagneGold;
          labelColor = AppTheme.darkText;
          panelBgColor = const Color(0xFFFFFBF2);
          panelBorderColor = AppTheme.champagneGold.withValues(alpha: 0.4);
          timerColor = AppTheme.darkText;
          headerIcon = Icons.alarm;
          headerLabel = 'BREAK ENDING SOON';
          badgeLabel = 'Ending Soon';
          badgeColor = AppTheme.champagneGold.withValues(alpha: 0.2);
        case _BreakTimerState.normal:
          borderColor = AppTheme.primaryTeal.withValues(alpha: 0.4);
          iconColor = AppTheme.primaryTeal;
          labelColor = AppTheme.darkText;
          panelBgColor = AppTheme.softAqua;
          panelBorderColor = AppTheme.primaryTeal.withValues(alpha: 0.2);
          timerColor = AppTheme.darkText;
          headerIcon = Icons.free_breakfast;
          headerLabel = 'ACTIVE BREAK SESSION';
          badgeLabel = 'On Break';
          badgeColor = AppTheme.primaryTeal.withValues(alpha: 0.15);
      }
    } else {
      // Non-active break state derived strictly from machine availabilityStatus
      final status = breakState?.availabilityStatus ?? 'unavailable';
      headerIcon = Icons.free_breakfast_outlined;
      headerLabel = 'EMPLOYEE BREAK POLICY';
      badgeLabel = breakState?.displayStatusLabel ?? 'Closed';

      switch (status) {
        case 'available':
          borderColor = AppTheme.softBorder;
          iconColor = AppTheme.primaryTeal;
          labelColor = AppTheme.darkText;
          panelBgColor = AppTheme.softAqua;
          panelBorderColor = AppTheme.softBorder;
          timerColor = AppTheme.darkText;
          badgeColor = AppTheme.softAqua;
        case 'limit_reached':
          borderColor = AppTheme.softBorder;
          iconColor = AppTheme.mutedText;
          labelColor = AppTheme.mutedText;
          panelBgColor = const Color(0xFFF8FAFC);
          panelBorderColor = AppTheme.softBorder;
          timerColor = AppTheme.mutedText;
          badgeColor = AppTheme.softAqua;
        case 'not_checked_in':
        case 'attendance_closed':
        case 'outside_allowed_time':
        case 'approval_required':
        case 'policy_inactive':
        case 'unavailable':
        default:
          borderColor = AppTheme.softBorder;
          iconColor = AppTheme.mutedText;
          labelColor = AppTheme.mutedText;
          panelBgColor = const Color(0xFFF8FAFC);
          panelBorderColor = AppTheme.softBorder;
          timerColor = AppTheme.mutedText;
          badgeColor = AppTheme.softAqua;
      }
    }

    return Container(
      decoration: BoxDecoration(
        color: AppTheme.pureWhite,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: borderColor, width: 1.0),
        boxShadow: const [
          BoxShadow(
            color: Color(0x14073B4C),
            blurRadius: 10,
            offset: Offset(0, 4),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ── Header Row ───────────────────────────────────────────────────
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(headerIcon, color: iconColor, size: 20),
                    const SizedBox(width: 8),
                    Text(
                      headerLabel,
                      style: TextStyle(
                        color: labelColor,
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ],
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: badgeColor,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    badgeLabel,
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.bold,
                      color: labelColor,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            if (isBreakActive) ...[
              // ── Timer Panel ──────────────────────────────────────────────
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: panelBgColor,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: panelBorderColor),
                ),
                child: Column(
                  children: [
                    // Large real-time timer display (Countdown timer: MM:SS or -MM:SS if overdue)
                    Text(
                      timerState == _BreakTimerState.overdue
                          ? _formatSigned(difference)
                          : _formatPositive(difference),
                      style: TextStyle(
                        fontSize: 38,
                        fontWeight: FontWeight.bold,
                        fontFamily: 'monospace',
                        color: timerColor,
                        letterSpacing: 1.0,
                      ),
                    ),
                    const SizedBox(height: 4),

                    // State-specific sub-labels
                    if (timerState == _BreakTimerState.overdue) ...[
                      Text(
                        'Overdue by ${_formatPositive(overdueElapsed)} (${allowedMins}m allowed)',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Colors.red[800],
                        ),
                      ),
                      const SizedBox(height: 6),
                      if (overdueElapsed.inMinutes >= 10)
                        _infoChip(
                          '👔 HR & Manager emailed & notified (10m overdue)',
                          Colors.red[700]!,
                        )
                      else
                        _infoChip(
                          '⚠️ Please end your break and resume work immediately.',
                          Colors.red[700]!,
                        ),
                    ] else if (timerState == _BreakTimerState.expired) ...[
                      Text(
                        'Allowed: ${allowedMins}m · Please end your break and resume work.',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Colors.red[800],
                        ),
                      ),
                    ] else if (timerState == _BreakTimerState.endingSoon) ...[
                      Text(
                        'Countdown to end (${allowedMins}m allowed break)',
                        style: TextStyle(
                          fontSize: 12,
                          color: Colors.amber[900],
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      _infoChip(
                        '⚠️ Break ending soon. Please prepare to resume work.',
                        Colors.amber[800]!,
                      ),
                    ] else ...[
                      Text(
                        'Remaining Time (${allowedMins}m total break allowed)',
                        style: TextStyle(
                          fontSize: 12,
                          color: Colors.grey[700],
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],

                    const SizedBox(height: 8),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        Text(
                          'Started: ${_formatTimeStr(activeBreak.startedAt)}',
                          style:
                              const TextStyle(fontSize: 10, color: Colors.grey),
                        ),
                        Text(
                          'Allowed: ${policy?.allowedDurationMinutes ?? 60}m',
                          style:
                              const TextStyle(fontSize: 10, color: Colors.grey),
                        ),
                        Text(
                          'Ends: ${_formatTimeStr(expectedEndAtStr)}',
                          style:
                              const TextStyle(fontSize: 10, color: Colors.grey),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),

              // ── End Break Button ─────────────────────────────────────────
              ElevatedButton.icon(
                onPressed: attendanceState.isBreakLoading
                    ? null
                    : () async {
                        await ref
                            .read(attendanceProvider.notifier)
                            .endBreak();
                      },
                icon: attendanceState.isBreakLoading
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.stop_circle,
                        color: Colors.white, size: 20),
                label: const Text(
                  'END BREAK NOW',
                  style: TextStyle(
                      color: Colors.white, fontWeight: FontWeight.bold),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.red[700],
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ] else ...[
              // ── No Active Break: Policy details & Start Break button ──────
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Policy: ${policy?.name ?? 'Flexible Break'} (${policy?.allowedDurationMinutes ?? 60}m max)',
                    style: const TextStyle(
                        fontSize: 11,
                        color: Colors.black87,
                        fontWeight: FontWeight.w600),
                  ),
                  Text(
                    'Remaining: ${breakState?.remainingBreaksCount ?? 0} break(s)',
                    style: TextStyle(
                        fontSize: 11,
                        color: (breakState?.remainingBreaksCount ?? 0) <= 0
                            ? Colors.red[700]
                            : Colors.grey,
                        fontWeight: FontWeight.bold),
                  ),
                ],
              ),
              const SizedBox(height: 10),

              if (breakState?.canStartBreak == true)
                ElevatedButton.icon(
                  onPressed: attendanceState.isBreakLoading
                      ? null
                      : () async {
                          await ref
                              .read(attendanceProvider.notifier)
                              .startBreak();
                        },
                  icon: attendanceState.isBreakLoading
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.play_circle_fill,
                          color: Colors.white, size: 20),
                  label: const Text(
                    'START BREAK',
                    style: TextStyle(
                        color: Colors.white, fontWeight: FontWeight.bold),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.darkGold,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                  ),
                )
              else
                Container(
                  padding:
                      const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                  decoration: BoxDecoration(
                    color: Colors.grey[100],
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    breakState?.restrictionMessage ??
                        (breakState?.unavailableReason == 'NOT_CHECKED_IN'
                            ? 'Check in to enable break actions.'
                            : breakState?.unavailableReason == 'MAX_BREAKS_REACHED'
                                ? 'Maximum break count reached for this shift.'
                                : breakState?.unavailableReason == 'BREAK_NOT_ALLOWED_AT_THIS_TIME'
                                    ? 'Break not permitted at current time under policy schedule.'
                                    : 'Break unavailable.'),
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        color: Colors.grey,
                        fontSize: 11,
                        fontWeight: FontWeight.w500),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _infoChip(String text, Color bgColor) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: const TextStyle(
            color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
      ),
    );
  }
}
