import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'chat_screens.dart';

const _reportReasons = ['Inaccurate information', 'Inappropriate photo', 'Spam or misleading', 'Other'];

/// Business listing (business.php): photos, services, hours, reviews, favorite, review, message, report.
class BusinessDetailScreen extends StatefulWidget {
  const BusinessDetailScreen({super.key, required this.id});
  final int id;

  @override
  State<BusinessDetailScreen> createState() => _BusinessDetailScreenState();
}

class _BusinessDetailScreenState extends State<BusinessDetailScreen> {
  Map<String, dynamic>? _b;
  Object? _error;
  String? _favNote;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final res = await context.read<Session>().api.getJson('/businesses/detail.php', {'id': '${widget.id}'});
      if (!mounted) return;
      setState(() {
        _b = asMap(res['data']);
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<Map<String, dynamic>?> _engage(Map<String, dynamic> body, {String? photo}) {
    final api = context.read<Session>().api;
    final full = {'business_id': widget.id, ...body};
    return runApi(
      context,
      () => photo != null
          ? api.multipart('/businesses/engage.php', full, {'report_photo': photo})
          : api.postJson('/businesses/engage.php', full),
    );
  }

  Future<void> _review() async {
    final mine = _b?['my_review'] == null ? null : asMap(_b!['my_review']);
    var stars = asInt(mine?['rating'], 5);
    final comment = TextEditingController(text: asString(mine?['comment']));
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: Text(mine == null ? 'Write a review' : 'Update your review'),
        content: StatefulBuilder(
          builder: (ctx, setLocal) => Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(5, (i) {
                  final n = i + 1;
                  return IconButton(
                    onPressed: () => setLocal(() => stars = n),
                    icon: Icon(n <= stars ? Icons.star_rounded : Icons.star_border_rounded,
                        color: CtColors.gold, size: 30),
                  );
                }),
              ),
              TextField(
                  controller: comment, maxLines: 3, decoration: const InputDecoration(hintText: 'What was it like?')),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Back')),
          FilledButton(style: compactFilled(), onPressed: () => Navigator.pop(ctx, true), child: const Text('Save')),
        ],
      ),
    );
    if (ok == true) {
      final res = await _engage({'action': 'review', 'rating': stars, 'comment': comment.text.trim()});
      if (res != null) await _load();
    }
  }

  Future<void> _message() async {
    final subject = TextEditingController();
    final message = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: CtColors.surface,
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Message ${asString(_b?['name'])}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            TextField(controller: subject, maxLength: 160, decoration: const InputDecoration(labelText: 'Subject')),
            TextField(controller: message, maxLines: 4, decoration: const InputDecoration(labelText: 'Message')),
            const SizedBox(height: 12),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Send')),
          ],
        ),
      ),
    );
    if (ok != true) return;
    final res = await _engage({'action': 'inquiry', 'subject': subject.text.trim(), 'message': message.text.trim()});
    final owner = asInt(res?['owner_user_id']);
    if (owner > 0 && mounted) {
      Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => ChatThreadScreen(peerId: owner, peerName: asString(_b?['name'], 'Business'))),
      );
    }
  }

  Future<void> _report() async {
    String? photo;
    final pick = await askReason(
      context,
      title: 'Report this business',
      presets: _reportReasons,
      lead: 'Tell the Tourism Office what is wrong with this listing.',
      confirmLabel: 'Send report',
      extra: StatefulBuilder(
        builder: (ctx, setLocal) => OutlinedButton.icon(
          onPressed: () async {
            final p = await pickPhoto(ctx);
            if (p != null) setLocal(() => photo = p);
          },
          icon: Icon(photo == null ? Icons.add_a_photo_outlined : Icons.check_circle),
          label: Text(photo == null ? 'Add a photo (optional)' : 'Photo added'),
        ),
      ),
    );
    if (pick == null) return;
    await _engage({'action': 'report', 'reason': pick.preset, 'details': pick.detail}, photo: photo);
  }

  @override
  Widget build(BuildContext context) {
    final b = _b;
    final session = context.watch<Session>();
    final api = session.api;
    final tourist = session.user?.isTourist ?? false;
    return Scaffold(
      appBar: AppBar(
        title: Text(asString(b?['name'], 'Business')),
        actions: [
          if (b != null && tourist)
            IconButton(
              tooltip: 'Favorite',
              icon: Icon(asBool(b['favorited']) ? Icons.favorite : Icons.favorite_border,
                  color: asBool(b['favorited']) ? CtColors.coral : null),
              onPressed: () async {
                final res = await _engage({'action': 'favorite'});
                if (res != null && mounted) {
                  final on = asBool(res['favorited']);
                  setState(() {
                    b['favorited'] = on;
                    _favNote = on ? 'Added to favorite' : 'Removed from favorites';
                  });
                }
              },
            ),
          if (b != null && tourist)
            IconButton(tooltip: 'Report', icon: const Icon(Icons.flag_outlined), onPressed: _report),
        ],
      ),
      body: b == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Not found', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  CoverImage(url: api.mediaUrl(asString(b['cover_url'], asString(b['cover_image']))), height: 190),
                  if (_favNote != null) ...[
                    const SizedBox(height: 10),
                    Text(_favNote!, style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w800)),
                  ],
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      if (asString(b['logo_url']).isNotEmpty) ...[
                        Avatar(name: asString(b['name']), url: asString(b['logo_url']), radius: 24),
                        const SizedBox(width: 12),
                      ],
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(asString(b['name']),
                                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                            if (asString(b['type_name']).isNotEmpty)
                              Text(asString(b['type_name']), style: const TextStyle(color: CtColors.gold)),
                          ],
                        ),
                      ),
                    ],
                  ),
                  if (asString(b['address']).isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(asString(b['address']), style: const TextStyle(color: CtColors.muted)),
                    ),
                  const SizedBox(height: 12),
                  Text(asString(b['description']).isNotEmpty ? asString(b['description']) : 'No description yet.'),
                  const SizedBox(height: 12),
                  if (tourist)
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      FilledButton.icon(
                        style: compactFilled(),
                        onPressed: _message,
                        icon: const Icon(Icons.chat_bubble_outline, size: 18),
                        label: const Text('Message'),
                      ),
                      OutlinedButton.icon(
                        style: compactOutlined(),
                        onPressed: _review,
                        icon: const Icon(Icons.star_outline, size: 18),
                        label: Text(b['my_review'] == null ? 'Write a review' : 'Edit review'),
                      ),
                    ]),
                  const SizedBox(height: 12),
                  if (asString(b['phone']).isNotEmpty || asString(b['email']).isNotEmpty)
                    SectionCard(
                      title: 'Contact',
                      child: Column(children: [
                        if (asString(b['phone']).isNotEmpty)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.phone_outlined),
                            title: Text(asString(b['phone'])),
                            onTap: () => launchUrl(Uri(scheme: 'tel', path: asString(b['phone']))),
                          ),
                        if (asString(b['email']).isNotEmpty)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.email_outlined),
                            title: Text(asString(b['email'])),
                            onTap: () => launchUrl(Uri(scheme: 'mailto', path: asString(b['email']))),
                          ),
                      ]),
                    ),
                  if (asList(b['photos']).isNotEmpty)
                    SectionCard(
                      title: 'Photos',
                      child: SizedBox(
                        height: 120,
                        child: ListView.separated(
                          scrollDirection: Axis.horizontal,
                          itemCount: asList(b['photos']).length,
                          separatorBuilder: (_, __) => const SizedBox(width: 8),
                          itemBuilder: (_, i) {
                            final p = asMap(asList(b['photos'])[i]);
                            final url = api.mediaUrl(asString(p['url']));
                            return GestureDetector(
                              onTap: () => showDialog<void>(
                                context: context,
                                builder: (_) => Dialog(
                                  backgroundColor: Colors.black,
                                  child: InteractiveViewer(child: RemotePhoto(url: url, fit: BoxFit.contain)),
                                ),
                              ),
                              child: SizedBox(width: 150, child: CoverImage(url: url, height: 120, radius: 12)),
                            );
                          },
                        ),
                      ),
                    ),
                  if (asList(b['services']).isNotEmpty)
                    SectionCard(
                      title: 'Services',
                      child: Column(
                        children: asList(b['services']).map(asMap).map((s) {
                          final mins = asInt(s['duration_minutes']);
                          return ListTile(
                            contentPadding: EdgeInsets.zero,
                            title: Text(asString(s['name']), style: const TextStyle(fontWeight: FontWeight.w700)),
                            subtitle: Text(
                              [asString(s['description']), if (mins > 0) '$mins min']
                                  .where((e) => e.isNotEmpty)
                                  .join(' · '),
                            ),
                            trailing: Text(asString(s['price_label']),
                                style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w700)),
                          );
                        }).toList(),
                      ),
                    ),
                  SectionCard(
                    title: 'Opening hours',
                    child: Column(
                      children: asList(b['hours'])
                          .map(asMap)
                          .map((h) => InfoRow(asString(h['day']), asString(h['label'])))
                          .toList(),
                    ),
                  ),
                  SectionCard(
                    title: 'Reviews (${asList(b['reviews']).length})',
                    child: asList(b['reviews']).isEmpty
                        ? const Text('No reviews yet.', style: TextStyle(color: CtColors.muted))
                        : Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: asList(b['reviews']).map(asMap).map((r) {
                              final stars = asInt(r['rating']);
                              return Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(children: [
                                      ...List.generate(
                                          5,
                                          (i) => Icon(i < stars ? Icons.star_rounded : Icons.star_border_rounded,
                                              color: CtColors.gold, size: 16)),
                                      const SizedBox(width: 8),
                                      Text('${asString(r['first_name'])} ${asString(r['last_name'])}'.trim(),
                                          style: const TextStyle(fontWeight: FontWeight.w700)),
                                    ]),
                                    if (asString(r['comment']).isNotEmpty) Text(asString(r['comment'])),
                                  ],
                                ),
                              );
                            }).toList(),
                          ),
                  ),
                ],
              ),
            ),
    );
  }
}
