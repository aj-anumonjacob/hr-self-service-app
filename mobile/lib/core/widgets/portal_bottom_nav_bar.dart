import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../config/theme.dart';

enum NavTab { home, tasks, recents, leave, requests, calendar }

class PortalBottomNavBar extends StatelessWidget {
  final NavTab? currentTab;
  final int? currentIndex;

  const PortalBottomNavBar({
    super.key,
    this.currentTab,
    this.currentIndex,
  });

  @override
  Widget build(BuildContext context) {
    const items = [
      _NavItemData(tab: NavTab.home, route: '/dashboard', icon: Icons.home_rounded, activeIcon: Icons.home_rounded, label: 'Home'),
      _NavItemData(tab: NavTab.recents, route: '/recents', icon: Icons.history_rounded, activeIcon: Icons.history_rounded, label: 'Recents'),
      _NavItemData(tab: NavTab.leave, route: '/leave', icon: Icons.flight_takeoff_rounded, activeIcon: Icons.flight_takeoff_rounded, label: 'Leave'),
      _NavItemData(tab: NavTab.requests, route: '/requests', icon: Icons.assignment_rounded, activeIcon: Icons.assignment_rounded, label: 'Requests'),
      _NavItemData(tab: NavTab.calendar, route: '/calendar', icon: Icons.calendar_month_rounded, activeIcon: Icons.calendar_month_rounded, label: 'Calendar'),
    ];

    int activeIndex = 0;
    if (currentTab != null) {
      final idx = items.indexWhere((it) => it.tab == currentTab);
      if (idx != -1) {
        activeIndex = idx;
      } else {
        activeIndex = currentIndex ?? 0;
      }
    } else {
      activeIndex = currentIndex ?? 0;
    }

    return Container(
      decoration: const BoxDecoration(
        color: AppTheme.deepTeal,
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        boxShadow: [
          BoxShadow(
            color: Color(0x40000000),
            blurRadius: 16,
            offset: Offset(0, -4),
          ),
          BoxShadow(
            color: Color(0x300F8790),
            blurRadius: 3,
            offset: Offset(0, -1),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Container(
          height: 66,
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: List.generate(items.length, (index) {
              final isSelected = index == activeIndex;
              final item = items[index];

              return Expanded(
                child: GestureDetector(
                  onTap: () {
                    if (!isSelected) {
                      context.go(item.route);
                    }
                  },
                  behavior: HitTestBehavior.opaque,
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    curve: Curves.easeOutCubic,
                    padding: const EdgeInsets.symmetric(vertical: 5),
                    decoration: BoxDecoration(
                      color: isSelected ? const Color(0x22C6A15B) : Colors.transparent,
                      borderRadius: BorderRadius.circular(14),
                      border: isSelected
                          ? Border.all(color: const Color(0x55C6A15B), width: 1)
                          : Border.all(color: Colors.transparent, width: 1),
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        AnimatedScale(
                          scale: isSelected ? 1.15 : 1.0,
                          duration: const Duration(milliseconds: 200),
                          child: Icon(
                            isSelected ? item.activeIcon : item.icon,
                            size: 21,
                            color: isSelected ? AppTheme.champagneGold : const Color(0xFF8BA3A6),
                          ),
                        ),
                        const SizedBox(height: 3),
                        AnimatedDefaultTextStyle(
                          duration: const Duration(milliseconds: 200),
                          style: TextStyle(
                            fontSize: isSelected ? 11.5 : 10.5,
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                            color: isSelected ? AppTheme.champagneGold : const Color(0xFF8BA3A6),
                            letterSpacing: 0.2,
                          ),
                          child: Text(item.label),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            }),
          ),
        ),
      ),
    );
  }
}

class _NavItemData {
  final NavTab tab;
  final String route;
  final IconData icon;
  final IconData activeIcon;
  final String label;

  const _NavItemData({
    required this.tab,
    required this.route,
    required this.icon,
    required this.activeIcon,
    required this.label,
  });
}
