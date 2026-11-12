<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class MaintenanceWorkOrderController extends Controller
{
    /**
     * Get assigned maintenance schedules for authenticated employee
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.'
            ], 404);
        }

        $legacyUser = \Schema::hasTable('user')
            ? DB::table('user')
                ->where('email', $user->email)
                ->orWhere('employee_id', $employee->id ?? 0)
                ->orWhere('username', $employee->employee_code ?? '')
                ->first()
            : null;

        $userIds = array_unique(array_filter([
            $user->id,
            $employee->id ?? null,
            $legacyUser->id ?? null,
            $employee->employee_code ?? null,
            $employee->employee_number ?? null,
        ]));

        $filter = $request->query('filter', 'all'); // today, upcoming, completed, all

        $query = DB::table('re_maintenance_schedules as s')
            ->leftJoin('re_buildings as b', 's.building_id', '=', 'b.id')
            ->leftJoin('re_units as u', 's.unit_id', '=', 'u.id')
            ->leftJoin('re_building_common_areas as ca', 's.common_area_id', '=', 'ca.id')
            ->select(
                's.*',
                'b.name as building_name',
                'u.unit_number as unit_number',
                'ca.area_name as common_area_name'
            );

        // Role-based visibility check:
        // Admin, Owner, Accountant, Engineer, Supervisor, Property Manager roles assigned in Settings -> User Management can view ALL work orders.
        // Technicians and other staff see ONLY work orders assigned to them.
        $userRoleNames = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->pluck('r.name')
            ->map(fn($n) => strtolower($n))
            ->toArray();

        $roleName = strtolower($user->role ?? '');
        $desig = strtolower($employee->designation ?? $employee->position_title ?? '');
        $maintRole = strtolower($employee->maintenance_role ?? '');

        $allAccessKeywords = [
            'owner', 'admin', 'super_admin', 'accountant', 'manager', 'director',
            'supervisor', 'engineer', 'property manager', 'property administrator'
        ];

        $canSeeAll = false;

        // 1. Check roles assigned in Settings -> User Management (user_roles table)
        foreach ($userRoleNames as $rName) {
            foreach ($allAccessKeywords as $kw) {
                if (str_contains($rName, $kw)) {
                    $canSeeAll = true;
                    break 2;
                }
            }
        }

        // 2. Check direct user role attribute
        if (!$canSeeAll && !empty($roleName)) {
            foreach ($allAccessKeywords as $kw) {
                if (str_contains($roleName, $kw)) {
                    $canSeeAll = true;
                    break;
                }
            }
        }

        // 3. Check employee designation
        if (!$canSeeAll && !empty($desig)) {
            foreach ($allAccessKeywords as $kw) {
                if (str_contains($desig, $kw)) {
                    $canSeeAll = true;
                    break;
                }
            }
        }

        // 4. Check maintenance role
        if (!$canSeeAll && !empty($maintRole)) {
            foreach ($allAccessKeywords as $kw) {
                if (str_contains($maintRole, $kw)) {
                    $canSeeAll = true;
                    break;
                }
            }
        }

        if (!$canSeeAll) {
            $query->where(function ($q) use ($userIds) {
                $q->whereIn('s.id', function ($sub) use ($userIds) {
                    $sub->select('schedule_id')
                        ->from('re_maintenance_schedule_assignees')
                        ->whereIn('employee_id', $userIds);
                })->orWhereIn('s.created_by', $userIds);
            });
        }

        $today = Carbon::today()->toDateString();
        $nowStr = Carbon::now()->toDateTimeString();

        if ($filter === 'today') {
            $query->where('s.schedule_date', '=', $today)
                  ->whereNotIn('s.status', ['completed', 'cancelled']);
        } elseif ($filter === 'upcoming') {
            $query->where('s.schedule_date', '>=', $today)
                  ->whereNotIn('s.status', ['completed', 'cancelled']);
        } elseif ($filter === 'overdue') {
            $query->whereNotIn('s.status', ['completed', 'cancelled'])
                  ->where(function($q) use ($today, $nowStr) {
                      $q->where('s.schedule_date', '<', $today)
                        ->orWhere(function($sub) use ($nowStr) {
                            $sub->whereNotNull('s.end_time')
                                ->whereRaw("CONCAT(s.schedule_date, ' ', s.end_time) < ?", [$nowStr]);
                        });
                  });
        } elseif ($filter === 'completed') {
            $query->where('s.status', '=', 'completed');
        }

        $schedules = $query->orderBy('s.schedule_date', 'asc')
            ->orderBy('s.start_time', 'asc')
            ->get();

        // Attach assignees & photos to each schedule
        $result = $schedules->map(function ($s) {
            $assignees = DB::table('re_maintenance_schedule_assignees as sa')
                ->leftJoin('employees as e', function($join) {
                    $join->on('sa.employee_id', '=', 'e.id')
                         ->orOn('sa.employee_id', '=', 'e.employee_code');
                })
                ->where('sa.schedule_id', $s->id)
                ->select(
                    DB::raw("COALESCE(e.id, sa.employee_id) as id"),
                    DB::raw("COALESCE(e.full_name, 'Team Member') as full_name"),
                    'e.position_title',
                    'sa.team_role'
                )
                ->get();

            $photos = DB::table('re_maintenance_photos')
                ->where('schedule_id', $s->id)
                ->orWhere(function ($q) use ($s) {
                    if (!empty($s->maintenance_request_id)) {
                        $q->where('maintenance_request_id', $s->maintenance_request_id);
                    }
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($p) {
                    $url = asset($p->file_path);
                    if (str_starts_with($p->file_path, 'uploads/')) {
                        $url = url('/sys/' . $p->file_path);
                    }
                    return [
                        'id' => $p->id,
                        'photo_type' => $p->photo_type,
                        'file_name' => $p->file_name,
                        'file_path' => $p->file_path,
                        'photo_url' => $url,
                        'description' => $p->description,
                        'created_at' => $p->created_at,
                    ];
                });

            // Check if overdue
            $isOverdue = false;
            if ($s->status !== 'completed' && $s->status !== 'cancelled') {
                $endDateTimeStr = $s->schedule_date . ' ' . ($s->end_time ?? '23:59:59');
                if (Carbon::now()->isAfter(Carbon::parse($endDateTimeStr))) {
                    $isOverdue = true;
                }
            }

            return [
                'id' => $s->id,
                'company_id' => $s->company_id,
                'work_order_number' => $s->work_order_number,
                'maintenance_request_id' => $s->maintenance_request_id,
                'task_type' => $s->task_type,
                'title' => $s->title,
                'description' => $s->description,
                'priority' => $s->priority,
                'schedule_date' => $s->schedule_date,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'status' => $s->status,
                'notes' => $s->notes,
                'delay_reason' => $s->delay_reason,
                'is_overdue' => $isOverdue,
                'location' => [
                    'building_name' => $s->building_name ?? 'N/A',
                    'unit_number' => $s->unit_number ?? null,
                    'common_area_name' => $s->common_area_name ?? null,
                    'location_type' => $s->location_type,
                    'display_location' => !empty($s->unit_number) 
                        ? ($s->building_name . ' - Unit ' . $s->unit_number)
                        : (!empty($s->common_area_name) ? ($s->building_name . ' - ' . $s->common_area_name) : ($s->building_name ?? 'Property Location')),
                ],
                'assignees' => $assignees,
                'photos' => $photos,
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Update Work Order Status, Notes, and Delay Reason
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:scheduled,in_progress,completed,delayed,cancelled',
            'notes' => 'nullable|string',
            'delay_reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $schedule = DB::table('re_maintenance_schedules')->where('id', $id)->first();

        if (!$schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Work order not found.'
            ], 404);
        }

        $newStatus = $request->input('status');
        $notes = $request->input('notes');
        $delayReason = $request->input('delay_reason');

        // Check if work order is overdue
        $endDateTimeStr = $schedule->schedule_date . ' ' . ($schedule->end_time ?? '23:59:59');
        $isOverdue = Carbon::now()->isAfter(Carbon::parse($endDateTimeStr));

        if (($isOverdue || $newStatus === 'delayed') && empty($delayReason) && empty($schedule->delay_reason)) {
            return response()->json([
                'success' => false,
                'message' => 'Work is past the assigned schedule end time. Please provide a reason why it was delayed or not completed in time.',
                'requires_delay_reason' => true
            ], 422);
        }

        $updateData = [
            'status' => $newStatus,
            'updated_at' => Carbon::now(),
        ];

        if ($notes !== null) {
            $updateData['notes'] = $notes;
        }

        if (!empty($delayReason)) {
            $updateData['delay_reason'] = $delayReason;
        }

        DB::table('re_maintenance_schedules')->where('id', $id)->update($updateData);

        // Sync linked re_maintenance_requests status if present
        if (!empty($schedule->maintenance_request_id)) {
            $reqStatus = $newStatus === 'completed' ? 'completed' : ($newStatus === 'in_progress' ? 'in_progress' : null);
            if ($reqStatus) {
                $reqUpdate = ['status' => $reqStatus, 'updated_at' => Carbon::now()];
                if ($reqStatus === 'completed') {
                    $reqUpdate['completed_at'] = Carbon::now();
                }
                DB::table('re_maintenance_requests')
                    ->where('id', $schedule->maintenance_request_id)
                    ->update($reqUpdate);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Work order status updated successfully.',
            'data' => [
                'id' => $schedule->id,
                'status' => $newStatus,
                'notes' => $notes ?? $schedule->notes,
                'delay_reason' => $delayReason ?? $schedule->delay_reason,
            ]
        ]);
    }

    /**
     * Upload Before/After/During Photo for a Maintenance Work Order
     */
    public function uploadPhoto(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'photo_type' => 'required|string|in:before,during,after,completion',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $schedule = DB::table('re_maintenance_schedules')->where('id', $id)->first();

        if (!$schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Work order not found.'
            ], 404);
        }

        $existingPhotoCount = DB::table('re_maintenance_photos')->where('schedule_id', $schedule->id)->count();
        if ($existingPhotoCount >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum 5 photos allowed per maintenance work order.'
            ], 422);
        }

        $file = $request->file('photo');
        $photoType = $request->input('photo_type');
        $description = $request->input('description');

        $sysBasePath = '/Applications/XAMPP/xamppfiles/htdocs/sys';
        $uploadSubDir = 'uploads/realestate/maintenance/' . $schedule->id;
        $targetDir = $sysBasePath . '/' . $uploadSubDir;
        $publicDir = public_path($uploadSubDir);

        if (!file_exists($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        if (!file_exists($publicDir)) {
            @mkdir($publicDir, 0777, true);
        }

        $filename = 'photo_' . $photoType . '_' . time() . '_' . rand(1000, 9999) . '.' . $file->getClientOriginalExtension();
        $file->move($targetDir, $filename);
        @copy($targetDir . '/' . $filename, $publicDir . '/' . $filename);

        $filePath = $uploadSubDir . '/' . $filename;
        $fileSize = filesize($targetDir . '/' . $filename);
        $mimeType = $file->getClientMimeType();

        $photoId = DB::table('re_maintenance_photos')->insertGetId([
            'company_id' => $schedule->company_id,
            'maintenance_request_id' => $schedule->maintenance_request_id ?? 0,
            'schedule_id' => $schedule->id,
            'photo_type' => $photoType,
            'file_name' => $filename,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'description' => $description,
            'uploaded_by' => $request->user()->id,
            'created_at' => Carbon::now(),
        ]);

        $photoUrl = url('/sys/' . $filePath);

        return response()->json([
            'success' => true,
            'message' => 'Maintenance photo uploaded successfully.',
            'data' => [
                'id' => $photoId,
                'schedule_id' => $schedule->id,
                'photo_type' => $photoType,
                'file_name' => $filename,
                'file_path' => $filePath,
                'photo_url' => $photoUrl,
                'description' => $description,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]
        ]);
    }
}
