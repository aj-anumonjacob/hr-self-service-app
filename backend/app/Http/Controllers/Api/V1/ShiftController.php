<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeShiftAllocation;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShiftController extends Controller
{
    /**
     * Get list of all shifts.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id ?? 1;

        $shifts = Shift::where('company_id', $companyId)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $shifts,
        ]);
    }

    /**
     * Create a new shift definition.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
            'type' => 'nullable|string|in:fixed,flexible,rotational',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'grace_period_minutes' => 'nullable|integer|min:0|max:120',
            'late_threshold_minutes' => 'nullable|integer|min:0|max:240',
            'early_departure_minutes' => 'nullable|integer|min:0|max:240',
            'min_working_minutes' => 'nullable|integer|min:0|max:1440',
            'overtime_calc_enabled' => 'nullable|boolean',
            'working_days' => 'nullable|array',
            'working_days.*' => 'integer|min:0|max:6',
        ]);

        $companyId = $request->user()->company_id ?? 1;
        $code = $validated['code'] ?? 'SHIFT-' . strtoupper(Str::random(5));

        $shift = Shift::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'name' => $validated['name'],
            'code' => $code,
            'type' => $validated['type'] ?? 'fixed',
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'grace_period_minutes' => $validated['grace_period_minutes'] ?? 15,
            'late_threshold_minutes' => $validated['late_threshold_minutes'] ?? 30,
            'early_departure_minutes' => $validated['early_departure_minutes'] ?? 15,
            'min_working_minutes' => $validated['min_working_minutes'] ?? 480,
            'overtime_calc_enabled' => $validated['overtime_calc_enabled'] ?? false,
            'working_days' => $validated['working_days'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift created successfully.',
            'data' => $shift,
        ], 201);
    }

    /**
     * Update an existing shift definition.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'code' => 'nullable|string|max:50',
            'type' => 'nullable|string|in:fixed,flexible,rotational',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'grace_period_minutes' => 'nullable|integer|min:0|max:120',
            'late_threshold_minutes' => 'nullable|integer|min:0|max:240',
            'early_departure_minutes' => 'nullable|integer|min:0|max:240',
            'min_working_minutes' => 'nullable|integer|min:0|max:1440',
            'overtime_calc_enabled' => 'nullable|boolean',
            'working_days' => 'nullable|array',
            'working_days.*' => 'integer|min:0|max:6',
        ]);

        $shift->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shift updated successfully.',
            'data' => $shift,
        ]);
    }

    /**
     * Delete a shift definition.
     */
    public function destroy(int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $shift->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shift deleted successfully.',
        ]);
    }

    /**
     * Assign shift to single or multiple employees.
     */
    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'integer|exists:employees,id',
            'shift_id' => 'required|integer|exists:shifts,id',
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'day_of_week' => 'nullable|string',
            'is_permanent' => 'nullable|boolean',
            'notes' => 'nullable|string|max:255',
        ]);

        $shift = Shift::findOrFail($validated['shift_id']);
        $employeeIds = $validated['employee_ids'];
        $isPermanent = $validated['is_permanent'] ?? false;
        $effectiveFrom = $validated['effective_from'];
        $effectiveTo = $validated['effective_to'] ?? null;
        $dayOfWeek = $validated['day_of_week'] ?? 'all';
        $userId = $request->user()->id ?? null;

        DB::transaction(function () use ($employeeIds, $shift, $effectiveFrom, $effectiveTo, $dayOfWeek, $isPermanent, $userId, $validated) {
            foreach ($employeeIds as $empId) {
                // If set as permanent default shift
                if ($isPermanent) {
                    Employee::where('id', $empId)->update(['shift_id' => $shift->id]);
                }

                // Deactivate overlapping active allocations
                EmployeeShiftAllocation::where('employee_id', $empId)
                    ->where('is_active', true)
                    ->where('effective_from', '>=', $effectiveFrom)
                    ->update(['is_active' => false]);

                // Create new allocation
                EmployeeShiftAllocation::create([
                    'uuid' => (string) Str::uuid(),
                    'employee_id' => $empId,
                    'shift_id' => $shift->id,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'day_of_week' => $dayOfWeek,
                    'is_active' => true,
                    'created_by' => $userId,
                    'notes' => $validated['notes'] ?? null,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Shift assigned successfully to ' . count($employeeIds) . ' employee(s).',
        ]);
    }

    /**
     * Get shift allocations roster.
     */
    public function allocations(Request $request): JsonResponse
    {
        $query = EmployeeShiftAllocation::with(['employee', 'shift'])
            ->where('is_active', true);

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->has('date')) {
            $date = $request->query('date');
            $query->where('effective_from', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
                });
        }

        $allocations = $query->orderBy('effective_from', 'desc')->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $allocations,
        ]);
    }

    /**
     * Get employee active shift for current date.
     */
    public function getEmployeeShift(Request $request, int $employeeId): JsonResponse
    {
        $date = $request->query('date', date('Y-m-d'));
        $employee = Employee::findOrFail($employeeId);
        $shift = $employee->getActiveShiftForDate($date);

        return response()->json([
            'success' => true,
            'data' => [
                'employee_id' => $employee->id,
                'date' => $date,
                'shift' => $shift,
            ],
        ]);
    }
}
