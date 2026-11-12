import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/notifications/models/notification_model.dart';
import 'package:mobile/features/notifications/providers/notification_provider.dart';
import 'package:mobile/features/notifications/data/notification_repository.dart';

class MockNotificationRepository extends NotificationRepository {
  int unreadCountToReturn = 2;
  List<InAppNotificationModel> itemsToReturn = [];

  @override
  Future<int> getUnreadCount() async {
    return unreadCountToReturn;
  }

  @override
  Future<Map<String, dynamic>> getNotifications({
    int page = 1,
    int perPage = 20,
    bool? unreadOnly,
  }) async {
    return {
      'items': itemsToReturn,
      'current_page': 1,
      'last_page': 1,
      'total': itemsToReturn.length,
    };
  }

  @override
  Future<InAppNotificationModel> markRead(String uuid) async {
    final found = itemsToReturn.firstWhere((n) => n.uuid == uuid);
    return found.copyWith(isRead: true);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late MockNotificationRepository mockRepo;
  late NotificationNotifier notifier;

  setUp(() {
    mockRepo = MockNotificationRepository();
    notifier = NotificationNotifier(mockRepo);
  });

  tearDown(() {
    notifier.dispose();
  });

  test('pollLatestNotifications updates state and queues active alert for new unread item', () async {
    final sampleItem = InAppNotificationModel(
      id: 101,
      uuid: 'notif-uuid-1',
      type: InAppNotificationModel.typeBreakExpired,
      title: 'Break Expired',
      message: 'Your break has expired',
      isRead: false,
      createdAt: DateTime.now(),
    );

    mockRepo.unreadCountToReturn = 1;
    mockRepo.itemsToReturn = [sampleItem];

    await notifier.pollLatestNotifications();

    expect(notifier.state.unreadCount, 1);
    expect(notifier.state.notifications.length, 1);
    expect(notifier.state.activeAlert?.uuid, 'notif-uuid-1');
  });

  test('dismissAlert clears activeAlert while preserving notifications list', () async {
    final sampleItem = InAppNotificationModel(
      id: 102,
      uuid: 'notif-uuid-2',
      type: InAppNotificationModel.typeBreakExpired,
      title: 'Break Expired',
      message: 'Your break has expired',
      isRead: false,
      createdAt: DateTime.now(),
    );

    mockRepo.unreadCountToReturn = 1;
    mockRepo.itemsToReturn = [sampleItem];

    await notifier.pollLatestNotifications();
    expect(notifier.state.activeAlert?.uuid, 'notif-uuid-2');

    notifier.dismissAlert();
    expect(notifier.state.activeAlert, isNull);
    expect(notifier.state.notifications.length, 1);
  });

  test('markRead updates unread count and optimistic state', () async {
    final sampleItem = InAppNotificationModel(
      id: 103,
      uuid: 'notif-uuid-3',
      type: InAppNotificationModel.typeBreakExpired,
      title: 'Break Expired',
      message: 'Your break has expired',
      isRead: false,
      createdAt: DateTime.now(),
    );

    mockRepo.unreadCountToReturn = 1;
    mockRepo.itemsToReturn = [sampleItem];

    await notifier.pollLatestNotifications();
    await notifier.markRead('notif-uuid-3');

    expect(notifier.state.unreadCount, 0);
    expect(notifier.state.notifications.first.isRead, isTrue);
    expect(notifier.state.activeAlert, isNull);
  });
}
