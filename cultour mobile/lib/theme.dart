import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Matches CulTour web (`assets/css/style.css` :root).
class CtColors {
  static const teal = Color(0xFF086375);
  static const tealDark = Color(0xFF043844);
  static const sea = Color(0xFF0E8A7A);
  static const gold = Color(0xFFD4A84B);
  static const coral = Color(0xFFC44536);
  static const foam = Color(0x1F0E8A7A);
  static const sand = Color(0xFF071618);
  static const bg = Color(0xFF0B1C22);
  static const ink = Color(0xFFE6F2F4);
  static const muted = Color(0xFFA8C0C8);
  static const surface = Color(0xFF12262C);
  static const card = Color(0xDB0C2024);
  static const line = Color(0x29D4A84B);
  static const pageTop = Color(0xFF061214);
  static const pageBottom = Color(0xFF050F11);
}

class CtDecor {
  static const page = BoxDecoration(
    gradient: LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [CtColors.pageTop, CtColors.sand, CtColors.pageBottom],
    ),
  );

  static BoxDecoration card({bool selected = false}) => BoxDecoration(
        color: CtColors.card,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: selected ? CtColors.sea : CtColors.line),
        boxShadow: const [
          BoxShadow(color: Color(0x73000000), blurRadius: 28, offset: Offset(0, 14)),
        ],
      );
}

ThemeData cultourTheme() {
  final display = GoogleFonts.fraunces(fontWeight: FontWeight.w700, color: CtColors.ink);
  final body = GoogleFonts.figtree(color: CtColors.ink);

  const scheme = ColorScheme.dark(
    primary: CtColors.sea,
    onPrimary: Colors.white,
    secondary: CtColors.gold,
    onSecondary: Color(0xFF1A1408),
    surface: CtColors.surface,
    onSurface: CtColors.ink,
    error: CtColors.coral,
    onError: Colors.white,
  );

  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: scheme,
    scaffoldBackgroundColor: Colors.transparent,
    canvasColor: CtColors.bg,
    textTheme: TextTheme(
      displayLarge: display.copyWith(fontSize: 36),
      headlineMedium: display.copyWith(fontSize: 26),
      headlineSmall: display.copyWith(fontSize: 22),
      titleLarge: display.copyWith(fontSize: 20),
      titleMedium: body.copyWith(fontSize: 16, fontWeight: FontWeight.w700),
      bodyLarge: body.copyWith(fontSize: 16, height: 1.4),
      bodyMedium: body.copyWith(fontSize: 14, height: 1.4),
      labelLarge: body.copyWith(fontWeight: FontWeight.w700, fontSize: 15),
    ),
    appBarTheme: AppBarTheme(
      backgroundColor: Colors.transparent,
      foregroundColor: CtColors.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: display.copyWith(fontSize: 22),
    ),
    cardTheme: CardThemeData(
      color: CtColors.card,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: const BorderSide(color: CtColors.line),
      ),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: CtColors.foam,
      side: const BorderSide(color: CtColors.line),
      labelStyle: body.copyWith(fontSize: 13, fontWeight: FontWeight.w600),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: const Color(0x0DFFFFFF),
      labelStyle: const TextStyle(color: CtColors.muted),
      hintStyle: const TextStyle(color: CtColors.muted),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: CtColors.line),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: CtColors.line),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: CtColors.sea, width: 1.4),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: CtColors.sea,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(50),
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: body.copyWith(fontWeight: FontWeight.w700, fontSize: 16),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: CtColors.ink,
        minimumSize: const Size.fromHeight(50),
        side: const BorderSide(color: CtColors.line),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(foregroundColor: const Color(0xFF7ED4C8)),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: const Color(0xF20B1C22),
      indicatorColor: CtColors.foam,
      elevation: 0,
      height: 70,
      labelTextStyle: WidgetStateProperty.resolveWith((states) {
        final selected = states.contains(WidgetState.selected);
        return body.copyWith(
          fontSize: 12,
          fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
          color: selected ? CtColors.gold : CtColors.muted,
        );
      }),
      iconTheme: WidgetStateProperty.resolveWith((states) {
        final selected = states.contains(WidgetState.selected);
        return IconThemeData(color: selected ? CtColors.gold : CtColors.muted);
      }),
    ),
    dividerColor: CtColors.line,
    dialogTheme: DialogThemeData(
      backgroundColor: CtColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: CtColors.line),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      backgroundColor: CtColors.surface,
      contentTextStyle: body.copyWith(color: CtColors.ink),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
    datePickerTheme: const DatePickerThemeData(
      backgroundColor: CtColors.surface,
      headerBackgroundColor: CtColors.teal,
    ),
    timePickerTheme: const TimePickerThemeData(
      backgroundColor: CtColors.surface,
    ),
  );
}
