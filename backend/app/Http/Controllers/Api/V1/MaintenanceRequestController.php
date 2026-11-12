<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintenanceRequestController extends Controller
{
    /**
     * Get list of maintenance requests.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $emp = DB::table('employees')
            ->where('email', $user->email)
            ->orWhere('user_id', $user->id)
            ->orWhere('id', $user->id)
            ->first();

        $legacyUser = \Schema::hasTable('user')
            ? DB::table('user')
                ->where('email', $user->email)
                ->orWhere('employee_id', $emp->id ?? 0)
                ->orWhere('username', $emp->employee_code ?? '')
                ->first()
            : null;

        $query = DB::table('re_maintenance_requests as r')
            ->leftJoin('re_buildings as b', 'b.id', '=', 'r.building_id')
            ->leftJoin('re_units as u', 'u.id', '=', 'r.unit_id')
            ->leftJoin('re_tenants as t', 't.id', '=', 'r.tenant_id')
            ->select(
                'r.id',
                'r.company_id',
                'r.request_date',
                'r.category',
                'r.priority',
                'r.status',
                'r.cost',
                'r.description',
                'r.notes',
                'r.source',
                'r.created_by',
                'r.created_at',
                'r.updated_at',
                'b.name as building_name',
                'u.unit_number',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.first_name, t.last_name)), ''), t.company_name, 'Tenant') as tenant_name"),
                't.phone as tenant_phone',
                't.email as tenant_email'
            );

        // Role-based visibility check:
        // Admin, Owner, Accountant, Engineer, Supervisor, Property Manager roles assigned in Settings -> User Management can view ALL requests.
        // Technicians and other staff see ONLY requests assigned to them.
        $userRoleNames = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->pluck('r.name')
            ->map(fn($n) => strtolower($n))
            ->toArray();

        $roleName = strtolower($user->role ?? '');
        $desig = strtolower($emp->designation ?? $emp->position_title ?? '');
        $maintRole = strtolower($emp->maintenance_role ?? '');

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
            $userIds = array_unique(array_filter([
                $user->id,
                $emp->id ?? null,
                $legacyUser->id ?? null,
                $emp->employee_code ?? null,
                $emp->employee_number ?? null,
            ]));

            $query->where(function($q) use ($userIds) {
                $q->whereIn('r.assigned_to', $userIds)
                  ->orWhereIn('r.created_by', $userIds)
                  ->orWhereIn('r.id', function($sub) use ($userIds) {
                      $sub->select('maintenance_request_id')
                          ->from('re_maintenance_schedules as ms')
                          ->join('re_maintenance_schedule_assignees as msa', 'msa.schedule_id', '=', 'ms.id')
                          ->whereIn('msa.employee_id', $userIds)
                          ->whereNotNull('ms.maintenance_request_id');
                  });
            });
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('r.status', $request->status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function($q) use ($search) {
                $q->where('r.description', 'like', $search)
                  ->orWhere('r.category', 'like', $search)
                  ->orWhere('b.name', 'like', $search)
                  ->orWhere('u.unit_number', 'like', $search);
            });
        }

        $requests = $query->orderBy('r.id', 'desc')->limit(100)->get();

        $data = $requests->map(function($r) {
            $locationStr = trim(($r->building_name ?? '') . ($r->unit_number ? ' — Unit ' . $r->unit_number : ''));
            return [
                'id' => (int) $r->id,
                'company_id' => (int) $r->company_id,
                'request_date' => $r->request_date,
                'category' => $r->category ?? 'General Maintenance',
                'priority' => $r->priority ?? 'normal',
                'status' => $r->status ?? 'pending',
                'cost' => (float) ($r->cost ?? 0.0),
                'description' => $r->description,
                'notes' => $r->notes,
                'source' => $r->source ?? 'internal',
                'location' => [
                    'building_name' => $r->building_name,
                    'unit_number' => $r->unit_number,
                    'display_location' => $locationStr ?: 'General Location',
                ],
                'tenant' => [
                    'name' => $r->tenant_name,
                    'phone' => $r->tenant_phone,
                    'email' => $r->tenant_email,
                ],
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get single maintenance request details.
     */
    public function show(Request $request, $id)
    {
        $r = DB::table('re_maintenance_requests as r')
            ->leftJoin('re_buildings as b', 'b.id', '=', 'r.building_id')
            ->leftJoin('re_units as u', 'u.id', '=', 'r.unit_id')
            ->leftJoin('re_tenants as t', 't.id', '=', 'r.tenant_id')
            ->select(
                'r.*',
                'b.name as building_name',
                'u.unit_number',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.first_name, t.last_name)), ''), t.company_name, 'Tenant') as tenant_name"),
                't.phone as tenant_phone',
                't.email as tenant_email'
            )
            ->where('r.id', $id)
            ->first();

        if (!$r) {
            return response()->json(['success' => false, 'message' => 'Maintenance request not found.'], 404);
        }

        // Fetch photos
        $photos = DB::table('re_maintenance_photos')
            ->where('maintenance_request_id', $id)
            ->get()
            ->map(function($p) {
                $filePath = $p->file_path;
                if (!empty($filePath) && !str_starts_with($filePath, 'http')) {
                    $filePath = url('/sys/' . $filePath);
                }
                return [
                    'id' => (int) $p->id,
                    'photo_type' => $p->photo_type ?? 'before',
                    'file_name' => $p->file_name,
                    'file_path' => $filePath,
                    'description' => $p->description,
                    'uploaded_by' => $p->uploaded_by,
                    'created_at' => $p->created_at,
                ];
            });

        // Fetch linked material requests
        $materialRequests = DB::table('inv_request_headers as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.requested_by')
            ->select('h.*', 'u.name as requested_username')
            ->where(function($q) use ($id) {
                $q->where('h.context_work_order_id', $id)
                  ->orWhere(function($sub) use ($id) {
                      $sub->where('h.source_table', 're_maintenance_requests')
                          ->where('h.source_id', $id);
                  });
            })
            ->orderBy('h.id', 'desc')
            ->get()
            ->map(function($m) {
                $lines = DB::table('inv_request_lines as l')
                    ->leftJoin('inv_items as i', 'i.id', '=', 'l.item_id')
                    ->select('l.*', 'i.name as item_name', 'i.item_code')
                    ->where('l.header_id', $m->id)
                    ->get()
                    ->map(function($ln) {
                        return [
                            'id' => (int) $ln->id,
                            'item_id' => (int) $ln->item_id,
                            'item_name' => $ln->item_name ?? 'Item #' . $ln->item_id,
                            'item_code' => $ln->item_code ?? '',
                            'requested_qty' => (float) $ln->requested_qty,
                            'approved_qty' => (float) ($ln->approved_qty ?? 0.0),
                            'line_notes' => $ln->line_notes,
                        ];
                    });

                return [
                    'id' => (int) $m->id,
                    'request_no' => $m->request_no ?? 'MR-' . $m->id,
                    'request_date' => $m->request_date,
                    'status' => $m->status ?? 'pending',
                    'notes' => $m->notes,
                    'requested_by' => $m->requested_username ?? 'Technician',
                    'lines' => $lines,
                    'created_at' => $m->created_at,
                ];
            });

        $locationStr = trim(($r->building_name ?? '') . ($r->unit_number ? ' — Unit ' . $r->unit_number : ''));

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $r->id,
                'company_id' => (int) $r->company_id,
                'request_date' => $r->request_date,
                'category' => $r->category ?? 'General Maintenance',
                'priority' => $r->priority ?? 'normal',
                'status' => $r->status ?? 'pending',
                'cost' => (float) ($r->cost ?? 0.0),
                'description' => $r->description,
                'notes' => $r->notes,
                'source' => $r->source ?? 'internal',
                'building_id' => $r->building_id,
                'unit_id' => $r->unit_id,
                'location' => [
                    'building_name' => $r->building_name,
                    'unit_number' => $r->unit_number,
                    'display_location' => $locationStr ?: 'General Location',
                ],
                'tenant' => [
                    'name' => $r->tenant_name,
                    'phone' => $r->tenant_phone,
                    'email' => $r->tenant_email,
                ],
                'photos' => $photos,
                'material_requests' => $materialRequests,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ],
        ]);
    }

    /**
     * Update request status and notes.
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,assigned,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
            'cost' => 'nullable|numeric',
        ]);

        $r = DB::table('re_maintenance_requests')->where('id', $id)->first();
        if (!$r) {
            return response()->json(['success' => false, 'message' => 'Maintenance request not found.'], 404);
        }

        $updateData = [
            'status' => $request->status,
            'updated_at' => now(),
        ];

        if ($request->has('notes') && !empty($request->notes)) {
            $oldNotes = $r->notes ? $r->notes . "\n" : '';
            $updateData['notes'] = $oldNotes . '[' . now()->toDateTimeString() . '] ' . $request->notes;
        }

        if ($request->has('cost')) {
            $updateData['cost'] = (float) $request->cost;
        }

        DB::table('re_maintenance_requests')->where('id', $id)->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Maintenance request updated successfully.',
        ]);
    }

    /**
     * Upload before / after photo for maintenance request.
     */
    public function uploadPhoto(Request $request, $id)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240',
            'photo_type' => 'nullable|string|in:before,during,after,completion',
            'description' => 'nullable|string',
        ]);

        $r = DB::table('re_maintenance_requests')->where('id', $id)->first();
        if (!$r) {
            return response()->json(['success' => false, 'message' => 'Maintenance request not found.'], 404);
        }

        $existingPhotoCount = DB::table('re_maintenance_photos')->where('maintenance_request_id', $id)->count();
        if ($existingPhotoCount >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum 5 photos allowed per maintenance request.'
            ], 422);
        }

        $file = $request->file('photo');
        $fileName = 'photo_' . ($request->photo_type ?? 'before') . '_' . time() . '_' . rand(1000, 9999) . '.' . $file->getClientOriginalExtension();
        
        $sysDir = '/Applications/XAMPP/xamppfiles/htdocs/sys/uploads/realestate/maintenance/' . $id;
        $publicDir = public_path('uploads/realestate/maintenance/' . $id);
        
        if (!file_exists($sysDir)) {
            @mkdir($sysDir, 0777, true);
        }
        if (!file_exists($publicDir)) {
            @mkdir($publicDir, 0777, true);
        }

        $file->move($sysDir, $fileName);
        @copy($sysDir . '/' . $fileName, $publicDir . '/' . $fileName);

        $relativePath = 'uploads/realestate/maintenance/' . $id . '/' . $fileName;

        $photoId = DB::table('re_maintenance_photos')->insertGetId([
            'company_id' => $r->company_id ?? 1,
            'maintenance_request_id' => $id,
            'photo_type' => $request->photo_type ?? 'before',
            'file_name' => $fileName,
            'file_path' => $relativePath,
            'file_size' => filesize($sysDir . '/' . $fileName),
            'mime_type' => $file->getClientMimeType(),
            'description' => $request->description,
            'uploaded_by' => $request->user()->id ?? 1,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Photo uploaded successfully.',
            'data' => [
                'id' => $photoId,
                'file_path' => url('/sys/' . $relativePath),
                'photo_type' => $request->photo_type ?? 'before',
            ],
        ]);
    }
}
