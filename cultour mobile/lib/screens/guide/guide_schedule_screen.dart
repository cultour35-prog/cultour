import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../live_reload.dart';
import '../../session.dart';
import '../../theme.dart';
import '../../util/jsonx.dart';
import '../../widgets/kit.dart';
import '../../widgets/ui.dart';
import '../booking_detail_screen.dart';
import 'guide_checkin_screen.dart';

/// Month calendar of tours (tourguide/schedule.php).
class GuideScheduleScreen extends StatefulWidget {
  const GuideScheduleScreen({super.key});

  @override
  State<GuideScheduleScreen> createState() => _GuideScheduleScreenState();
}

class _GuideScheduleScreenState extends State<GuideScheduleScreen> with LiveReloadMixin {
  Map<String, dynamic>? _data;
  Object? _error;
  String _month = '';
  String? _selected;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  String liveKey(Session session) => session.bookingsVersion;

  @override
  void onLiveReload() => _load();

  Future<void> _load([String? month]) async {
    try {
      final res = await context.read<Session>().api.getJson(
            '/guide/schedule.php',
            (month ?? _month).isEmpty ? null : {'month': month ?? _month},
          );
      if (!mounted) return;
      final data = asMap(res['data']);
      setState(() {
        _data = data;
        _month = asString(data['month']);
        _error = null;
        _selected ??= asString(data['today']);
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Color _tone(String tone) {
    switch (tone) {
      case 'ongoing':
        return CtColors.gold;
      case 'completed':
        return CtColors.muted;
      default:
        return CtColors.sea;
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return Scaffold(
      appBar: AppBar(title: const Text('Schedule')),
      body: d == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load schedule', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _load, child: _body(d)),
    );
  }

  Widget _body(Map<String, dynamic> d) {
    final firstDow = asInt(d['first_dow']);
    final daysIn = asInt(d['days_in_month'], 30);
    final dates = asMap(d['dates']);
    final today = asString(d['today']);
    final month = asString(d['month']);
    final cells = <Widget>[];
    for (final l in const ['S', 'M', 'T', 'W', 'T', 'F', 'S']) {
      cells.add(Center(child: Text(l, style: const TextStyle(color: CtColors.muted, fontWeight: FontWeight.w700))));
    }
    for (var i = 0; i < firstDow; i++) {
      cells.add(const SizedBox.shrink());
    }
    for (var day = 1; day <= daysIn; day++) {
      final key = '$month-${day.toString().padLeft(2, '0')}';
      final items = asList(dates[key]).map(asMap).toList();
      final selected = key == _selected;
      cells.add(InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () => setState(() => _selected = key),
        child: Container(
          decoration: BoxDecoration(
            color: selected ? CtColors.sea.withOpacity(0.25) : null,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: key == today ? CtColors.gold : Colors.transparent),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text('$day', style: TextStyle(fontWeight: selected ? FontWeight.w800 : FontWeight.w500)),
              const SizedBox(height: 3),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: items
                    .take(3)
                    .map((b) => Container(
                          width: 6,
                          height: 6,
                          margin: const EdgeInsets.symmetric(horizontal: 1),
                          decoration: BoxDecoration(color: _tone(asString(b['tone'])), shape: BoxShape.circle),
                        ))
                    .toList(),
              ),
            ],
          ),
        ),
      ));
    }
    final selectedItems = asList(dates[_selected ?? '']).map(asMap).toList();

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
      children: [
        Row(
          children: [
            IconButton(onPressed: () => _load(asString(d['prev'])), icon: const Icon(Icons.chevron_left)),
            Expanded(
              child: Text(asString(d['month_label']),
                  textAlign: TextAlign.center, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            ),
            IconButton(onPressed: () => _load(asString(d['next'])), icon: const Icon(Icons.chevron_right)),
          ],
        ),
        GridView.count(
          crossAxisCount: 7,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          childAspectRatio: 0.95,
          mainAxisSpacing: 4,
          crossAxisSpacing: 4,
          children: cells,
        ),
        const SizedBox(height: 8),
        const Wrap(spacing: 12, children: [
          _Legend(color: CtColors.sea, label: 'Upcoming'),
          _Legend(color: CtColors.gold, label: 'Ongoing'),
          _Legend(color: CtColors.muted, label: 'Completed'),
        ]),
        const SizedBox(height: 16),
        Text(_selected ?? '', style: const TextStyle(color: CtColors.muted)),
        const SizedBox(height: 8),
        if (selectedItems.isEmpty) const Text('No tours on this day.', style: TextStyle(color: CtColors.muted)),
        ...selectedItems.map((b) => Card(
              margin: const EdgeInsets.only(bottom: 10),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Expanded(
                        child: Text(asString(b['title']), style: const TextStyle(fontWeight: FontWeight.w800)),
                      ),
                      StatusChip(asString(b['tone']), tone: _tone(asString(b['tone']))),
                    ]),
                    const SizedBox(height: 4),
                    Text(
                      [
                        asString(b['booking_code']),
                        if (asInt(b['day_count']) > 1) 'Day ${asInt(b['day'])} of ${asInt(b['day_count'])}',
                        asString(b['time']),
                        asString(b['tourist']),
                        '${asInt(b['party_size'], 1)} guest(s)',
                      ].where((e) => e.isNotEmpty).join(' · '),
                      style: const TextStyle(color: CtColors.muted, fontSize: 13),
                    ),
                    if (asString(b['busy_label']).isNotEmpty)
                      Text(asString(b['busy_label']), style: const TextStyle(fontSize: 13)),
                    if (asBool(b['is_pwd'])) ...[
                      const SizedBox(height: 6),
                      const StatusChip('Accessibility needs', tone: CtColors.gold),
                    ],
                    const SizedBox(height: 10),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      OutlinedButton(
                        style: compactOutlined(),
                        onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => BookingDetailScreen(
                              bookingId: asInt(b['booking_id']),
                              code: asString(b['booking_code']),
                            ),
                          ),
                        ),
                        child: const Text('View details'),
                      ),
                      if (asBool(b['can_checkin']))
                        FilledButton.icon(
                          style: compactFilled(),
                          onPressed: () => Navigator.push(
                            context,
                            MaterialPageRoute(builder: (_) => const GuideCheckinScreen(standalone: true)),
                          ),
                          icon: const Icon(Icons.photo_camera_outlined, size: 18),
                          label: const Text('Check in'),
                        ),
                    ]),
                  ],
                ),
              ),
            )),
      ],
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.color, required this.label});

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
      const SizedBox(width: 4),
      Text(label, style: const TextStyle(color: CtColors.muted, fontSize: 12)),
    ]);
  }
}
