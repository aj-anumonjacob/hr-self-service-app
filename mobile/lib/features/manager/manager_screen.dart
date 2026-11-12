import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/config/theme.dart';
import '../auth/providers/auth_provider.dart';
import '../notifications/providers/notification_provider.dart';
import '../../core/widgets/notification_bell.dart';
import '../../core/widgets/connectivity_badge.dart';

class ManagerScreen extends ConsumerStatefulWidget {
  const ManagerScreen({super.key});

  @override
  ConsumerState<ManagerScreen> createState() => _ManagerScreenState();
}

class _ManagerScreenState extends ConsumerState<ManagerScreen> {
  @override
  void initState() {
    super.initState();
    Future.microtask(
      () => ref.read(notificationProvider.notifier).fetchUnreadCount(),
    );
  }
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Manager Console'),
        actions: [
          const ConnectivityBadge(),
          const NotificationBell(),
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Log Out',
            onPressed: () async {
              ref.read(notificationProvider.notifier).clearState();
              await ref.read(authProvider.notifier).logout();
              if (context.mounted) {
                context.go('/login');
              }
            },
          ),
        ],
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: 0,
        selectedItemColor: AppTheme.darkGold,
        unselectedItemColor: Colors.grey,
        onTap: (index) {
          if (index == 2) {
            context.push('/manager/requests');
          } else if (index == 3) {
            context.push('/calendar');
          }
        },
        items: const [
          BottomNavigationBarItem(icon: Icon(Icons.dashboard), label: 'Dashboard'),
          BottomNavigationBarItem(icon: Icon(Icons.group), label: 'Team'),
          BottomNavigationBarItem(icon: Icon(Icons.assignment_turned_in), label: 'Requests'),
          BottomNavigationBarItem(icon: Icon(Icons.calendar_month), label: 'Calendar'),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(20.0),
        children: [
          const Text('TEAM STATUS SUMMARY', style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.grey)),
          const SizedBox(height: 12),
          const Row(
            children: [
              Expanded(
                child: Card(
                  color: Color(0xFFE8F5E9),
                  child: Padding(
                    padding: EdgeInsets.all(16.0),
                    child: Column(
                      children: [
                        Text('PRESENT', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.green)),
                        Text('12 / 15', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Colors.green)),
                      ],
                    ),
                  ),
                ),
              ),
              Expanded(
                child: Card(
                  color: Color(0xFFFFEBEE),
                  child: Padding(
                    padding: EdgeInsets.all(16.0),
                    child: Column(
                      children: [
                        Text('ABSENT', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.red)),
                        Text('3 / 15', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Colors.red)),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('PENDING APPROVALS', style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.grey)),
              TextButton(
                onPressed: () => context.push('/manager/requests'),
                child: const Text('View All Requests >'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Card(
            color: Colors.white,
            child: ListTile(
              onTap: () => context.push('/manager/requests'),
              leading: const CircleAvatar(backgroundColor: AppTheme.darkGold, child: Icon(Icons.assignment_turned_in, color: Colors.white)),
              title: const Text('Manage Requests & Claims'),
              subtitle: const Text('Review employee late arrival, half-day, and attendance claims'),
              trailing: const Icon(Icons.chevron_right, color: Colors.grey),
            ),
          ),
        ],
      ),
    );
  }
}
