<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryMaterialRequestController extends Controller
{
    /**
     * Get active inventory catalog items for mobile selection.
     */
    public function catalog(Request $request)
    {
        $query = DB::table('inv_items as i')
            ->leftJoin('inv_item_categories as c', 'c.id', '=', 'i.category_id')
            ->select('i.id', 'i.item_code', 'i.name', 'i.sale_price', 'i.default_purchase_price', 'c.name as category_name')
            ->where('i.is_active', 1);

        if ($request->has('search') && !empty($request->search)) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function($q) use ($search) {
                $q->where('i.name', 'like', $search)
                  ->orWhere('i.item_code', 'like', $search);
            });
        }

        $items = $query->orderBy('i.name', 'asc')->limit(150)->get()->map(function($item) {
            return [
                'id' => (int) $item->id,
                'item_code' => $item->item_code ?? 'ITEM-' . $item->id,
                'name' => $item->name,
                'category_name' => $item->category_name ?? 'General Inventory',
                'unit_price' => (float) ($item->sale_price ?? $item->default_purchase_price ?? 0.0),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Submit an inventory material request for a maintenance request / work order.
     */
    public function store(Request $request)
    {
        $request->validate([
            'work_order_id' => 'required|integer',
            'building_id' => 'nullable|integer',
            'unit_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.requested_qty' => 'required|numeric|min:0.01',
            'items.*.line_notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $woId = $request->work_order_id;

        $wo = DB::table('re_maintenance_requests')->where('id', $woId)->first();
        $buildingId = $request->building_id ?? ($wo->building_id ?? null);
        $unitId = $request->unit_id ?? ($wo->unit_id ?? null);
        $companyId = $wo->company_id ?? 1;

        // Generate request number
        $lastId = DB::table('inv_request_headers')->max('id') ?? 0;
        $reqNo = 'MR-' . date('Ymd') . '-' . str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);

        DB::beginTransaction();
        try {
            $headerId = DB::table('inv_request_headers')->insertGetId([
                'company_id' => $companyId,
                'request_no' => $reqNo,
                'request_date' => date('Y-m-d'),
                'request_type' => 'material',
                'source_module' => 'realestate',
                'source_table' => 're_maintenance_requests',
                'source_id' => $woId,
                'status' => 'pending',
                'requested_by' => $user->id ?? 1,
                'notes' => $request->notes ?? ('Maintenance MR #' . $woId),
                'context_building_id' => $buildingId,
                'context_unit_id' => $unitId,
                'context_work_order_id' => $woId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sortOrder = 1;
            foreach ($request->items as $item) {
                $invItem = DB::table('inv_items')->where('id', $item['item_id'])->first();
                $uomId = $invItem->base_uom_id ?? 1;

                DB::table('inv_request_lines')->insert([
                    'header_id' => $headerId,
                    'company_id' => $companyId,
                    'item_id' => $item['item_id'],
                    'uom_id' => $uomId,
                    'requested_qty' => $item['requested_qty'],
                    'approved_qty' => 0,
                    'sort_order' => $sortOrder++,
                    'line_notes' => $item['line_notes'] ?? null,
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Material request submitted successfully.',
                'data' => [
                    'id' => $headerId,
                    'request_no' => $reqNo,
                    'status' => 'pending',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit material request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get material requests linked to a work order / maintenance request.
     */
    public function indexForWorkOrder(Request $request, $woId)
    {
        $requests = DB::table('inv_request_headers as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.requested_by')
            ->select('h.*', 'u.name as requested_username')
            ->where('h.context_work_order_id', $woId)
            ->orWhere(function($q) use ($woId) {
                $q->where('h.source_table', 're_maintenance_requests')
                  ->where('h.source_id', $woId);
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

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }
}
