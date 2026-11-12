<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceEvent;
use App\Models\AttendanceBreak;
use App\Services\GeofenceService;
use App\Services\AttendancePolicyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    protected $geofenceService;
    protected $policyService;
    protected $breakPolicyService;

    public function __construct(
        GeofenceService $geofenceService,
        AttendancePolicyService $policyService,
        \App\Services\BreakPolicyService $breakPolicyService
    ) {
        $this->geofenceService = $geofenceService;
        $this->policyService = $policyService;
        $this->breakPolicyService = $breakPolicyService;
    }

    /**
     * Get employee attendance history
     */
    /**
     * Get employee attendance history (Synced directly with Main System HR Module table 'attendance')
     */
    public function history(Request $request)
    {
        $employee = $request->user()->employee;
        $month = $request->query('month');
        $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';

        $hrQuery = \DB::table('attendance')
            ->where('employee_id', $employee->id);

        if (!empty($month)) {
            $hrQuery->where('work_date', 'like', $month . '-%');
        }
        $hrMap = $hrQuery->get()->keyBy('work_date');

        $recQuery = $employee->attendanceRecords()
            ->with(['checkInEvent', 'checkOutEvent', 'breaks', 'shift', 'location']);

        if (!empty($month)) {
            $recQuery->where('date', 'like', $month . '-%');
        }
        $recMap = $recQuery->get()->keyBy('date');

        $allDates = $hrMap->keys()->concat($recMap->keys())->unique()->sortDesc()->values();

        $mergedData = [];
        foreach ($allDates as $date) {
            $hr = $hrMap->get($date);
            $rec = $recMap->get($date);

            $checkInTime = ($hr && !empty($hr->check_in))
                ? ($date . ' ' . $hr->check_in)
                : (($rec && $rec->checkInEvent) ? $rec->checkInEvent->event_time_server?->copy()->setTimezone($tz)->format('Y-m-d H:i:s') : null);

            $checkOutTime = ($hr && !empty($hr->check_out))
                ? ($date . ' ' . $hr->check_out)
                : (($rec && $rec->checkOutEvent) ? $rec->checkOutEvent->event_time_server?->copy()->setTimezone($tz)->format('Y-m-d H:i:s') : null);

            $hours = ($hr && $hr->hours !== null)
                ? (float) $hr->hours
                : (($rec && ($rec->net_duration_minutes || $rec->total_duration_minutes)) ? round(($rec->net_duration_minutes ?: $rec->total_duration_minutes) / 60, 2) : null);

            $minutes = $hours !== null ? (int) round($hours * 60) : 0;

            $mergedData[] = [
                'id' => $hr?->id ?? $rec?->id,
                'uuid' => $rec?->uuid ?? (string) ($hr?->id ?? 1),
                'date' => $date,
                'status' => $hr?->status ?: ($rec?->status ?? 'present'),
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
                'hours' => $hours,
                'total_duration_minutes' => $minutes,
                'net_duration_minutes' => $minutes,
                'late_minutes' => $rec?->late_minutes ?? 0,
                'breaks' => $rec?->breaks ?? [],
                'notes' => $hr?->notes
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $mergedData
        ]);
    }

    /**
     * Get today's attendance status (Synced directly with Main System HR Module table 'attendance')
     */
    public function today(Request $request)
    {
        $employee = $request->user()->employee;
        $today = Carbon::now()->toDateString();
        $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';

        $hrRecord = \DB::table('attendance')
            ->where('employee_id', $employee->id)
            ->where('work_date', $today)
            ->first();

        $record = $employee->attendanceRecords()
            ->with(['checkInEvent', 'checkOutEvent', 'breaks', 'shift', 'location'])
            ->where('date', $today)
            ->first();

        // Same source priority as history(): the HR-table value (which reflects
        // any manager correction/claim approval) wins over the app's own raw
        // event when both exist, so today's card and the calendar never
        // disagree for the same date.
        $checkInTime = ($hrRecord && !empty($hrRecord->check_in))
            ? ($today . ' ' . $hrRecord->check_in)
            : (($record && $record->checkInEvent) ? $record->checkInEvent->event_time_server?->copy()->setTimezone($tz)->format('Y-m-d H:i:s') : null);

        $checkOutTime = ($hrRecord && !empty($hrRecord->check_out))
            ? ($today . ' ' . $hrRecord->check_out)
            : (($record && $record->checkOutEvent) ? $record->checkOutEvent->event_time_server?->copy()->setTimezone($tz)->format('Y-m-d H:i:s') : null);

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $today,
                'record' => ($hrRecord || $record) ? [
                    'uuid' => $record?->uuid ?? (string) ($hrRecord?->id ?? 1),
                    'status' => $hrRecord?->status ?: ($record?->status ?? 'present'),
                    'check_in_time' => $checkInTime,
                    'check_out_time' => $checkOutTime,
                    'total_duration_minutes' => $hrRecord?->hours ? (int) round($hrRecord->hours * 60) : ($record?->total_duration_minutes ?? 0),
                    'net_duration_minutes' => $hrRecord?->hours ? (int) round($hrRecord->hours * 60) : ($record?->net_duration_minutes ?? 0),
                    'break_duration_minutes' => $record?->break_duration_minutes ?? 0,
                    'late_minutes' => $record?->late_minutes ?? 0,
                    'early_departure_minutes' => $record?->early_departure_minutes ?? 0,
                    'has_active_session' => ($checkInTime && !$checkOutTime),
                ] : null,
            ]
        ]);
    }

    /**
     * Missed attendance events (checked in but never checked out) from the last
     * 30 days, excluding today (still in progress) and dates that already have
     * a pending/approved claim. Surfaced on the mobile dashboard with a
     * "Verify" + quick-claim action.
     */
    public function missedAttendance(Request $request)
    {
        $employee = $request->user()->employee;
        $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
        $today = Carbon::now($tz)->toDateString();
        $windowStart = Carbon::now($tz)->subDays(30)->toDateString();

        $claimedDates = \App\Models\AttendanceClaim::where('employee_id', $employee->id)
            ->where('claim_type', 'missing_check_out')
            ->whereIn('status', ['pending', 'submitted', 'reviewing', 'approved', 'explanation_requested', 'employee_responded'])
            ->pluck('date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->toArray();

        // Note: AttendanceRecord.date is a plain string column (not Carbon-cast).
        $missed = AttendanceRecord::with('checkInEvent')
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in_event_id')
            ->whereNull('check_out_event_id')
            ->where('date', '<', $today)
            ->where('date', '>=', $windowStart)
            ->orderByDesc('date')
            ->get()
            ->filter(fn ($rec) => !in_array($rec->date, $claimedDates))
            ->map(function ($rec) use ($tz) {
                return [
                    'attendance_record_id' => $rec->id,
                    'date' => $rec->date,
                    'recorded_check_in_at' => $rec->checkInEvent?->event_time_server?->copy()->setTimezone($tz)->format('Y-m-d H:i:s'),
                    'issue_type' => 'missing_check_out',
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $missed,
        ]);
    }

    /**
     * Check-in endpoint
     */
    public function checkIn(Request $request)
    {
        $employee = $request->user()->employee;

        // Verify employee is active
        if (!$employee || $employee->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not active.',
                'error' => ['code' => 'ACCOUNT_INACTIVE']
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|min:0',
            'altitude' => 'nullable|numeric',
            'device_time' => 'required|date',
            'location_timestamp' => 'required|date',
            'device_id' => 'required|string|max:255',
            'app_version' => 'nullable|string|max:50',
            'os_version' => 'nullable|string|max:50',
            'mock_location' => 'nullable|boolean',
            'idempotency_key' => 'required|string|max:255|unique:attendance_events,idempotency_key'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Check-in validation error.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        // Reject stale location (older than 1800 seconds / 30 mins)
        $locationAge = abs(Carbon::now('UTC')->diffInSeconds(Carbon::parse($request->location_timestamp)->utc()));
        if ($locationAge > 1800) {
            return response()->json([
                'success' => false,
                'message' => 'Location data is too old. Please try again.',
                'error' => ['code' => 'STALE_LOCATION', 'details' => ['age_seconds' => $locationAge]]
            ], 400);
        }

        // Reject poor accuracy (> 100 meters)
        if ($request->accuracy > 100) {
            return response()->json([
                'success' => false,
                'message' => 'GPS accuracy is too low. Please move to an open area.',
                'error' => ['code' => 'POOR_ACCURACY', 'details' => ['accuracy_meters' => $request->accuracy]]
            ], 400);
        }

        $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
        $now = Carbon::now($tz);
        $today = $request->filled('device_time')
            ? Carbon::parse($request->device_time, $tz)->setTimezone($tz)->toDateString()
            : $now->toDateString();

        // 1. Resolve current active policy
        $policy = $this->policyService->resolveActivePolicy($employee, $now->toDateTimeString());
        $location = $policy['location'];
        $shift = $policy['shift'];

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'No active work location assigned for today.',
                'error' => ['code' => 'NO_LOCATION_ASSIGNED']
            ], 400);
        }

        // 2. Validate Geofence on server side — an employee assigned to multiple
        // locations (secondary/temporary) may check in from ANY of them, not just
        // the single "primary" one. Try each assigned location, use the nearest.
        $candidateLocations = !empty($policy['locations']) ? $policy['locations'] : [$location];
        $geoCheck = null;
        $matchedLocation = $location;

        foreach ($candidateLocations as $candidate) {
            $check = $this->geofenceService->verifyGeofence(
                $request->latitude,
                $request->longitude,
                $candidate['latitude'],
                $candidate['longitude'],
                $candidate['check_in_radius']
            );

            if ($check['in_geofence']) {
                $geoCheck = $check;
                $matchedLocation = $candidate;
                break;
            }

            if ($geoCheck === null || $check['distance_meters'] < $geoCheck['distance_meters']) {
                $geoCheck = $check;
                $matchedLocation = $candidate;
            }
        }

        $location = $matchedLocation;

        if (!$geoCheck['in_geofence']) {
            return response()->json([
                'success' => false,
                'message' => 'You are outside all of your permitted work locations.',
                'error' => [
                    'code' => 'OUTSIDE_GEOFENCE',
                    'details' => [
                        'distance_meters' => $geoCheck['distance_meters'],
                        'allowed_radius_meters' => $geoCheck['allowed_radius_meters'],
                        'nearest_location' => $location['name'] ?? null,
                    ]
                ]
            ], 400);
        }

        // 3. Create or update Attendance Record (inside transaction)
        return \Illuminate\Support\Facades\DB::transaction(function () use ($employee, $today, $shift, $location, $policy, $geoCheck, $request, $now) {
            $record = AttendanceRecord::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $today],
                [
                    'shift_id' => $shift ? \App\Models\Shift::where('uuid', $shift['uuid'])->value('id') : null,
                    'location_id' => \App\Models\WorkLocation::where('uuid', $location['uuid'])->value('id'),
                    'assignment_id' => $policy['assignment'] ? \App\Models\EmployeeLocationAssignment::where('uuid', $policy['assignment']['uuid'])->value('id') : null,
                    'status' => 'present'
                ]
            );

            // Check if already checked in
            if ($record->check_in_event_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already checked in today.',
                    'error' => ['code' => 'ALREADY_CHECKED_IN']
                ], 400);
            }

            // 4. Calculate punctuality
            $lateMinutes = 0;
            if ($shift && $shift['start_time']) {
                $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
                $checkInTimeLocal = $now->copy()->setTimezone($tz);
                $shiftStartLocal = Carbon::parse($today . ' ' . $shift['start_time'], $tz);

                if ($checkInTimeLocal->gt($shiftStartLocal)) {
                    $lateMinutes = (int) ceil($shiftStartLocal->diffInSeconds($checkInTimeLocal) / 60);
                    $record->update(['status' => 'late', 'late_minutes' => $lateMinutes]);
                }
            }

            // 5. Build security flags
            $securityFlags = [];
            if ($request->mock_location === true) {
                $securityFlags['mock_location'] = true;
            }

            // 6. Create Check-in Event
            $event = AttendanceEvent::create([
                'attendance_record_id' => $record->id,
                'employee_id' => $employee->id,
                'event_type' => 'ATTENDANCE_CHECKED_IN',
                'event_time_server' => $now,
                'event_time_device' => Carbon::parse($request->device_time),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'accuracy' => $request->accuracy,
                'distance_meters' => $geoCheck['distance_meters'],
                'device_id' => $request->device_id,
                'app_version' => $request->app_version,
                'os_version' => $request->os_version,
                'ip_address' => $request->ip(),
                'security_flags' => !empty($securityFlags) ? $securityFlags : null,
                'idempotency_key' => $request->idempotency_key,
                'event_source' => 'mobile_app',
                'actor_user_id' => $request->user()->id,
                'actor_role' => 'employee',
                'metadata' => [
                    'location_name' => $location['name'] ?? null,
                    'shift_name' => $shift['name'] ?? null,
                    'late_minutes' => $lateMinutes
                ]
            ]);

            $record->update(['check_in_event_id' => $event->id]);

            // Sync to Main System HR Module table (attendance)
            $this->syncToMainHRAttendance($employee, $request->device_time ?: $now, 'check_in');

            return response()->json([
                'success' => true,
                'message' => 'Check-in completed successfully.',
                'data' => [
                    'record_uuid' => $record->uuid,
                    'check_in_time' => $event->event_time_server->toIso8601String(),
                    'late_minutes' => $lateMinutes,
                    'distance_meters' => $geoCheck['distance_meters'],
                    'tracking_policy' => $policy['tracking_policy']
                ]
            ]);
        });
    }

    /**
     * Check-out endpoint
     */
    public function checkOut(Request $request)
    {
        $employee = $request->user()->employee;

        if (!$employee || $employee->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not active.',
                'error' => ['code' => 'ACCOUNT_INACTIVE']
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|min:0',
            'altitude' => 'nullable|numeric',
            'device_time' => 'required|date',
            'location_timestamp' => 'required|date',
            'device_id' => 'required|string|max:255',
            'app_version' => 'nullable|string|max:50',
            'os_version' => 'nullable|string|max:50',
            'mock_location' => 'nullable|boolean',
            'idempotency_key' => 'required|string|max:255|unique:attendance_events,idempotency_key'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Check-out validation error.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        // Reject stale location (older than 1800 seconds / 30 mins)
        $locationAge = abs(Carbon::now('UTC')->diffInSeconds(Carbon::parse($request->location_timestamp)->utc()));
        if ($locationAge > 1800) {
            return response()->json([
                'success' => false,
                'message' => 'Location data is too old. Please try again.',
                'error' => ['code' => 'STALE_LOCATION']
            ], 400);
        }

        // Reject poor accuracy
        if ($request->accuracy > 100) {
            return response()->json([
                'success' => false,
                'message' => 'GPS accuracy is too low. Please move to an open area.',
                'error' => ['code' => 'POOR_ACCURACY']
            ], 400);
        }

        $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
        $now = Carbon::now($tz);
        $today = $request->filled('device_time')
            ? Carbon::parse($request->device_time, $tz)->setTimezone($tz)->toDateString()
            : $now->toDateString();

        // 1. Fetch active check-in record for today
        $record = $employee->attendanceRecords()
            ->where('date', $today)
            ->whereNotNull('check_in_event_id')
            ->whereNull('check_out_event_id')
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'You must check in first before checking out.',
                'error' => ['code' => 'NO_ACTIVE_SESSION']
            ], 400);
        }

        $policy = $this->policyService->resolveActivePolicy($employee, $now->toDateTimeString());
        $location = $policy['location'];

        // 2. Validate Geofence boundary — same multi-location allowance as check-in:
        // an employee may check out from any of their currently assigned locations.
        $candidateLocations = !empty($policy['locations']) ? $policy['locations'] : [$location];
        $geoCheck = null;
        $matchedLocation = $location;

        foreach ($candidateLocations as $candidate) {
            $check = $this->geofenceService->verifyGeofence(
                $request->latitude,
                $request->longitude,
                $candidate['latitude'],
                $candidate['longitude'],
                $candidate['checkout_radius']
            );

            if ($check['in_geofence']) {
                $geoCheck = $check;
                $matchedLocation = $candidate;
                break;
            }

            if ($geoCheck === null || $check['distance_meters'] < $geoCheck['distance_meters']) {
                $geoCheck = $check;
                $matchedLocation = $candidate;
            }
        }

        $location = $matchedLocation;

        if (!$geoCheck['in_geofence']) {
            return response()->json([
                'success' => false,
                'message' => 'You are outside all of your permitted work locations for checkout.',
                'error' => [
                    'code' => 'OUTSIDE_GEOFENCE',
                    'details' => $geoCheck
                ]
            ], 400);
        }

        // 2.5 Check if break is currently active
        $activeBreak = $record->breaks()->whereNull('end_time_server')->where('status', 'active')->first();
        if ($activeBreak) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot check out while a break is active. Please end your break first.',
                'error' => ['code' => 'BREAK_ACTIVE']
            ], 400);
        }

        // 3. Process checkout inside transaction
        return \Illuminate\Support\Facades\DB::transaction(function () use ($record, $employee, $today, $geoCheck, $request, $now) {
            // Close active breaks
            $activeBreak = $record->breaks()->whereNull('end_time_server')->first();
            if ($activeBreak) {
                $activeBreak->update([
                    'end_time_server' => $now,
                    'end_time_device' => $now,
                    'duration_minutes' => $now->diffInMinutes($activeBreak->start_time_server)
                ]);
            }

            // Build security flags
            $securityFlags = [];
            if ($request->mock_location === true) {
                $securityFlags['mock_location'] = true;
            }

            // 4. Create Check-out Event
            $event = AttendanceEvent::create([
                'attendance_record_id' => $record->id,
                'employee_id' => $employee->id,
                'event_type' => 'ATTENDANCE_CHECKED_OUT',
                'event_time_server' => $now,
                'event_time_device' => Carbon::parse($request->device_time),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'accuracy' => $request->accuracy,
                'distance_meters' => $geoCheck['distance_meters'],
                'device_id' => $request->device_id,
                'app_version' => $request->app_version,
                'os_version' => $request->os_version,
                'ip_address' => $request->ip(),
                'security_flags' => !empty($securityFlags) ? $securityFlags : null,
                'idempotency_key' => $request->idempotency_key,
                'event_source' => 'mobile_app',
                'actor_user_id' => $request->user()->id,
                'actor_role' => 'employee'
            ]);

            // 5. Calculate durations & breaks
            $checkInTime = Carbon::parse($record->checkInEvent->event_time_server);
            $totalMinutes = (int) abs($now->diffInMinutes($checkInTime));
            $breakMinutes = (int) $record->breaks()->sum('duration_minutes');
            $netMinutes = $totalMinutes - $breakMinutes;

            $event->update([
                'metadata' => [
                    'total_duration_minutes' => $totalMinutes,
                    'net_duration_minutes' => $netMinutes,
                    'break_duration_minutes' => $breakMinutes
                ]
            ]);

            // Calculate early departure
            $earlyDepartureMinutes = 0;
            $shift = $record->shift;
            if ($shift && $shift->end_time) {
                $shiftEnd = Carbon::parse($today . ' ' . $shift->end_time);
                if ($now->lt($shiftEnd)) {
                    $earlyDepartureMinutes = (int) abs($shiftEnd->diffInMinutes($now));
                }
            }

            $record->update([
                'check_out_event_id' => $event->id,
                'total_duration_minutes' => $totalMinutes,
                'break_duration_minutes' => $breakMinutes,
                'net_duration_minutes' => $netMinutes,
                'early_departure_minutes' => $earlyDepartureMinutes
            ]);

            // Sync to Main System HR Module table (attendance)
            $this->syncToMainHRAttendance($employee, $request->device_time ?: $now, 'check_out', ['break_duration_minutes' => $breakMinutes]);

            return response()->json([
                'success' => true,
                'message' => 'Check-out completed successfully.',
                'data' => [
                    'record_uuid' => $record->uuid,
                    'check_out_time' => $event->event_time_server->toIso8601String(),
                    'net_duration_minutes' => $netMinutes,
                    'break_duration_minutes' => $breakMinutes,
                    'early_departure_minutes' => $earlyDepartureMinutes,
                    'distance_meters' => $geoCheck['distance_meters']
                ]
            ]);
        });
    }

    /**
     * Get Break State & Effective Policy
     */
    public function getBreakState(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        $now = Carbon::now();
        $today = $now->toDateString();

        $policy = $this->breakPolicyService->resolveEffectivePolicy($employee, $today);

        // Find active record: today's check-in, or an overnight shift that started
        // yesterday and hasn't checked out yet. Bounded to "yesterday or today" so
        // a forgotten checkout from days ago (a missed-checkout, handled via the
        // dashboard's quick-claim flow) is never mistaken for an active session
        // that would incorrectly unlock breaks on a day the employee never checked in.
        $yesterday = $now->copy()->subDay()->toDateString();
        $record = $employee->attendanceRecords()
            ->whereNotNull('check_in_event_id')
            ->whereNull('check_out_event_id')
            ->whereIn('date', [$today, $yesterday])
            ->orderBy('id', 'desc')
            ->first();

        if (!$record) {
            $record = $employee->attendanceRecords()
                ->where('date', $today)
                ->first();
        }

        $activeBreak = null;
        $breaksToday = collect();
        $completedBreaksCount = 0;
        $usedBreakCount = 0;
        $isCheckedInWorking = false;
        $isAttendanceClosed = false;

        if ($record) {
            $isCheckedInWorking = $record->check_in_event_id && !$record->check_out_event_id;
            $isAttendanceClosed = (bool) $record->check_out_event_id;
            $breaksToday = $record->breaks()->orderBy('start_time_server', 'asc')->get();
            $activeBreak = $breaksToday->whereNull('end_time_server')->where('status', 'active')->first();
            $completedBreaksCount = $breaksToday->whereNotNull('end_time_server')->where('status', 'completed')->count();
            // Non-cancelled breaks count toward used limit
            $usedBreakCount = $breaksToday->where('status', '!=', 'cancelled')->count();
        }

        $maxBreaks = $policy ? $policy->max_breaks_per_shift : 1;
        $remainingBreaksCount = max(0, $maxBreaks - $usedBreakCount);

        $canStartBreak = false;
        $canEndBreak = $activeBreak !== null;

        $availabilityStatus = 'unavailable';
        $restrictionCode = null;
        $restrictionMessage = null;

        if (!$policy || !$policy->is_active) {
            $availabilityStatus = 'policy_inactive';
            $restrictionCode = 'POLICY_INACTIVE';
            $restrictionMessage = 'Break policy is currently inactive.';
        } elseif (!$record || !$record->check_in_event_id) {
            $availabilityStatus = 'not_checked_in';
            $restrictionCode = 'NOT_CHECKED_IN';
            $restrictionMessage = 'Check in required to start a break.';
        } elseif ($isAttendanceClosed) {
            $availabilityStatus = 'attendance_closed';
            $restrictionCode = 'ATTENDANCE_CLOSED';
            $restrictionMessage = 'Attendance session closed for this shift.';
        } elseif ($activeBreak) {
            $availabilityStatus = 'on_break';
            $restrictionCode = 'BREAK_ACTIVE';
            $restrictionMessage = 'You currently have an active break session.';
        } elseif ($remainingBreaksCount <= 0) {
            $availabilityStatus = 'limit_reached';
            $restrictionCode = 'MAX_BREAKS_REACHED';
            $restrictionMessage = 'Maximum break count reached for this shift.';
        } else {
            // Check fixed or window policy schedule if applicable
            $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
            $nowLocal = $now->copy()->setTimezone($tz);

            if (($policy->policy_type === 'fixed' || $policy->policy_type === 'window') && $policy->start_time && $policy->end_time) {
                $startLocal = Carbon::parse($today . ' ' . $policy->start_time, $tz);
                $endLocal = Carbon::parse($today . ' ' . $policy->end_time, $tz);
                if ($nowLocal->lt($startLocal) || $nowLocal->gt($endLocal)) {
                    $availabilityStatus = 'outside_allowed_time';
                    $restrictionCode = 'BREAK_NOT_ALLOWED_AT_THIS_TIME';
                    $restrictionMessage = "Breaks are not permitted at the current time under your policy schedule.";
                } else {
                    $availabilityStatus = 'available';
                    $canStartBreak = true;
                }
            } else {
                $availabilityStatus = 'available';
                $canStartBreak = true;
            }
        }

        $expectedEndAt = $activeBreak ? $activeBreak->expected_end_at : null;
        $isOverdue = $expectedEndAt ? $now->gt($expectedEndAt) : false;
        $overdueMinutes = ($isOverdue && $expectedEndAt) ? (int) ceil($now->diffInSeconds($expectedEndAt) / 60) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'policy' => [
                    'id' => $policy->id,
                    'uuid' => $policy->uuid,
                    'name' => $policy->name,
                    'type' => $policy->policy_type,
                    'allowedDurationMinutes' => $policy->allowed_duration_minutes,
                    'maximumBreaksPerShift' => $policy->max_breaks_per_shift,
                    'active' => (bool) $policy->is_active,
                ],
                'usedBreakCount' => $usedBreakCount,
                'remainingBreakCount' => $remainingBreaksCount,
                'canStartBreak' => $canStartBreak,
                'availabilityStatus' => $availabilityStatus,
                'restrictionCode' => $restrictionCode,
                'restrictionMessage' => $restrictionMessage,

                // Legacy & snake_case aliases for backwards compatibility
                'effective_policy' => [
                    'uuid' => $policy->uuid,
                    'name' => $policy->name,
                    'policy_type' => $policy->policy_type,
                    'start_time' => $policy->start_time,
                    'end_time' => $policy->end_time,
                    'allowed_duration_minutes' => $policy->allowed_duration_minutes,
                    'max_breaks_per_shift' => $policy->max_breaks_per_shift,
                    'is_paid' => $policy->is_paid,
                    'requires_approval' => $policy->requires_approval
                ],
                'active_break' => $activeBreak,
                'breaks_today' => $breaksToday,
                'can_start_break' => $canStartBreak,
                'can_end_break' => $canEndBreak,
                'remaining_breaks_count' => $remainingBreaksCount,
                'completed_breaks_count' => $completedBreaksCount,
                'used_break_count' => $usedBreakCount,
                'availability_status' => $availabilityStatus,
                'server_time' => $now->toIso8601String(),
                'expected_end_at' => $expectedEndAt ? $expectedEndAt->toIso8601String() : null,
                'is_overdue' => $isOverdue,
                'overdue_minutes' => $overdueMinutes,
                'next_notification_at' => $activeBreak && $activeBreak->next_notification_at ? $activeBreak->next_notification_at->toIso8601String() : null,
                'notification_count' => $activeBreak ? $activeBreak->overdue_notification_count : 0,
                'escalation_level' => $activeBreak ? $activeBreak->escalation_level : 0,
                'unavailable_reason' => $restrictionCode ?: $availabilityStatus
            ]
        ]);
    }

    /**
     * Start Break
     */
    public function startBreak(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        // Check idempotency key if present
        if ($request->filled('idempotency_key')) {
            $existing = AttendanceBreak::where('idempotency_key', $request->idempotency_key)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Break start request processed idempotently.',
                    'data' => [
                        'break_uuid' => $existing->uuid,
                        'expected_end_at' => $existing->expected_end_at ? $existing->expected_end_at->toIso8601String() : null,
                        'status' => $existing->status
                    ]
                ]);
            }
        }

        $now = Carbon::now();
        $today = $now->toDateString();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($employee, $request, $now, $today) {
            // Lock active record for update to prevent concurrent break starts exceeding limit.
            // Bounded to "yesterday or today" so a forgotten checkout from days ago is never
            // mistaken for an active session — see getBreakState() for the same fix/reasoning.
            $yesterday = $now->copy()->subDay()->toDateString();
            $record = $employee->attendanceRecords()
                ->whereNotNull('check_in_event_id')
                ->whereNull('check_out_event_id')
                ->whereIn('date', [$today, $yesterday])
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must check in first before starting a break.',
                    'error' => ['code' => 'NOT_CHECKED_IN', 'message' => 'You must check in first before starting a break.']
                ], 400);
            }

            // Verify active break
            $activeBreak = $record->breaks()
                ->whereNull('end_time_server')
                ->where('status', 'active')
                ->first();

            if ($activeBreak) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an active break session.',
                    'error' => ['code' => 'BREAK_ALREADY_ACTIVE', 'message' => 'You already have an active break session.']
                ], 400);
            }

            $policy = $this->breakPolicyService->resolveEffectivePolicy($employee, $record->date ?: $today);

            if (!$policy || !$policy->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Break policy is currently inactive.',
                    'error' => ['code' => 'POLICY_INACTIVE', 'message' => 'Break policy is currently inactive.']
                ], 400);
            }

            // Check max breaks limit (counting non-cancelled breaks)
            $usedCount = $record->breaks()->where('status', '!=', 'cancelled')->count();
            if ($usedCount >= $policy->max_breaks_per_shift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum break count reached for this shift.',
                    'error' => ['code' => 'MAX_BREAKS_REACHED', 'message' => 'Maximum break count reached for this shift.']
                ], 400);
            }

            // Check fixed or window policy schedule
            $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
            $nowLocal = $now->copy()->setTimezone($tz);
            if (($policy->policy_type === 'fixed' || $policy->policy_type === 'window') && $policy->start_time && $policy->end_time) {
                $startLocal = Carbon::parse($today . ' ' . $policy->start_time, $tz);
                $endLocal = Carbon::parse($today . ' ' . $policy->end_time, $tz);
                if ($nowLocal->lt($startLocal) || $nowLocal->gt($endLocal)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Breaks for your policy are only allowed between {$policy->start_time} and {$policy->end_time}.",
                        'error' => ['code' => 'BREAK_NOT_ALLOWED_AT_THIS_TIME']
                    ], 400);
                }
            }

            // Calculate authoritative expected end at
            $expectedEndAt = $now->copy()->addMinutes($policy->allowed_duration_minutes);

            $break = AttendanceBreak::create([
                'attendance_record_id' => $record->id,
                'employee_id' => $employee->id,
                'break_policy_id' => $policy->id,
                'start_time_server' => $now,
                'start_time_device' => $request->filled('device_time') ? Carbon::parse($request->device_time) : $now,
                'expected_end_at' => $expectedEndAt,
                'status' => 'active',
                'notification_series_status' => 'pending',
                'is_paid' => $policy->is_paid,
                'approval_status' => $policy->requires_approval ? 'pending' : 'approved',
                'reason' => $request->reason ?: 'General Break',
                'idempotency_key' => $request->idempotency_key
            ]);

            AttendanceEvent::create([
                'employee_id' => $employee->id,
                'attendance_record_id' => $record->id,
                'attendance_break_id' => $break->id,
                'event_type' => 'BREAK_STARTED',
                'event_time_server' => $now,
                'event_time_device' => $request->filled('device_time') ? Carbon::parse($request->device_time) : $now,
                'event_source' => 'mobile_app',
                'actor_user_id' => $request->user()->id,
                'actor_role' => 'employee',
                'idempotency_key' => $request->idempotency_key ? $request->idempotency_key . '_evt' : null,
                'metadata' => [
                    'policy_name' => $policy->name,
                    'policy_type' => $policy->policy_type,
                    'allowed_duration_minutes' => $policy->allowed_duration_minutes,
                    'expected_end_at' => $expectedEndAt->toIso8601String()
                ]
            ]);

            // Sync to Main System HR Module table (attendance)
            $this->syncToMainHRAttendance($employee, $request->filled('device_time') ? $request->device_time : $now, 'break_start');

            return response()->json([
                'success' => true,
                'message' => 'Break started successfully.',
                'data' => [
                    'break_uuid' => $break->uuid,
                    'start_time' => $break->start_time_server->toIso8601String(),
                    'expected_end_at' => $expectedEndAt->toIso8601String(),
                    'allowed_duration_minutes' => $policy->allowed_duration_minutes,
                    'policy_name' => $policy->name
                ]
            ]);
        });
    }

    /**
     * End Break
     */
    public function endBreak(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        // Location is required to end a break — same geofence rule as
        // check-out, so an employee can't end a break (and re-enter working
        // time) from outside their assigned location.
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|min:0',
            'location_timestamp' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'End-break validation error.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        if ($request->filled('location_timestamp')) {
            $locationAge = abs(Carbon::now('UTC')->diffInSeconds(Carbon::parse($request->location_timestamp)->utc()));
            if ($locationAge > 1800) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location data is too old. Please try again.',
                    'error' => ['code' => 'STALE_LOCATION']
                ], 400);
            }
        }

        if ($request->accuracy > 100) {
            return response()->json([
                'success' => false,
                'message' => 'GPS accuracy is too low. Please move to an open area.',
                'error' => ['code' => 'POOR_ACCURACY']
            ], 400);
        }

        // Idempotency check for end break
        if ($request->filled('idempotency_key')) {
            $existingLog = AttendanceBreak::where('idempotency_key', $request->idempotency_key . '_end')->first();
            if ($existingLog) {
                $policy = $existingLog->breakPolicy ?: (new \App\Services\BreakPolicyService())->resolveEffectivePolicy($employee, $existingLog->start_time_server ? $existingLog->start_time_server->toDateString() : null);
                $allowedMinutes = $policy ? $policy->allowed_duration_minutes : 60;
                $allowedSeconds = $allowedMinutes * 60;
                $actualSeconds = $existingLog->duration_seconds;
                $overdueSeconds = max(0, $actualSeconds - $allowedSeconds);
                $wasOverdue = $overdueSeconds > 0;

                return response()->json([
                    'success' => true,
                    'message' => 'Break end request processed idempotently.',
                    'data' => [
                        'break_uuid' => $existingLog->uuid,
                        'startedAt' => $existingLog->start_time_server ? $existingLog->start_time_server->toIso8601String() : null,
                        'endedAt' => $existingLog->end_time_server ? $existingLog->end_time_server->toIso8601String() : null,
                        'actualDurationSeconds' => $actualSeconds,
                        'allowedDurationSeconds' => $allowedSeconds,
                        'overdueDurationSeconds' => $overdueSeconds,
                        'wasOverdue' => $wasOverdue,
                        'duration_seconds' => $actualSeconds,
                        'status' => $existingLog->status
                    ]
                ]);
            }
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $employee) {
            $now = Carbon::now();

            $activeBreak = AttendanceBreak::where('employee_id', $employee->id)
                ->whereNull('end_time_server')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (!$activeBreak) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active break session found.',
                    'error' => ['code' => 'NO_ACTIVE_BREAK']
                ], 400);
            }

            $record = $activeBreak->record;

            // Geofence check — same rule and radius as check-out: ending a
            // break re-enters working time, so it's gated on being inside
            // one of the employee's currently assigned locations, exactly
            // like checkOut() above.
            $locationPolicy = $this->policyService->resolveActivePolicy($employee, $now->toDateTimeString());
            $location = $locationPolicy['location'];
            $candidateLocations = !empty($locationPolicy['locations']) ? $locationPolicy['locations'] : [$location];
            $geoCheck = null;

            foreach ($candidateLocations as $candidate) {
                $check = $this->geofenceService->verifyGeofence(
                    $request->latitude,
                    $request->longitude,
                    $candidate['latitude'],
                    $candidate['longitude'],
                    $candidate['checkout_radius']
                );

                if ($check['in_geofence']) {
                    $geoCheck = $check;
                    break;
                }

                if ($geoCheck === null || $check['distance_meters'] < $geoCheck['distance_meters']) {
                    $geoCheck = $check;
                }
            }

            if (!$geoCheck['in_geofence']) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are outside all of your permitted work locations. Please return to end your break.',
                    'error' => [
                        'code' => 'OUTSIDE_GEOFENCE',
                        'details' => $geoCheck
                    ]
                ], 400);
            }

            $startedAt = $activeBreak->start_time_server;

            // Prefer device-clock duration when both device timestamps are available —
            // matters for a break synced late from an offline queue, where server "now"
            // (sync time) can be well after the break actually ended on the device.
            $startedAtDevice = $activeBreak->start_time_device;
            $endedAtDevice = $request->filled('device_time') ? Carbon::parse($request->device_time) : null;
            if ($startedAtDevice && $endedAtDevice && $endedAtDevice->gte($startedAtDevice)) {
                $actualDurationSeconds = max(0, $endedAtDevice->getTimestamp() - $startedAtDevice->getTimestamp());
            } else {
                $actualDurationSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
            }
            $durationMinutes = (int) max(1, ceil($actualDurationSeconds / 60));

            // Dynamic policy resolution
            $policy = $activeBreak->breakPolicy ?: (new \App\Services\BreakPolicyService())->resolveEffectivePolicy($employee, $record->date);
            $allowedDurationMinutes = $policy ? $policy->allowed_duration_minutes : 60;
            $allowedDurationSeconds = $allowedDurationMinutes * 60;

            $overdueDurationSeconds = max(0, $actualDurationSeconds - $allowedDurationSeconds);
            $wasOverdue = $overdueDurationSeconds > 0;
            $violationCode = $wasOverdue ? 'EXCEEDED_MAX_DURATION' : null;

            $endIdempotencyKey = $request->filled('idempotency_key') ? $request->idempotency_key . '_end' : null;

            $activeBreak->update([
                'end_time_server' => $now,
                'end_time_device' => $request->filled('device_time') ? Carbon::parse($request->device_time) : $now,
                'duration_seconds' => $actualDurationSeconds,
                'duration_minutes' => $durationMinutes,
                'overdue_duration_seconds' => $overdueDurationSeconds,
                'status' => 'completed',
                'notification_series_status' => 'stopped',
                'notification_series_stopped_at' => $now,
                'notification_series_stop_reason' => 'EMPLOYEE_ENDED',
                'violation_code' => $violationCode,
                'idempotency_key' => $endIdempotencyKey ?: $activeBreak->idempotency_key
            ]);

            $evtIdempotencyKey = $request->filled('idempotency_key')
                ? $request->idempotency_key . '_end_evt'
                : 'break_end_' . $activeBreak->id;

            $metadataPayload = [
                'startedAt' => $startedAt ? $startedAt->toIso8601String() : null,
                'endedAt' => $now->toIso8601String(),
                'actualDurationSeconds' => $actualDurationSeconds,
                'allowedDurationSeconds' => $allowedDurationSeconds,
                'overdueDurationSeconds' => $overdueDurationSeconds,
                'wasOverdue' => $wasOverdue,
                // Duplicate snake_case aliases for safety across legacy consumers
                'actual_duration_seconds' => $actualDurationSeconds,
                'allowed_duration_seconds' => $allowedDurationSeconds,
                'overdue_duration_seconds' => $overdueDurationSeconds,
                'duration_seconds' => $actualDurationSeconds,
                'duration_minutes' => $durationMinutes,
                'was_overdue' => $wasOverdue,
                'overdue_seconds' => $overdueDurationSeconds
            ];

            AttendanceEvent::updateOrCreate(
                ['idempotency_key' => $evtIdempotencyKey],
                [
                    'employee_id' => $employee->id,
                    'attendance_record_id' => $record->id,
                    'attendance_break_id' => $activeBreak->id,
                    'event_type' => 'BREAK_ENDED',
                    'event_time_server' => $now,
                    'event_time_device' => $request->filled('device_time') ? Carbon::parse($request->device_time) : $now,
                    'event_source' => 'mobile_app',
                    'actor_user_id' => $request->user()->id,
                    'actor_role' => 'employee',
                    'metadata' => $metadataPayload
                ]
            );

            // Sync to Main System HR Module table (attendance)
            $this->syncToMainHRAttendance($employee, $request->filled('device_time') ? $request->device_time : $now, 'break_end');

            return response()->json([
                'success' => true,
                'message' => 'Break ended successfully.',
                'data' => [
                    'break_uuid' => $activeBreak->uuid,
                    'startedAt' => $startedAt ? $startedAt->toIso8601String() : null,
                    'endedAt' => $now->toIso8601String(),
                    'actualDurationSeconds' => $actualDurationSeconds,
                    'allowedDurationSeconds' => $allowedDurationSeconds,
                    'overdueDurationSeconds' => $overdueDurationSeconds,
                    'wasOverdue' => $wasOverdue,
                    'duration_seconds' => $actualDurationSeconds,
                    'duration_minutes' => $durationMinutes,
                    'overdue_duration_seconds' => $overdueDurationSeconds,
                    'violation_code' => $violationCode
                ]
            ]);
        });
    }

    /**
     * Synchronize check-in, check-out, and break events live into the main system HR attendance table.
     */
    protected function syncToMainHRAttendance($employee, $dateTime, string $type, array $extra = [])
    {
        if (!\Schema::hasTable('attendance')) {
            return;
        }

        try {
            $tz = ($employee->company && !empty($employee->company->timezone)) ? $employee->company->timezone : 'Asia/Dubai';
            $localTime = Carbon::parse($dateTime ?: Carbon::now())->setTimezone($tz);
            $workDate = $localTime->toDateString();
            $formattedTime = $localTime->format('H:i:s');
            $companyId = $employee->company_id ?? 1;

            $existing = \DB::table('attendance')
                ->where('employee_id', $employee->id)
                ->where('work_date', $workDate)
                ->first();

            if ($type === 'check_in') {
                \DB::table('attendance')->updateOrInsert(
                    ['employee_id' => $employee->id, 'work_date' => $workDate],
                    [
                        'check_in' => $formattedTime,
                        'check_out' => null,
                        'hours' => null,
                        'status' => 'present',
                        'source' => 'self',
                        'company_id' => $companyId,
                        'updated_at' => Carbon::now()
                    ]
                );
            } elseif ($type === 'check_out') {
                $checkInStr = $existing ? $existing->check_in : null;
                $hours = null;

                if (!empty($checkInStr)) {
                    $inTime = Carbon::parse($workDate . ' ' . $checkInStr, $tz);
                    $outTime = Carbon::parse($workDate . ' ' . $formattedTime, $tz);
                    if ($outTime->gt($inTime)) {
                        $grossMinutes = $inTime->diffInMinutes($outTime);
                        $breakMinutes = $extra['break_duration_minutes'] ?? 0;
                        $netMinutes = max(0, $grossMinutes - $breakMinutes);
                        $hours = round($netMinutes / 60, 2);
                    }
                }

                \DB::table('attendance')->updateOrInsert(
                    ['employee_id' => $employee->id, 'work_date' => $workDate],
                    [
                        'check_out' => $formattedTime,
                        'hours' => $hours,
                        'status' => $existing && !empty($existing->status) ? $existing->status : 'present',
                        'source' => 'self',
                        'company_id' => $companyId,
                        'updated_at' => Carbon::now()
                    ]
                );
            } elseif ($type === 'break_start' || $type === 'break_end') {
                $record = $employee->attendanceRecords()->where('date', $workDate)->first();
                $notesArr = [];
                if ($record) {
                    $breaks = $record->breaks()->get();
                    foreach ($breaks as $idx => $b) {
                        $bStart = $b->start_time_device ? Carbon::parse($b->start_time_device)->setTimezone($tz)->format('H:i:s') : '';
                        $bEnd = $b->end_time_device ? Carbon::parse($b->end_time_device)->setTimezone($tz)->format('H:i:s') : 'Ongoing';
                        $notesArr[] = "Break " . ($idx + 1) . ": {$bStart} - {$bEnd} (" . ($b->duration_minutes ?? 0) . " mins)";
                    }
                }
                $notesStr = implode(' | ', $notesArr);

                $hours = $existing ? $existing->hours : null;
                if ($existing && !empty($existing->check_in) && !empty($existing->check_out)) {
                    $inTime = Carbon::parse($workDate . ' ' . $existing->check_in, $tz);
                    $outTime = Carbon::parse($workDate . ' ' . $existing->check_out, $tz);
                    if ($outTime->gt($inTime)) {
                        $grossMinutes = $inTime->diffInMinutes($outTime);
                        $breakMinutes = $record ? (int) $record->breaks()->sum('duration_minutes') : 0;
                        $netMinutes = max(0, $grossMinutes - $breakMinutes);
                        $hours = round($netMinutes / 60, 2);
                    }
                }

                \DB::table('attendance')->updateOrInsert(
                    ['employee_id' => $employee->id, 'work_date' => $workDate],
                    [
                        'notes' => $notesStr,
                        'hours' => $hours,
                        'company_id' => $companyId,
                        'updated_at' => Carbon::now()
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Log::error('Main System HR attendance sync error: ' . $e->getMessage());
        }
    }
}
