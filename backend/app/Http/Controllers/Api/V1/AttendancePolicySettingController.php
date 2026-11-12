<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendancePolicySetting;
use Illuminate\Http\Request;

class AttendancePolicySettingController extends Controller
{
    /**
     * Get attendance policy settings for the user's company.
     */
    public function show(Request $request)
    {
        $employee = $request->user()->employee;
        $companyId = $employee ? $employee->company_id : 1;

        $settings = AttendancePolicySetting::forCompany($companyId);

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }
}
