import 'package:flutter/material.dart';
import 'dart:io';

class PhotoLightboxDialog extends StatefulWidget {
  final String photoUrl;
  final String title;

  const PhotoLightboxDialog({
    super.key,
    required this.photoUrl,
    this.title = 'Photo Preview',
  });

  static void show(BuildContext context, {required String photoUrl, String title = 'Photo Preview'}) {
    showDialog(
      context: context,
      barrierColor: Colors.black87,
      builder: (ctx) => PhotoLightboxDialog(photoUrl: photoUrl, title: title),
    );
  }

  @override
  State<PhotoLightboxDialog> createState() => _PhotoLightboxDialogState();
}

class _PhotoLightboxDialogState extends State<PhotoLightboxDialog> {
  final TransformationController _transformationController = TransformationController();
  bool _isSaving = false;

  @override
  void dispose() {
    _transformationController.dispose();
    super.dispose();
  }

  Future<void> _savePhotoToDevice() async {
    setState(() => _isSaving = true);
    try {
      final client = HttpClient();
      final req = await client.getUrl(Uri.parse(widget.photoUrl));
      final res = await req.close();
      if (res.statusCode == 200) {
        final bytes = await res.fold<List<int>>(<int>[], (previous, element) => previous..addAll(element));
        final dir = Directory.systemTemp;
        final fileName = 'saved_photo_${DateTime.now().millisecondsSinceEpoch}.jpg';
        final file = File('${dir.path}/$fileName');
        await file.writeAsBytes(bytes);

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Photo saved successfully: $fileName'),
              backgroundColor: Colors.green,
              action: SnackBarAction(
                label: 'OK',
                textColor: Colors.white,
                onPressed: () {},
              ),
            ),
          );
        }
      } else {
        throw Exception('Failed to download image (HTTP ${res.statusCode})');
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Could not save photo: $e'),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog.fullscreen(
      backgroundColor: Colors.black,
      child: SafeArea(
        child: Stack(
          children: [
            // Interactive Pinch & Zoom Image Viewer
            Center(
              child: InteractiveViewer(
                transformationController: _transformationController,
                minScale: 0.8,
                maxScale: 4.0,
                child: Image.network(
                  widget.photoUrl,
                  fit: BoxFit.contain,
                  loadingBuilder: (ctx, child, progress) {
                    if (progress == null) return child;
                    return Center(
                      child: CircularProgressIndicator(
                        value: progress.expectedTotalBytes != null
                            ? progress.cumulativeBytesLoaded / (progress.expectedTotalBytes!)
                            : null,
                        color: Colors.white,
                      ),
                    );
                  },
                  errorBuilder: (ctx, err, stack) => const Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.broken_image_rounded, size: 64, color: Colors.white54),
                      SizedBox(height: 12),
                      Text('Unable to load full-resolution photo.', style: TextStyle(color: Colors.white70)),
                    ],
                  ),
                ),
              ),
            ),

            // Top Header Overlay Bar
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                color: Colors.black.withValues(alpha: 0.65),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        widget.title,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Row(
                      children: [
                        IconButton(
                          icon: _isSaving
                              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Icon(Icons.download_rounded, color: Colors.white),
                          tooltip: 'Save Photo',
                          onPressed: _isSaving ? null : _savePhotoToDevice,
                        ),
                        IconButton(
                          icon: const Icon(Icons.close_rounded, color: Colors.white),
                          tooltip: 'Close',
                          onPressed: () => Navigator.of(context).pop(),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
