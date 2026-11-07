import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../api.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'business_detail_screen.dart';
import 'destination_detail_screen.dart';
import 'itinerary_editor_screen.dart';

class ItinerariesScreen extends StatefulWidget {
  const ItinerariesScreen({super.key, this.asTab = false});
  final bool asTab;

  @override
  State<ItinerariesScreen> createState() => _ItinerariesScreenState();
}

class _ItinerariesScreenState extends State<ItinerariesScreen> with LiveReloadMixin {
  late Future<List<Itinerary>> _future;
  List<Itinerary>? _cache;
  int _seg = 0;

  @override
  void initState() {
    super.initState();
    _future = context.read<Session>().api.itineraries();
  }

  @override
  String liveKey(Session session) => session.itinerariesVersion;

  @override
  void onLiveReload() {
    _reload();
  }

  CulTourApi get _api => context.read<Session>().api;

  Future<void> _reload() async {
    setState(() => _future = _api.itineraries());
  }

  Future<void> _deleteTour(Itinerary it) async {
    final ok = await confirmAction(
      context,
      title: 'Delete this itinerary?',
      message: '“${it.title}” and its stops will be removed. This cannot be undone.',
      confirmLabel: 'Delete',
      danger: true,
    );
    if (!ok) return;
    try {
      await _api.saveItinerary({'action': 'delete', 'itinerary_id': it.id});
      if (mounted) await _reload();
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    }
  }

  Future<void> _newTour() async {
    try {
      final created = await _api.saveItinerary({'action': 'create', 'title': 'Unnamed', 'meetup_time': '8:00 AM'});
      if (!mounted) return;
      await Navigator.push(context, MaterialPageRoute(builder: (_) => ItineraryEditorScreen(id: created.id)));
      await _reload();
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    }
  }

  Future<void> _auto() async {
    var days = 1;
    DateTime? start;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          backgroundColor: CtColors.surface,
          title: const Text('Auto-generate a tour'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  const Expanded(child: Text('Days')),
                  IconButton(
                      onPressed: days > 1 ? () => setLocal(() => days--) : null,
                      icon: const Icon(Icons.remove_circle_outline)),
                  Text('$days', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                  IconButton(
                      onPressed: days < 30 ? () => setLocal(() => days++) : null,
                      icon: const Icon(Icons.add_circle_outline)),
                ],
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Start date (optional)'),
                subtitle: Text(start == null ? 'Pick later' : ymd(start!)),
                trailing: const Icon(Icons.event),
                onTap: () async {
                  final now = DateTime.now();
                  final d = await showDatePicker(
                    context: ctx,
                    initialDate: now.add(const Duration(days: 1)),
                    firstDate: DateTime(now.year, now.month, now.day),
                    lastDate: now.add(const Duration(days: 365)),
                  );
                  if (d != null) setLocal(() => start = d);
                },
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Back')),
            FilledButton(
                style: compactFilled(), onPressed: () => Navigator.pop(ctx, true), child: const Text('Generate')),
          ],
        ),
      ),
    );
    if (ok != true) return;
    try {
      final created = await _api.saveItinerary({
        'action': 'auto_generate',
        'title': days > 1 ? 'Ormoc $days-day tour' : 'Ormoc day tour',
        'meetup_time': '8:00 AM',
        'is_multiday': days > 1 ? 1 : 0,
        'day_count': days,
        if (start != null) 'start_date': ymd(start!),
      });
      if (!mounted) return;
      await Navigator.push(context, MaterialPageRoute(builder: (_) => ItineraryEditorScreen(id: created.id)));
      await _reload();
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Plan'),
        automaticallyImplyLeading: !widget.asTab,
        actions: [
          TextButton(onPressed: _auto, child: const Text('Auto tour')),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newTour,
        backgroundColor: CtColors.sea,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('New tour'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<List<Itinerary>>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasData) _cache = snap.data;
            final all = snap.data ?? _cache;
            if (all == null) {
              if (snap.hasError) {
                return ListView(children: [
                  EmptyState(icon: Icons.wifi_off, title: 'Could not load tours', detail: snap.error.toString())
                ]);
              }
              return const Center(child: CircularProgressIndicator());
            }
            final drafts = all.where((i) => i.draft && !i.locked).toList();
            final booked = all.where((i) => !i.suggested && i.locked).toList();
            final suggested = all.where((i) => i.suggested).toList();
            final items = _seg == 0 ? drafts : (_seg == 1 ? booked : suggested);
            return ListView(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 100),
              children: [
                SegmentedButton<int>(
                  segments: const [
                    ButtonSegment(value: 0, label: Text('Drafts')),
                    ButtonSegment(value: 1, label: Text('Booked')),
                    ButtonSegment(value: 2, label: Text('Suggested')),
                  ],
                  selected: {_seg},
                  onSelectionChanged: (s) => setState(() => _seg = s.first),
                ),
                const SizedBox(height: 16),
                if (items.isEmpty)
                  EmptyState(
                    icon: Icons.route_outlined,
                    title: _seg == 0 ? 'No drafts yet' : (_seg == 1 ? 'No booked tours' : 'No suggested tours'),
                    detail: _seg == 0
                        ? 'A tour is listed here after you tap Save draft.'
                        : (_seg == 1
                            ? 'Booked tours show up here.'
                            : 'The Tourism Office has not published suggested tours.'),
                  )
                else
                  ...items.map((it) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Material(
                          color: CtColors.surface,
                          borderRadius: BorderRadius.circular(16),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(16),
                            onTap: () async {
                              if (it.suggested) {
                                final copy = await showDialog<bool>(
                                  context: context,
                                  builder: (ctx) => AlertDialog(
                                    title: const Text('Use this tour?'),
                                    content: const Text(
                                        'A copy will be added to Drafts so you can edit it and book a guide.'),
                                    actions: [
                                      TextButton(
                                          onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                                      FilledButton(
                                          onPressed: () => Navigator.pop(ctx, true), child: const Text('Copy')),
                                    ],
                                  ),
                                );
                                if (copy == true) {
                                  try {
                                    final created = await _api.saveItinerary({'action': 'clone', 'id': it.id});
                                    if (!mounted) return;
                                    await Navigator.push(this.context,
                                        MaterialPageRoute(builder: (_) => ItineraryEditorScreen(id: created.id)));
                                    await _reload();
                                  } catch (e) {
                                    if (mounted) showCulTourError(this.context, e);
                                  }
                                }
                                return;
                              }
                              await Navigator.push(
                                  context, MaterialPageRoute(builder: (_) => ItineraryEditorScreen(id: it.id)));
                              await _reload();
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(16),
                              child: Row(
                                children: [
                                  Container(
                                    width: 42,
                                    height: 42,
                                    decoration:
                                        BoxDecoration(color: CtColors.bg, borderRadius: BorderRadius.circular(12)),
                                    child: const Icon(Icons.route_outlined, color: CtColors.sea),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(it.title,
                                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                                        const SizedBox(height: 2),
                                        Text(
                                          [
                                            '${it.stops.length} stop${it.stops.length == 1 ? '' : 's'}',
                                            if (it.dayCount > 1) '${it.dayCount} days',
                                            if ((it.startDate ?? '').isNotEmpty) it.startDate!,
                                            if (it.locked) 'Booked',
                                          ].join(' · '),
                                          style: const TextStyle(color: CtColors.muted, fontSize: 13),
                                        ),
                                      ],
                                    ),
                                  ),
                                  if (!it.suggested)
                                    IconButton(
                                      tooltip: 'Delete tour',
                                      onPressed: () => _deleteTour(it),
                                      icon: const Icon(Icons.delete_outline, color: CtColors.coral),
                                    )
                                  else
                                    const Icon(Icons.chevron_right, color: CtColors.muted),
                                ],
                              ),
                            ),
                          ),
                        ),
                      )),
              ],
            );
          },
        ),
      ),
    );
  }
}

class FavoritesScreen extends StatefulWidget {
  const FavoritesScreen({super.key});

  @override
  State<FavoritesScreen> createState() => _FavoritesScreenState();
}

class _FavoritesScreenState extends State<FavoritesScreen> with LiveReloadMixin {
  Map<String, dynamic>? _data;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  String liveKey(Session session) => session.favoritesVersion;

  @override
  void onLiveReload() => _load();

  Future<void> _load() async {
    try {
      final res = await context.read<Session>().api.getJson('/favorites/list.php');
      if (!mounted) return;
      setState(() {
        _data = res;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Widget _tile(String cover, String title, String subtitle, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: ListTile(
        tileColor: CtColors.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        leading: SizedBox(width: 52, child: CoverImage(url: cover, height: 52, radius: 10)),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final api = context.watch<Session>().api;
    final places = asList(_data?['data']).map(asMap).toList();
    final biz = asList(_data?['businesses']).map(asMap).toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Favorites')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _data == null
            ? (_error != null
                ? ListView(children: [
                    EmptyState(icon: Icons.error_outline, title: 'Could not load favorites', detail: '$_error')
                  ])
                : const Center(child: CircularProgressIndicator()))
            : (places.isEmpty && biz.isEmpty)
                ? ListView(children: const [
                    EmptyState(
                      icon: Icons.favorite_border,
                      title: 'No favorites yet',
                      detail: 'Tap the heart on a place or business to save it here.',
                    ),
                  ])
                : ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (places.isNotEmpty) ...[
                        const Text('Places', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                        const SizedBox(height: 8),
                        ...places.map((p) => _tile(
                              api.mediaUrl(asString(p['cover_url'], asString(p['cover_image']))),
                              asString(p['name']),
                              asString(p['location'], asString(p['category_name'])),
                              () => Navigator.push(
                                context,
                                MaterialPageRoute(builder: (_) => DestinationDetailScreen(id: asInt(p['id']))),
                              ).then((_) => _load()),
                            )),
                        const SizedBox(height: 8),
                      ],
                      if (biz.isNotEmpty) ...[
                        const Text('Businesses', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                        const SizedBox(height: 8),
                        ...biz.map((b) => _tile(
                              api.mediaUrl(asString(b['cover_url'], asString(b['cover_image']))),
                              asString(b['name']),
                              asString(b['type_name'], asString(b['address'])),
                              () => Navigator.push(
                                context,
                                MaterialPageRoute(builder: (_) => BusinessDetailScreen(id: asInt(b['id']))),
                              ).then((_) => _load()),
                            )),
                      ],
                    ],
                  ),
      ),
    );
  }
}
