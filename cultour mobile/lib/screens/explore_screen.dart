import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/ui.dart';
import 'business_detail_screen.dart';
import 'destination_detail_screen.dart';
import 'map_screen.dart';

class ExploreScreen extends StatefulWidget {
  const ExploreScreen({super.key, this.asPage = false});
  final bool asPage;

  @override
  State<ExploreScreen> createState() => _ExploreScreenState();
}

class _ExploreScreenState extends State<ExploreScreen> with SingleTickerProviderStateMixin, LiveReloadMixin {
  late TabController _tabs;
  late Future<List<Place>> _dests;
  late Future<List<Business>> _biz;
  List<Place>? _destCache;
  List<Business>? _bizCache;
  final _search = TextEditingController();
  String _query = '';
  final Set<int> _favDest = {};
  final Set<int> _favBiz = {};
  final Map<String, String> _favNotes = {};

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 3, vsync: this);
    _tabs.addListener(() {
      if (!_tabs.indexIsChanging) setState(() {});
    });
    _reload();
  }

  @override
  String liveKey(Session session) => session.exploreVersion;

  @override
  void onLiveReload() {
    setState(_reload);
  }

  void _reload() {
    final api = context.read<Session>().api;
    _dests = api.destinations(q: _query);
    _biz = api.businesses();
    _loadFavs();
  }

  Future<void> _loadFavs() async {
    try {
      final res = await context.read<Session>().api.getJson('/favorites/list.php');
      if (!mounted) return;
      setState(() {
        _favDest
          ..clear()
          ..addAll(asList(res['data']).map((e) => asInt(asMap(e)['id'])));
        _favBiz
          ..clear()
          ..addAll(asList(res['businesses']).map((e) => asInt(asMap(e)['id'])));
      });
    } catch (_) {}
  }

  Future<void> _toggleDest(Place place) async {
    try {
      final on = await context.read<Session>().api.toggleFavorite(place.id);
      if (!mounted) return;
      setState(() {
        if (on) {
          _favDest.add(place.id);
        } else {
          _favDest.remove(place.id);
        }
        _favNotes['d:${place.id}'] = on ? 'Added to favorite' : 'Removed from favorites';
      });
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    }
  }

  Future<void> _toggleBiz(Business biz) async {
    try {
      final on = await context.read<Session>().api.toggleBusinessFavorite(biz.id);
      if (!mounted) return;
      setState(() {
        if (on) {
          _favBiz.add(biz.id);
        } else {
          _favBiz.remove(biz.id);
        }
        _favNotes['b:${biz.id}'] = on ? 'Added to favorite' : 'Removed from favorites';
      });
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    }
  }

  Widget _heart({required bool on, required String? note, required VoidCallback toggle}) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          onPressed: toggle,
          icon: Icon(on ? Icons.favorite : Icons.favorite_border, color: on ? CtColors.coral : CtColors.muted),
        ),
        if (note != null)
          Text(note, style: const TextStyle(color: CtColors.gold, fontSize: 10, fontWeight: FontWeight.w700)),
      ],
    );
  }

  @override
  void dispose() {
    _tabs.dispose();
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final body = Column(
      children: [
        if (_tabs.index < 2)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: TextField(
              controller: _search,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'Search Ormoc places…',
              ),
              onSubmitted: (v) => setState(() {
                _query = v.trim();
                _reload();
              }),
            ),
          ),
        TabBar(
          controller: _tabs,
          labelColor: CtColors.ink,
          unselectedLabelColor: CtColors.muted,
          indicatorColor: CtColors.sea,
          tabs: const [Tab(text: 'Places'), Tab(text: 'Businesses'), Tab(text: 'Map')],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabs,
            children: [
              _destList(),
              _bizList(),
              const MapScreen(),
            ],
          ),
        ),
      ],
    );

    if (widget.asPage) {
      return Scaffold(appBar: AppBar(title: const Text('Explore')), body: SafeArea(child: body));
    }
    return SafeArea(child: body);
  }

  Widget _destList() {
    final api = context.watch<Session>().api;
    return RefreshIndicator(
      onRefresh: () async => setState(_reload),
      child: FutureBuilder<List<Place>>(
        future: _dests,
        builder: (context, snap) {
          if (snap.hasData) _destCache = snap.data;
          if (snap.hasError && _destCache == null) {
            return Center(
                child: EmptyState(icon: Icons.wifi_off, title: 'Could not load places', detail: snap.error.toString()));
          }
          final items = snap.data ?? _destCache;
          if (items == null) {
            return const Center(child: CircularProgressIndicator());
          }
          if (items.isEmpty) {
            return ListView(children: const [EmptyState(icon: Icons.place_outlined, title: 'No destinations found')]);
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
            itemCount: items.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (_, i) {
              final p = items[i];
              return ListTile(
                tileColor: CtColors.surface,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                contentPadding: const EdgeInsets.fromLTRB(8, 6, 12, 6),
                leading: SizedBox(
                  width: 64,
                  child: CoverImage(url: api.mediaUrl(p.coverUrl ?? p.coverImage), height: 64, radius: 10),
                ),
                title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(
                  [p.categoryName, p.location].where((e) => (e ?? '').isNotEmpty).join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                trailing: _heart(
                  on: _favDest.contains(p.id),
                  note: _favNotes['d:${p.id}'],
                  toggle: () => _toggleDest(p),
                ),
                onTap: () =>
                    Navigator.push(context, MaterialPageRoute(builder: (_) => DestinationDetailScreen(id: p.id))),
              );
            },
          );
        },
      ),
    );
  }

  Widget _bizList() {
    final api = context.watch<Session>().api;
    return RefreshIndicator(
      onRefresh: () async => setState(_reload),
      child: FutureBuilder<List<Business>>(
        future: _biz,
        builder: (context, snap) {
          if (snap.hasData) _bizCache = snap.data;
          if (snap.hasError && _bizCache == null) {
            return Center(
                child: EmptyState(
                    icon: Icons.wifi_off, title: 'Could not load businesses', detail: snap.error.toString()));
          }
          final loaded = snap.data ?? _bizCache;
          if (loaded == null) return const Center(child: CircularProgressIndicator());
          var items = loaded;
          if (_query.isNotEmpty) {
            final q = _query.toLowerCase();
            items = items
                .where((b) => b.name.toLowerCase().contains(q) || (b.address ?? '').toLowerCase().contains(q))
                .toList();
          }
          if (items.isEmpty) {
            return ListView(
                children: const [EmptyState(icon: Icons.storefront_outlined, title: 'No businesses found')]);
          }
          return ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: items.length,
            itemBuilder: (_, i) {
              final b = items[i];
              return Card(
                margin: const EdgeInsets.only(bottom: 12),
                child: ListTile(
                  contentPadding: const EdgeInsets.all(10),
                  leading: SizedBox(
                    width: 64,
                    child: CoverImage(url: api.mediaUrl(b.coverUrl ?? b.coverImage), height: 64, radius: 10),
                  ),
                  title: Text(b.name),
                  subtitle: Text([b.typeName, b.address].where((e) => (e ?? '').isNotEmpty).join(' · ')),
                  trailing: _heart(
                    on: _favBiz.contains(b.id),
                    note: _favNotes['b:${b.id}'],
                    toggle: () => _toggleBiz(b),
                  ),
                  onTap: () =>
                      Navigator.push(context, MaterialPageRoute(builder: (_) => BusinessDetailScreen(id: b.id))),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
