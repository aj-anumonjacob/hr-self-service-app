<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\AttendanceRecord;
use App\Models\AttendanceCorrection;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ManagerController extends Controller
{
    /**
     * Manager team dashboard summaries
     */
    public function dashboard(Request $request)
    {
        $manager = $request->user()->employee;
        if (!$manager) {
            return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $teamCount = Employee::where('reporting_manager_id', $manager->id)->count();
        
        $today = Carbon::now()->toDateString();
        
        // Present today
        $presentCount = AttendanceRecord::where('date', $today)
            ->whereHas('employee', fn($q) => $q->where('reporting_manager_id', $manager->id))
            ->whereIn('status', ['present', 'late'])
            ->count();
            
        // Late today
        $lateCount = AttendanceRecord::where('date', $today)
            ->whereHas('employee', fn($q) => $q->where('reporting_manager_id', $manager->id))
            ->where('status', 'late')
            ->count();

        // Pending corrections
        $pendingCorrections = AttendanceCorrection::where('status', 'pending')
            ->where('manager_id', $manager->id)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'team_size' => $teamCount,
                'present_today' => $presentCount,
                'late_today' => $lateCount,
                'absent_today' => max(0, $teamCount - $presentCount),
                'pending_corrections' => $pendingCorrections
            ]
        ]);
    }

    /**
     * List manager subordinates/employees
     */
    public function employees(Request $request)
    {
        $manager = $request->user()->employee;
        $team = Employee::where('reporting_manager_id', $manager->id)->get();

        return response()->json([
            'success' => true,
            'data' => $team
        ]);
    }

    /**
     * List team attendance records
     */
    public function attendance(Request $request)
    {
        $manager = $request->user()->employee;
        $records = AttendanceRecord::with(['employee', 'checkInEvent', 'checkOutEvent'])
            ->whereHas('employee', fn($q) => $q->where('reporting_manager_id', $manager->id))
            ->orderBy('date', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $records
        ]);
    }

    /**
     * Approve correction request
     */
    public function approveCorrection(Request $request, $uuid)
    {
        $manager = $request->user()->employee;
        $correction = AttendanceCorrection::where('uuid', $uuid)
            ->where('manager_id', $manager->id)
            ->first();

        if (!$correction) {
            return response()->json(['success' => false, 'message' => 'Correction request not found.'], 404);
        }

        $correction->update([
            'status' => 'approved',
            'manager_notes' => $request->manager_notes
        ]);

        // Overwrite or create the attendance record event as requested
        $record = AttendanceRecord::updateOrCreate(
            ['employee_id' => $correction->employee_id, 'date' => $correction->date],
            ['status' => 'present']
        );

        if ($correction->correction_type === 'missed_check_in' || $correction->correction_type === 'wrong_time') {
            // simulate event update
            $record->update(['status' => 'present']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Correction request approved.'
        ]);
    }

    /**
     * Reject correction request
     */
    public function rejectCorrection(Request $request, $uuid)
    {
        $manager = $request->user()->employee;
        $correction = AttendanceCorrection::where('uuid', $uuid)
            ->where('manager_id', $manager->id)
            ->first();

        if (!$correction) {
            return response()->json(['success' => false, 'message' => 'Correction request not found.'], 404);
        }

        $correction->update([
            'status' => 'rejected',
            'manager_notes' => $request->manager_notes
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Correction request rejected.'
        ]);
    }
}
