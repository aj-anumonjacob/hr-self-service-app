<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Services\OfflineTokenService;
use App\Services\AttendancePolicyService;
use App\Services\GeofenceService;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OfflineController extends Controller
{
    protected $offlineTokenService;
    protected $policyService;
    protected $geofenceService;
    protected AttendanceController $attendanceController;
    protected InAppNotificationService $inAppNotificationService;

    public function __construct(
        OfflineTokenService $ots,
        AttendancePolicyService $aps,
        GeofenceService $gs,
        AttendanceController $attendanceController,
        InAppNotificationService $inAppNotificationService
    ) {
        $this->offlineTokenService = $ots;
        $this->policyService = $aps;
        $this->geofenceService = $gs;
        $this->attendanceController = $attendanceController;
        $this->inAppNotificationService = $inAppNotificationService;
    }

    /**
     * Sends a push/in-app acknowledgement to the employee once an offline
     * action has actually been persisted server-side — they may have closed
     * the app or moved on by the time this sync happens minutes/hours later,
     * so this is the only confirmation they'll get that it really went through.
     */
    private function sendOfflineSyncAck(Employee $employee, string $eventType, string $idempotencyKey, string $deviceTime): void
    {
        $user = $employee->user;
        if (!$user) return;

        $labels = [
            'check_in' => 'Check-in',
            'check_out' => 'Check-out',
            'start_break' => 'Break start',
            'end_break' => 'Break end',
        ];
        $label = $labels[$eventType] ?? ucfirst(str_replace('_', ' ', $eventType));
        $timeStr = Carbon::parse($deviceTime)->format('h:i A');

        try {
            $this->inAppNotificationService->createNotification(
                $user,
                'employee',
                'OFFLINE_SYNC_ACK',
                'Offline attendance synced',
                "Your {$label} at {$timeStr} (recorded while offline) has been successfully synced.",
                ['event_type' => $eventType, 'device_time' => $deviceTime],
                "offline_sync_ack:{$idempotencyKey}"
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to send offline sync ack for {$idempotencyKey}: " . $e->getMessage());
        }
    }

    /**
     * Issue signed offline authorization token while employee is online
     */
    public function issueAuthorization(Request $request)
    {
        $employee = $request->user()->employee;
        
        $validator = Validator::make($request->all(), [
            'platform_device_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Device ID required.'], 422);
        }

        $policy = $this->policyService->resolveActivePolicy($employee);
        
        if ($policy['offline_policy'] === 'disabled') {
            return response()->json([
                'success' => false,
                'message' => 'Offline attendance is disabled by company policy for this location.',
                'error' => ['code' => 'OFFLINE_DISABLED']
            ], 403);
        }

        $auth = $this->offlineTokenService->generateAuthorization($employee, $request->platform_device_id, $policy);

        // Store authorization record
        $authRecord = \App\Models\OfflineAttendanceAuthorization::create([
            'employee_id' => $employee->id,
            'device_id' => $request->platform_device_id,
            'valid_from' => $auth['valid_from'],
            'valid_to' => $auth['valid_to'],
            'policy_data' => $auth['policy_data'],
            'cryptographic_signature' => $auth['signature']
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'authorization_uuid' => $authRecord->uuid,
                'valid_from' => $auth['valid_from'],
                'valid_to' => $auth['valid_to'],
                'signature' => $auth['signature'],
                'policy' => $auth['policy_data']
            ]
        ]);
    }

    /**
     * Sync client offline attendance logs sequentially
     */
    public function sync(Request $request)
    {
        $employee = $request->user()->employee;
        
        // Breaks don't need geofence proof (no location claim is being made), so their
        // signature/lat/long requirements are lighter than check-in/check-out.
        $validator = Validator::make($request->all(), [
            'records' => 'required|array',
            'records.*.event_type' => 'required|string|in:check_in,check_out,start_break,end_break',
            'records.*.device_time' => 'required|date',
            'records.*.idempotency_key' => 'required|string',
            'records.*.signature' => 'required_if:records.*.event_type,check_in,check_out|nullable|string',
            'records.*.latitude' => 'required_if:records.*.event_type,check_in,check_out|nullable|numeric',
            'records.*.longitude' => 'required_if:records.*.event_type,check_in,check_out|nullable|numeric',
            'records.*.accuracy' => 'required_if:records.*.event_type,check_in,check_out|nullable|numeric',
            'records.*.device_id' => 'required_if:records.*.event_type,check_in,check_out|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Log payload validation failed.', 'error' => $validator->errors()], 422);
        }

        $syncedCount = 0;
        $failedRecords = [];
        $alreadySynced = [];
        $rejectedRecords = [];

        foreach ($request->records as $index => $log) {
            if (in_array($log['event_type'], ['start_break', 'end_break'])) {
                $result = $this->syncBreakRecord($request, $employee, $log);
                if ($result['success']) {
                    $syncedCount++;
                    $this->sendOfflineSyncAck($employee, $log['event_type'], $log['idempotency_key'], $log['device_time']);
                } else {
                    $failedRecords[] = [
                        'index' => $index,
                        'idempotency_key' => $log['idempotency_key'],
                        'reason' => $result['message'],
                    ];
                }
                continue;
            }

            // 0. Duplicate check FIRST, before touching any record — a
            // resubmission (e.g. the client never received the previous
            // response because the request timed out after the server had
            // already committed) is a success, not a failure, and must never
            // create a second event for the same idempotency key.
            $existingEvent = AttendanceEvent::where('idempotency_key', $log['idempotency_key'])->first();
            if ($existingEvent) {
                $alreadySynced[] = [
                    'index' => $index,
                    'idempotency_key' => $log['idempotency_key'],
                    'server_ref' => $existingEvent->uuid ?? (string) $existingEvent->id,
                ];
                continue;
            }

            // 1. Cryptographically verify signature
            $verifyResult = $this->offlineTokenService->verifyToken($log['signature'], $employee, $log['device_id']);

            if (!$verifyResult['success']) {
                $failedRecords[] = [
                    'index' => $index,
                    'idempotency_key' => $log['idempotency_key'],
                    'reason' => $verifyResult['message']
                ];
                continue;
            }

            $policyData = $verifyResult['payload']['policy'];
            $location = $policyData['location'];
            $shift = $policyData['shift'];

            // 2. Geofence check — an employee assigned to multiple locations
            // (secondary/temporary) may check in/out from ANY of them, not
            // just the single "primary" one, matching the live (online)
            // check-in/out endpoints' own multi-location logic.
            $radiusField = $log['event_type'] === 'check_out' ? 'checkout_radius' : 'check_in_radius';
            $candidateLocations = !empty($policyData['locations']) ? $policyData['locations'] : [$location];
            $geoCheck = null;
            $matchedLocation = $location;

            foreach ($candidateLocations as $candidate) {
                $check = $this->geofenceService->verifyGeofence(
                    $log['latitude'],
                    $log['longitude'],
                    $candidate['latitude'],
                    $candidate['longitude'],
                    $candidate[$radiusField] ?? $candidate['check_in_radius']
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

            // Offline check-ins/outs outside every assigned geofence are
            // hard-rejected — never persisted, not even flagged for manual
            // review. An employee whose GPS was inaccurate can file an
            // attendance claim afterward instead.
            if (!$geoCheck['in_geofence']) {
                $rejectedRecords[] = [
                    'index' => $index,
                    'idempotency_key' => $log['idempotency_key'],
                    'reason' => 'OUTSIDE_GEOFENCE',
                    'distance_meters' => $geoCheck['distance_meters'],
                    'allowed_radius_meters' => $geoCheck['allowed_radius_meters'],
                ];
                continue;
            }

            // 3. Active-break guard — mirrors the live checkOut() endpoint's
            // BREAK_ACTIVE rejection. The offline queue only validated
            // geofence + signature before this; a checkout captured while on
            // an active break (never validated live, since it was offline at
            // capture time) must still be hard-rejected here at sync time,
            // not silently persisted.
            $deviceDate = Carbon::parse($log['device_time'])->toDateString();

            if ($log['event_type'] === 'check_out') {
                $existingRecordForBreakCheck = AttendanceRecord::where('employee_id', $employee->id)
                    ->where('date', $deviceDate)
                    ->first();
                $activeBreak = $existingRecordForBreakCheck
                    ? $existingRecordForBreakCheck->breaks()->whereNull('end_time_server')->where('status', 'active')->first()
                    : null;

                if ($activeBreak) {
                    $rejectedRecords[] = [
                        'index' => $index,
                        'idempotency_key' => $log['idempotency_key'],
                        'reason' => 'BREAK_ACTIVE',
                    ];
                    continue;
                }
            }

            // 4. Database operations
            $record = AttendanceRecord::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $deviceDate],
                [
                    'shift_id' => $shift ? \App\Models\Shift::where('uuid', $shift['uuid'])->value('id') : null,
                    'location_id' => \App\Models\WorkLocation::where('uuid', $location['uuid'])->value('id'),
                    'status' => 'present',
                    'security_risk_level' => 'low'
                ]
            );

            // Create offline event
            $event = AttendanceEvent::create([
                'attendance_record_id' => $record->id,
                'employee_id' => $employee->id,
                'event_type' => $log['event_type'],
                'event_time_server' => Carbon::now(), // received time
                'event_time_device' => Carbon::parse($log['device_time']),
                'latitude' => $log['latitude'],
                'longitude' => $log['longitude'],
                'accuracy' => $log['accuracy'],
                'distance_meters' => $geoCheck['distance_meters'],
                'device_id' => $log['device_id'],
                'is_offline' => true,
                'sync_status' => 'synced',
                'idempotency_key' => $log['idempotency_key']
            ]);

            if ($log['event_type'] === 'check_in') {
                $record->update(['check_in_event_id' => $event->id]);
                if (\Schema::hasTable('attendance')) {
                    try {
                        \DB::table('attendance')->updateOrInsert(
                            ['employee_id' => $employee->id, 'work_date' => $deviceDate],
                            ['check_in' => Carbon::parse($log['device_time'])->format('H:i:s'), 'status' => 'approved', 'source' => 'self', 'company_id' => $employee->company_id ?? 1, 'updated_at' => Carbon::now()]
                        );
                    } catch (\Throwable $e) {}
                }
            } else {
                $record->update(['check_out_event_id' => $event->id]);
                
                // calculate duration
                if ($record->checkInEvent) {
                    $duration = Carbon::parse($event->event_time_device)->diffInMinutes(Carbon::parse($record->checkInEvent->event_time_device));
                    $record->update([
                        'total_duration_minutes' => $duration,
                        'net_duration_minutes' => $duration
                    ]);
                }
                if (\Schema::hasTable('attendance')) {
                    try {
                        $outTimeStr = Carbon::parse($log['device_time'])->format('H:i:s');
                        $existing = \DB::table('attendance')->where('employee_id', $employee->id)->where('work_date', $deviceDate)->first();
                        $hours = null;
                        if ($existing && !empty($existing->check_in)) {
                            $inTime = Carbon::parse($deviceDate . ' ' . $existing->check_in);
                            $outTime = Carbon::parse($deviceDate . ' ' . $outTimeStr);
                            if ($outTime->gt($inTime)) {
                                $hours = round($inTime->diffInMinutes($outTime) / 60, 2);
                            }
                        }
                        \DB::table('attendance')->updateOrInsert(
                            ['employee_id' => $employee->id, 'work_date' => $deviceDate],
                            ['check_out' => $outTimeStr, 'hours' => $hours, 'status' => 'approved', 'source' => 'self', 'company_id' => $employee->company_id ?? 1, 'updated_at' => Carbon::now()]
                        );
                    } catch (\Throwable $e) {}
                }
            }

            $syncedCount++;
            $this->sendOfflineSyncAck($employee, $log['event_type'], $log['idempotency_key'], $log['device_time']);
        }

        return response()->json([
            'success' => count($failedRecords) === 0,
            'message' => "Synchronized $syncedCount offline records successfully.",
            'data' => [
                'synced_count' => $syncedCount,
                'failed_records' => $failedRecords,
                'already_synced' => $alreadySynced,
                'rejected_records' => $rejectedRecords,
            ]
        ]);
    }

    /**
     * Sync a queued start_break/end_break log by delegating to the same
     * AttendanceController::startBreak()/endBreak() logic used for live, online
     * requests — avoids duplicating break-policy/idempotency/limit handling.
     * Breaks don't carry a geofence signature (no location claim to verify).
     */
    private function syncBreakRecord(Request $originalRequest, $employee, array $log): array
    {
        // end_break's location fields are only present when the queued item
        // was captured on an app version that sends them (see mobile
        // OfflineQueueService::enqueueBreak()) — endBreak() requires them,
        // so an older queued item with none will fail validation here and
        // surface back as a normal sync failure rather than crash.
        $synthetic = Request::create('/', 'POST', [
            'device_time' => $log['device_time'],
            'idempotency_key' => $log['idempotency_key'],
            'reason' => $log['reason'] ?? 'General Break',
            'latitude' => $log['latitude'] ?? null,
            'longitude' => $log['longitude'] ?? null,
            'accuracy' => $log['accuracy'] ?? null,
            'location_timestamp' => $log['device_time'] ?? null,
        ]);
        $synthetic->setUserResolver(fn () => $originalRequest->user());

        try {
            $response = $log['event_type'] === 'start_break'
                ? $this->attendanceController->startBreak($synthetic)
                : $this->attendanceController->endBreak($synthetic);

            $payload = json_decode($response->getContent(), true);

            if (!empty($payload['success'])) {
                return ['success' => true];
            }

            return ['success' => false, 'message' => $payload['message'] ?? 'Break sync failed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
