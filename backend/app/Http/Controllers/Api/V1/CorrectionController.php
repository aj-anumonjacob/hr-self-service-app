<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CorrectionController extends Controller
{
    /**
     * List employee's own correction requests
     */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        $corrections = $employee->corrections()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $corrections
        ]);
    }

    /**
     * Create correction request
     */
    public function store(Request $request)
    {
        $employee = $request->user()->employee;

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'correction_type' => 'required|string',
            'requested_time' => 'required|date',
            'reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error.', 'error' => $validator->errors()], 422);
        }

        $correction = AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => $request->date,
            'correction_type' => $request->correction_type,
            'requested_time' => Carbon::parse($request->requested_time),
            'reason' => $request->reason,
            'manager_id' => $employee->reporting_manager_id,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Correction request submitted successfully.',
            'data' => ['correction_uuid' => $correction->uuid]
        ]);
    }
}
