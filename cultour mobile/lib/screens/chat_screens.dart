import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';

class ChatListScreen extends StatefulWidget {
  const ChatListScreen({super.key});

  @override
  State<ChatListScreen> createState() => _ChatListScreenState();
}

class _ChatListScreenState extends State<ChatListScreen> with LiveReloadMixin {
  late Future<Map<String, dynamic>> _future;
  Map<String, dynamic>? _cache;

  @override
  void initState() {
    super.initState();
    _future = context.read<Session>().api.chatThread();
  }

  @override
  String liveKey(Session session) => session.chatVersion;

  @override
  void onLiveReload() {
    setState(() => _future = context.read<Session>().api.chatThread());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      body: RefreshIndicator(
        onRefresh: () async => setState(() => _future = context.read<Session>().api.chatThread()),
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasData) _cache = snap.data;
            final data = snap.data ?? _cache;
            if (data == null) {
              if (snap.hasError) {
                return ListView(children: [
                  EmptyState(icon: Icons.chat_bubble_outline, title: 'Chat unavailable', detail: snap.error.toString())
                ]);
              }
              return const Center(child: CircularProgressIndicator());
            }
            final peers = asList(data['peers']).map((e) => ChatPeer.fromJson(asMap(e))).toList();
            if (peers.isEmpty) {
              return ListView(children: const [
                EmptyState(
                    icon: Icons.forum_outlined,
                    title: 'No conversations yet',
                    detail: 'Message a guide from a booking.'),
              ]);
            }
            return ListView.separated(
              itemCount: peers.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (_, i) {
                final p = peers[i];
                return ListTile(
                  leading: Avatar(name: p.name, url: p.avatar ?? ''),
                  title: Text(p.name),
                  trailing: p.unread > 0
                      ? CircleAvatar(
                          radius: 12,
                          backgroundColor: CtColors.coral,
                          child: Text('${p.unread}', style: const TextStyle(fontSize: 11, color: Colors.white)),
                        )
                      : const Icon(Icons.chevron_right),
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => ChatThreadScreen(peerId: p.id, peerName: p.name)),
                  ).then((_) => setState(() => _future = context.read<Session>().api.chatThread())),
                );
              },
            );
          },
        ),
      ),
    );
  }
}

class ChatThreadScreen extends StatefulWidget {
  const ChatThreadScreen({super.key, required this.peerId, required this.peerName});
  final int peerId;
  final String peerName;

  @override
  State<ChatThreadScreen> createState() => _ChatThreadScreenState();
}

class _ChatThreadScreenState extends State<ChatThreadScreen> {
  final _input = TextEditingController();
  final _scroll = ScrollController();
  List<ChatMessage> _messages = [];
  bool _loading = true;
  bool _sending = false;
  Timer? _timer;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load(full: true);
    _timer = Timer.periodic(const Duration(seconds: 2), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  int get _after => _messages.isEmpty ? 0 : _messages.map((m) => m.id).reduce((a, b) => a > b ? a : b);

  Future<void> _load({bool full = false}) async {
    if (_busy) return;
    _busy = true;
    try {
      final data = await context.read<Session>().api.chatThread(
            withUserId: widget.peerId,
            after: full ? 0 : _after,
          );
      if (!mounted) return;
      final incoming = asList(data['messages']).map((e) => ChatMessage.fromJson(asMap(e))).toList();
      setState(() {
        if (full) {
          _messages = incoming;
        } else if (incoming.isNotEmpty) {
          final seen = _messages.map((m) => m.id).toSet();
          _messages = [..._messages, ...incoming.where((m) => !seen.contains(m.id))];
        }
        _loading = false;
      });
    } catch (e) {
      if (full && mounted) {
        setState(() => _loading = false);
        showCulTourError(context, e);
      }
    } finally {
      _busy = false;
    }
  }

  Future<void> _sendPhoto() async {
    final path = await pickPhoto(context);
    if (path == null || !mounted) return;
    await _send(imagePath: path);
  }

  Future<void> _send({String? imagePath}) async {
    final text = _input.text.trim();
    if (text.isEmpty && imagePath == null) return;
    setState(() => _sending = true);
    try {
      await context.read<Session>().api.sendChat(widget.peerId, text, imagePath: imagePath);
      _input.clear();
      await _load(full: true);
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.peerName)),
      body: Column(
        children: [
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : ListView.builder(
                    controller: _scroll,
                    padding: const EdgeInsets.all(12),
                    itemCount: _messages.length,
                    itemBuilder: (_, i) {
                      final m = _messages[i];
                      final mine = m.mine;
                      return Align(
                        alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
                        child: Container(
                          margin: const EdgeInsets.symmetric(vertical: 4),
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          constraints: const BoxConstraints(maxWidth: 280),
                          decoration: BoxDecoration(
                            color: mine ? CtColors.sea : CtColors.surface,
                            borderRadius: BorderRadius.circular(14),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              if ((m.imagePath ?? '').isNotEmpty)
                                Padding(
                                  padding: EdgeInsets.only(bottom: m.body.isEmpty ? 0 : 6),
                                  child: AuthImage(context.read<Session>().api.mediaUrl(m.imagePath)),
                                ),
                              if (m.body.isNotEmpty) Text(m.body),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Row(
                children: [
                  IconButton(
                    tooltip: 'Send a photo',
                    onPressed: _sending ? null : _sendPhoto,
                    icon: const Icon(Icons.photo_camera_outlined),
                  ),
                  Expanded(
                    child: TextField(
                      controller: _input,
                      decoration: const InputDecoration(hintText: 'Message'),
                      onSubmitted: (_) => _send(),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    onPressed: _sending ? null : _send,
                    icon: const Icon(Icons.send),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
