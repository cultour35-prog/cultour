import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../session.dart';
import '../theme.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'account_screens.dart';
import 'chat_screens.dart';
import 'guide/guide_availability_screen.dart';
import 'guide/guide_schedule_screen.dart';
import 'guide/guide_tours_screen.dart';
import 'itineraries_screen.dart';
import 'notifications_screen.dart';
import 'profile_edit_screen.dart';
import 'website_fallback_screen.dart';
import 'welcome_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key, this.webOnly = false});
  final bool webOnly;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    if (user == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Avatar(name: user.fullName, url: user.avatarUrl ?? '', radius: 28),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(user.fullName, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                        Text(user.email, style: const TextStyle(color: CtColors.muted)),
                        const SizedBox(height: 6),
                        StatusChip(user.roleName),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (user.isSuspended)
            const Padding(
              padding: EdgeInsets.only(top: 12),
              child: Card(
                child: ListTile(
                  leading: Icon(Icons.gpp_maybe_outlined, color: CtColors.coral),
                  title: Text('Account suspended'),
                  subtitle: Text('Use the CulTour website to submit an appeal.'),
                ),
              ),
            ),
          if (webOnly) ...[
            const SizedBox(height: 16),
            const Text(
              'Admin and business tools stay on the CulTour website. This app is for tourists and tour guides.',
              style: TextStyle(color: CtColors.muted),
            ),
          ],
          const SizedBox(height: 8),
          if (user.isTourist) ...[
            ListTile(
              leading: const Icon(Icons.favorite_outline),
              title: const Text('Favorites'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const FavoritesScreen())),
            ),
            ListTile(
              leading: const Icon(Icons.route_outlined),
              title: const Text('Your tours'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ItinerariesScreen())),
            ),
          ],
          if (user.isTourist || user.isGuide)
            ListTile(
              leading: const Icon(Icons.chat_bubble_outline),
              title: const Text('Messages'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ChatListScreen())),
            ),
          ListTile(
            leading: const Icon(Icons.notifications_outlined),
            title: const Text('Notifications'),
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen())),
          ),
          if (user.isGuide) ...[
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 4),
              child: Text('Guide tools', style: TextStyle(fontWeight: FontWeight.w700)),
            ),
            ListTile(
              leading: const Icon(Icons.schedule_outlined),
              title: const Text('Availability'),
              subtitle: const Text('Weekly hours and blocked dates'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const GuideAvailabilityScreen())),
            ),
            ListTile(
              leading: const Icon(Icons.calendar_month_outlined),
              title: const Text('Schedule'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const GuideScheduleScreen())),
            ),
            ListTile(
              leading: const Icon(Icons.route_outlined),
              title: const Text('My suggested tours'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const GuideToursScreen())),
            ),
          ],
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 16, 16, 4),
            child: Text('Account', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
          if (user.isTourist || user.isGuide)
            ListTile(
              leading: const Icon(Icons.badge_outlined),
              title: Text(user.isGuide ? 'Edit guide profile' : 'Edit profile'),
              subtitle: const Text('Name, contact, address, photo'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfileEditScreen())),
            ),
          ListTile(
            leading: const Icon(Icons.password_outlined),
            title: const Text('Change password'),
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ChangePasswordScreen())),
          ),
          if (user.isTourist || user.isGuide)
            ListTile(
              leading: const Icon(Icons.link),
              title: const Text('Link another role'),
              subtitle: const Text('Tourist ↔ Tour Guide'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const LinkRoleScreen())),
            ),
          ListTile(
            leading: const Icon(Icons.lock_reset_outlined),
            title: const Text('Forgot password'),
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => ForgotPasswordScreen(prefillEmail: user.email)),
            ),
          ),
          ListTile(
            leading: const Icon(Icons.alternate_email),
            title: const Text('Change email'),
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ChangeEmailScreen())),
          ),
          if (webOnly)
            ListTile(
              leading: const Icon(Icons.open_in_browser),
              title: const Text('Open the CulTour website'),
              onTap: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => const WebsiteFallbackScreen(
                    title: 'CulTour website',
                    detail: 'Admin and business tools are on the CulTour website.',
                    path: '/',
                  ),
                ),
              ),
            ),
          if (!user.isTourist && !user.isGuide)
            ListTile(
              leading: const Icon(Icons.settings_outlined),
              title: const Text('API server'),
              subtitle: Text(session.api.baseUrl, maxLines: 1, overflow: TextOverflow.ellipsis),
              onTap: () => _editApi(context, session),
            ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () async {
              await session.logout();
              if (!context.mounted) return;
              Navigator.of(context).pushAndRemoveUntil(
                MaterialPageRoute(builder: (_) => const WelcomeScreen()),
                (_) => false,
              );
            },
            icon: const Icon(Icons.logout),
            label: const Text('Log out'),
          ),
        ],
      ),
    );
  }

  Future<void> _editApi(BuildContext context, Session session) async {
    final c = TextEditingController(text: session.api.baseUrl);
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: const Text('API URL'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Android emulator: http://10.0.2.2/cultour/api\nPhone on Wi-Fi: http://YOUR_PC_LAN_IP/cultour/api',
              style: TextStyle(fontSize: 12, color: CtColors.muted),
            ),
            const SizedBox(height: 12),
            TextField(controller: c, decoration: const InputDecoration(labelText: 'Base URL')),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Save')),
        ],
      ),
    );
    if (ok == true) {
      await session.setApiBase(c.text);
      if (context.mounted) showCulTourMessage(context, 'API URL saved.');
    }
  }
}
