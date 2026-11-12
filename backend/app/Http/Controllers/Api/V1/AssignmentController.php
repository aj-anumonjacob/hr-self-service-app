<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLocationAssignment;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    /**
     * Get temporary assignments
     */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        $assignments = $employee->assignments()
            ->with(['assignedLocation'])
            ->orderBy('start_time', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $assignments
        ]);
    }

    /**
     * Acknowledge/Confirm assignment
     */
    public function acknowledge(Request $request, $uuid)
    {
        $employee = $request->user()->employee;
        $assignment = EmployeeLocationAssignment::where('uuid', $uuid)
            ->where('employee_id', $employee->id)
            ->first();

        if (!$assignment) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }

        $assignment->update(['employee_acknowledged' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Assignment acknowledged.'
        ]);
    }
}
