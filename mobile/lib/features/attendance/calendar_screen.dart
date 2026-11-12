import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../../core/config/theme.dart';
import 'providers/attendance_provider.dart';
import '../../core/widgets/portal_header.dart';
import '../../core/widgets/portal_bottom_nav_bar.dart';

class CalendarScreen extends ConsumerStatefulWidget {
  const CalendarScreen({super.key});

  @override
  ConsumerState<CalendarScreen> createState() => _CalendarScreenState();
}

class _CalendarScreenState extends ConsumerState<CalendarScreen> {
  late DateTime _selectedMonth;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _selectedMonth = DateTime(now.year, now.month, 1);
    Future.microtask(() {
      _loadMonthlyData();
    });
  }

  void _loadMonthlyData() {
    final monthStr = DateFormat('yyyy-MM').format(_selectedMonth);
    ref.read(attendanceProvider.notifier).fetchMonthlyHistory(monthStr);
  }

  void _changeMonth(int delta) {
    setState(() {
      _selectedMonth = DateTime(_selectedMonth.year, _selectedMonth.month + delta, 1);
    });
    _loadMonthlyData();
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

  String _formatHours(int minutes) {
    if (minutes <= 0) return '0.0 hrs';
    final hours = minutes / 60.0;
    return '${hours.toStringAsFixed(1)} hrs';
  }

  void _showHolidayDetail(BuildContext context, Map<String, dynamic> holidayObj) {
    final name = holidayObj['name'] as String? ?? 'Official Holiday';
    final dateStr = holidayObj['date'] as String? ?? '';
    final notes = holidayObj['notes'] as String?;
    final isAnnual = holidayObj['repeats_annually'] == true;

    DateTime? parsedDate;
    try {
      parsedDate = DateTime.parse(dateStr);
    } catch (_) {}

    final formattedDate = parsedDate != null
        ? DateFormat('EEEE, d MMMM yyyy').format(parsedDate)
        : dateStr;

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) {
        return Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.grey[300],
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0x159C27B0),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.celebration_rounded, color: Color(0xFF7B1FA2), size: 32),
              ),
              const SizedBox(height: 12),
              Text(
                name,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: AppTheme.charcoal,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 4),
              Text(
                formattedDate,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF7B1FA2),
                ),
              ),
              if (isAnnual) ...[
                const SizedBox(height: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: Colors.purple.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: const Text(
                    'Annual Holiday (Repeats Yearly)',
                    style: TextStyle(
                      color: Color(0xFF7B1FA2),
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
              if (notes != null && notes.trim().isNotEmpty) ...[
                const SizedBox(height: 12),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF7F9F9),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    notes,
                    style: const TextStyle(
                      color: Color(0xFF555555),
                      fontSize: 12,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final attendanceState = ref.watch(attendanceProvider);
    final records = attendanceState.monthlyHistory ?? [];
    final rawHolidays = attendanceState.holidays ?? [];

    // Map records by date YYYY-MM-DD
    final Map<String, Map<String, dynamic>> recordsByDate = {};
    int totalMonthlyMinutes = 0;
    int presentDaysCount = 0;
    int lateDaysCount = 0;

    for (var item in records) {
      if (item is Map<String, dynamic>) {
        final date = item['date'] as String?;
        if (date != null) {
          recordsByDate[date] = item;
          final netMin = (item['net_duration_minutes'] as num?)?.toInt() ?? 
                         (item['total_duration_minutes'] as num?)?.toInt() ?? 0;
          totalMonthlyMinutes += netMin;

          final status = item['status'] as String? ?? 'present';
          final lateMin = (item['late_minutes'] as num?)?.toInt() ?? 0;
          if (status == 'late' || lateMin > 0) {
            lateDaysCount++;
            presentDaysCount++;
          } else if (status == 'present' || status == 'approved' || item['check_in_time'] != null) {
            presentDaysCount++;
          }
        }
      }
    }

    // Map holidays by date YYYY-MM-DD
    final Map<String, Map<String, dynamic>> holidaysByDate = {};
    for (var item in rawHolidays) {
      if (item is Map<String, dynamic>) {
        final dateStr = item['date'] as String?;
        if (dateStr != null) {
          holidaysByDate[dateStr] = item;
        }
      }
    }

    final daysInMonth = DateTime(_selectedMonth.year, _selectedMonth.month + 1, 0).day;
    final firstDayOfWeek = DateTime(_selectedMonth.year, _selectedMonth.month, 1).weekday % 7; // 0 = Sun

    // Dynamic Weekly Off Days from backend policy (0 = Sunday, 1 = Monday ... 6 = Saturday)
    final rawWeeklyOff = attendanceState.todayContext?['policy']?['weekly_off_days'] as List<dynamic>?;
    final List<int> weeklyOffDays = rawWeeklyOff != null
        ? rawWeeklyOff.map((e) => (e as num).toInt()).toList()
        : [0];

    // Compute Absent Days dynamically for passed working days of the selected month
    int absentDaysCount = 0;
    final now = DateTime.now();
    final todayStr = DateFormat('yyyy-MM-dd').format(now);

    for (int day = 1; day <= daysInMonth; day++) {
      final dateObj = DateTime(_selectedMonth.year, _selectedMonth.month, day);
      final dateStr = DateFormat('yyyy-MM-dd').format(dateObj);

      // Skip future dates
      if (dateStr.compareTo(todayStr) > 0) continue;

      final dayOfWeek = dateObj.weekday % 7;
      final isWeeklyOff = weeklyOffDays.contains(dayOfWeek);
      final isHoliday = holidaysByDate.containsKey(dateStr);

      if (isWeeklyOff || isHoliday) continue;

      final record = recordsByDate[dateStr];
      if (record != null) {
        final status = record['status'] as String? ?? 'present';
        if (status == 'absent') {
          absentDaysCount++;
        }
      } else {
        // Past working day without attendance record = Absent
        absentDaysCount++;
      }
    }

    return Scaffold(
      backgroundColor: AppTheme.lightAqua,
      appBar: PortalHeader(
        title: 'Attendance Calendar',
        actionButton: Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(10),
          ),
          child: IconButton(
            padding: EdgeInsets.zero,
            icon: const Icon(Icons.refresh_rounded, color: Colors.white, size: 18),
            tooltip: 'Refresh',
            onPressed: () {
              _loadMonthlyData();
            },
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          _loadMonthlyData();
        },
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Month Selector Header
              Card(
                color: Colors.white,
                elevation: 0,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                  side: BorderSide(color: Colors.grey[200]!),
                ),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 8.0),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      IconButton(
                        icon: const Icon(Icons.chevron_left, color: AppTheme.charcoal),
                        onPressed: () => _changeMonth(-1),
                      ),
                      Row(
                        children: [
                          const Icon(Icons.calendar_month, color: AppTheme.darkGold, size: 20),
                          const SizedBox(width: 8),
                          Text(
                            DateFormat('MMMM yyyy').format(_selectedMonth),
                            style: const TextStyle(
                              fontSize: 13.5,
                              fontWeight: FontWeight.bold,
                              color: AppTheme.charcoal,
                            ),
                          ),
                        ],
                      ),
                      IconButton(
                        icon: const Icon(Icons.chevron_right, color: AppTheme.charcoal),
                        onPressed: () => _changeMonth(1),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),

              // Monthly Summary Overview Card (Hours, Present, Late, Absent, Holidays)
              Card(
                color: AppTheme.charcoal,
                elevation: 2,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 6.0, vertical: 14.0),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      Column(
                        children: [
                          const Text('HOURS', style: TextStyle(color: Colors.grey, fontSize: 9, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          Text(
                            _formatHours(totalMonthlyMinutes),
                            style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                      Container(width: 1, height: 28, color: Colors.grey[700]),
                      Column(
                        children: [
                          const Text('PRESENT', style: TextStyle(color: Colors.grey, fontSize: 9, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          Text(
                            '$presentDaysCount Days',
                            style: const TextStyle(color: Colors.greenAccent, fontSize: 13, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                      Container(width: 1, height: 28, color: Colors.grey[700]),
                      Column(
                        children: [
                          const Text('LATE', style: TextStyle(color: Colors.grey, fontSize: 9, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          Text(
                            '$lateDaysCount Days',
                            style: TextStyle(color: lateDaysCount > 0 ? Colors.orangeAccent : Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                      Container(width: 1, height: 28, color: Colors.grey[700]),
                      Column(
                        children: [
                          const Text('HOLIDAYS', style: TextStyle(color: Colors.grey, fontSize: 9, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          Text(
                            '${rawHolidays.length} Days',
                            style: const TextStyle(color: Color(0xFFE1BEE7), fontSize: 13, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Calendar Days Header (Dynamic weekly off highlights)
              Row(
                children: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].asMap().entries.map((entry) {
                  final dayIndex = entry.key; // 0 = Sun ... 6 = Sat
                  final dayName = entry.value;
                  final isOffHeader = weeklyOffDays.contains(dayIndex);
                  return Expanded(
                    child: Center(
                      child: Text(
                        dayName,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                          color: isOffHeader ? Colors.red[400] : Colors.grey[600],
                        ),
                      ),
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 8),

              // Calendar Grid View
              if (attendanceState.isLoading && attendanceState.monthlyHistory == null)
                const Padding(
                  padding: EdgeInsets.all(32.0),
                  child: Center(child: CircularProgressIndicator(color: AppTheme.darkGold)),
                )
              else
                GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: daysInMonth + firstDayOfWeek,
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 7,
                    childAspectRatio: 0.50,
                    crossAxisSpacing: 3,
                    mainAxisSpacing: 3,
                  ),
                  itemBuilder: (context, index) {
                    if (index < firstDayOfWeek) {
                      return const SizedBox.shrink();
                    }

                    final dayNumber = index - firstDayOfWeek + 1;
                    final dateObj = DateTime(_selectedMonth.year, _selectedMonth.month, dayNumber);
                    final dateStr = DateFormat('yyyy-MM-dd').format(dateObj);
                    final record = recordsByDate[dateStr];
                    final holidayObj = holidaysByDate[dateStr];
                    final isHoliday = holidayObj != null;

                    // Dynamic Weekly Off Check (0 = Sun, 1 = Mon ... 6 = Sat)
                    final dayOfWeek = dateObj.weekday % 7;
                    final isWeeklyOff = weeklyOffDays.contains(dayOfWeek);

                    final hasRecord = record != null;
                    final checkInStr = record?['check_in_time'] ?? record?['check_in_event']?['event_time_server'];
                    final checkOutStr = record?['check_out_time'] ?? record?['check_out_event']?['event_time_server'];

                    final netMin = (record?['net_duration_minutes'] as num?)?.toInt() ?? 
                                   (record?['total_duration_minutes'] as num?)?.toInt() ?? 0;
                    final status = record?['status'] as String?;
                    final lateMin = (record?['late_minutes'] as num?)?.toInt() ?? 0;

                    Color cardBorderColor = Colors.grey[200]!;
                    Color statusBgColor = Colors.grey[100]!;
                    Color statusTextColor = Colors.grey[600]!;
                    String statusLabel = isWeeklyOff ? 'OFF' : '--';

                    if (isWeeklyOff) {
                      cardBorderColor = Colors.red[100]!;
                      statusBgColor = Colors.red.withAlpha(15);
                      statusTextColor = Colors.red[700]!;
                    }

                    if (isHoliday) {
                      cardBorderColor = const Color(0xFFAB47BC);
                      statusBgColor = const Color(0x259C27B0);
                      statusTextColor = const Color(0xFF7B1FA2);
                      statusLabel = 'HOLIDAY';
                    } else if (hasRecord) {
                      if (status == 'late' || lateMin > 0) {
                        cardBorderColor = Colors.orange[300]!;
                        statusBgColor = Colors.orange.withAlpha(25);
                        statusTextColor = Colors.orange[900]!;
                        statusLabel = 'LATE';
                      } else if (status == 'present' || checkInStr != null) {
                        cardBorderColor = Colors.green[300]!;
                        statusBgColor = Colors.green.withAlpha(25);
                        statusTextColor = Colors.green[800]!;
                        statusLabel = 'PRESENT';
                      } else if (status == 'absent') {
                        cardBorderColor = Colors.red[300]!;
                        statusBgColor = Colors.red.withAlpha(25);
                        statusTextColor = Colors.red[800]!;
                        statusLabel = 'ABSENT';
                      }
                    }

                    return InkWell(
                      onTap: isHoliday && holidayObj != null ? () => _showHolidayDetail(context, holidayObj) : null,
                      borderRadius: BorderRadius.circular(6),
                      child: Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: cardBorderColor, width: (hasRecord || isHoliday) ? 1.5 : 1.0),
                        ),
                        padding: const EdgeInsets.all(2.5),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            // Day Number & Badge Header
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Text(
                                  '$dayNumber',
                                  style: TextStyle(
                                    fontSize: 10.0,
                                    fontWeight: FontWeight.bold,
                                    color: isHoliday
                                        ? const Color(0xFF7B1FA2)
                                        : (isWeeklyOff ? Colors.red[700] : AppTheme.charcoal),
                                  ),
                                ),
                                Flexible(
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 1.5, vertical: 0.5),
                                    decoration: BoxDecoration(
                                      color: statusBgColor,
                                      borderRadius: BorderRadius.circular(3),
                                    ),
                                    child: FittedBox(
                                      fit: BoxFit.scaleDown,
                                      child: Text(
                                        statusLabel,
                                        style: TextStyle(
                                          fontSize: 6.0,
                                          fontWeight: FontWeight.bold,
                                          color: statusTextColor,
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                            const Divider(height: 2, thickness: 0.5),
                            
                            // Check in time or Holiday indicator
                            if (isHoliday)
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerLeft,
                                child: Text(
                                  '🎉 ${holidayObj['name']}',
                                  style: const TextStyle(
                                    fontSize: 6.5,
                                    fontWeight: FontWeight.bold,
                                    color: Color(0xFF7B1FA2),
                                  ),
                                ),
                              )
                            else ...[
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerLeft,
                                child: Text(
                                  'IN: ${_formatTime(checkInStr)}',
                                  style: const TextStyle(fontSize: 7.0, color: Colors.black87),
                                ),
                              ),
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerLeft,
                                child: Text(
                                  'OUT: ${_formatTime(checkOutStr)}',
                                  style: const TextStyle(fontSize: 7.0, color: Colors.black87),
                                ),
                              ),
                            ],

                            // Total Hours Worked Box or Holiday Banner
                            Container(
                              padding: const EdgeInsets.symmetric(vertical: 1.5),
                              decoration: BoxDecoration(
                                color: isHoliday
                                    ? const Color(0x259C27B0)
                                    : (netMin > 0 ? AppTheme.darkGold.withAlpha(20) : Colors.grey[100]),
                                borderRadius: BorderRadius.circular(3),
                              ),
                              child: FittedBox(
                                fit: BoxFit.scaleDown,
                                child: Text(
                                  isHoliday ? 'HOLIDAY' : _formatHours(netMin),
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    fontSize: 7.0,
                                    fontWeight: FontWeight.bold,
                                    color: isHoliday
                                        ? const Color(0xFF7B1FA2)
                                        : (netMin > 0 ? AppTheme.darkGold : Colors.grey[600]),
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),

              // ── Holidays Section Card List ────────────────────────────────
              if (rawHolidays.isNotEmpty) ...[
                const SizedBox(height: 18),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xFFE2EEEE)),
                    boxShadow: const [
                      BoxShadow(
                        color: Color(0x0C000000),
                        blurRadius: 8,
                        offset: Offset(0, 3),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: const [
                          Icon(Icons.celebration_rounded, color: Color(0xFF7B1FA2), size: 20),
                          SizedBox(width: 8),
                          Text(
                            'Official Holidays this Month',
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.bold,
                              color: AppTheme.charcoal,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 10),
                      const Divider(height: 1, color: Color(0xFFEEEEEE)),
                      const SizedBox(height: 8),
                      ...rawHolidays.map((h) {
                        if (h is! Map<String, dynamic>) return const SizedBox.shrink();
                        final name = h['name'] as String? ?? 'Public Holiday';
                        final dateStr = h['date'] as String? ?? '';
                        final isAnnual = h['repeats_annually'] == true;
                        final notes = h['notes'] as String?;

                        DateTime? parsedDate;
                        try {
                          parsedDate = DateTime.parse(dateStr);
                        } catch (_) {}

                        final formattedDate = parsedDate != null
                            ? DateFormat('EEE, d MMM yyyy').format(parsedDate)
                            : dateStr;

                        return Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6.0),
                          child: InkWell(
                            onTap: () => _showHolidayDetail(context, h),
                            borderRadius: BorderRadius.circular(8),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(vertical: 2.0),
                              child: Row(
                                children: [
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                    decoration: BoxDecoration(
                                      color: const Color(0x209C27B0),
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    child: Text(
                                      formattedDate,
                                      style: const TextStyle(
                                        color: Color(0xFF7B1FA2),
                                        fontSize: 11,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          name,
                                          style: const TextStyle(
                                            color: AppTheme.charcoal,
                                            fontSize: 12.5,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                        if (notes != null && notes.trim().isNotEmpty) ...[
                                          const SizedBox(height: 1),
                                          Text(
                                            notes,
                                            style: TextStyle(
                                              color: Colors.grey[600],
                                              fontSize: 10.5,
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  if (isAnnual)
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: Colors.purple.withValues(alpha: 0.1),
                                        borderRadius: BorderRadius.circular(4),
                                      ),
                                      child: const Text(
                                        'Annual',
                                        style: TextStyle(
                                          color: Color(0xFF7B1FA2),
                                          fontSize: 9.5,
                                          fontWeight: FontWeight.bold,
                                        ),
                                      ),
                                    ),
                                ],
                              ),
                            ),
                          ),
                        );
                      }),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
      bottomNavigationBar: const PortalBottomNavBar(currentTab: NavTab.calendar),
    );
  }
}
