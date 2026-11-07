import 'package:flutter/material.dart';

import '../theme.dart';
import 'login_screen.dart';
import 'register_screen.dart';

class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: CtColors.foam,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: CtColors.line),
                ),
                child: const Text('Ormoc City Tourism Office',
                    style: TextStyle(color: CtColors.gold, fontSize: 12, fontWeight: FontWeight.w700)),
              ),
              const SizedBox(height: 18),
              Text('CulTour', style: Theme.of(context).textTheme.displayLarge?.copyWith(color: CtColors.sea)),
              const SizedBox(height: 10),
              Text(
                'Ormoc, mapped for your next day out',
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(height: 1.25),
              ),
              const SizedBox(height: 10),
              const Text(
                'Discover places on the city map, build a day tour, and book a licensed guide — in one tourism system.',
                style: TextStyle(color: CtColors.muted, fontSize: 16, height: 1.4),
              ),
              const Spacer(),
              _row(Icons.map_outlined, 'See places on the map'),
              _row(Icons.route_outlined, 'Build your itinerary'),
              _row(Icons.badge_outlined, 'Book a licensed guide'),
              const Spacer(),
              FilledButton(
                onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RegisterScreen())),
                child: const Text('Create account'),
              ),
              const SizedBox(height: 10),
              OutlinedButton(
                onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const LoginScreen())),
                child: const Text('Log in'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static Widget _row(IconData icon, String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: CtColors.foam,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: CtColors.line),
            ),
            child: Icon(icon, color: CtColors.gold, size: 22),
          ),
          const SizedBox(width: 12),
          Text(text, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}
