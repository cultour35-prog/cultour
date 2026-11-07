import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../live_reload.dart';
import '../../session.dart';
import '../../theme.dart';
import '../../util/jsonx.dart';
import '../../widgets/kit.dart';
import '../../widgets/ui.dart';
import '../chat_screens.dart';

const _ormoc = LatLng(11.0064, 124.6075);
const _pinDone = Color(0xFF2F6FED);
const _pinCurrent = Color(0xFF1AAD6F);
const _pinNext = Color(0xFFE0B341);
const _pinSkipped = Color(0xFFE04545);

/// Today's tours with the step-by-step check-in (start tour → meetup → each stop → end day).
class GuideCheckinScreen extends StatefulWidget {
  const GuideCheckinScreen({super.key, this.standalone = false});

  final bool standalone;

  @override
  State<GuideCheckinScreen> createState() => _GuideCheckinScreenState();
}

class _GuideCheckinScreenState extends State<GuideCheckinScreen> with LiveReloadMixin {
  Map<String, dynamic>? _board;
  Object? _error;
  final Map<int, int> _focus = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  String liveKey(Session session) => session.bookingsVersion;

  @override
  void onLiveReload() => _load();

  void _syncFocus({bool preferCurrent = false}) {
    for (final t in asList(_board?['tours']).map(asMap)) {
      final bid = asInt(t['booking_id']);
      final items = asList(t['items']).map(asMap).toList();
      if (items.isEmpty) continue;
      final ids = {for (final i in items) asInt(i['item_id'])};
      Map<String, dynamic>? current;
      for (final i in items) {
        if (asString(i['state']) == 'current') {
          current = i;
          break;
        }
      }
      if (preferCurrent && current != null) {
        _focus[bid] = asInt(current['item_id']);
        continue;
      }
      if (_focus[bid] != null && ids.contains(_focus[bid])) continue;
      _focus[bid] = asInt((current ?? items.first)['item_id']);
    }
  }

  Future<void> _load() async {
    try {
      final res = await context.read<Session>().api.getJson('/guide/checkin.php');
      if (!mounted) return;
      setState(() {
        _board = asMap(res['data']);
        _error = null;
        _syncFocus();
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _post(Map<String, dynamic> body, {PickedPhoto? photo}) async {
    final api = context.read<Session>().api;
    final res = await runApi(
      context,
      () => photo != null
          ? api.multipart(
              '/guide/checkin.php',
              body,
              const {},
              fileBytes: {'photo': photo.bytes},
              fileNames: {'photo': photo.name},
            )
          : api.postJson('/guide/checkin.php', body),
    );
    if (!mounted) return;
    if (res != null && res['data'] != null) {
      setState(() {
        _board = asMap(res['data']);
        _syncFocus(preferCurrent: true);
      });
    } else {
      await _load();
    }
  }

  Future<void> _checkin(Map<String, dynamic> tour, Map<String, dynamic> item) async {
    String? ticketScan;
    if (asBool(item['is_meetup']) || asInt(item['item_id']) == 0) {
      ticketScan = await askMeetupTicket(
        context,
        bookingId: asInt(tour['booking_id']),
        bookingCode: asString(tour['booking_code']),
      );
      if (ticketScan == null || !mounted) return;
    }
    await _photoAndSubmit(tour, item, ticketScan);
  }

  Future<void> _photoAndSubmit(Map<String, dynamic> tour, Map<String, dynamic> item, String? ticketScan) async {
    final photo = await pickPhotoAsset(context, cameraOnly: false);
    if (photo == null || !mounted) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: Text('Check in at ${asString(item['name'])}'),
        content: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: Image.memory(photo.bytes, height: 260, fit: BoxFit.cover),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Retake')),
          FilledButton(
              style: compactFilled(), onPressed: () => Navigator.pop(ctx, true), child: const Text('Check in here')),
        ],
      ),
    );
    if (ok == true) {
      await _post({
        'action': 'checkin',
        'booking_id': asInt(tour['booking_id']),
        'itinerary_item_id': asInt(item['item_id']),
        if (ticketScan != null) 'ticket_scan': ticketScan,
      }, photo: photo);
    } else if (ok == false && mounted) {
      await _photoAndSubmit(tour, item, ticketScan);
    }
  }

  Future<void> _skip(Map<String, dynamic> tour, Map<String, dynamic> item) async {
    final reasons = asList(_board?['skip_reasons']).map((e) => '$e').toList();
    final pick = await askReason(
      context,
      title: 'Skip ${asString(item['name'])}?',
      lead: 'The tourist sees this reason on their tour progress.',
      presets: reasons.isEmpty ? const ['Not available right now', 'Closed', 'Could not reach it', 'Other'] : reasons,
      confirmLabel: 'Skip this stop',
    );
    if (pick == null) return;
    await _post({
      'action': 'skip_stop',
      'booking_id': asInt(tour['booking_id']),
      'itinerary_item_id': asInt(item['item_id']),
      'reason_preset': pick.preset,
      'reason_detail': pick.detail,
    });
  }

  Future<void> _end(Map<String, dynamic> tour) async {
    final later = asBool(tour['has_later_days']);
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: CtColors.surface,
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.8,
        builder: (ctx, controller) => ListView(
          controller: controller,
          padding: const EdgeInsets.all(20),
          children: [
            const Text('Tour summary', style: TextStyle(color: CtColors.muted)),
            Text(asString(tour['title']), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            Text('Code ${asString(tour['booking_code'])} · ${asString(tour['tourist_name'])}',
                style: const TextStyle(color: CtColors.muted)),
            const SizedBox(height: 12),
            ...asList(tour['items']).map(asMap).map((it) => _summaryRow(it)),
            const SizedBox(height: 16),
            Text(
              later ? 'This ends today only. Later days stay scheduled.' : 'This ends the tour.',
              style: const TextStyle(color: CtColors.muted),
            ),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(later ? 'Confirm end day' : 'Confirm end tour'),
            ),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Back')),
          ],
        ),
      ),
    );
    if (ok == true) {
      await _post({
        'action': 'end_tour',
        'booking_id': asInt(tour['booking_id']),
        'visit_date': asString(_board?['date']),
      });
    }
  }

  Widget _summaryRow(Map<String, dynamic> it) {
    final state = asString(it['state']);
    final photo = asString(it['photo_url']);
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 72,
            child: photo.isNotEmpty
                ? AuthImage(photo, height: 56)
                : Container(
                    height: 56,
                    decoration: BoxDecoration(color: CtColors.tealDark, borderRadius: BorderRadius.circular(12)),
                    child: Icon(state == 'skipped' ? Icons.redo : Icons.place_outlined, color: CtColors.muted),
                  ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${asString(it['label'])} · ${asString(it['name'])}',
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(
                  state == 'skipped'
                      ? 'Skipped · ${asString(it['skip_reason'])}'
                      : [asString(it['scheduled']), asString(it['checked_in_label'])]
                          .where((e) => e.isNotEmpty)
                          .join(' · checked in '),
                  style: const TextStyle(color: CtColors.muted, fontSize: 13),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final b = _board;
    final tours = asList(b?['tours']).map(asMap).toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Tour check-in')),
      body: b == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load today\'s tours', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
                children: [
                  Text('Today · ${asString(b['date_label'])}', style: const TextStyle(color: CtColors.muted)),
                  const SizedBox(height: 10),
                  if (tours.isEmpty)
                    const EmptyState(
                      icon: Icons.tour_outlined,
                      title: 'No tours today',
                      detail: 'Approved tours show here on their tour day so you can check in at each stop.',
                    ),
                  ...tours.map(_tourCard),
                ],
              ),
            ),
    );
  }

  Widget _tourCard(Map<String, dynamic> t) {
    final items = asList(t['items']).map(asMap).toList();
    final started = asBool(t['tour_started']);
    final canSubmit = asBool(t['can_submit']);
    final allDone = asBool(t['all_done']);
    final dayEnded = asBool(t['day_ended']);
    final done = items.where((i) => ['done', 'skipped'].contains(asString(i['state']))).length;
    final bid = asInt(t['booking_id']);
    final focusId = _focus[bid] ?? (items.isEmpty ? 0 : asInt(items.first['item_id']));
    Map<String, dynamic>? selected;
    for (final it in items) {
      if (asInt(it['item_id']) == focusId) {
        selected = it;
        break;
      }
    }
    selected ??= items.isEmpty ? null : items.first;

    Widget? banner;
    if (allDone && dayEnded) {
      banner = const NoticeBox('This day is ended. Later days stay on the tour.',
          tone: CtColors.sea, icon: Icons.check_circle_outline);
    } else if (allDone && canSubmit) {
      banner = NoticeBox(
        asBool(t['has_later_days'])
            ? 'All stops checked in. Open the summary, then end this day only. Later days stay scheduled.'
            : 'All stops checked in. Open the summary to review times and photos, then end the tour.',
        tone: CtColors.sea,
        icon: Icons.flag_outlined,
        action: AsyncButton(label: asString(t['end_label'], 'End tour'), onPressed: () => _end(t)),
      );
    } else if (!started && canSubmit) {
      banner = NoticeBox(
        'Start the tour first. After that, check in at the meetup, then each stop.',
        icon: Icons.play_circle_outline,
        action: AsyncButton(
          label: 'Start tour',
          busyLabel: 'Starting…',
          icon: Icons.play_arrow,
          onPressed: () => _post({'action': 'start_tour', 'booking_id': asInt(t['booking_id'])}),
        ),
      );
    } else if (!canSubmit && !allDone) {
      banner = const NoticeBox('Check-in for this tour is not open for you right now.');
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Expanded(
                child: Text(asString(t['title']), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              ),
              StatusChip('$done / ${items.length} checked in', tone: CtColors.sea),
            ]),
            const SizedBox(height: 4),
            Text(
              '${asString(t['booking_code'])} · ${asString(t['tourist_name'])} · ${asInt(t['party_size'], 1)} guest(s) · Meetup ${asString(t['meetup_label'])}',
              style: const TextStyle(color: CtColors.muted, fontSize: 13),
            ),
            if (asBool(t['is_pwd'])) ...[
              const SizedBox(height: 6),
              NoticeBox(
                asString(t['pwd_notes']).isEmpty
                    ? 'Accessibility needs'
                    : 'Accessibility needs · ${asString(t['pwd_notes'])}',
                icon: Icons.accessible,
              ),
            ],
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => ChatThreadScreen(
                      peerId: asInt(t['tourist_user_id']),
                      peerName: asString(t['tourist_name']),
                    ),
                  ),
                ),
                icon: const Icon(Icons.chat_bubble_outline, size: 18),
                label: const Text('Message tourist'),
              ),
            ),
            if (banner != null) ...[const SizedBox(height: 6), banner, const SizedBox(height: 10)],
            const Text('Today’s route', style: TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: 2),
            const Text(
              'Blue is done, green is current, yellow is next, red is skipped. Tap a pin to open that stop.',
              style: TextStyle(color: CtColors.muted, fontSize: 12),
            ),
            const SizedBox(height: 8),
            ClipRRect(
              borderRadius: BorderRadius.circular(14),
              child: SizedBox(
                height: 340,
                child: _TourRouteMap(
                  items: items,
                  selectedId: focusId,
                  onSelect: (id) => setState(() => _focus[bid] = id),
                ),
              ),
            ),
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final it in items) ...[
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        selected: asInt(it['item_id']) == focusId,
                        label: Text('${_pinLabel(it)}  ${asString(it['name'])}'),
                        avatar: CircleAvatar(
                          backgroundColor: _pinColor(it),
                          child: Text(
                            _pinLabel(it),
                            style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w800),
                          ),
                        ),
                        onSelected: (_) => setState(() => _focus[bid] = asInt(it['item_id'])),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (selected != null) _item(t, selected),
          ],
        ),
      ),
    );
  }

  Widget _item(Map<String, dynamic> t, Map<String, dynamic> it) {
    final state = asString(it['state']);
    final photo = asString(it['photo_url']);
    final lat = asDoubleOrNull(it['lat']);
    final lng = asDoubleOrNull(it['lng']);
    final current = state == 'current' || state == 'wait';
    final sub = [asString(it['scheduled']), asString(it['location'])].where((e) => e.isNotEmpty).join(' · ');

    Widget body;
    switch (state) {
      case 'done':
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (photo.isNotEmpty) ...[const SizedBox(height: 6), AuthImage(photo, height: 170)],
          const SizedBox(height: 6),
          Row(children: [
            const StatusChip('Checked in', tone: CtColors.sea),
            const SizedBox(width: 8),
            Text(asString(it['checked_in_label']), style: const TextStyle(color: CtColors.muted, fontSize: 13)),
          ]),
        ]);
        break;
      case 'skipped':
        body = Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            const StatusChip('Skipped', tone: CtColors.gold),
            Text(
              [asString(it['skip_reason']), asString(it['checked_in_label'])].where((e) => e.isNotEmpty).join(' · '),
              style: const TextStyle(color: CtColors.muted, fontSize: 13),
            ),
          ]),
        );
        break;
      case 'not_started':
        body = const Padding(
          padding: EdgeInsets.only(top: 6),
          child: Text('Start the tour first. Check-in at the meetup comes after that.',
              style: TextStyle(color: CtColors.muted, fontSize: 13)),
        );
        break;
      case 'locked':
        body = const Padding(
          padding: EdgeInsets.only(top: 6),
          child: Text('Check in the earlier stop first. You can start this one as soon as that check-in is done.',
              style: TextStyle(color: CtColors.muted, fontSize: 13)),
        );
        break;
      case 'wait':
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: const EdgeInsets.only(top: 6, bottom: 8),
            child: Text(
              'This stop is scheduled at ${asString(it['wait_until'])}. If you arrived early, start the stop, then check in with a photo.',
              style: const TextStyle(color: CtColors.muted, fontSize: 13),
            ),
          ),
          Wrap(spacing: 8, runSpacing: 8, children: [
            AsyncButton(
              label: 'Start this stop',
              icon: Icons.play_arrow,
              onPressed: () => _post({
                'action': 'start_stop',
                'booking_id': asInt(t['booking_id']),
                'itinerary_item_id': asInt(it['item_id']),
              }),
            ),
            AsyncButton(
              label: 'Skip this stop',
              outlined: true,
              color: CtColors.gold,
              onPressed: () => _skip(t, it),
            ),
          ]),
        ]);
        break;
      case 'current':
        final meetup = asBool(it['is_meetup']);
        body = Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Wrap(spacing: 8, runSpacing: 8, children: [
            if (meetup)
              FilledButton.icon(
                style: compactFilled(),
                onPressed: () => _checkin(t, it),
                icon: const Icon(Icons.qr_code_scanner, size: 18),
                label: const Text('Scan ticket'),
              )
            else
              AsyncButton(
                label: 'Check in here',
                busyLabel: 'Uploading…',
                icon: Icons.photo_camera_outlined,
                onPressed: () => _checkin(t, it),
              ),
            AsyncButton(
              label: 'Skip this stop',
              outlined: true,
              color: CtColors.gold,
              onPressed: () => _skip(t, it),
            ),
          ]),
        );
        break;
      default:
        body = const Padding(
          padding: EdgeInsets.only(top: 6),
          child: Text('Not checked in yet.', style: TextStyle(color: CtColors.muted, fontSize: 13)),
        );
    }

    return Container(
      margin: const EdgeInsets.only(top: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: current ? CtColors.sea.withOpacity(0.10) : null,
        border: Border.all(color: current ? CtColors.sea : CtColors.line),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(asString(it['label']).toUpperCase(),
                        style: const TextStyle(
                            color: CtColors.gold, fontSize: 11, fontWeight: FontWeight.w800, letterSpacing: 0.8)),
                    Text(asString(it['name']), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                    if (asBool(it['is_meetup']) && state == 'current')
                      const Text('Scan the tourist ticket QR first. A ticket for a different tour is rejected.',
                          style: TextStyle(color: CtColors.muted, fontSize: 13)),
                    if (sub.isNotEmpty) Text(sub, style: const TextStyle(color: CtColors.muted, fontSize: 13)),
                  ],
                ),
              ),
              if (lat != null && lng != null && lat != 0 && lng != 0)
                IconButton(
                  tooltip: 'Directions',
                  icon: const Icon(Icons.directions_outlined),
                  onPressed: () => launchUrl(
                    Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng'),
                    mode: LaunchMode.externalApplication,
                  ),
                ),
            ],
          ),
          body,
        ],
      ),
    );
  }
}

Color _pinColor(Map<String, dynamic> it) {
  switch (asString(it['state'])) {
    case 'done':
      return _pinDone;
    case 'skipped':
      return _pinSkipped;
    case 'current':
      return _pinCurrent;
    default:
      return _pinNext;
  }
}

String _pinLabel(Map<String, dynamic> it) {
  if (asBool(it['is_meetup']) || asInt(it['item_id']) == 0) return 'M';
  final m = RegExp(r'(\d+)').firstMatch(asString(it['label']));
  return m?.group(1) ?? '•';
}

class _TourRouteMap extends StatefulWidget {
  const _TourRouteMap({
    required this.items,
    required this.selectedId,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> items;
  final int selectedId;
  final ValueChanged<int> onSelect;

  @override
  State<_TourRouteMap> createState() => _TourRouteMapState();
}

class _TourRouteMapState extends State<_TourRouteMap> {
  final _map = MapController();

  List<({int id, LatLng point, Map<String, dynamic> item})> get _pins {
    final out = <({int id, LatLng point, Map<String, dynamic> item})>[];
    for (final it in widget.items) {
      final lat = asDoubleOrNull(it['lat']);
      final lng = asDoubleOrNull(it['lng']);
      if (lat == null || lng == null || lat == 0 || lng == 0) continue;
      out.add((id: asInt(it['item_id']), point: LatLng(lat, lng), item: it));
    }
    return out;
  }

  void _fit() {
    final pins = _pins;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      try {
        if (pins.isEmpty) {
          _map.move(_ormoc, 13);
        } else if (pins.length == 1) {
          _map.move(pins.first.point, 14);
        } else {
          _map.fitCamera(
            CameraFit.coordinates(
              coordinates: pins.map((p) => p.point).toList(),
              padding: const EdgeInsets.all(40),
              maxZoom: 15,
            ),
          );
        }
      } catch (_) {}
    });
  }

  @override
  void initState() {
    super.initState();
    _fit();
  }

  @override
  void didUpdateWidget(covariant _TourRouteMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.items.length != widget.items.length) _fit();
  }

  @override
  void dispose() {
    _map.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final pins = _pins;
    if (pins.isEmpty) {
      return const ColoredBox(
        color: CtColors.tealDark,
        child: Center(
          child: Text('No map pins for this tour yet.', style: TextStyle(color: CtColors.muted)),
        ),
      );
    }
    return FlutterMap(
      mapController: _map,
      options: MapOptions(
        initialCenter: pins.first.point,
        initialZoom: 13,
        onMapReady: _fit,
      ),
      children: [
        TileLayer(
          urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
          userAgentPackageName: 'com.ormoc.cultour',
        ),
        if (pins.length >= 2)
          PolylineLayer(
            polylines: [
              Polyline(
                points: pins.map((p) => p.point).toList(),
                color: CtColors.sea.withOpacity(0.85),
                strokeWidth: 4,
              ),
            ],
          ),
        MarkerLayer(
          markers: [
            for (final pin in pins)
              Marker(
                point: pin.point,
                width: asInt(pin.item['item_id']) == widget.selectedId ? 52 : 42,
                height: asInt(pin.item['item_id']) == widget.selectedId ? 52 : 42,
                alignment: Alignment.center,
                child: GestureDetector(
                  onTap: () => widget.onSelect(pin.id),
                  child: _MapPinDot(
                    label: _pinLabel(pin.item),
                    color: _pinColor(pin.item),
                    selected: pin.id == widget.selectedId,
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _MapPinDot extends StatelessWidget {
  const _MapPinDot({required this.label, required this.color, required this.selected});

  final String label;
  final Color color;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final size = selected ? 36.0 : 30.0;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 160),
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: selected ? 3 : 2),
        boxShadow: [
          BoxShadow(color: color.withOpacity(0.45), blurRadius: selected ? 10 : 4, offset: const Offset(0, 2)),
        ],
      ),
      child: Text(
        label,
        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13),
      ),
    );
  }
}

({int id, String code}) parseTouristTicket(String raw) {
  final text = raw.trim().replaceAll('&amp;', '&').replaceAll('&AMP;', '&');
  var id = 0;
  var code = '';
  final url = RegExp(r'ticket-scan\.php\?([^#\s]+)', caseSensitive: false).firstMatch(text);
  String query = '';
  if (url != null) {
    query = url.group(1) ?? '';
  } else {
    final uri = Uri.tryParse(text);
    if (uri != null && uri.hasQuery) query = uri.query;
  }
  if (query.isNotEmpty) {
    final q = Uri.splitQueryString(query);
    id = int.tryParse(q['id'] ?? '') ?? 0;
    code = (q['code'] ?? '').toUpperCase();
  }
  if (code.isEmpty) {
    final m = RegExp(r'\b(CT[A-Z0-9]{6,})\b', caseSensitive: false).firstMatch(text);
    if (m != null) code = m.group(1)!.toUpperCase();
  }
  return (id: id, code: code);
}

String? meetupTicketError(String raw, int bookingId, String bookingCode) {
  final wantCode = bookingCode.toUpperCase();
  final parsed = parseTouristTicket(raw);
  if (parsed.id < 1 && parsed.code.isEmpty) {
    return 'Scan the tourist\'s ticket QR for this tour, or type the booking code.';
  }
  final idOk = parsed.id < 1 || parsed.id == bookingId;
  final codeOk = parsed.code.isEmpty || parsed.code == wantCode;
  final matched = (parsed.id == bookingId && bookingId > 0) || (parsed.code == wantCode && wantCode.isNotEmpty);
  if (!idOk || !codeOk || !matched) {
    return 'This ticket is not for this tour.';
  }
  return null;
}

Future<String?> askMeetupTicket(BuildContext context, {required int bookingId, required String bookingCode}) {
  return Navigator.of(context).push<String>(
    MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => _MeetupTicketScanPage(bookingId: bookingId, bookingCode: bookingCode),
    ),
  );
}

class _MeetupTicketScanPage extends StatefulWidget {
  const _MeetupTicketScanPage({required this.bookingId, required this.bookingCode});

  final int bookingId;
  final String bookingCode;

  @override
  State<_MeetupTicketScanPage> createState() => _MeetupTicketScanPageState();
}

class _MeetupTicketScanPageState extends State<_MeetupTicketScanPage> with SingleTickerProviderStateMixin {
  final _raw = TextEditingController();
  late final AnimationController _laser;
  late final MobileScannerController _cam;
  String? _error;
  String? _ok;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (kIsWeb) {
      MobileScannerPlatform.instance.setBarcodeLibraryScriptUrl('zxing.min.js');
    }
    _laser = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600))..repeat(reverse: true);
    _cam = MobileScannerController(
      detectionSpeed: DetectionSpeed.normal,
      facing: kIsWeb ? CameraFacing.front : CameraFacing.back,
      formats: const [BarcodeFormat.qrCode],
    );
  }

  @override
  void dispose() {
    _laser.dispose();
    _cam.dispose();
    _raw.dispose();
    super.dispose();
  }

  void _onCode(String value) {
    if (_busy) return;
    final err = meetupTicketError(value, widget.bookingId, widget.bookingCode);
    if (err != null) {
      setState(() {
        _error = err;
        _ok = null;
      });
      return;
    }
    _busy = true;
    setState(() {
      _error = null;
      _ok = 'Ticket matched ${widget.bookingCode}.';
    });
    () async {
      try {
        await _cam.stop();
      } catch (_) {}
      await Future<void>.delayed(const Duration(milliseconds: 250));
      if (mounted) Navigator.pop(context, value);
    }();
  }

  void _submitTyped() => _onCode(_raw.text.trim());

  Widget _camera() {
    final scanner = MobileScanner(
      controller: _cam,
      fit: BoxFit.cover,
      placeholderBuilder: (context, _) => const ColoredBox(
        color: Colors.black,
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(color: Colors.white),
              SizedBox(height: 12),
              Text('Starting camera…', style: TextStyle(color: Colors.white70)),
              SizedBox(height: 6),
              Text('Allow camera if the browser asks.', style: TextStyle(color: Colors.white54, fontSize: 12)),
            ],
          ),
        ),
      ),
      errorBuilder: (context, error, _) => ColoredBox(
        color: Colors.black,
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              'Camera could not start. Type the booking code below.\n${error.errorDetails?.message ?? error.errorCode.name}',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white70),
            ),
          ),
        ),
      ),
      onDetect: (capture) {
        final raw = capture.barcodes
            .map((b) => b.rawValue)
            .whereType<String>()
            .firstWhere((v) => v.trim().isNotEmpty, orElse: () => '');
        if (raw.isNotEmpty) _onCode(raw);
      },
    );
    final framed = Stack(
      fit: StackFit.expand,
      children: [
        scanner,
        IgnorePointer(
          child: CustomPaint(
            painter: _ScanFramePainter(ok: _ok != null, bad: _error != null),
          ),
        ),
        IgnorePointer(
          child: AnimatedBuilder(
            animation: _laser,
            builder: (context, _) {
              return Align(
                alignment: Alignment(0, -0.75 + 1.5 * _laser.value),
                child: Container(
                  margin: const EdgeInsets.symmetric(horizontal: 36),
                  height: 2,
                  decoration: BoxDecoration(
                    color: const Color(0xFF3EE0C0),
                    boxShadow: const [BoxShadow(color: Color(0xFF3EE0C0), blurRadius: 12)],
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
    if (kIsWeb) return framed;
    return ClipRRect(borderRadius: BorderRadius.circular(12), child: framed);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scan tourist ticket')),
      body: Padding(
        padding: EdgeInsets.fromLTRB(16, 8, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Hold the tourist ticket QR in the camera. A ticket for a different tour is rejected.',
              style: TextStyle(color: CtColors.muted),
            ),
            const SizedBox(height: 12),
            Expanded(
              child: Center(
                child: AspectRatio(
                  aspectRatio: 1,
                  child: ColoredBox(color: Colors.black, child: _camera()),
                ),
              ),
            ),
            const SizedBox(height: 10),
            Text(
              _ok ?? _error ?? 'Hold the QR inside the frame',
              style: TextStyle(
                color: _ok != null
                    ? CtColors.sea
                    : _error != null
                        ? CtColors.coral
                        : CtColors.muted,
                fontWeight: _ok != null || _error != null ? FontWeight.w700 : FontWeight.w400,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _raw,
              textCapitalization: TextCapitalization.characters,
              decoration: const InputDecoration(
                labelText: 'Or type the booking code',
                hintText: 'CTxxxxxxxx',
              ),
              onChanged: (_) => setState(() {
                _error = null;
                _ok = null;
              }),
              onSubmitted: (_) => _submitTyped(),
            ),
            const SizedBox(height: 10),
            FilledButton(
              style: compactFilled(),
              onPressed: _submitTyped,
              child: const Text('Use this code'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ScanFramePainter extends CustomPainter {
  _ScanFramePainter({required this.ok, required this.bad});

  final bool ok;
  final bool bad;

  @override
  void paint(Canvas canvas, Size size) {
    final color = ok
        ? const Color(0xFF3EE0C0)
        : bad
            ? const Color(0xFFE37A72)
            : const Color(0xFFD4A84B);
    final pad = size.shortestSide * 0.12;
    final r = Rect.fromLTWH(pad, pad, size.width - pad * 2, size.height - pad * 2);
    final corner = size.shortestSide * 0.08;
    final p = Paint()
      ..color = color
      ..strokeWidth = 3
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.square;
    void cornerLine(Offset a, Offset b, Offset c) {
      canvas.drawLine(a, b, p);
      canvas.drawLine(a, c, p);
    }

    cornerLine(r.topLeft, r.topLeft + Offset(corner, 0), r.topLeft + Offset(0, corner));
    cornerLine(r.topRight, r.topRight - Offset(corner, 0), r.topRight + Offset(0, corner));
    cornerLine(r.bottomLeft, r.bottomLeft + Offset(corner, 0), r.bottomLeft - Offset(0, corner));
    cornerLine(r.bottomRight, r.bottomRight - Offset(corner, 0), r.bottomRight - Offset(0, corner));
  }

  @override
  bool shouldRepaint(covariant _ScanFramePainter old) => old.ok != ok || old.bad != bad;
}
