<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\OfflineController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\CorrectionController;
use App\Http\Controllers\Api\V1\ManagerController;
use App\Http\Controllers\Api\V1\BreakPolicyController;
use App\Http\Controllers\Api\V1\AttendanceEventController;
use App\Http\Controllers\Api\V1\MaintenanceWorkOrderController;
use App\Http\Controllers\Api\V1\MaintenanceRequestController;
use App\Http\Controllers\Api\V1\InventoryMaterialRequestController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\AttendanceRequestController;
use App\Http\Controllers\Api\V1\AttendanceClaimController;
use App\Http\Controllers\Api\V1\AttendancePolicySettingController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\CleaningScheduleController;
use App\Http\Controllers\Api\V1\HolidayController;

use App\Http\Controllers\Api\V1\SecurityConfigController;
use App\Http\Controllers\Api\V1\AdminDeviceController;

Route::prefix('v1')->group(function () {
    // Guest Routes
    Route::post('/auth/request-activation-code', [AuthController::class, 'requestActivationCode']);
    Route::post('/auth/request-activation-code/set-email', [AuthController::class, 'setActivationEmail']);
    Route::post('/auth/activate', [AuthController::class, 'activate']);
    Route::post('/auth/login', [AuthController::class, 'login'])->name('login');
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/redeem-certificate', [AuthController::class, 'redeemCertificate']);
    Route::post('/auth/forgot-password/request-otp', [AuthController::class, 'requestPasswordResetOtp']);
    Route::post('/auth/forgot-password/verify-otp', [AuthController::class, 'verifyPasswordResetOtp']);
    Route::post('/auth/forgot-password/reset', [AuthController::class, 'resetPassword']);

    // Admin & Security Management Routes (Unprotected fallback for local testing & Protected via auth:sanctum)
    Route::post('/admin/devices/generate-code', [AdminDeviceController::class, 'generateCode']);
    Route::post('/admin/devices/unbind', [AdminDeviceController::class, 'unbind']);
    Route::get('/admin/audit-logs', [AdminDeviceController::class, 'auditLogs']);
    Route::get('/admin/security-config', [SecurityConfigController::class, 'show']);
    Route::put('/admin/security-config', [SecurityConfigController::class, 'update']);

    // Internal server-to-server route (PHP HR portal -> this API), guarded by
    // the X-Internal-Secret header instead of Sanctum since the caller is a
    // trusted server, not a logged-in mobile user.
    Route::post('/internal/push/send', [\App\Http\Controllers\Api\V1\InternalPushController::class, 'send']);

    // Authenticated Routes
    Route::middleware('auth:sanctum')->group(function () {
        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Profile
        Route::get('/me', [ProfileController::class, 'me']);

        // Holidays
        Route::get('/holidays', [HolidayController::class, 'index']);

        // Maintenance Work Orders Schedule
        Route::get('/me/maintenance/schedules', [MaintenanceWorkOrderController::class, 'index']);
        Route::post('/me/maintenance/schedules/{id}/status', [MaintenanceWorkOrderController::class, 'updateStatus']);
        Route::post('/me/maintenance/schedules/{id}/photos', [MaintenanceWorkOrderController::class, 'uploadPhoto']);

        // Cleaning Schedules (For Cleaners, Admins, and Owners)
        Route::get('/me/cleaning/schedules', [CleaningScheduleController::class, 'index']);
        Route::post('/me/cleaning/schedules/{id}/status', [CleaningScheduleController::class, 'updateStatus']);

        // Maintenance Requests & Material Requests
        Route::get('/me/maintenance/requests', [MaintenanceRequestController::class, 'index']);
        Route::get('/me/maintenance/requests/{id}', [MaintenanceRequestController::class, 'show']);
        Route::post('/me/maintenance/requests/{id}/status', [MaintenanceRequestController::class, 'updateStatus']);
        Route::post('/me/maintenance/requests/{id}/photos', [MaintenanceRequestController::class, 'uploadPhoto']);

        // Inventory Material Requests
        Route::get('/inventory/items', [InventoryMaterialRequestController::class, 'catalog']);
        Route::post('/me/inventory/material-requests', [InventoryMaterialRequestController::class, 'store']);
        Route::get('/me/maintenance/requests/{id}/material-requests', [InventoryMaterialRequestController::class, 'indexForWorkOrder']);

        // Shifts & Shift Allocations
        Route::get('/shifts', [\App\Http\Controllers\Api\V1\ShiftController::class, 'index']);
        Route::post('/shifts', [\App\Http\Controllers\Api\V1\ShiftController::class, 'store']);
        Route::put('/shifts/{id}', [\App\Http\Controllers\Api\V1\ShiftController::class, 'update']);
        Route::delete('/shifts/{id}', [\App\Http\Controllers\Api\V1\ShiftController::class, 'destroy']);
        Route::post('/shifts/assign', [\App\Http\Controllers\Api\V1\ShiftController::class, 'assign']);
        Route::get('/shifts/allocations', [\App\Http\Controllers\Api\V1\ShiftController::class, 'allocations']);
        Route::get('/employees/{id}/shift', [\App\Http\Controllers\Api\V1\ShiftController::class, 'getEmployeeShift']);

        // In-App Notifications
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/me/notifications/{uuid}/read', [NotificationController::class, 'markRead']);
        Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/me/notifications/push-token', [NotificationController::class, 'registerPushToken']);
        Route::get('/me/today-context', [ProfileController::class, 'todayContext']);

        // Attendance, Breaks & Recents Activity
        Route::get('/me/attendance', [AttendanceController::class, 'history']);
        Route::get('/me/attendance/today', [AttendanceController::class, 'today']);
        Route::get('/me/attendance/missed', [AttendanceController::class, 'missedAttendance']);
        Route::post('/me/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/me/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/me/break/state', [AttendanceController::class, 'getBreakState']);
        Route::post('/me/attendance/start-break', [AttendanceController::class, 'startBreak']);
        Route::post('/me/attendance/end-break', [AttendanceController::class, 'endBreak']);
        Route::get('/me/attendance-events', [AttendanceEventController::class, 'index']);

        // Policy Settings
        Route::get('/attendance-policy-settings', [AttendancePolicySettingController::class, 'show']);

        // Leave Management (Types, Balances, Requests)
        Route::get('/leave-types', [LeaveController::class, 'getLeaveTypes']);
        Route::get('/me/leave-balances', [LeaveController::class, 'getBalances']);
        Route::get('/me/leave-requests', [LeaveController::class, 'getRequests']);
        Route::post('/me/leave-requests', [LeaveController::class, 'storeRequest']);

        // Late Arrival & Morning Half-Day Requests (Employee)
        Route::get('/me/attendance-requests', [AttendanceRequestController::class, 'index']);
        Route::post('/me/attendance-requests', [AttendanceRequestController::class, 'store']);
        Route::get('/me/attendance-requests/{uuid}', [AttendanceRequestController::class, 'show']);
        Route::post('/me/attendance-requests/{uuid}/respond', [AttendanceRequestController::class, 'respond']);
        Route::post('/me/attendance-requests/{uuid}/cancel', [AttendanceRequestController::class, 'cancel']);

        // Attendance Claims (Employee)
        Route::get('/me/attendance-claims', [AttendanceClaimController::class, 'index']);
        Route::post('/me/attendance-claims', [AttendanceClaimController::class, 'store']);
        Route::get('/me/attendance-claims/{uuid}', [AttendanceClaimController::class, 'show']);
        Route::post('/me/attendance-claims/{uuid}/respond', [AttendanceClaimController::class, 'respond']);
        Route::post('/me/attendance-claims/{uuid}/cancel', [AttendanceClaimController::class, 'cancel']);

        // Offline Attendance
        Route::post('/me/offline-authorization', [OfflineController::class, 'issueAuthorization']);
        Route::post('/me/offline-attendance/sync', [OfflineController::class, 'sync']);

        // Tracking
        Route::post('/me/tracking/start', [TrackingController::class, 'start']);
        Route::post('/me/tracking/locations', [TrackingController::class, 'uploadLocations']);
        Route::post('/me/tracking/stop', [TrackingController::class, 'stop']);

        // Assignments
        Route::get('/me/assignments', [AssignmentController::class, 'index']);
        Route::post('/me/assignments/{uuid}/acknowledge', [AssignmentController::class, 'acknowledge']);

        // Corrections
        Route::get('/me/corrections', [CorrectionController::class, 'index']);
        Route::post('/me/corrections', [CorrectionController::class, 'store']);

        // Manager / Admin Routes
        Route::prefix('manager')->group(function () {
            Route::get('/dashboard', [ManagerController::class, 'dashboard']);
            Route::get('/employees', [ManagerController::class, 'employees']);
            Route::get('/attendance', [ManagerController::class, 'attendance']);
            Route::post('/corrections/{uuid}/approve', [ManagerController::class, 'approveCorrection']);
            Route::post('/corrections/{uuid}/reject', [ManagerController::class, 'rejectCorrection']);

            // Attendance Requests (Manager)
            Route::get('/attendance-requests', [AttendanceRequestController::class, 'managerIndex']);
            Route::get('/attendance-requests/{uuid}', [AttendanceRequestController::class, 'show']);
            Route::post('/attendance-requests/{uuid}/approve', [AttendanceRequestController::class, 'approve']);
            Route::post('/attendance-requests/{uuid}/reject', [AttendanceRequestController::class, 'reject']);
            Route::post('/attendance-requests/{uuid}/request-explanation', [AttendanceRequestController::class, 'requestExplanation']);

            // Attendance Claims (Manager)
            Route::get('/attendance-claims', [AttendanceClaimController::class, 'managerIndex']);
            Route::get('/attendance-claims/{uuid}', [AttendanceClaimController::class, 'show']);
            Route::post('/attendance-claims/{uuid}/approve', [AttendanceClaimController::class, 'approve']);
            Route::post('/attendance-claims/{uuid}/reject', [AttendanceClaimController::class, 'reject']);
            Route::post('/attendance-claims/{uuid}/request-explanation', [AttendanceClaimController::class, 'requestExplanation']);

            // Break Management & Overdue Monitoring
            Route::get('/break-policies', [BreakPolicyController::class, 'index']);
            Route::post('/break-policies', [BreakPolicyController::class, 'store']);
            Route::put('/break-policies/{uuid}', [BreakPolicyController::class, 'update']);
            Route::post('/break-policies/{uuid}/assign', [BreakPolicyController::class, 'assign']);
            Route::get('/breaks/overdue', [BreakPolicyController::class, 'activeOverdueBreaks']);
            Route::post('/breaks/{uuid}/correct', [BreakPolicyController::class, 'managerCorrectBreak']);
            Route::get('/break-settings', [BreakPolicyController::class, 'companySettings']);
            Route::put('/break-settings', [BreakPolicyController::class, 'updateCompanySettings']);
        });
    });
});

