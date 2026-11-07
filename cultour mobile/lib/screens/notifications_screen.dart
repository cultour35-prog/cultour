import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/ui.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> with LiveReloadMixin {
  late Future<List<Notice>> _future;
  List<Notice>? _cache;

  @override
  void initState() {
    super.initState();
    _future = context.read<Session>().api.notifications();
  }

  @override
  String liveKey(Session session) => session.notificationsVersion;

  @override
  void onLiveReload() {
    setState(() => _future = context.read<Session>().api.notifications());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          TextButton(
            onPressed: () async {
              try {
                await context.read<Session>().api.markNotificationsRead(all: true);
                setState(() => _future = context.read<Session>().api.notifications());
              } catch (e) {
                if (context.mounted) showCulTourError(context, e);
              }
            },
            child: const Text('Mark all read'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => setState(() => _future = context.read<Session>().api.notifications()),
        child: FutureBuilder<List<Notice>>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasData) _cache = snap.data;
            final items = snap.data ?? _cache;
            if (items == null) return const Center(child: CircularProgressIndicator());
            if (items.isEmpty) {
              return ListView(children: const [
                EmptyState(icon: Icons.notifications_none, title: 'You are all caught up'),
              ]);
            }
            return ListView.separated(
              itemCount: items.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (_, i) {
                final n = items[i];
                return ListTile(
                  leading: Icon(
                    n.isRead ? Icons.notifications_outlined : Icons.notifications_active_outlined,
                    color: n.isRead ? CtColors.muted : CtColors.gold,
                  ),
                  title: Text(n.title, style: TextStyle(fontWeight: n.isRead ? FontWeight.w500 : FontWeight.w800)),
                  subtitle: Text(n.body, maxLines: 3, overflow: TextOverflow.ellipsis),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
