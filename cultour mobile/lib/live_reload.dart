import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'session.dart';

/// Reloads a screen when CulTour's live poll sees a change (same idea as the website).
mixin LiveReloadMixin<T extends StatefulWidget> on State<T> {
  Session? _liveSession;
  String? _liveSeen;

  String liveKey(Session session);
  void onLiveReload();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _liveSession = context.read<Session>();
      _liveSeen = liveKey(_liveSession!);
      _liveSession!.addListener(_onLiveTick);
    });
  }

  void _onLiveTick() {
    final session = _liveSession;
    if (!mounted || session == null) return;
    final next = liveKey(session);
    if (next.isEmpty || next == _liveSeen) return;
    _liveSeen = next;
    onLiveReload();
  }

  @override
  void dispose() {
    _liveSession?.removeListener(_onLiveTick);
    super.dispose();
  }
}

Widget liveBadge({required int count, required Widget child}) {
  return Badge(
    isLabelVisible: count > 0,
    label: Text(count > 9 ? '9+' : '$count'),
    child: child,
  );
}
