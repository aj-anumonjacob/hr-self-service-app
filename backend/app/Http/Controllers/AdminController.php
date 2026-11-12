<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\Shift;
use App\Models\AttendanceRecord;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'team_size' => Employee::count(),
            'present_today' => AttendanceRecord::where('date', Carbon::now()->toDateString())->whereIn('status', ['present', 'late'])->count(),
            'late_today' => AttendanceRecord::where('date', Carbon::now()->toDateString())->where('status', 'late')->count(),
            'pending_corrections' => AttendanceCorrection::where('status', 'pending')->count()
        ];

        $locations = WorkLocation::all();
        $activeSessions = AttendanceRecord::where('date', Carbon::now()->toDateString())
            ->whereNotNull('check_in_event_id')
            ->whereNull('check_out_event_id')
            ->with(['employee', 'location', 'checkInEvent'])
            ->get();

        return view('admin.dashboard', compact('stats', 'locations', 'activeSessions'));
    }

    public function employees()
    {
        $employees = Employee::with(['department', 'defaultLocation'])->get();
        return view('admin.employees', compact('employees'));
    }

    public function locations()
    {
        $locations = WorkLocation::all();
        return view('admin.locations', compact('locations'));
    }

    public function shifts()
    {
        $shifts = Shift::all();
        return view('admin.shifts', compact('shifts'));
    }

    public function attendance()
    {
        $records = AttendanceRecord::with(['employee', 'checkInEvent', 'checkOutEvent'])->orderBy('date', 'desc')->get();
        return view('admin.attendance', compact('records'));
    }

    public function corrections()
    {
        $corrections = AttendanceCorrection::with('employee')->orderBy('created_at', 'desc')->get();
        return view('admin.corrections', compact('corrections'));
    }

    public function approveCorrection($uuid)
    {
        $correction = AttendanceCorrection::where('uuid', $uuid)->firstOrFail();
        $correction->update(['status' => 'approved']);
        
        AttendanceRecord::updateOrCreate(
            ['employee_id' => $correction->employee_id, 'date' => $correction->date],
            ['status' => 'present']
        );
        
        return redirect()->back()->with('success', 'Correction approved.');
    }

    public function rejectCorrection($uuid)
    {
        $correction = AttendanceCorrection::where('uuid', $uuid)->firstOrFail();
        $correction->update(['status' => 'rejected']);
        
        return redirect()->back()->with('success', 'Correction rejected.');
    }

    public function audit()
    {
        $logs = AuditLog::with('user')->orderBy('created_at', 'desc')->get();
        return view('admin.audit', compact('logs'));
    }
}
