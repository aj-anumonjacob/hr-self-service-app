import 'package:flutter/material.dart';

class AppTheme {
  // Deep Teal & Champagne Gold Palette
  static const deepTeal = Color(0xFF073B4C);
  static const tealBlue = Color(0xFF0B5965);
  static const primaryTeal = Color(0xFF08737A);
  static const actionTeal = Color(0xFF0F8790);
  static const lightAqua = Color(0xFFE5F5F4);
  static const softAqua = Color(0xFFDFF3F1);
  static const pureWhite = Color(0xFFFFFFFF);
  static const champagneGold = Color(0xFFC6A15B);
  static const darkText = Color(0xFF183136);
  static const mutedText = Color(0xFF647C7E);
  static const softBorder = Color(0xFFCFE2E0);
  static const errorBackground = Color(0xFFFEE9E7);
  static const errorText = Color(0xFF8A342F);

  // Legacy compatibility mappings
  static const darkGold = primaryTeal;
  static const lightGold = champagneGold;
  static const charcoal = darkText;
  static const darkGray = mutedText;
  static const midnightNavy = deepTeal;

  static ThemeData get lightTheme {
    return ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: lightAqua,
      colorScheme: const ColorScheme.light(
        primary: actionTeal,
        secondary: champagneGold,
        surface: pureWhite,
        onPrimary: pureWhite,
        onSurface: darkText,
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: deepTeal,
        foregroundColor: pureWhite,
        elevation: 0,
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: actionTeal,
          foregroundColor: pureWhite,
          padding: const EdgeInsets.symmetric(vertical: 16),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
    );
  }
}
