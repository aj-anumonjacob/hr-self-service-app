import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../config/theme.dart';
import 'notification_bell.dart';
import 'connectivity_badge.dart';

class PortalHeader extends StatelessWidget implements PreferredSizeWidget {
  final String title;
  final Widget? actionButton;
  final PreferredSizeWidget? bottom;
  final bool showBackButton;

  const PortalHeader({
    super.key,
    required this.title,
    this.actionButton,
    this.bottom,
    this.showBackButton = true,
  });

  @override
  Size get preferredSize => Size.fromHeight(
        kToolbarHeight + (bottom?.preferredSize.height ?? 0) + 12,
      );

  @override
  Widget build(BuildContext context) {
    final topPadding = MediaQuery.of(context).padding.top;

    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [AppTheme.tealBlue, AppTheme.deepTeal],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
      ),
      child: Stack(
        children: [
          // Subtle Radial Aqua Highlight in Upper Right
          Positioned(
            top: -40,
            right: -30,
            width: 220,
            height: 220,
            child: Container(
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  center: Alignment.center,
                  radius: 0.85,
                  colors: [
                    Color(0x280F8790),
                    Colors.transparent,
                  ],
                ),
              ),
            ),
          ),

          Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(height: topPadding + 6),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 14.0),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Row(
                        children: [
                          if (showBackButton)
                            IconButton(
                              icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white, size: 18),
                              onPressed: () {
                                if (context.canPop()) {
                                  context.pop();
                                } else {
                                  context.go('/dashboard');
                                }
                              },
                            ),
                          Flexible(
                            child: Text(
                              title,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 17.5,
                                fontWeight: FontWeight.bold,
                                letterSpacing: 0.3,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Row(
                      children: [
                        Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Center(child: ConnectivityBadge()),
                        ),
                        const SizedBox(width: 6),
                        Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Center(child: NotificationBell()),
                        ),
                        if (actionButton != null) ...[
                          const SizedBox(width: 8),
                          actionButton!,
                        ],
                      ],
                    ),
                  ],
                ),
              ),
              if (bottom != null) bottom!,
              const SizedBox(height: 6),
            ],
          ),
        ],
      ),
    );
  }
}
