<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CleaningScheduleController extends Controller
{
    /**
     * Get assigned cleaning schedules for authenticated employee / cleaner / admin
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

        $filter = $request->query('filter', 'all'); // today, upcoming, overdue, completed, all

        // 1. Determine User Roles and Access Level (Admin/Owner vs Cleaner)
        $userRoleNames = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->pluck('r.name')
            ->map(fn($n) => strtolower($n))
            ->toArray();

        $roleName = strtolower($user->role ?? '');
        $desig = strtolower($employee->designation ?? $employee->position_title ?? '');

        $allAccessKeywords = [
            'owner', 'admin', 'super_admin', 'accountant', 'manager', 'director',
            'supervisor', 'engineer', 'property manager', 'property administrator',
            'technician', 'it', 'information technology', 'system'
        ];

        $canSeeAll = false;

        foreach (array_merge($userRoleNames, [$roleName, $desig]) as $checkRole) {
            if (empty($checkRole)) continue;
            foreach ($allAccessKeywords as $kw) {
                if (str_contains($checkRole, $kw)) {
                    $canSeeAll = true;
                    break 2;
                }
            }
        }

        // 2. Find Cleaner Worker IDs associated with this employee
        $workerIds = [];
        if (\Schema::hasTable('workers')) {
            $workerIds = DB::table('workers')
                ->where('emp_num', $employee->employee_code ?? '')
                ->orWhere('id', $employee->id)
                ->orWhere(function($q) use ($employee) {
                    if (!empty($employee->full_name)) {
                        $q->whereRaw('LOWER(worker_name) = ?', [strtolower($employee->full_name)]);
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        // 3. Build Base Query for make_order
        $query = DB::table('make_order as mo')
            ->leftJoin('ars_bookings as b', 'mo.ars_booking_id', '=', 'b.id')
            ->leftJoin('re_units as u', 'b.unit_id', '=', 'u.id')
            ->leftJoin('re_buildings as bl', 'u.building_id', '=', 'bl.id')
            ->select(
                'mo.*',
                'b.booking_number',
                'u.unit_number',
                'bl.name as building_name'
            );

        // Access check: if not Admin/Owner, restrict to assigned orders in order_workers
        if (!$canSeeAll) {
            if (empty($workerIds)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $query->whereIn('mo.id', function ($sub) use ($workerIds) {
                $sub->select('order_id')
                    ->from('order_workers')
                    ->whereIn('worker_id', $workerIds);
            });
        }

        // Exclude cancelled unless explicitly requested
        $query->where('mo.status', '!=', 'cancelled');

        $today = Carbon::today()->toDateString();

        // Date / Status Filters
        if ($filter === 'today') {
            $query->where(function($q) use ($today) {
                $q->whereDate('mo.service_date', '=', $today)
                  ->orWhere(function($sub) use ($today) {
                      $sub->whereNull('mo.service_date')
                          ->where('mo.date', 'LIKE', "%{$today}%");
                  });
            })->whereNotIn('mo.status', ['completed', 'cancelled']);
        } elseif ($filter === 'upcoming') {
            $query->where(function($q) use ($today) {
                $q->whereDate('mo.service_date', '>=', $today)
                  ->orWhere(function($sub) use ($today) {
                      $sub->whereNull('mo.service_date')
                          ->where('mo.date', '>=', $today);
                  });
            })->whereNotIn('mo.status', ['completed', 'cancelled']);
        } elseif ($filter === 'overdue') {
            $query->whereNotIn('mo.status', ['completed', 'cancelled'])
                  ->where(function($q) use ($today) {
                      $q->whereDate('mo.service_date', '<', $today)
                        ->orWhere(function($sub) use ($today) {
                            $sub->whereNull('mo.service_date')
                                ->where('mo.date', '<', $today);
                        });
                  });
        } elseif ($filter === 'completed') {
            $query->where('mo.status', '=', 'completed');
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $paginated = $query->orderBy(DB::raw("COALESCE(mo.service_date, mo.date)"), 'desc')
            ->orderBy('mo.time', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        // 4. Transform response format
        $result = collect($paginated->items())->map(function ($o) {
            // Fetch assigned workers for this order
            $assignedWorkers = DB::table('order_workers as ow')
                ->join('workers as w', 'w.id', '=', 'ow.worker_id')
                ->where('ow.order_id', $o->id)
                ->select('w.id', 'w.worker_name', 'w.emp_num', 'w.nickname', 'ow.role')
                ->get();

            $serviceDate = !empty($o->service_date) ? $o->service_date : ($o->date ?? null);
            $cleanDate = is_string($serviceDate) && strlen($serviceDate) >= 10 ? substr($serviceDate, 0, 10) : $serviceDate;

            return [
                'id' => (int) $o->id,
                'work_order_number' => $o->invoice_no ?: ('CLN-' . sprintf('%04d', $o->id)),
                'title' => $o->client_name ? ("Cleaning - " . $o->client_name) : ("Housekeeping Cleaning #" . $o->id),
                'client_name' => $o->client_name ?: 'Al Reem Guest / Tenant',
                'building_name' => $o->building_name ?: 'AR Properties',
                'unit_number' => $o->unit_number ?: null,
                'location_address' => $o->address_o ?: ($o->building_name ? "{$o->building_name} Unit {$o->unit_number}" : 'Main Property'),
                'schedule_date' => $cleanDate,
                'time_slot' => $o->time ?: ($o->start_time ? substr($o->start_time, 0, 5) . ' - ' . substr($o->end_time, 0, 5) : 'Scheduled Slot'),
                'start_time' => $o->start_time ? substr($o->start_time, 0, 5) : '09:00',
                'end_time' => $o->end_time ? substr($o->end_time, 0, 5) : '18:00',
                'status' => $o->status ?: 'scheduled',
                'priority' => 'normal',
                'notes' => $o->notes ?: ($o->remark ?: 'No special notes.'),
                'need_materials' => (bool) ($o->need_materials ?? false),
                'materials_note' => $o->materials_note ?: null,
                'assigned_cleaners' => $assignedWorkers,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'has_more' => $paginated->hasMorePages(),
                'next_cursor' => $paginated->hasMorePages() ? ($paginated->currentPage() + 1) : null,
            ],
        ]);
    }

    /**
     * Update status of a cleaning order (in_progress, completed, scheduled, cancelled)
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:scheduled,confirmed,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
            'cancel_reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'error' => $validator->errors()
            ], 422);
        }

        $order = DB::table('make_order')->where('id', $id)->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Cleaning order not found.'], 404);
        }

        $status = $request->status;
        $updates = [
            'status' => $status,
            'updated_at' => Carbon::now(),
        ];

        if ($status === 'completed') {
            $updates['ops_status'] = 'completed';
        } elseif ($status === 'cancelled') {
            $updates['ops_status'] = 'cancelled';
            if ($request->filled('cancel_reason')) {
                $updates['cancel_reason'] = $request->cancel_reason;
            }
        } elseif ($status === 'in_progress') {
            $updates['ops_status'] = 'open';
        }

        if ($request->filled('notes')) {
            $existingNotes = $order->notes ? ($order->notes . "\n") : '';
            $updates['notes'] = $existingNotes . '[' . Carbon::now()->format('Y-m-d H:i') . '] ' . $request->notes;
        }

        DB::table('make_order')->where('id', $id)->update($updates);

        return response()->json([
            'success' => true,
            'message' => "Cleaning schedule status updated to {$status}.",
            'status' => $status
        ]);
    }
}
