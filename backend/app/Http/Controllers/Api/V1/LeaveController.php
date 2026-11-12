<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LeaveController extends Controller
{
    protected EmailNotificationService $emailNotificationService;

    public function __construct(EmailNotificationService $emailNotificationService)
    {
        $this->emailNotificationService = $emailNotificationService;
    }

    /**
     * Resolve corresponding employee_id in HR module.
     */
    private function resolveEmployeeId($user)
    {
        if (isset($user->employee_id) && $user->employee_id) {
            return $user->employee_id;
        }

        if ($user->email) {
            $emp = DB::table('employees')->where('email', $user->email)->first();
            if ($emp) {
                return $emp->id;
            }
        }

        $emp = DB::table('employees')->where('id', $user->id)->first();
        if ($emp) {
            return $emp->id;
        }

        return $user->id;
    }

    /**
     * Get active leave types from database.
     */
    public function getLeaveTypes()
    {
        $types = DB::table('leave_types')
            ->select('id', 'name', 'code', 'is_paid', 'annual_quota_days', 'requires_attachment', 'color_hex')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Get leave balances for the authenticated employee by year.
     * Direct sync from leave_balances table in HR module.
     */
    public function getBalances(Request $request)
    {
        $user = $request->user();
        $employeeId = $this->resolveEmployeeId($user);
        $year = (int) ($request->query('year', date('Y')));

        // Query leave balances synced from HR module table
        $balances = DB::table('leave_balances as lb')
            ->join('leave_types as lt', 'lt.id', '=', 'lb.leave_type_id')
            ->where('lb.employee_id', $employeeId)
            ->where('lb.year', $year)
            ->select(
                'lb.id',
                'lb.employee_id',
                'lb.leave_type_id',
                'lt.name as type_name',
                'lt.code as type_code',
                'lt.color_hex',
                'lb.year',
                'lb.opening',
                'lb.accrued',
                'lb.taken',
                'lb.carried',
                'lb.closing'
            )
            ->orderBy('lt.id', 'asc')
            ->get();

        // If balances don't exist for this year, auto-initialize from leave_types
        if ($balances->isEmpty()) {
            $types = DB::table('leave_types')->get();
            foreach ($types as $type) {
                $quota = (float) ($type->annual_quota_days ?? 30.0);
                DB::table('leave_balances')->insertOrIgnore([
                    'employee_id' => $employeeId,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                    'opening' => $quota,
                    'accrued' => 0.00,
                    'taken' => 0.00,
                    'carried' => 0.00,
                    'closing' => $quota,
                    'updated_at' => now(),
                ]);
            }

            $balances = DB::table('leave_balances as lb')
                ->join('leave_types as lt', 'lt.id', '=', 'lb.leave_type_id')
                ->where('lb.employee_id', $employeeId)
                ->where('lb.year', $year)
                ->select(
                    'lb.id',
                    'lb.employee_id',
                    'lb.leave_type_id',
                    'lt.name as type_name',
                    'lt.code as type_code',
                    'lt.color_hex',
                    'lb.year',
                    'lb.opening',
                    'lb.accrued',
                    'lb.taken',
                    'lb.carried',
                    'lb.closing'
                )
                ->orderBy('lt.id', 'asc')
                ->get();
        }

        return response()->json([
            'success' => true,
            'year' => $year,
            'employee_id' => $employeeId,
            'data' => $balances
        ]);
    }

    /**
     * List leave requests for employee directly synced with HR Module.
     */
    public function getRequests(Request $request)
    {
        $user = $request->user();
        $employeeId = $this->resolveEmployeeId($user);
        $year = $request->query('year');

        $query = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->where('lr.employee_id', $employeeId)
            ->select(
                'lr.id',
                'lr.employee_id',
                'lr.leave_type_id',
                'lt.name as leave_type_name',
                'lt.code as leave_type_code',
                'lt.color_hex',
                'lr.date_from',
                'lr.date_to',
                'lr.days',
                'lr.reason',
                'lr.status',
                'lr.attachment_path',
                'lr.created_at',
                'lr.updated_at'
            );

        if ($year) {
            $query->whereYear('lr.date_from', $year);
        }

        $requests = $query->orderBy('lr.id', 'desc')->get();

        // Transform attachment paths into full URLs
        $requests->transform(function ($item) {
            if ($item->attachment_path) {
                if (str_starts_with($item->attachment_path, 'http://') || str_starts_with($item->attachment_path, 'https://')) {
                    $item->attachment_url = $item->attachment_path;
                } else {
                    $item->attachment_url = asset(ltrim($item->attachment_path, '/'));
                }
            } else {
                $item->attachment_url = null;
            }
            return $item;
        });

        return response()->json([
            'success' => true,
            'employee_id' => $employeeId,
            'data' => $requests
        ]);
    }

    /**
     * Submit a new leave request creating a new record in main system HR module for the corresponding Employee.
     */
    public function storeRequest(Request $request)
    {
        $user = $request->user();
        $employeeId = $this->resolveEmployeeId($user);

        // Fetch company_id from employee or user
        $emp = DB::table('employees')->where('id', $employeeId)->first();
        $companyId = $emp ? ($emp->company_id ?? 1) : ($user->company_id ?? 1);

        $today = Carbon::today()->format('Y-m-d');

        $validator = Validator::make($request->all(), [
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'date_from' => 'required|date_format:Y-m-d|after_or_equal:' . $today,
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'reason' => 'nullable|string|max:500',
        ], [
            'date_from.after_or_equal' => 'From Date cannot be a past date before today.',
            'date_to.after_or_equal' => 'To Date cannot be before From Date.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->hasFile('attachment')) {
            $attachment = $request->file('attachment');
            $ext = strtolower($attachment->getClientOriginalExtension());
            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            if (!in_array($ext, $allowedExts)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Allowed file types: PDF, PNG, JPG, JPEG, DOC, DOCX.',
                    'errors' => ['attachment' => ['Allowed file types: PDF, PNG, JPG, JPEG, DOC, DOCX.']]
                ], 422);
            }
            if ($attachment->getSize() > 5 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attachment file size exceeds the 5 MB limit.',
                    'errors' => ['attachment' => ['Attachment file size exceeds the 5 MB limit.']]
                ], 422);
            }
        }

        $leaveType = DB::table('leave_types')->where('id', $request->leave_type_id)->first();
        if ($leaveType && $leaveType->requires_attachment && !$request->hasFile('attachment')) {
            return response()->json([
                'success' => false,
                'message' => 'An attachment document is required for ' . $leaveType->name . '.',
                'errors' => ['attachment' => ['An attachment document is required for ' . $leaveType->name . '.']]
            ], 422);
        }

        // Calculate total days
        $start = Carbon::parse($request->date_from);
        $end = Carbon::parse($request->date_to);
        $days = $start->diffInDays($end) + 1;

        // Handle attachment file upload matching main PHP system format
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $ext = strtolower($file->getClientOriginalExtension());
            $fname = 'att_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;

            $sysDir = '/Applications/XAMPP/xamppfiles/htdocs/sys/uploads/leave_attachments';
            if (!is_dir($sysDir)) {
                @mkdir($sysDir, 0775, true);
            }
            if (is_dir($sysDir) && is_writable($sysDir)) {
                $file->move($sysDir, $fname);
            } else {
                $file->storeAs('uploads/leave_attachments', $fname, 'public');
            }
            $attachmentPath = 'uploads/leave_attachments/' . $fname;
        }

        // Insert into leave_requests table for corresponding Employee
        $insertId = DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type_id' => $request->leave_type_id,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
            'days' => $days,
            'reason' => $request->reason,
            'status' => 'pending',
            'requester_id' => $user->id,
            'attachment_path' => $attachmentPath,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newRequest = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->where('lr.id', $insertId)
            ->select(
                'lr.id',
                'lr.employee_id',
                'lr.leave_type_id',
                'lt.name as leave_type_name',
                'lt.code as leave_type_code',
                'lr.date_from',
                'lr.date_to',
                'lr.days',
                'lr.reason',
                'lr.status',
                'lr.attachment_path',
                'lr.created_at'
            )
            ->first();

        if ($newRequest && $newRequest->attachment_path) {
            $newRequest->attachment_url = asset(ltrim($newRequest->attachment_path, '/'));
        }

        try {
            $this->emailNotificationService->sendLeaveRequestSubmitted([
                'id' => $insertId,
                'employee_name' => $emp->full_name ?? 'Employee',
                'employee_code' => $emp->employee_code ?? $emp->employee_number ?? (string) $employeeId,
                'leave_type_name' => $newRequest->leave_type_name ?? 'Leave',
                'date_from' => $newRequest->date_from ?? $request->date_from,
                'date_to' => $newRequest->date_to ?? $request->date_to,
                'days' => $newRequest->days ?? $days,
                'reason' => $newRequest->reason ?? $request->reason,
                'has_attachment' => !empty($attachmentPath),
                'created_at' => $newRequest->created_at ?? now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning("Leave request email notification error: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully to Main System HR Module.',
            'data' => $newRequest
        ], 201);
    }
}
