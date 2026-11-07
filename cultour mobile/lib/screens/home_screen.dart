import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/ui.dart';
import 'bookings_screen.dart';
import 'destination_detail_screen.dart';
import 'explore_screen.dart';
import 'itineraries_screen.dart';
import 'notifications_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with LiveReloadMixin {
  late Future<_HomeData> _future;
  _HomeData? _cache;

  @override
  String liveKey(Session session) => session.homeVersion;

  @override
  void onLiveReload() {
    final next = _load();
    setState(() => _future = next);
  }

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_HomeData> _load() async {
    final api = context.read<Session>().api;
    final dests = await api.destinations();
    var favs = 0;
    var itins = 0;
    var books = 0;
    var pending = 0;
    var banners = <AnnouncementBanner>[];
    var cards = <AnnouncementCard>[];
    try {
      favs = (await api.favorites()).length;
    } catch (_) {}
    try {
      itins = (await api.itineraries()).where((i) => i.draft).length;
    } catch (_) {}
    try {
      final list = await api.myBookings();
      books = list.length;
      pending = list.where((b) => b.status.toLowerCase() == 'pending').length;
    } catch (_) {}
    try {
      final board = await api.announcements();
      banners = board.banners;
      cards = board.cards;
    } catch (_) {}
    final featured = dests.where((d) => d.featured).toList();
    return _HomeData(
      featured: featured.isEmpty ? dests.take(8).toList() : featured.take(8).toList(),
      favCount: favs,
      itinCount: itins,
      bookCount: books,
      pendingCount: pending,
      banners: banners,
      cards: cards,
    );
  }

  Future<void> _refresh() async {
    final next = _load();
    setState(() => _future = next);
    await next;
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<Session>().user!;
    final api = context.watch<Session>().api;
    return SafeArea(
      child: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<_HomeData>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasData) _cache = snap.data;
            final data = snap.data ?? _cache;
            if (data == null) {
              if (snap.hasError) {
                return ListView(children: [
                  EmptyState(icon: Icons.wifi_off, title: 'Could not load home', detail: snap.error.toString()),
                ]);
              }
              return const Center(child: CircularProgressIndicator());
            }
            final bannerUrls = data.banners
                .map((b) => api.mediaUrl(
                      (b.bannerImage != null && b.bannerImage!.isNotEmpty) ? b.bannerImage : b.bannerUrl,
                    ))
                .where((u) => u.isNotEmpty)
                .toList();
            return ListView(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Hello, ${user.firstName}', style: Theme.of(context).textTheme.headlineMedium),
                          const Text('What would you like to do in Ormoc?', style: TextStyle(color: CtColors.muted)),
                        ],
                      ),
                    ),
                    IconButton(
                      onPressed: () =>
                          Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen())),
                      icon: liveBadge(
                        count: context.watch<Session>().unreadNotifications,
                        child: const Icon(Icons.notifications_none_rounded),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 18),
                if (bannerUrls.isNotEmpty) ...[
                  _BannerCarousel(urls: bannerUrls),
                  const SizedBox(height: 18),
                ],
                _announcements(data.cards),
                const SizedBox(height: 14),
                _statGrid(context, data),
                const SizedBox(height: 18),
                Row(
                  children: [
                    Expanded(
                      child: _action(
                        'Plan a tour',
                        'Make an itinerary',
                        Icons.route_outlined,
                        () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ItinerariesScreen())),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _action(
                        'Explore',
                        'Places & shops',
                        Icons.explore_outlined,
                        () => Navigator.push(
                            context, MaterialPageRoute(builder: (_) => const ExploreScreen(asPage: true))),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 22),
                const Text('Featured', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                const SizedBox(height: 12),
                SizedBox(
                  height: 196,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: data.featured.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 12),
                    itemBuilder: (_, i) {
                      final p = data.featured[i];
                      final url = api.mediaUrl(p.coverUrl ?? p.coverImage);
                      return SizedBox(
                        width: 220,
                        child: InkWell(
                          borderRadius: BorderRadius.circular(16),
                          onTap: () => Navigator.push(
                              context, MaterialPageRoute(builder: (_) => DestinationDetailScreen(id: p.id))),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CoverImage(url: url, height: 128, radius: 16),
                              const SizedBox(height: 8),
                              Text(p.name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontWeight: FontWeight.w700)),
                              Text(p.location ?? p.categoryName ?? '',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(color: CtColors.muted, fontSize: 12)),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _announcements(List<AnnouncementCard> cards) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        color: const Color(0xB3102226),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0x66C9A227)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Announcements', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          if (cards.isEmpty)
            const Text('No announcements yet.', style: TextStyle(color: CtColors.muted))
          else
            ...cards.asMap().entries.map((entry) {
              final a = entry.value;
              final last = entry.key == cards.length - 1;
              return Padding(
                padding: EdgeInsets.only(bottom: last ? 0 : 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (a.publishedLabel.isNotEmpty)
                      Text(a.publishedLabel,
                          style: const TextStyle(color: CtColors.muted, fontSize: 12, fontWeight: FontWeight.w600)),
                    Text(a.title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                    if (a.body.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(a.body, style: const TextStyle(color: CtColors.muted, height: 1.4)),
                    ],
                    if (!last) ...[
                      const SizedBox(height: 12),
                      const Divider(height: 1, color: Color(0x33C9A227)),
                    ],
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }

  Widget _action(String title, String sub, IconData icon, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: CtDecor.card(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: CtColors.sea),
            const SizedBox(height: 12),
            Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
            Text(sub, style: const TextStyle(color: CtColors.muted, fontSize: 12)),
          ],
        ),
      ),
    );
  }

  Widget _statGrid(BuildContext context, _HomeData data) {
    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _statCard(
                Icons.favorite_outline,
                'Favorites',
                data.favCount,
                () => Navigator.push(context, MaterialPageRoute(builder: (_) => const FavoritesScreen())),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _statCard(
                Icons.signpost_outlined,
                'Itineraries',
                data.itinCount,
                () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ItinerariesScreen())),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _statCard(
                Icons.event_outlined,
                'Bookings',
                data.bookCount,
                () => Navigator.push(context, MaterialPageRoute(builder: (_) => const BookingsScreen())),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _statCard(
                Icons.hourglass_bottom_outlined,
                'Pending',
                data.pendingCount,
                () => Navigator.push(context, MaterialPageRoute(builder: (_) => const BookingsScreen())),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _statCard(IconData icon, String label, int value, VoidCallback onTap) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          decoration: CtDecor.card(),
          child: Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: CtColors.sea.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(icon, size: 18, color: CtColors.sea),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: CtColors.muted, fontSize: 12, fontWeight: FontWeight.w600),
                    ),
                    Text(
                      '$value',
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, height: 1.15),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BannerCarousel extends StatefulWidget {
  const _BannerCarousel({required this.urls});
  final List<String> urls;

  @override
  State<_BannerCarousel> createState() => _BannerCarouselState();
}

class _BannerCarouselState extends State<_BannerCarousel> {
  final _controller = PageController();
  int _page = 0;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    if (widget.urls.length > 1) {
      _timer = Timer.periodic(const Duration(seconds: 6), (_) {
        if (!mounted) return;
        final next = (_page + 1) % widget.urls.length;
        _controller.animateToPage(next, duration: const Duration(milliseconds: 420), curve: Curves.easeInOut);
      });
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(16),
      child: AspectRatio(
        aspectRatio: 16 / 10,
        child: Stack(
          fit: StackFit.expand,
          children: [
            PageView.builder(
              controller: _controller,
              itemCount: widget.urls.length,
              onPageChanged: (i) => setState(() => _page = i),
              itemBuilder: (_, i) => RemotePhoto(url: widget.urls[i], fit: BoxFit.cover),
            ),
            if (widget.urls.length > 1)
              Positioned(
                left: 0,
                right: 0,
                bottom: 10,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(widget.urls.length, (i) {
                    final on = i == _page;
                    return Container(
                      width: on ? 16 : 7,
                      height: 7,
                      margin: const EdgeInsets.symmetric(horizontal: 3),
                      decoration: BoxDecoration(
                        color: on ? Colors.white : Colors.white38,
                        borderRadius: BorderRadius.circular(8),
                      ),
                    );
                  }),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _HomeData {
  _HomeData({
    required this.featured,
    required this.favCount,
    required this.itinCount,
    required this.bookCount,
    required this.pendingCount,
    required this.banners,
    required this.cards,
  });
  final List<Place> featured;
  final int favCount;
  final int itinCount;
  final int bookCount;
  final int pendingCount;
  final List<AnnouncementBanner> banners;
  final List<AnnouncementCard> cards;
}
