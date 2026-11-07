import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../live_reload.dart';
import '../../models.dart';
import '../../session.dart';
import '../../theme.dart';
import '../../util/jsonx.dart';
import '../../widgets/kit.dart';
import '../../widgets/ui.dart';
import '../booking_detail_screen.dart';
import '../notifications_screen.dart';
import 'guide_availability_screen.dart';
import 'guide_checkin_screen.dart';
import 'guide_schedule_screen.dart';
import 'guide_tours_screen.dart';

/// Guide dashboard (tourguide/dashboard.php + ratings.php).
class GuideHomeScreen extends StatefulWidget {
  const GuideHomeScreen({super.key});

  @override
  State<GuideHomeScreen> createState() => _GuideHomeScreenState();
}

class _GuideHomeScreenState extends State<GuideHomeScreen> with LiveReloadMixin {
  Map<String, dynamic>? _data;
  List<AnnouncementCard> _news = [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  String liveKey(Session session) => '${session.bookingsVersion}|${session.homeVersion}';

  @override
  void onLiveReload() => _load();

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final res = await api.getJson('/guide/dashboard.php');
      List<AnnouncementCard> news = _news;
      try {
        news = (await api.announcements()).cards;
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _data = asMap(res['data']);
        _news = news;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _go(Widget page) => Navigator.push(context, MaterialPageRoute(builder: (_) => page)).then((_) => _load());

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user!;
    final d = _data;
    return Scaffold(
      appBar: AppBar(
        title: Text('Hi, ${user.firstName}'),
        actions: [
          IconButton(
            icon: Badge(
              isLabelVisible: session.unreadNotifications > 0,
              label: Text('${session.unreadNotifications}'),
              child: const Icon(Icons.notifications_outlined),
            ),
            onPressed: () => _go(const NotificationsScreen()),
          ),
        ],
      ),
      body: d == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load your dashboard', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
                children: [
                  if (asString(d['approval_status']).isNotEmpty && asString(d['approval_status']) != 'approved')
                    NoticeBox(
                        'Your guide profile is ${asString(d['approval_status'])}. The Tourism Office reviews new guides before tourists can book them.'),
                  if (!asBool(d['has_schedule'], true))
                    NoticeBox(
                      'Tourists cannot book you until you set weekly hours.',
                      action: OutlinedButton(
                        style: compactOutlined(),
                        onPressed: () => _go(const GuideAvailabilityScreen()),
                        child: const Text('Set availability'),
                      ),
                    ),
                  _stats(asMap(d['stats'])),
                  const SizedBox(height: 14),
                  _actions(),
                  const SizedBox(height: 14),
                  SectionCard(
                    title: 'Upcoming tours',
                    child: asList(d['upcoming']).isEmpty
                        ? const Text('No upcoming tours.', style: TextStyle(color: CtColors.muted))
                        : Column(
                            children: asList(d['upcoming']).map(asMap).map((u) {
                              final tone = asString(u['tone']);
                              return ListTile(
                                contentPadding: EdgeInsets.zero,
                                title: Text(asString(u['title']), style: const TextStyle(fontWeight: FontWeight.w700)),
                                subtitle: Text(
                                  '${asString(u['date_label'])} · ${asString(u['meetup'])}\n${asString(u['booking_code'])} · ${asString(u['tourist'])}',
                                ),
                                isThreeLine: true,
                                trailing: StatusChip(
                                  asString(u['tone_label'], tone),
                                  tone: tone == 'ongoing' ? CtColors.gold : CtColors.sea,
                                ),
                                onTap: () => _go(BookingDetailScreen(
                                  bookingId: asInt(u['booking_id']),
                                  code: asString(u['booking_code']),
                                )),
                              );
                            }).toList(),
                          ),
                  ),
                  if (_news.isNotEmpty)
                    SectionCard(
                      title: 'Announcements',
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: _news
                            .take(5)
                            .map((n) => Padding(
                                  padding: const EdgeInsets.only(bottom: 12),
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(n.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                                      if (n.publishedLabel.isNotEmpty)
                                        Text(n.publishedLabel,
                                            style: const TextStyle(color: CtColors.muted, fontSize: 12)),
                                      const SizedBox(height: 4),
                                      Text(n.body, maxLines: 4, overflow: TextOverflow.ellipsis),
                                    ],
                                  ),
                                ))
                            .toList(),
                      ),
                    ),
                  SectionCard(
                    title: 'Ratings',
                    child: asList(d['ratings']).isEmpty
                        ? const Text('No ratings yet. Tourists rate you after a completed tour.',
                            style: TextStyle(color: CtColors.muted))
                        : Column(
                            children: asList(d['ratings']).map(asMap).map((r) {
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
                                            color: CtColors.gold, size: 18),
                                      ),
                                      const SizedBox(width: 8),
                                      Expanded(
                                        child: Text(asString(r['tourist']),
                                            style: const TextStyle(fontWeight: FontWeight.w700),
                                            overflow: TextOverflow.ellipsis),
                                      ),
                                    ]),
                                    Text('${asString(r['title'])} · ${asString(r['date_label'])}',
                                        style: const TextStyle(color: CtColors.muted, fontSize: 12)),
                                    if (asString(r['comment']).isNotEmpty) ...[
                                      const SizedBox(height: 4),
                                      Text('“${asString(r['comment'])}”'),
                                    ],
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

  Widget _stats(Map<String, dynamic> s) {
    Widget tile(String label, String value, IconData icon, Color tone) => Expanded(
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: CtColors.surface,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: tone.withOpacity(0.35)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, color: tone, size: 20),
                const SizedBox(height: 6),
                Text(value, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                Text(label, style: const TextStyle(color: CtColors.muted, fontSize: 12)),
              ],
            ),
          ),
        );
    final rating = asDouble(s['rating']);
    return Column(children: [
      Row(children: [
        tile('Pending', '${asInt(s['pending'])}', Icons.hourglass_top, CtColors.gold),
        const SizedBox(width: 10),
        tile('Upcoming', '${asInt(s['upcoming'])}', Icons.event_available, CtColors.sea),
      ]),
      const SizedBox(height: 10),
      Row(children: [
        tile('Completed', '${asInt(s['completed'])}', Icons.check_circle_outline, CtColors.teal),
        const SizedBox(width: 10),
        tile('Rating', rating > 0 ? rating.toStringAsFixed(2) : '—', Icons.star_outline, CtColors.gold),
      ]),
    ]);
  }

  Widget _actions() {
    Widget action(IconData icon, String label, Widget page) => Expanded(
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () => _go(page),
            child: Container(
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(color: CtColors.tealDark, borderRadius: BorderRadius.circular(14)),
              child: Column(children: [
                Icon(icon, color: CtColors.ink),
                const SizedBox(height: 6),
                Text(label,
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600), textAlign: TextAlign.center),
              ]),
            ),
          ),
        );
    return Row(children: [
      action(Icons.photo_camera_outlined, 'Check-in', const GuideCheckinScreen(standalone: true)),
      const SizedBox(width: 8),
      action(Icons.calendar_month_outlined, 'Schedule', const GuideScheduleScreen()),
      const SizedBox(width: 8),
      action(Icons.schedule_outlined, 'Availability', const GuideAvailabilityScreen()),
      const SizedBox(width: 8),
      action(Icons.route_outlined, 'My tours', const GuideToursScreen()),
    ]);
  }
}
