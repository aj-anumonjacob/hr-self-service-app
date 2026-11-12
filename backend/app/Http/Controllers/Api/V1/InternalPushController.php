<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * InternalPushController
 *
 * Lets the PHP HR portal (a separate codebase sharing the same database)
 * trigger a real Firebase push without duplicating FCM/OAuth logic in raw
 * PHP. Guarded by a shared secret header instead of Sanctum, since the
 * caller is a server, not a logged-in mobile user.
 */
class InternalPushController extends Controller
{
    public function send(Request $request)
    {
        $expectedSecret = config('services.internal_api_secret');
        $providedSecret = $request->header('X-Internal-Secret');

        if (empty($expectedSecret) || !hash_equals((string) $expectedSecret, (string) $providedSecret)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'title'       => 'required|string|max:255',
            'message'     => 'required|string',
            'data'        => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $employee = Employee::find($request->integer('employee_id'));
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found.',
            ], 404);
        }

        $deliveredCount = app(PushNotificationService::class)->sendToEmployee(
            $employee,
            $request->input('title'),
            $request->input('message'),
            $request->input('data', [])
        );

        return response()->json([
            'success' => true,
            'data' => ['delivered_to_devices' => $deliveredCount],
        ]);
    }
}
