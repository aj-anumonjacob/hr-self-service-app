<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Models\MobileDevice;
use App\Models\PushNotificationToken;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * NotificationController
 *
 * All endpoints are scoped to the authenticated user.
 * Employees see only their own notifications.
 * Managers/admins see notifications addressed to their user account.
 *
 * Routes (all require auth:sanctum):
 *   GET  /me/notifications               – paginated list
 *   GET  /me/notifications/unread-count  – badge count
 *   POST /me/notifications/{uuid}/read   – mark single read
 *   POST /me/notifications/read-all      – mark all read
 */
class NotificationController extends Controller
{
    protected InAppNotificationService $inAppService;

    public function __construct(InAppNotificationService $inAppService)
    {
        $this->inAppService = $inAppService;
    }

    /**
     * GET /me/notifications
     *
     * Query params:
     *   - per_page  int   (default 20, max 50)
     *   - page      int   (default 1)
     *   - unread    bool  (optional: return only unread)
     *   - type      string (optional: filter by notification type)
     */
    public function index(Request $request)
    {
        $user    = $request->user();
        $perPage = min($request->integer('per_page', 20), 50);

        $query = InAppNotification::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->boolean('unread')) {
            $query->where('is_read', false);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'        => $notifications->items(),
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
                'per_page'     => $notifications->perPage(),
            ],
        ]);
    }

    /**
     * GET /me/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        $user  = $request->user();
        $count = $this->inAppService->unreadCount($user->id);

        return response()->json([
            'success' => true,
            'data'    => ['unread_count' => $count],
        ]);
    }

    /**
     * POST /me/notifications/{uuid}/read
     */
    public function markRead(Request $request, string $uuid)
    {
        $user = $request->user();

        $notification = InAppNotification::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
                'error'   => ['code' => 'NOT_FOUND'],
            ], 404);
        }

        $this->inAppService->markRead($notification);

        return response()->json([
            'success' => true,
            'data'    => $notification->fresh(),
        ]);
    }

    /**
     * POST /me/notifications/read-all
     */
    public function markAllRead(Request $request)
    {
        $user = $request->user();

        InAppNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'data'    => ['unread_count' => 0],
        ]);
    }

    /**
     * POST /me/notifications/push-token
     *
     * Called by the mobile app right after it obtains (or refreshes) its
     * Firebase Cloud Messaging registration token, so the server can send
     * real push notifications to this device even when the app is closed.
     */
    public function registerPushToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform_device_id' => 'required|string',
            'fcm_token'           => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile linked to this account.',
            ], 422);
        }

        $device = MobileDevice::where('employee_id', $employee->id)
            ->where('platform_device_id', $request->input('platform_device_id'))
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Device not recognized. Please log in again.',
            ], 404);
        }

        // A token rotated by Firebase for this device replaces the old one.
        PushNotificationToken::where('device_id', $device->id)
            ->where('fcm_token', '!=', $request->input('fcm_token'))
            ->update(['active' => false]);

        $token = PushNotificationToken::updateOrCreate(
            ['employee_id' => $employee->id, 'device_id' => $device->id],
            ['fcm_token' => $request->input('fcm_token'), 'active' => true]
        );

        return response()->json([
            'success' => true,
            'data' => ['id' => $token->id],
        ]);
    }
}
