import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'screens/shell.dart';
import 'screens/welcome_screen.dart';
import 'session.dart';
import 'theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const CulTourApp());
}

class CulTourApp extends StatefulWidget {
  const CulTourApp({super.key});

  @override
  State<CulTourApp> createState() => _CulTourAppState();
}

class _CulTourAppState extends State<CulTourApp> {
  final Session _session = Session();

  @override
  void initState() {
    super.initState();
    _session.bootstrap();
  }

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider<Session>.value(
      value: _session,
      child: MaterialApp(
        title: 'CulTour',
        debugShowCheckedModeBanner: false,
        theme: cultourTheme(),
        builder: (context, child) {
          return DecoratedBox(
            decoration: CtDecor.page,
            child: child ?? const SizedBox.shrink(),
          );
        },
        home: const _SessionGate(),
      ),
    );
  }
}

class _SessionGate extends StatelessWidget {
  const _SessionGate();

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    if (!session.ready) {
      return const Scaffold(
        backgroundColor: Color(0xFF061214),
        body: Center(child: CircularProgressIndicator()),
      );
    }
    if (session.isLoggedIn) {
      return const AppShell();
    }
    return const WelcomeScreen();
  }
}
