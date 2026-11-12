import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme.dart';
import '../../../core/widgets/portal_header.dart';
import '../models/material_request_model.dart';
import '../providers/maintenance_request_provider.dart';

class RequestMaterialFormScreen extends ConsumerStatefulWidget {
  final int requestId;

  const RequestMaterialFormScreen({
    super.key,
    required this.requestId,
  });

  @override
  ConsumerState<RequestMaterialFormScreen> createState() => _RequestMaterialFormScreenState();
}

class _RequestMaterialFormScreenState extends ConsumerState<RequestMaterialFormScreen> {
  final TextEditingController _notesController = TextEditingController();
  final TextEditingController _searchController = TextEditingController();
  String _searchQuery = '';

  final List<Map<String, dynamic>> _selectedItems = [];
  bool _isSubmitting = false;

  @override
  void dispose() {
    _notesController.dispose();
    _searchController.dispose();
    super.dispose();
  }

  void _showItemPickerSheet(BuildContext parentContext, List<InventoryItem> catalog, Function(InventoryItem) onSelect) {
    final searchCtrl = TextEditingController();
    List<InventoryItem> filtered = List.from(catalog);

    showModalBottomSheet(
      context: parentContext,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setSheetState) {
            return Container(
              height: MediaQuery.of(context).size.height * 0.75,
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              child: Column(
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('Select Catalog Material', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      IconButton(
                        onPressed: () => Navigator.pop(ctx),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: searchCtrl,
                    autofocus: true,
                    decoration: InputDecoration(
                      hintText: 'Search by item code, name or category...',
                      prefixIcon: const Icon(Icons.search_rounded),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    ),
                    onChanged: (val) {
                      final query = val.toLowerCase().trim();
                      setSheetState(() {
                        filtered = catalog.where((item) {
                          return item.name.toLowerCase().contains(query) ||
                              item.itemCode.toLowerCase().contains(query) ||
                              item.categoryName.toLowerCase().contains(query);
                        }).toList();
                      });
                    },
                  ),
                  const SizedBox(height: 12),
                  Expanded(
                    child: filtered.isEmpty
                        ? const Center(child: Text('No items match search.', style: TextStyle(color: Colors.grey)))
                        : ListView.separated(
                            itemCount: filtered.length,
                            separatorBuilder: (c, i) => const Divider(height: 1),
                            itemBuilder: (c, idx) {
                              final item = filtered[idx];
                              return InkWell(
                                onTap: () {
                                  onSelect(item);
                                  Navigator.pop(ctx);
                                },
                                borderRadius: BorderRadius.circular(10),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
                                  child: Row(
                                    children: [
                                      Container(
                                        padding: const EdgeInsets.all(8),
                                        decoration: BoxDecoration(
                                          color: AppTheme.champagneGold.withValues(alpha: 0.1),
                                          borderRadius: BorderRadius.circular(8),
                                        ),
                                        child: const Icon(Icons.inventory_2_outlined, color: AppTheme.champagneGold, size: 20),
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item.name,
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                            ),
                                            const SizedBox(height: 2),
                                            Text(
                                              'Code: ${item.itemCode} · ${item.categoryName}',
                                              style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                                            ),
                                          ],
                                        ),
                                      ),
                                      const Icon(Icons.chevron_right_rounded, color: Colors.grey),
                                    ],
                                  ),
                                ),
                              );
                            },
                          ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  void _showAddItemModal(List<InventoryItem> catalog) {
    InventoryItem? selectedItem;
    final qtyController = TextEditingController(text: '1');
    final lineNotesController = TextEditingController();

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return Padding(
              padding: EdgeInsets.only(
                left: 20,
                right: 20,
                top: 24,
                bottom: MediaQuery.of(context).viewInsets.bottom + 24,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Add Material Item',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                      IconButton(
                        onPressed: () => Navigator.pop(ctx),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),

                  // Searchable Item Picker Trigger
                  const Text('Item Name', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 6),
                  InkWell(
                    onTap: () {
                      _showItemPickerSheet(ctx, catalog, (item) {
                        setModalState(() => selectedItem = item);
                      });
                    },
                    borderRadius: BorderRadius.circular(12),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                      decoration: BoxDecoration(
                        border: Border.all(color: Colors.grey.shade400),
                        borderRadius: BorderRadius.circular(12),
                        color: selectedItem != null ? Colors.amber.shade50.withValues(alpha: 0.3) : Colors.white,
                      ),
                      child: Row(
                        children: [
                          Icon(
                            selectedItem != null ? Icons.check_circle_rounded : Icons.search_rounded,
                            color: selectedItem != null ? AppTheme.champagneGold : Colors.grey,
                            size: 20,
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              selectedItem != null
                                  ? '[${selectedItem!.itemCode}] ${selectedItem!.name}'
                                  : 'Tap to search & select catalog item...',
                              style: TextStyle(
                                fontSize: 14,
                                fontWeight: selectedItem != null ? FontWeight.bold : FontWeight.normal,
                                color: selectedItem != null ? const Color(0xFF1E293B) : Colors.grey.shade600,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          const Icon(Icons.arrow_drop_down_rounded, color: Colors.grey),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),

                  // Requested Quantity
                  const Text('Requested Quantity', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      IconButton(
                        onPressed: () {
                          final current = double.tryParse(qtyController.text) ?? 1.0;
                          if (current > 1) {
                            qtyController.text = (current - 1).toStringAsFixed(0);
                            setModalState(() {});
                          }
                        },
                        icon: const Icon(Icons.remove_circle_outline_rounded, color: AppTheme.champagneGold, size: 28),
                      ),
                      Expanded(
                        child: TextField(
                          controller: qtyController,
                          textAlign: TextAlign.center,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          decoration: InputDecoration(
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          ),
                        ),
                      ),
                      IconButton(
                        onPressed: () {
                          final current = double.tryParse(qtyController.text) ?? 1.0;
                          qtyController.text = (current + 1).toStringAsFixed(0);
                          setModalState(() {});
                        },
                        icon: const Icon(Icons.add_circle_outline_rounded, color: AppTheme.champagneGold, size: 28),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),

                  // Line Notes
                  const Text('Line Notes (Optional)', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 6),
                  TextField(
                    controller: lineNotesController,
                    maxLength: 100,
                    decoration: InputDecoration(
                      hintText: 'e.g. For bathroom sink repair',
                      helperText: 'Max 100 characters',
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    ),
                  ),
                  const SizedBox(height: 20),

                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppTheme.champagneGold,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      onPressed: () {
                        if (selectedItem == null) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Please select an item from catalog.')),
                          );
                          return;
                        }

                        final qty = double.tryParse(qtyController.text.trim()) ?? 1.0;
                        if (qty <= 0) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Quantity must be greater than 0.')),
                          );
                          return;
                        }

                        setState(() {
                          _selectedItems.add({
                            'item_id': selectedItem!.id,
                            'item_code': selectedItem!.itemCode,
                            'item_name': selectedItem!.name,
                            'category_name': selectedItem!.categoryName,
                            'requested_qty': qty,
                            'line_notes': lineNotesController.text.trim(),
                          });
                        });

                        Navigator.pop(ctx);
                      },
                      icon: const Icon(Icons.add_shopping_cart_rounded, color: Colors.white),
                      label: const Text('Add to Request', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  Future<void> _submitRequest() async {
    if (_selectedItems.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please add at least one item to request.'), backgroundColor: Colors.orange),
      );
      return;
    }

    setState(() => _isSubmitting = true);
    try {
      final repo = ref.read(maintenanceRequestRepositoryProvider);
      final ok = await repo.submitMaterialRequest(
        workOrderId: widget.requestId,
        notes: _notesController.text.trim().isEmpty ? 'Maintenance MR #${widget.requestId}' : _notesController.text.trim(),
        items: _selectedItems,
      );

      if (mounted) {
        if (ok) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Material request submitted successfully!'), backgroundColor: Colors.green),
          );
          ref.invalidate(maintenanceRequestDetailProvider(widget.requestId));
          context.pop();
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Failed to submit material request.'), backgroundColor: Colors.red),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Submission error: $e'), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final catalogAsync = ref.watch(inventoryCatalogProvider(_searchQuery));

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: const PortalHeader(title: 'Request Materials'),
      body: Stack(
        children: [
          SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Header Banner
                Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.arrow_back_rounded),
                      onPressed: () => context.pop(),
                    ),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Request Inventory Materials',
                            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                          ),
                          Text(
                            'Maintenance Request #${widget.requestId}',
                            style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Card 1: Added Items List
                Card(
                  elevation: 2,
                  shadowColor: Colors.black.withValues(alpha: 0.06),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                    side: BorderSide(color: Colors.grey.shade200),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              'Requested Items',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                            ),
                            catalogAsync.when(
                              data: (catalog) => ElevatedButton.icon(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: AppTheme.champagneGold,
                                  foregroundColor: Colors.white,
                                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                ),
                                onPressed: () => _showAddItemModal(catalog),
                                icon: const Icon(Icons.add_rounded, size: 18),
                                label: const Text('Add Item', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                              ),
                              loading: () => const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
                              error: (e, s) => const Icon(Icons.error_outline_rounded, color: Colors.red),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        const Divider(height: 1),
                        const SizedBox(height: 12),

                        if (_selectedItems.isEmpty)
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 24),
                            child: Center(
                              child: Column(
                                children: [
                                  Icon(Icons.add_shopping_cart_rounded, size: 42, color: Colors.grey.shade400),
                                  const SizedBox(height: 8),
                                  Text(
                                    'No materials added to this request yet.',
                                    style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'Tap "+ Add Item" above to select catalog materials.',
                                    style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
                                  ),
                                ],
                              ),
                            ),
                          )
                        else
                          ListView.separated(
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            itemCount: _selectedItems.length,
                            separatorBuilder: (ctx, i) => const Divider(height: 1),
                            itemBuilder: (ctx, index) {
                              final item = _selectedItems[index];
                              final hasLineNote = item['line_notes'] != null && item['line_notes'].toString().isNotEmpty;
                              final qty = (item['requested_qty'] as num).toDouble();
                              return Padding(
                                padding: const EdgeInsets.symmetric(vertical: 8),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.center,
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.all(10),
                                      decoration: BoxDecoration(
                                        color: AppTheme.champagneGold.withValues(alpha: 0.12),
                                        borderRadius: BorderRadius.circular(10),
                                      ),
                                      child: const Icon(Icons.build_circle_rounded, color: AppTheme.champagneGold),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            item['item_name'],
                                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                          ),
                                          const SizedBox(height: 2),
                                          Text(
                                            'Code: ${item['item_code']} · Category: ${item['category_name']}${hasLineNote ? "\nNote: ${item['line_notes']}" : ""}',
                                            style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                                          ),
                                        ],
                                      ),
                                    ),

                                    // Stepper Widget (+ / -)
                                    Container(
                                      decoration: BoxDecoration(
                                        color: Colors.blue.shade50,
                                        borderRadius: BorderRadius.circular(10),
                                        border: Border.all(color: Colors.blue.shade100),
                                      ),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          InkWell(
                                            onTap: () {
                                              setState(() {
                                                if (qty > 1) {
                                                  _selectedItems[index]['requested_qty'] = qty - 1;
                                                } else {
                                                  _selectedItems.removeAt(index);
                                                }
                                              });
                                            },
                                            child: Padding(
                                              padding: const EdgeInsets.all(6),
                                              child: Icon(
                                                qty > 1 ? Icons.remove_rounded : Icons.delete_outline_rounded,
                                                size: 18,
                                                color: qty > 1 ? Colors.blue.shade800 : Colors.red,
                                              ),
                                            ),
                                          ),
                                          Padding(
                                            padding: const EdgeInsets.symmetric(horizontal: 6),
                                            child: Text(
                                              qty.toStringAsFixed(0),
                                              style: TextStyle(
                                                fontWeight: FontWeight.bold,
                                                fontSize: 14,
                                                color: Colors.blue.shade900,
                                              ),
                                            ),
                                          ),
                                          InkWell(
                                            onTap: () {
                                              setState(() {
                                                _selectedItems[index]['requested_qty'] = qty + 1;
                                              });
                                            },
                                            child: Padding(
                                              padding: const EdgeInsets.all(6),
                                              child: Icon(
                                                Icons.add_rounded,
                                                size: 18,
                                                color: Colors.blue.shade800,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                              );
                            },
                          ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 16),

                // Card 2: Request Notes
                Card(
                  elevation: 2,
                  shadowColor: Colors.black.withValues(alpha: 0.06),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                    side: BorderSide(color: Colors.grey.shade200),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Overall Request Notes', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
                        const SizedBox(height: 8),
                        TextField(
                          controller: _notesController,
                          maxLines: 3,
                          maxLength: 250,
                          decoration: InputDecoration(
                            hintText: 'Add notes for warehouse manager (e.g. Urgent request for Unit 102 repairs)...',
                            helperText: 'Max 250 characters',
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                            contentPadding: const EdgeInsets.all(12),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 24),

                // Submit Button
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppTheme.champagneGold,
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    ),
                    onPressed: _submitRequest,
                    icon: const Icon(Icons.send_rounded, color: Colors.white),
                    label: const Text(
                      'Submit Material Request',
                      style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),

          if (_isSubmitting)
            Container(
              color: Colors.black38,
              child: const Center(child: CircularProgressIndicator()),
            ),
        ],
      ),
    );
  }
}
