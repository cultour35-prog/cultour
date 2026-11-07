import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../live_reload.dart';
import '../../session.dart';
import '../../theme.dart';
import '../../util/jsonx.dart';
import '../../widgets/kit.dart';
import '../../widgets/ui.dart';
import '../itinerary_editor_screen.dart';

/// Guide's suggested tours: published templates and drafts (tourguide/itineraries.php).
class GuideToursScreen extends StatefulWidget {
  const GuideToursScreen({super.key});

  @override
  State<GuideToursScreen> createState() => _GuideToursScreenState();
}

class _GuideToursScreenState extends State<GuideToursScreen> with LiveReloadMixin {
  Map<String, dynamic>? _data;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  String liveKey(Session session) => session.itinerariesVersion;

  @override
  void onLiveReload() => _load();

  Future<void> _load() async {
    try {
      final res = await context.read<Session>().api.getJson('/guide/itineraries.php');
      if (!mounted) return;
      setState(() {
        _data = asMap(res['data']);
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _open(int id) async {
    await Navigator.push(
        context, MaterialPageRoute(builder: (_) => ItineraryEditorScreen(id: id, guideTemplate: true)));
    await _load();
  }

  Future<void> _create({bool auto = false}) async {
    final api = context.read<Session>().api;
    var days = 1;
    if (auto) {
      final picked = await showDialog<int>(
        context: context,
        builder: (ctx) => StatefulBuilder(
          builder: (ctx, setLocal) => AlertDialog(
            backgroundColor: CtColors.surface,
            title: const Text('Auto-generate a tour'),
            content: Row(
              children: [
                const Expanded(child: Text('Days')),
                IconButton(
                    onPressed: days > 1 ? () => setLocal(() => days--) : null,
                    icon: const Icon(Icons.remove_circle_outline)),
                Text('$days', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                IconButton(
                    onPressed: days < 7 ? () => setLocal(() => days++) : null,
                    icon: const Icon(Icons.add_circle_outline)),
              ],
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Back')),
              FilledButton(
                  style: compactFilled(), onPressed: () => Navigator.pop(ctx, days), child: const Text('Generate')),
            ],
          ),
        ),
      );
      if (picked == null) return;
      days = picked;
    }
    if (!mounted) return;
    final res = await runApi(
      context,
      () => api.postJson('/guide/itineraries.php', {
        'action': auto ? 'auto_generate' : 'create',
        'meetup_time': '8:00 AM',
        if (auto) 'title': days > 1 ? 'Ormoc $days-day tour' : 'Ormoc day tour',
        if (auto) 'is_multiday': days > 1 ? 1 : 0,
        if (auto) 'day_count': days,
      }),
    );
    final id = asInt(asMap(res?['data'])['id']);
    if (id > 0) await _open(id);
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return Scaffold(
      appBar: AppBar(
        title: const Text('My suggested tours'),
        actions: [TextButton(onPressed: () => _create(auto: true), child: const Text('Auto tour'))],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _create,
        backgroundColor: CtColors.sea,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('New tour'),
      ),
      body: d == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load tours', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 100),
                children: [
                  const Text(
                    'Publish tours that tourists can copy and book with you.',
                    style: TextStyle(color: CtColors.muted),
                  ),
                  const SizedBox(height: 12),
                  _section('Published', asList(d['published']), 'Nothing published yet.'),
                  _section(
                    'Drafts (${asList(d['drafts']).length} / ${asInt(d['draft_max'], 3)})',
                    asList(d['drafts']),
                    'No drafts.',
                  ),
                ],
              ),
            ),
    );
  }

  Widget _section(String title, List<dynamic> rows, String empty) {
    return SectionCard(
      title: title,
      child: rows.isEmpty
          ? Text(empty, style: const TextStyle(color: CtColors.muted))
          : Column(
              children: rows.map(asMap).map((r) {
                final stops = asList(r['stops']).length;
                final days = asInt(r['day_count'], 1);
                return ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(color: CtColors.bg, borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.route_outlined, color: CtColors.sea),
                  ),
                  title: Text(asString(r['title']), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('$stops stop${stops == 1 ? '' : 's'} · ${days > 1 ? '$days days' : '1 day'}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _open(asInt(r['id'])),
                );
              }).toList(),
            ),
    );
  }
}
