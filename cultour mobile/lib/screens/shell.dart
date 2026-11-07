import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../session.dart';
import '../theme.dart';
import 'bookings_screen.dart';
import 'chat_screens.dart';
import 'explore_screen.dart';
import 'guide/guide_checkin_screen.dart';
import 'guide/guide_home_screen.dart';
import 'home_screen.dart';
import 'itineraries_screen.dart';
import 'profile_screen.dart';
import 'replacement_popup.dart';

class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> with WidgetsBindingObserver {
  int _index = 0;
  int? _roleId;
  bool _tabLoading = false;
  final Set<int> _opened = {0};
  Timer? _hideLoad;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      context.read<Session>().startLive();
    });
  }

  @override
  void dispose() {
    _hideLoad?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    context.read<Session>().setLivePaused(state != AppLifecycleState.resumed);
  }

  void _selectTab(int i) {
    if (i == _index) return;
    final firstOpen = _opened.add(i);
    setState(() {
      _index = i;
      _tabLoading = true;
    });
    _hideLoad?.cancel();
    _hideLoad = Timer(Duration(milliseconds: firstOpen ? 1600 : 700), () {
      if (mounted) setState(() => _tabLoading = false);
    });
  }

  Widget _shell({
    required int index,
    required List<Widget> pages,
    required List<NavigationDestination> destinations,
  }) {
    return ReplacementHost(
      child: Scaffold(
        body: Stack(
          children: [
            IndexedStack(index: index, children: pages),
            Positioned(
              top: MediaQuery.paddingOf(context).top,
              left: 0,
              right: 0,
              child: IgnorePointer(
                child: AnimatedOpacity(
                  opacity: _tabLoading ? 1 : 0,
                  duration: const Duration(milliseconds: 180),
                  child: const LinearProgressIndicator(
                    minHeight: 3,
                    color: CtColors.gold,
                    backgroundColor: CtColors.tealDark,
                  ),
                ),
              ),
            ),
          ],
        ),
        bottomNavigationBar: NavigationBar(
          selectedIndex: index,
          onDestinationSelected: _selectTab,
          destinations: destinations,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    if (user == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_roleId != user.roleId) {
      _roleId = user.roleId;
      _index = 0;
      _opened
        ..clear()
        ..add(0);
    }

    if (user.isGuide) {
      const pages = [GuideHomeScreen(), BookingsScreen(), GuideCheckinScreen(), ChatListScreen(), ProfileScreen()];
      final index = _index.clamp(0, pages.length - 1);
      return _shell(
        index: index,
        pages: pages,
        destinations: [
          NavigationDestination(
            icon: liveBadge(count: session.unreadNotifications, child: const Icon(Icons.dashboard_outlined)),
            selectedIcon: liveBadge(count: session.unreadNotifications, child: const Icon(Icons.dashboard)),
            label: 'Home',
          ),
          NavigationDestination(
            icon: liveBadge(count: session.pendingBookings, child: const Icon(Icons.event_outlined)),
            selectedIcon: liveBadge(count: session.pendingBookings, child: const Icon(Icons.event)),
            label: 'Bookings',
          ),
          const NavigationDestination(
            icon: Icon(Icons.photo_camera_outlined),
            selectedIcon: Icon(Icons.photo_camera),
            label: 'Tour',
          ),
          NavigationDestination(
            icon: liveBadge(count: session.unreadMessages, child: const Icon(Icons.chat_bubble_outline)),
            selectedIcon: liveBadge(count: session.unreadMessages, child: const Icon(Icons.chat_bubble)),
            label: 'Messages',
          ),
          const NavigationDestination(
              icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'You'),
        ],
      );
    }

    if (!user.isTourist) {
      return const ProfileScreen(webOnly: true);
    }

    const pages = [
      HomeScreen(),
      ExploreScreen(),
      ItinerariesScreen(asTab: true),
      BookingsScreen(),
      ChatListScreen(),
      ProfileScreen(),
    ];
    final index = _index.clamp(0, pages.length - 1);
    return _shell(
      index: index,
      pages: pages,
      destinations: [
        NavigationDestination(
          icon: liveBadge(count: session.unreadNotifications, child: const Icon(Icons.home_outlined)),
          selectedIcon: liveBadge(count: session.unreadNotifications, child: const Icon(Icons.home_rounded)),
          label: 'Home',
        ),
        const NavigationDestination(
            icon: Icon(Icons.explore_outlined), selectedIcon: Icon(Icons.explore), label: 'Explore'),
        const NavigationDestination(
            icon: Icon(Icons.route_outlined), selectedIcon: Icon(Icons.route), label: 'Plan'),
        NavigationDestination(
          icon: liveBadge(count: session.pendingBookings, child: const Icon(Icons.event_outlined)),
          selectedIcon: liveBadge(count: session.pendingBookings, child: const Icon(Icons.event)),
          label: 'Bookings',
        ),
        NavigationDestination(
          icon: liveBadge(count: session.unreadMessages, child: const Icon(Icons.chat_bubble_outline)),
          selectedIcon: liveBadge(count: session.unreadMessages, child: const Icon(Icons.chat_bubble)),
          label: 'Messages',
        ),
        const NavigationDestination(
            icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'You'),
      ],
    );
  }
}
