<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LocationTrackingSession;
use App\Models\EmployeeLocationLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TrackingController extends Controller
{
    /**
     * Start background location tracking session
     */
    public function start(Request $request)
    {
        $employee = $request->user()->employee;
        $today = Carbon::now()->toDateString();
        
        $record = $employee->attendanceRecords()->where('date', $today)->first();
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'No active checked-in session found.'], 400);
        }

        $session = LocationTrackingSession::create([
            'employee_id' => $employee->id,
            'attendance_record_id' => $record->id,
            'start_time' => Carbon::now(),
            'tracking_mode' => $request->tracking_mode ?: 'fixed_interval',
            'status' => 'active'
        ]);

        return response()->json([
            'success' => true,
            'data' => ['session_uuid' => $session->uuid]
        ]);
    }

    /**
     * Upload batch location tracking logs
     */
    public function uploadLocations(Request $request)
    {
        $employee = $request->user()->employee;

        $validator = Validator::make($request->all(), [
            'session_uuid' => 'required|uuid',
            'logs' => 'required|array',
            'logs.*.latitude' => 'required|numeric',
            'logs.*.longitude' => 'required|numeric',
            'logs.*.accuracy' => 'required|numeric',
            'logs.*.captured_at' => 'required|date',
            'logs.*.battery_level' => 'nullable|integer',
            'logs.*.network_status' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error.', 'error' => $validator->errors()], 422);
        }

        $session = LocationTrackingSession::where('uuid', $request->session_uuid)
            ->where('employee_id', $employee->id)
            ->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Active session not found.'], 404);
        }

        $now = Carbon::now();
        foreach ($request->logs as $log) {
            EmployeeLocationLog::create([
                'tracking_session_id' => $session->id,
                'employee_id' => $employee->id,
                'latitude' => $log['latitude'],
                'longitude' => $log['longitude'],
                'accuracy' => $log['accuracy'],
                'captured_at' => Carbon::parse($log['captured_at']),
                'received_at' => $now,
                'battery_level' => $log['battery_level'] ?? null,
                'network_status' => $log['network_status'] ?? null,
                'device_id' => $request->device_id
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Locations uploaded successfully.'
        ]);
    }

    /**
     * Stop background tracking session
     */
    public function stop(Request $request)
    {
        $employee = $request->user()->employee;

        $session = LocationTrackingSession::where('uuid', $request->session_uuid)
            ->where('employee_id', $employee->id)
            ->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Session not found.'], 404);
        }

        $session->update([
            'status' => 'completed',
            'end_time' => Carbon::now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tracking session stopped.'
        ]);
    }
}
