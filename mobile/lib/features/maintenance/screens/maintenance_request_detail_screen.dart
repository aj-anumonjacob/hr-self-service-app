import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/config/theme.dart';
import '../../../core/widgets/portal_bottom_nav_bar.dart';
import '../../../core/widgets/portal_header.dart';
import '../models/maintenance_request_model.dart';
import '../providers/maintenance_request_provider.dart';
import '../../../core/widgets/photo_lightbox_dialog.dart';

class MaintenanceRequestDetailScreen extends ConsumerStatefulWidget {
  final int requestId;

  const MaintenanceRequestDetailScreen({
    super.key,
    required this.requestId,
  });

  @override
  ConsumerState<MaintenanceRequestDetailScreen> createState() => _MaintenanceRequestDetailScreenState();
}

class _MaintenanceRequestDetailScreenState extends ConsumerState<MaintenanceRequestDetailScreen> {
  final ImagePicker _picker = ImagePicker();
  bool _isUpdating = false;

  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'completed':
        return Colors.green.shade700;
      case 'in_progress':
        return Colors.blue.shade700;
      case 'assigned':
        return Colors.amber.shade800;
      case 'cancelled':
        return Colors.red.shade700;
      default:
        return Colors.orange.shade800;
    }
  }

  Color _getPriorityColor(String priority) {
    switch (priority.toLowerCase()) {
      case 'high':
      case 'urgent':
        return Colors.red.shade600;
      case 'medium':
        return Colors.orange.shade700;
      default:
        return Colors.blue.shade600;
    }
  }

  Future<void> _pickAndUploadPhoto(String photoType) async {
    final detailAsync = ref.read(maintenanceRequestDetailProvider(widget.requestId));
    final currentPhotosCount = detailAsync.valueOrNull?.photos.length ?? 0;

    if (currentPhotosCount >= 5) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Maximum 5 photos allowed per maintenance request.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Upload ${photoType.toUpperCase()} Photo',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 4),
            const Text(
              'Max 5 photos per request · Max 10MB per photo (JPEG/PNG)',
              style: TextStyle(fontSize: 11, color: Colors.grey),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            ListTile(
              leading: const Icon(Icons.camera_alt_rounded, color: AppTheme.primaryTeal),
              title: const Text('Take Photo with Camera'),
              onTap: () => Navigator.pop(ctx, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_rounded, color: AppTheme.primaryTeal),
              title: const Text('Choose from Gallery'),
              onTap: () => Navigator.pop(ctx, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );

    if (source == null) return;

    final XFile? image = await _picker.pickImage(
      source: source,
      imageQuality: 85,
      maxWidth: 1600,
    );

    if (image == null) return;

    final file = File(image.path);
    if (file.lengthSync() > 10 * 1024 * 1024) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Selected photo exceeds maximum 10MB file size limit.'),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
      return;
    }

    setState(() => _isUpdating = true);
    try {
      final repo = ref.read(maintenanceRequestRepositoryProvider);
      final success = await repo.uploadPhoto(
        id: widget.requestId,
        photoFile: file,
        photoType: photoType,
      );

      if (mounted) {
        if (success) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Photo uploaded successfully!'), backgroundColor: Colors.green),
          );
          ref.invalidate(maintenanceRequestDetailProvider(widget.requestId));
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Failed to upload photo.'), backgroundColor: Colors.red),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Upload error: $e'), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _isUpdating = false);
    }
  }

  void _showUpdateStatusModal(MaintenanceRequestModel request) {
    String selectedStatus = request.status;
    final notesController = TextEditingController();
    final costController = TextEditingController(text: request.cost > 0 ? request.cost.toStringAsFixed(2) : '');

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
                      Text(
                        'Update Request #${request.id}',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                      IconButton(
                        onPressed: () => Navigator.pop(ctx),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  const Text('Select New Status', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: selectedStatus,
                    decoration: InputDecoration(
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    ),
                    items: const [
                      DropdownMenuItem(value: 'pending', child: Text('Pending')),
                      DropdownMenuItem(value: 'assigned', child: Text('Assigned')),
                      DropdownMenuItem(value: 'in_progress', child: Text('In Progress')),
                      DropdownMenuItem(value: 'completed', child: Text('Completed')),
                      DropdownMenuItem(value: 'cancelled', child: Text('Cancelled')),
                    ],
                    onChanged: (val) {
                      if (val != null) setModalState(() => selectedStatus = val);
                    },
                  ),
                  const SizedBox(height: 14),
                  const Text('Cost (AED)', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 6),
                  TextField(
                    controller: costController,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(
                      hintText: 'e.g. 100.00',
                      prefixText: 'AED ',
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    ),
                  ),
                  const SizedBox(height: 14),
                  const Text('Update Notes', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 6),
                  TextField(
                    controller: notesController,
                    maxLines: 3,
                    maxLength: 250,
                    decoration: InputDecoration(
                      hintText: 'Enter status change notes or work remarks...',
                      helperText: 'Max 250 characters',
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.all(12),
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
                      onPressed: () async {
                        final costVal = double.tryParse(costController.text.trim());
                        Navigator.pop(ctx);
                        setState(() => _isUpdating = true);
                        try {
                          final repo = ref.read(maintenanceRequestRepositoryProvider);
                          final ok = await repo.updateRequestStatus(
                            id: request.id,
                            status: selectedStatus,
                            notes: notesController.text.trim(),
                            cost: costVal,
                          );
                          if (mounted) {
                            if (ok) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(content: Text('Status updated successfully!'), backgroundColor: Colors.green),
                              );
                              ref.invalidate(maintenanceRequestDetailProvider(widget.requestId));
                            }
                          }
                        } catch (e) {
                          if (mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(content: Text('Update error: $e'), backgroundColor: Colors.red),
                            );
                          }
                        } finally {
                          if (mounted) setState(() => _isUpdating = false);
                        }
                      },
                      icon: const Icon(Icons.check_circle_rounded, color: Colors.white),
                      label: const Text('Save Status', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
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

  @override
  Widget build(BuildContext context) {
    final requestAsync = ref.watch(maintenanceRequestDetailProvider(widget.requestId));

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: PortalHeader(title: 'Request #${widget.requestId}'),
      bottomNavigationBar: const PortalBottomNavBar(currentTab: NavTab.requests),
      body: Stack(
        children: [
          requestAsync.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (err, stack) => Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.error_outline_rounded, size: 48, color: Colors.red),
                    const SizedBox(height: 12),
                    Text('Failed to load request #${widget.requestId}', style: const TextStyle(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 16),
                    ElevatedButton(
                      onPressed: () => ref.invalidate(maintenanceRequestDetailProvider(widget.requestId)),
                      child: const Text('Retry'),
                    ),
                  ],
                ),
              ),
            ),
            data: (req) => SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Title Bar & Action Buttons
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(
                        child: Text(
                          'Maintenance Request #${req.id}',
                          style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF1E293B),
                          ),
                        ),
                      ),
                      if (req.source == 'ars')
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: Colors.black87,
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: const Text(
                            'ARS',
                            style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 14),

                  // Top Action Buttons Row (Single Line & Matching Design Style)
                  Row(
                    children: [
                      Expanded(
                        child: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppTheme.champagneGold,
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            elevation: 1,
                          ),
                          onPressed: () => context.push('/maintenance/requests/${req.id}/material-request'),
                          icon: const Icon(Icons.inventory_2_rounded, size: 18),
                          label: const Text(
                            'Request Materials',
                            style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.blue.shade600,
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            elevation: 1,
                          ),
                          onPressed: () => _showUpdateStatusModal(req),
                          icon: const Icon(Icons.edit_note_rounded, size: 18),
                          label: const Text(
                            'Update Status',
                            style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),

                  // 1. Material Requests Card
                  _buildSectionCard(
                    title: '📦 Material requests for this work order',
                    trailing: TextButton.icon(
                      onPressed: () => context.push('/maintenance/requests/${req.id}/material-request'),
                      icon: const Icon(Icons.add_rounded, size: 18),
                      label: const Text('New Request', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                    ),
                    child: req.materialRequests.isEmpty
                        ? Padding(
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            child: Center(
                              child: Text(
                                'No material requests yet linked to this record.',
                                style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                              ),
                            ),
                          )
                        : ListView.separated(
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            itemCount: req.materialRequests.length,
                            separatorBuilder: (ctx, i) => const Divider(height: 1),
                            itemBuilder: (ctx, i) {
                              final mat = req.materialRequests[i];
                              return ListTile(
                                contentPadding: EdgeInsets.zero,
                                title: Row(
                                  children: [
                                    Text(mat.requestNo, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                                    const SizedBox(width: 8),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: Colors.blue.shade50,
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      child: Text(
                                        mat.status.toUpperCase(),
                                        style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.blue.shade800),
                                      ),
                                    ),
                                  ],
                                ),
                                subtitle: Text(
                                  'Items: ${mat.lines.map((l) => "${l.itemName} (${l.requestedQty.toStringAsFixed(0)})").join(", ")}\nRequested by: ${mat.requestedBy} · ${mat.requestDate ?? ""}',
                                  style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                                ),
                              );
                            },
                          ),
                  ),
                  const SizedBox(height: 16),

                  // 2. Request Details Card
                  _buildSectionCard(
                    title: '📋 Request Details',
                    child: Column(
                      children: [
                        _buildDetailRow('Request Date', req.requestDate ?? 'N/A'),
                        _buildDetailRow('Location', req.displayLocation),
                        _buildDetailRow('Category', req.category),
                        _buildDetailRow(
                          'Priority',
                          req.priority.toUpperCase(),
                          valueColor: _getPriorityColor(req.priority),
                        ),
                        _buildDetailRow(
                          'Status',
                          req.status.replaceAll('_', ' ').toUpperCase(),
                          valueColor: _getStatusColor(req.status),
                        ),
                        _buildDetailRow('Cost', '${req.cost.toStringAsFixed(2)} AED'),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),

                  // 3. Tenant Information Card
                  _buildSectionCard(
                    title: '👤 Tenant Information',
                    child: Column(
                      children: [
                        _buildDetailRow('Name', req.tenantName),
                        _buildDetailRow('Phone', req.tenantPhone.isNotEmpty ? req.tenantPhone : 'N/A'),
                        _buildDetailRow('Email', req.tenantEmail.isNotEmpty ? req.tenantEmail : 'N/A'),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),

                  // 4. Description & Notes
                  if (req.description != null && req.description!.isNotEmpty)
                    _buildSectionCard(
                      title: '📝 Description',
                      child: Text(req.description!, style: TextStyle(fontSize: 14, color: Colors.grey.shade800)),
                    ),
                  if (req.description != null && req.description!.isNotEmpty) const SizedBox(height: 16),

                  if (req.notes != null && req.notes!.isNotEmpty)
                    _buildSectionCard(
                      title: '📌 Notes',
                      child: Text(req.notes!, style: TextStyle(fontSize: 14, color: Colors.grey.shade800)),
                    ),
                  if (req.notes != null && req.notes!.isNotEmpty) const SizedBox(height: 16),

                  // 5. Request Photos (Before Work)
                  _buildSectionCard(
                    title: '📷 Request Photos (Before Work)',
                    trailing: TextButton.icon(
                      onPressed: () => _pickAndUploadPhoto('before'),
                      icon: const Icon(Icons.add_a_photo_rounded, size: 18),
                      label: const Text('Add Photo', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                    ),
                    child: _buildPhotoGallery(req.photos.where((p) => p.photoType == 'before' || p.photoType == 'request').toList()),
                  ),
                  const SizedBox(height: 16),

                  // 6. Completion Photos (After Work)
                  _buildSectionCard(
                    title: '📷 Completion Photos (After Work)',
                    trailing: TextButton.icon(
                      onPressed: () => _pickAndUploadPhoto('completion'),
                      icon: const Icon(Icons.add_a_photo_rounded, size: 18),
                      label: const Text('Add Photo', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                      style: TextButton.styleFrom(
                        foregroundColor: Colors.blue.shade700,
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      ),
                    ),
                    child: _buildPhotoGallery(req.photos.where((p) => p.photoType == 'completion' || p.photoType == 'after').toList()),
                  ),
                  const SizedBox(height: 24),
                ],
              ),
            ),
          ),

          if (_isUpdating)
            Container(
              color: Colors.black38,
              child: const Center(child: CircularProgressIndicator()),
            ),
        ],
      ),
    );
  }

  Widget _buildSectionCard({required String title, Widget? trailing, required Widget child}) {
    return Card(
      elevation: 2,
      shadowColor: Colors.black.withValues(alpha: 0.06),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: Colors.grey.shade200)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                  ),
                ),
                if (trailing != null) ...[
                  const SizedBox(width: 8),
                  trailing,
                ],
              ],
            ),
            const SizedBox(height: 12),
            const Divider(height: 1),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }

  Widget _buildDetailRow(String label, String value, {Color? valueColor}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.right,
              overflow: TextOverflow.ellipsis,
              maxLines: 2,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
                color: valueColor ?? const Color(0xFF1E293B),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPhotoGallery(List<MaintenanceRequestPhoto> photos) {
    if (photos.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 12),
        child: Center(
          child: Text('No photos uploaded yet.', style: TextStyle(color: Colors.grey.shade500, fontSize: 13)),
        ),
      );
    }

    return SizedBox(
      height: 140,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        itemCount: photos.length,
        itemBuilder: (ctx, idx) {
          final photo = photos[idx];
          return GestureDetector(
            onTap: () => PhotoLightboxDialog.show(context, photoUrl: photo.filePath, title: photo.fileName),
            child: Container(
              width: 140,
              margin: const EdgeInsets.only(right: 12),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.grey.shade300),
              ),
              clipBehavior: Clip.antiAlias,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  Image.network(
                    photo.filePath,
                    fit: BoxFit.cover,
                    errorBuilder: (ctx, err, stack) => Container(
                      color: Colors.grey.shade200,
                      child: const Icon(Icons.broken_image_rounded, color: Colors.grey),
                    ),
                  ),
                  Positioned(
                    bottom: 0,
                    left: 0,
                    right: 0,
                    child: Container(
                      padding: const EdgeInsets.all(4),
                      color: Colors.black54,
                      child: Row(
                        children: [
                          const Icon(Icons.zoom_in_rounded, color: Colors.white, size: 12),
                          const SizedBox(width: 4),
                          Expanded(
                            child: Text(
                              photo.fileName,
                              style: const TextStyle(color: Colors.white, fontSize: 10),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
