<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OtpMailAuditLog;
use App\Services\ActivationCodeService;
use App\Services\DeviceBindingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminDeviceController extends Controller
{
    public function generateCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $employee = Employee::find($request->employee_id);
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found.',
            ], 404);
        }

        $adminId = auth()->id() ?? 1;
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($employee, 'hr_portal', $adminId, $request->ip());

        // Set activation_token on employee record for legacy fallback compatibility
        $employee->update([
            'activation_token' => $res['plain_code'],
            'activation_token_expires_at' => $res['expires_at'],
            'device_registration_status' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Activation code generated successfully.',
            'data' => [
                'code' => $res['plain_code'],
                'expires_at' => $res['expires_at']->toIso8601String(),
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code ?? $employee->employee_number,
                'warning' => 'This code will only be displayed ONCE. Store it securely or share directly with the employee.',
            ]
        ]);
    }

    public function unbind(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $employee = Employee::find($request->employee_id);
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found.',
            ], 404);
        }

        $adminId = auth()->id() ?? 1;
        $bindingService = app(DeviceBindingService::class);
        $bindingService->unbindDevice($employee, $adminId, $request->ip());

        return response()->json([
            'success' => true,
            'message' => 'Device binding revoked and active sessions terminated successfully.',
        ]);
    }

    public function auditLogs(Request $request)
    {
        $query = OtpMailAuditLog::query();

        if ($request->has('event_type') && !empty($request->event_type)) {
            $query->where('event_type', $request->event_type);
        }
        if ($request->has('employee_id') && !empty($request->employee_id)) {
            $query->where('employee_id', $request->employee_id);
        }

        $logs = $query->orderBy('id', 'desc')->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
