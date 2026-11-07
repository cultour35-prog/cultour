import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../live_reload.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'chat_screens.dart';

/// Booking details: same content as the web "View details" modal plus the ticket QR.
class BookingDetailScreen extends StatefulWidget {
  const BookingDetailScreen({super.key, required this.bookingId, this.code = ''});

  final int bookingId;
  final String code;

  @override
  State<BookingDetailScreen> createState() => _BookingDetailScreenState();
}

class _BookingDetailScreenState extends State<BookingDetailScreen> with LiveReloadMixin {
  Map<String, dynamic>? _view;
  Map<String, dynamic>? _progress;
  Object? _error;
  int _day = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  String liveKey(Session session) => session.bookingsVersion;

  @override
  void onLiveReload() => _load();

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final view = await api.getJson('/bookings/view.php', {'id': '${widget.bookingId}'});
      Map<String, dynamic>? progress;
      try {
        final p = await api.getJson('/bookings/progress.php', {'booking_id': '${widget.bookingId}'});
        progress = asMap(p['data']);
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _view = asMap(view['data']);
        _progress = progress;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final v = _view;
    final me = context.watch<Session>().user!;
    return Scaffold(
      appBar: AppBar(title: Text(asString(v?['booking_code'], widget.code.isEmpty ? 'Booking' : widget.code))),
      body: v == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load this booking', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
                children: [
                  _summary(v),
                  _people(v, me.isGuide),
                  if (asBool(v['is_pwd'])) _accessibility(v),
                  if (asList(v['days']).isNotEmpty) _itinerary(v),
                  if (_progress != null && asList(_progress!['days']).isNotEmpty) _checkins(_progress!),
                  if (asBool(v['ticket_ready']) && asString(v['qr_url']).isNotEmpty) _ticket(v),
                ],
              ),
            ),
    );
  }

  Widget _summary(Map<String, dynamic> v) {
    final desc = asString(v['description']);
    final status = asString(v['status']);
    final reason = asString(v['rejection_reason']);
    final ref = asString(v['payment_reference']);
    return SectionCard(
      title: asString(v['title']),
      trailing: StatusChip(status),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (desc.isNotEmpty) ...[
            Text(desc, style: const TextStyle(color: CtColors.muted)),
            const SizedBox(height: 8),
          ],
          InfoRow('Dates', asString(v['date_label'])),
          InfoRow('Meetup', asString(v['meetup'])),
          InfoRow('Guests', '${asInt(v['party_size'], 1)}'),
          if (asString(v['accommodation']).isNotEmpty) InfoRow('Accommodation', asString(v['accommodation'])),
          if (asString(v['transportation']).isNotEmpty) InfoRow('Transportation', asString(v['transportation'])),
          InfoRow('Payment', ref.isEmpty ? asString(v['payment_label']) : '${asString(v['payment_label'])} · Ref $ref'),
          InfoRow('Notes', asString(v['notes'])),
          if (['rejected', 'cancelled'].contains(status) && reason.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                '${status == 'rejected' ? 'Declined' : 'Cancelled'}: $reason',
                style: const TextStyle(color: CtColors.coral),
              ),
            ),
        ],
      ),
    );
  }

  Widget _person(String role, Map<String, dynamic> p, {bool showEmail = false}) {
    final name = asString(p['name']).trim();
    final phone = asString(p['phone']).trim();
    final email = asString(p['email']).trim();
    final uid = asInt(p['user_id']);
    final me = context.read<Session>().user!;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Avatar(name: name, url: asString(p['avatar_url'])),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(role, style: const TextStyle(color: CtColors.muted, fontSize: 12)),
                Text(name.isEmpty ? '—' : name, style: const TextStyle(fontWeight: FontWeight.w700)),
                if (phone.isNotEmpty) Text(phone, style: const TextStyle(color: CtColors.muted, fontSize: 13)),
                if (showEmail && email.isNotEmpty)
                  Text(email, style: const TextStyle(color: CtColors.muted, fontSize: 13)),
              ],
            ),
          ),
          if (phone.isNotEmpty)
            IconButton(
              tooltip: 'Call',
              icon: const Icon(Icons.call_outlined),
              onPressed: () => launchUrl(Uri(scheme: 'tel', path: phone)),
            ),
          if (uid > 0 && uid != me.id)
            IconButton(
              tooltip: 'Message',
              icon: const Icon(Icons.chat_bubble_outline),
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => ChatThreadScreen(peerId: uid, peerName: name)),
              ),
            ),
        ],
      ),
    );
  }

  Widget _people(Map<String, dynamic> v, bool viewerIsGuide) {
    return SectionCard(
      title: 'People',
      child: Column(
        children: [
          _person('Tourist', asMap(v['tourist']), showEmail: viewerIsGuide),
          const Divider(),
          _person('Tour guide', asMap(v['guide'])),
        ],
      ),
    );
  }

  Widget _accessibility(Map<String, dynamic> v) {
    final notes = asString(v['pwd_notes']);
    final cert = asString(v['fitness_certificate_url']);
    return SectionCard(
      title: 'Accessibility needs',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(notes.isEmpty ? 'The guest asked for accessibility support.' : notes),
          if (cert.isNotEmpty) ...[
            const SizedBox(height: 10),
            const Text('Fitness-to-travel certificate', style: TextStyle(color: CtColors.muted, fontSize: 13)),
            const SizedBox(height: 6),
            AuthImage(
              cert,
              height: 220,
              fit: BoxFit.contain,
              errorLabel: 'This certificate is a PDF. Open it from the booking on the CulTour website.',
            ),
          ],
        ],
      ),
    );
  }

  Widget _itinerary(Map<String, dynamic> v) {
    final days = asList(v['days']).map(asMap).toList();
    final idx = _day.clamp(0, days.length - 1);
    final day = days[idx];
    final stops = asList(day['stops']).map(asMap).toList();
    final api = context.read<Session>().api;
    return SectionCard(
      title: 'Itinerary',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (days.length > 1)
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (var i = 0; i < days.length; i++)
                  ChoiceChip(
                    label: Text(asString(days[i]['label'], 'Day ${i + 1}')),
                    selected: i == idx,
                    onSelected: (_) => setState(() => _day = i),
                  ),
              ],
            ),
          const SizedBox(height: 8),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(color: CtColors.tealDark, borderRadius: BorderRadius.circular(10)),
            child: Text(
              [
                asString(day['date_label']),
                if (asString(day['meetup']).isNotEmpty) 'Meet at ${asString(day['meetup'])}',
                asString(day['meetup_time']),
              ].where((e) => e.isNotEmpty).join(' · '),
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
          if (stops.isEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 10),
              child: Text('No planned stops for this day.', style: TextStyle(color: CtColors.muted)),
            ),
          ...stops.map((s) {
            final time = [asString(s['start']), asString(s['end'])].where((e) => e.isNotEmpty && e != '—').join(' – ');
            final cover = api.mediaUrl(asString(s['cover_url']));
            return Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(width: 64, child: CoverImage(url: cover, height: 56, radius: 10)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(asString(s['name']), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(
                          [time, asString(s['location'])].where((e) => e.isNotEmpty).join(' · '),
                          style: const TextStyle(color: CtColors.muted, fontSize: 13),
                        ),
                        if (asString(s['notes']).isNotEmpty)
                          Text(asString(s['notes']), style: const TextStyle(fontSize: 13)),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }

  Widget _checkins(Map<String, dynamic> p) {
    final days = asList(p['days']).map(asMap).toList();
    return SectionCard(
      title: 'Stop check-ins',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Photo proof that the guide is at each planned stop.',
              style: TextStyle(color: CtColors.muted, fontSize: 13)),
          for (final d in days) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: Text('Day ${asInt(d['day'])} · ${asString(d['date_label'])}',
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                ),
                if (asBool(d['ended'])) const StatusChip('Ended', tone: CtColors.muted),
              ],
            ),
            ...asList(d['items']).map(asMap).map(_checkinItem),
          ],
        ],
      ),
    );
  }

  Widget _checkinItem(Map<String, dynamic> it) {
    final state = asString(it['state']);
    final photo = asString(it['photo_url']);
    return Container(
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: CtColors.line),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${asString(it['label'])} · ${asString(it['name'])}',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          if (asString(it['scheduled']).isNotEmpty)
            Text(asString(it['scheduled']), style: const TextStyle(color: CtColors.muted, fontSize: 13)),
          const SizedBox(height: 6),
          if (state == 'done') ...[
            if (photo.isNotEmpty) ...[AuthImage(photo, height: 160), const SizedBox(height: 6)],
            Row(children: [
              const StatusChip('Checked in', tone: CtColors.sea),
              const SizedBox(width: 8),
              Text(asString(it['checked_in_label']), style: const TextStyle(color: CtColors.muted, fontSize: 13)),
            ]),
          ] else if (state == 'skipped')
            Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                const StatusChip('Skipped', tone: CtColors.gold),
                Text(
                  [asString(it['skip_reason']), asString(it['checked_in_label'])]
                      .where((e) => e.isNotEmpty)
                      .join(' · '),
                  style: const TextStyle(color: CtColors.muted, fontSize: 13),
                ),
              ],
            )
          else
            const Text('Not checked in yet.', style: TextStyle(color: CtColors.muted, fontSize: 13)),
        ],
      ),
    );
  }

  Widget _ticket(Map<String, dynamic> v) {
    final url = context.read<Session>().api.mediaUrl(asString(v['qr_url']));
    return SectionCard(
      title: 'Ticket QR',
      child: Center(
        child: Column(
          children: [
            Container(
              color: Colors.white,
              padding: const EdgeInsets.all(12),
              child: RemotePhoto(url: url, width: 220, height: 220, fit: BoxFit.contain),
            ),
            const SizedBox(height: 8),
            const Text('Show this at the meetup.', style: TextStyle(color: CtColors.muted)),
          ],
        ),
      ),
    );
  }
}
