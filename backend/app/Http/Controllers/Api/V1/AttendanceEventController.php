<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceEventController extends Controller
{
    /**
     * Get authenticated employee's attendance activity events (Recents)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee profile not found.'
            ], 404);
        }

        $perPage = min((int) ($request->input('per_page', 20)), 100);
        $filter = $request->input('filter', 'all'); // all, attendance, breaks

        $query = AttendanceEvent::where('employee_id', $employee->id);

        if ($filter === 'attendance') {
            $query->whereIn('event_type', ['ATTENDANCE_CHECKED_IN', 'ATTENDANCE_CHECKED_OUT', 'check_in', 'check_out', 'ATTENDANCE_CORRECTED']);
        } elseif ($filter === 'breaks') {
            $query->whereIn('event_type', ['BREAK_STARTED', 'BREAK_ENDED', 'BREAK_CORRECTED', 'MANAGER_CORRECTION']);
        }

        if ($request->filled('start_date')) {
            $query->where('event_time_server', '>=', Carbon::parse($request->start_date)->startOfDay());
        }

        if ($request->filled('end_date')) {
            $query->where('event_time_server', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        $paginator = $query->orderBy('event_time_server', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $tz = ($employee->company && $employee->company->timezone) ? $employee->company->timezone : 'Asia/Dubai';

        $items = collect($paginator->items())->map(function ($evt) use ($tz) {
            $localTime = $evt->event_time_server ? $evt->event_time_server->copy()->setTimezone($tz) : null;
            $stdType = strtoupper($evt->event_type);
            if ($stdType === 'CHECK_IN') $stdType = 'ATTENDANCE_CHECKED_IN';
            if ($stdType === 'CHECK_OUT') $stdType = 'ATTENDANCE_CHECKED_OUT';

            $title = 'Activity';
            if ($stdType === 'ATTENDANCE_CHECKED_IN') $title = 'Checked In';
            elseif ($stdType === 'ATTENDANCE_CHECKED_OUT') $title = 'Checked Out';
            elseif ($stdType === 'BREAK_STARTED') $title = 'Break Started';
            elseif ($stdType === 'BREAK_ENDED') $title = 'Break Ended';
            elseif ($stdType === 'MANAGER_CORRECTION') $title = 'Break Corrected by Manager';
            elseif ($stdType === 'ATTENDANCE_CORRECTED') $title = 'Attendance Corrected';

            $details = (!empty($evt->metadata) && is_array($evt->metadata)) ? $evt->metadata : [];

            if ($stdType === 'BREAK_ENDED') {
                $actualSec = $details['actualDurationSeconds'] ?? $details['actual_duration_seconds'] ?? $details['duration_seconds'] ?? null;
                $allowedSec = $details['allowedDurationSeconds'] ?? $details['allowed_duration_seconds'] ?? (isset($details['allowed_duration_minutes']) ? ((int)$details['allowed_duration_minutes'] * 60) : 3600);
                $overdueSec = $details['overdueDurationSeconds'] ?? $details['overdue_duration_seconds'] ?? $details['overdue_seconds'] ?? null;

                if ($actualSec !== null && $overdueSec === null && $allowedSec !== null) {
                    $overdueSec = max(0, $actualSec - $allowedSec);
                }

                $wasOverdue = $details['wasOverdue'] ?? $details['was_overdue'] ?? ($overdueSec !== null && $overdueSec > 0);

                $details['actualDurationSeconds'] = $actualSec !== null ? (int)$actualSec : null;
                $details['allowedDurationSeconds'] = $allowedSec !== null ? (int)$allowedSec : null;
                $details['overdueDurationSeconds'] = $overdueSec !== null ? (int)$overdueSec : 0;
                $details['wasOverdue'] = (bool) $wasOverdue;
            }

            return [
                'id' => $evt->id,
                'uuid' => $evt->uuid,
                'type' => $stdType,
                'title' => $title,
                'occurred_at' => $evt->event_time_server ? $evt->event_time_server->toIso8601String() : null,
                'date' => $localTime ? $localTime->format('Y-m-d') : null,
                'time' => $localTime ? $localTime->format('h:i A') : null,
                'attendance_id' => $evt->attendance_record_id,
                'break_id' => $evt->attendance_break_id,
                'event_source' => $evt->event_source,
                'details' => (object) $details
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total()
            ]
        ]);
    }
}
