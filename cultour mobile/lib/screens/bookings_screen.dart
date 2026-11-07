import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'booking_detail_screen.dart';
import 'chat_screens.dart';
import 'guide/guide_availability_screen.dart';
import 'guide/guide_schedule_screen.dart';
import 'pay_screen.dart';
import 'replacement_popup.dart';

class BookingsScreen extends StatefulWidget {
  const BookingsScreen({super.key});

  @override
  State<BookingsScreen> createState() => _BookingsScreenState();
}

class _BookingsScreenState extends State<BookingsScreen> with LiveReloadMixin {
  Map<String, dynamic>? _data;
  Object? _error;
  bool _loading = true;
  String _filter = 'all';

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
    try {
      final data = await context.read<Session>().api.getJson('/bookings/my.php');
      if (!mounted) return;
      setState(() {
        _data = data;
        _error = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  Future<void> _update(Map<String, dynamic> b, String action,
      {Map<String, dynamic> extra = const {}, String? filePath}) async {
    final api = context.read<Session>().api;
    final res = await runApi(
      context,
      () => api.updateBooking(asInt(b['id']), action, extra: extra, filePath: filePath),
      success: 'Booking updated.',
    );
    if (res != null) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<Session>().user!;
    final rows = asList(_data?['data']).map(asMap).toList();
    final guide = user.isGuide;
    final filtered = rows.where((b) {
      final s = asString(b['status']);
      switch (_filter) {
        case 'pending':
          return s == 'pending';
        case 'approved':
          return s == 'approved';
        case 'past':
          return ['completed', 'rejected', 'cancelled'].contains(s);
        default:
          return true;
      }
    }).toList();

    return Scaffold(
      appBar: AppBar(
        title: Text(guide ? 'Bookings' : 'My bookings'),
        actions: [
          if (guide) ...[
            IconButton(
              tooltip: 'Schedule',
              icon: const Icon(Icons.calendar_month_outlined),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const GuideScheduleScreen())),
            ),
            IconButton(
              tooltip: 'Availability',
              icon: const Icon(Icons.schedule_outlined),
              onPressed: () =>
                  Navigator.push(context, MaterialPageRoute(builder: (_) => const GuideAvailabilityScreen())),
            ),
          ],
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: Builder(builder: (context) {
          if (_loading && _data == null) return const Center(child: CircularProgressIndicator());
          if (_data == null) {
            return ListView(children: [
              EmptyState(icon: Icons.error_outline, title: 'Could not load bookings', detail: '$_error'),
            ]);
          }
          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
            children: [
              if (guide && !asBool(_data!['has_schedule'], true))
                NoticeBox(
                  'Tourists cannot book you until you set weekly hours.',
                  action: OutlinedButton(
                    style: compactOutlined(),
                    onPressed: () => Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const GuideAvailabilityScreen()),
                    ).then((_) => _load()),
                    child: const Text('Set availability'),
                  ),
                ),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    for (final f in const [
                      ('all', 'All'),
                      ('pending', 'Pending'),
                      ('approved', 'Upcoming'),
                      ('past', 'Past'),
                    ])
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(f.$2),
                          selected: _filter == f.$1,
                          onSelected: (_) => setState(() => _filter = f.$1),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              if (filtered.isEmpty)
                EmptyState(
                  icon: Icons.event_busy,
                  title: rows.isEmpty ? 'No bookings yet' : 'Nothing here',
                  detail: rows.isEmpty
                      ? (guide
                          ? 'Paid requests show here for you to accept or decline.'
                          : 'Book a licensed guide from one of your itineraries.')
                      : null,
                ),
              ...filtered.map(
                (b) => guide
                    ? _GuideBookingCard(
                        b: b,
                        declineReasons: asList(_data!['decline_reasons']).map((e) => '$e').toList(),
                        withdrawReasons: asList(_data!['withdraw_reasons']).map((e) => '$e').toList(),
                        onUpdate: _update,
                      )
                    : _TouristBookingCard(b: b, onUpdate: _update, onReload: _load),
              ),
            ],
          );
        }),
      ),
    );
  }
}

typedef _Updater = Future<void> Function(Map<String, dynamic> b, String action,
    {Map<String, dynamic> extra, String? filePath});

class _Header extends StatelessWidget {
  const _Header({required this.b, required this.peer, required this.avatar});

  final Map<String, dynamic> b;
  final String peer;
  final String avatar;

  @override
  Widget build(BuildContext context) {
    final status = asString(b['status']);
    final party = asInt(b['party_size'], 1);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Avatar(name: peer, url: avatar),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(asString(b['booking_code']),
                  style: const TextStyle(fontWeight: FontWeight.w800, letterSpacing: 0.4)),
              Text(peer, style: const TextStyle(fontSize: 15)),
              const SizedBox(height: 2),
              Text(
                '${asString(b['date_label'])} · ${asString(b['time_label'])} · $party guest${party == 1 ? '' : 's'}',
                style: const TextStyle(color: CtColors.muted, fontSize: 13),
              ),
            ],
          ),
        ),
        const SizedBox(width: 8),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            StatusChip(status),
            if (asBool(b['needs_replacement'])) ...[
              const SizedBox(height: 4),
              const StatusChip('Needs replacement', tone: CtColors.gold),
            ],
            if (asBool(b['is_new'])) ...[
              const SizedBox(height: 4),
              const StatusChip('New', tone: CtColors.coral),
            ],
          ],
        ),
      ],
    );
  }
}

class _Facts extends StatelessWidget {
  const _Facts({required this.b});

  final Map<String, dynamic> b;

  @override
  Widget build(BuildContext context) {
    final status = asString(b['status']);
    final ref = asString(b['payment_reference']);
    final reason = asString(b['rejection_reason']);
    final notes = asString(b['notes']);
    final pwdNotes = asString(b['pwd_notes']);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 10),
        InfoRow('Itinerary', asString(b['itinerary_title'], '—')),
        InfoRow('Payment', ref.isEmpty ? asString(b['payment_label']) : '${asString(b['payment_label'])} · Ref $ref'),
        InfoRow('Amount', asString(b['amount_label'])),
        if (notes.isNotEmpty) InfoRow('Notes', notes),
        if (asBool(b['is_pwd']))
          InfoRow('Accessibility', pwdNotes.isEmpty ? 'Accessibility needs' : 'Accessibility needs · $pwdNotes'),
        if (status == 'rejected' && reason.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text('Declined: $reason', style: const TextStyle(color: CtColors.coral)),
          ),
        if (status == 'cancelled' && reason.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text('Cancelled: $reason', style: const TextStyle(color: CtColors.coral)),
          ),
      ],
    );
  }
}

void _openDetail(BuildContext context, Map<String, dynamic> b) {
  Navigator.push(
    context,
    MaterialPageRoute(
      builder: (_) => BookingDetailScreen(bookingId: asInt(b['id']), code: asString(b['booking_code'])),
    ),
  );
}

void _openChat(BuildContext context, int userId, String name) {
  if (userId < 1) return;
  Navigator.push(context, MaterialPageRoute(builder: (_) => ChatThreadScreen(peerId: userId, peerName: name)));
}

class _TouristBookingCard extends StatelessWidget {
  const _TouristBookingCard({required this.b, required this.onUpdate, required this.onReload});

  final Map<String, dynamic> b;
  final _Updater onUpdate;
  final Future<void> Function() onReload;

  @override
  Widget build(BuildContext context) {
    final status = asString(b['status']);
    final guideName = asString(b['guide_name']).trim();
    final original = asString(b['original_guide_name']).trim();
    final changedGuide = asInt(b['original_guide_id']) > 0 &&
        asInt(b['original_guide_id']) != asInt(b['guide_id']) &&
        original.isNotEmpty;
    final replacementNote = asString(b['replacement_note']);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Header(
                b: b,
                peer: guideName.isEmpty ? 'Tour guide' : 'Tour guide · $guideName',
                avatar: asString(b['guide_avatar_url'])),
            if (changedGuide)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text('Originally with $original', style: const TextStyle(color: CtColors.muted, fontSize: 13)),
              ),
            if (status == 'pending')
              const Padding(
                padding: EdgeInsets.only(top: 6),
                child: Text('Waiting for guide', style: TextStyle(color: CtColors.muted, fontSize: 13)),
              ),
            _Facts(b: b),
            if (asString(b['refund_line']).isNotEmpty) ...[
              const SizedBox(height: 10),
              NoticeBox(
                asString(b['refund_line']),
                icon: asString(b['refund_status']) == 'sent' ? Icons.check_circle_outline : Icons.payments_outlined,
                tone: asString(b['refund_status']) == 'sent' ? CtColors.sea : CtColors.gold,
                action: asBool(b['refund_needs_details'])
                    ? FilledButton(
                        style: compactFilled(),
                        onPressed: () async {
                          final sent = await askRefundDetails(
                            context,
                            bookingId: asInt(b['id']),
                            code: asString(b['booking_code']),
                            amountLabel: asString(b['refund_amount_label']),
                          );
                          if (sent) await onReload();
                        },
                        child: const Text('Add refund details'),
                      )
                    : null,
              ),
            ],
            if (replacementNote.isNotEmpty) ...[
              const SizedBox(height: 10),
              NoticeBox(
                replacementNote,
                icon: Icons.swap_horiz,
                action: OutlinedButton(
                  style: compactOutlined(),
                  onPressed: () => ReplacementHost.of(context)?.openNow(),
                  child: const Text('Review options'),
                ),
              ),
            ],
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (asBool(b['has_itinerary']) || asBool(b['ticket_ready']))
                  OutlinedButton.icon(
                    style: compactOutlined(),
                    onPressed: () => _openDetail(context, b),
                    icon: const Icon(Icons.list_alt, size: 18),
                    label: const Text('View details'),
                  ),
                if (asBool(b['ticket_ready']))
                  FilledButton.icon(
                    style: compactFilled(),
                    onPressed: () => showTicketQr(context, asInt(b['id']), asString(b['booking_code'])),
                    icon: const Icon(Icons.qr_code_2, size: 18),
                    label: const Text('Ticket / QR'),
                  )
                else if (status == 'pending')
                  const OutlinedButton(
                    style: ButtonStyle(minimumSize: WidgetStatePropertyAll(Size(0, 44))),
                    onPressed: null,
                    child: Text('Ticket after approval'),
                  ),
                if (asInt(b['guide_user_id']) > 0 && ['pending', 'approved', 'completed'].contains(status))
                  OutlinedButton.icon(
                    style: compactOutlined(),
                    onPressed: () => _openChat(context, asInt(b['guide_user_id']), guideName),
                    icon: const Icon(Icons.chat_bubble_outline, size: 18),
                    label: const Text('Message'),
                  ),
                if (asBool(b['can_cancel']))
                  OutlinedButton(
                    style: compactOutlined(color: CtColors.coral),
                    onPressed: () => _cancel(context),
                    child: Text(asString(b['cancel_label'], 'Cancel')),
                  ),
              ],
            ),
            if (status == 'completed') ...[
              const SizedBox(height: 12),
              if (asBool(b['rated']))
                const Row(
                  children: [
                    Icon(Icons.check_circle, color: CtColors.sea, size: 18),
                    SizedBox(width: 6),
                    Text('You already rated this tour'),
                  ],
                )
              else
                _RateBox(
                    onSubmit: (stars, comment) => onUpdate(b, 'rate', extra: {'rating': stars, 'comment': comment})),
            ],
            if (asBool(b['pay_open'])) ...[
              const Divider(height: 28),
              const Text('Payment', style: TextStyle(color: CtColors.muted, fontSize: 13)),
              const SizedBox(height: 8),
              FilledButton.icon(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => PayScreen(bookingId: asInt(b['id']))),
                ).then((_) => onReload()),
                icon: const Icon(Icons.account_balance_wallet_outlined),
                label: const Text('Pay with GCash'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _cancel(BuildContext context) async {
    final status = asString(b['status']);
    if (asBool(b['cancel_needs_refund'])) {
      final pick = await showRefundSheet(
        context,
        title: asString(b['cancel_label'], 'Cancel and refund'),
        lead: 'We will refund ${asString(b['amount_label'])}. Where should we send it?',
      );
      if (pick == null) return;
      await onUpdate(b, 'cancel', extra: refundFields(pick), filePath: pick.qrPath);
      return;
    }
    final ok = await confirmAction(
      context,
      title: asString(b['cancel_label'], 'Cancel'),
      message: status == 'pending'
          ? 'Cancel this booking request?'
          : 'Cancel the remaining days of this tour? Finished days stay as they are.',
      confirmLabel: 'Yes, cancel',
      danger: true,
    );
    if (ok) await onUpdate(b, 'cancel');
  }
}

class _RateBox extends StatefulWidget {
  const _RateBox({required this.onSubmit});

  final Future<void> Function(int stars, String comment) onSubmit;

  @override
  State<_RateBox> createState() => _RateBoxState();
}

class _RateBoxState extends State<_RateBox> {
  int _stars = 5;
  final _comment = TextEditingController();

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('How was your guide?', style: TextStyle(fontWeight: FontWeight.w700)),
        Row(
          children: List.generate(5, (i) {
            final n = i + 1;
            return IconButton(
              visualDensity: VisualDensity.compact,
              onPressed: () => setState(() => _stars = n),
              icon: Icon(n <= _stars ? Icons.star_rounded : Icons.star_border_rounded, color: CtColors.gold, size: 30),
            );
          }),
        ),
        TextField(controller: _comment, decoration: const InputDecoration(hintText: 'Add a comment (optional)')),
        const SizedBox(height: 8),
        AsyncButton(
          label: 'Rate guide',
          busyLabel: 'Sending…',
          onPressed: () => widget.onSubmit(_stars, _comment.text.trim()),
        ),
      ],
    );
  }
}

class _GuideBookingCard extends StatelessWidget {
  const _GuideBookingCard({
    required this.b,
    required this.declineReasons,
    required this.withdrawReasons,
    required this.onUpdate,
  });

  final Map<String, dynamic> b;
  final List<String> declineReasons;
  final List<String> withdrawReasons;
  final _Updater onUpdate;

  @override
  Widget build(BuildContext context) {
    final status = asString(b['status']);
    final tourist = asString(b['tourist_name']).trim();
    final touristId = asInt(b['tourist_id']);
    final fromLabel = asString(b['replacement_from_label']);
    final unpaid = asString(b['payment_status']) != 'paid';
    final notCheckedIn = asBool(b['not_checked_in']);

    final actions = <Widget>[];
    Widget chatBtn() => OutlinedButton.icon(
          style: compactOutlined(),
          onPressed: () => _openChat(context, touristId, tourist),
          icon: const Icon(Icons.chat_bubble_outline, size: 18),
          label: const Text('Chat'),
        );
    Widget ticketBtn() => OutlinedButton.icon(
          style: compactOutlined(),
          onPressed: () => showTicketQr(context, asInt(b['id']), asString(b['booking_code'])),
          icon: const Icon(Icons.qr_code_2, size: 18),
          label: const Text('Ticket'),
        );

    final extra = <Widget>[];
    if (status == 'pending') {
      if (asBool(b['waiting_payment'])) {
        extra.add(const NoticeBox('Waiting for payment.', tone: CtColors.sea));
      } else if (asBool(b['can_decide'])) {
        actions.addAll([
          AsyncButton(label: 'Accept', busyLabel: 'Accepting…', onPressed: () => onUpdate(b, 'approve')),
          OutlinedButton(
            style: compactOutlined(color: CtColors.coral),
            onPressed: () async {
              final pick = await askReason(
                context,
                title: 'Decline ${asString(b['booking_code'])}',
                presets: declineReasons,
                lead: asString(b['payment_status']) == 'paid' ? 'The tourist will be refunded.' : null,
                confirmLabel: 'Decline',
              );
              if (pick != null) {
                await onUpdate(b, 'decline', extra: {'reason_preset': pick.preset, 'reason_detail': pick.detail});
              }
            },
            child: const Text('Decline'),
          ),
        ]);
        if (asString(b['payment_status']) == 'paid') {
          extra.add(const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'Paid. Accept to add it to your schedule, or decline to refund the tourist.',
              style: TextStyle(color: CtColors.muted, fontSize: 13),
            ),
          ));
        }
      }
    } else if (status == 'approved') {
      if (asBool(b['handed_off'])) {
        extra.add(NoticeBox(
          'You stepped down${fromLabel.isNotEmpty ? ' from $fromLabel' : ''}. Remaining days were released from your schedule.',
          tone: CtColors.muted,
        ));
        actions.add(ticketBtn());
      } else if (asBool(b['needs_replacement'])) {
        extra.add(NoticeBox(
          'You cannot continue this tour${fromLabel.isNotEmpty ? ' from $fromLabel' : ''}. Remaining days are waiting for a replacement guide.',
        ));
        actions.addAll([chatBtn(), ticketBtn()]);
      } else {
        actions.addAll([
          AsyncButton(
            label: 'Mark completed',
            busyLabel: 'Saving…',
            onPressed: () async {
              final ok = await confirmAction(
                context,
                title: 'Mark completed',
                message: (unpaid || notCheckedIn)
                    ? 'Warning: payment and/or check-in may still be open. Mark completed anyway?'
                    : 'Mark this tour completed?',
                confirmLabel: 'Mark completed',
              );
              if (ok) await onUpdate(b, 'complete');
            },
          ),
          chatBtn(),
          ticketBtn(),
          if (asBool(b['can_pay_at_office']))
            AsyncButton(
              label: 'Pay at Tourism Office',
              outlined: true,
              onPressed: () => onUpdate(b, 'pay_at_office'),
            ),
        ]);
        if (unpaid || notCheckedIn) {
          extra.add(Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              '${unpaid ? 'Payment still open. ' : ''}${notCheckedIn ? 'Not checked in yet.' : ''}',
              style: const TextStyle(color: CtColors.muted, fontSize: 13),
            ),
          ));
        }
      }
    } else if (status == 'completed') {
      actions.add(ticketBtn());
    }

    final danger = <Widget>[];
    if (asBool(b['can_cancel'])) {
      final multi = asList(b['visit_dates']).length > 1;
      danger.add(OutlinedButton(
        style: compactOutlined(color: CtColors.coral),
        onPressed: () async {
          final pick = await askReason(
            context,
            title: asString(b['cancel_label'], 'Cancel tour'),
            presets: withdrawReasons,
            lead: multi
                ? 'This cancels every remaining day of the tour. The tourist will be notified.'
                : 'This cancels the tour. The tourist will be notified.',
            confirmLabel: asString(b['cancel_label'], 'Cancel tour'),
          );
          if (pick == null || !context.mounted) return;
          final ok = await confirmAction(
            context,
            title: 'Are you sure?',
            message: 'Cancel this entire tour? All days will be released from your schedule.',
            confirmLabel: 'Yes, cancel',
            danger: true,
          );
          if (ok) await onUpdate(b, 'cancel', extra: {'reason_preset': pick.preset, 'reason_detail': pick.detail});
        },
        child: Text(asString(b['cancel_label'], 'Cancel tour')),
      ));
    }
    if (asBool(b['can_withdraw'])) {
      danger.add(OutlinedButton(
        style: compactOutlined(color: CtColors.coral),
        onPressed: () => _withdraw(context),
        child: const Text('Cannot continue'),
      ));
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Header(b: b, peer: tourist.isEmpty ? 'Tourist' : tourist, avatar: asString(b['tourist_avatar_url'])),
            _Facts(b: b),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              style: compactOutlined(),
              onPressed: () => _openDetail(context, b),
              icon: const Icon(Icons.list_alt, size: 18),
              label: const Text('View details'),
            ),
            const SizedBox(height: 10),
            ...extra,
            if (actions.isNotEmpty) ...[
              const SizedBox(height: 4),
              Wrap(spacing: 8, runSpacing: 8, children: actions),
            ],
            if (danger.isNotEmpty) ...[
              const Divider(height: 28),
              Wrap(spacing: 8, runSpacing: 8, children: danger),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _withdraw(BuildContext context) async {
    final dates = asList(b['withdraw_dates']).map(asMap).toList();
    if (dates.isEmpty) return;
    var from = asString(dates.first['date']);
    final pick = await askReason(
      context,
      title: 'Cannot continue',
      lead: 'Release the days you cannot make. Days you already ended stay on your schedule.',
      presets: withdrawReasons,
      confirmLabel: 'Release remaining days',
      extra: StatefulBuilder(
        builder: (ctx, setLocal) => DropdownButtonFormField<String>(
          initialValue: from,
          decoration: const InputDecoration(labelText: 'From remaining day'),
          items: dates
              .map((d) => DropdownMenuItem(value: asString(d['date']), child: Text(asString(d['label']))))
              .toList(),
          onChanged: (v) => setLocal(() => from = v ?? from),
        ),
      ),
    );
    if (pick == null) return;
    await onUpdate(b, 'withdraw', extra: {
      'from_date': from,
      'reason_preset': pick.preset,
      'reason_detail': pick.detail,
    });
  }
}

Future<void> showTicketQr(BuildContext context, int bookingId, String code) async {
  final api = context.read<Session>().api;
  try {
    final data = await api.getJson('/bookings/view.php', {'id': '$bookingId'});
    final url = api.mediaUrl(asString(asMap(data['data'])['qr_url']));
    if (!context.mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: Text(code),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (url.isEmpty)
              const Text('QR is not available yet.')
            else
              Container(
                color: Colors.white,
                padding: const EdgeInsets.all(12),
                child: RemotePhoto(url: url, width: 240, height: 240, fit: BoxFit.contain),
              ),
            const SizedBox(height: 12),
            const Text(
              'Show this at the meetup so your guide can scan it.',
              textAlign: TextAlign.center,
              style: TextStyle(color: CtColors.muted),
            ),
          ],
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Close'))],
      ),
    );
  } catch (e) {
    if (context.mounted) showCulTourError(context, e);
  }
}

typedef RefundPick = ({String number, String? qrPath, bool save, bool useSavedQr});

/// Form fields the refund endpoints read next to the GCash number / QR file.
Map<String, dynamic> refundFields(RefundPick pick) => {
      if (pick.number.isNotEmpty) 'refund_number': pick.number,
      if (pick.save) 'refund_save': '1',
      if (pick.useSavedQr) 'refund_use_saved_qr': '1',
    };

/// GCash number or QR image, same rules as the website refund form.
Future<RefundPick?> showRefundSheet(
  BuildContext context, {
  required String title,
  required String lead,
  String confirmLabel = 'Confirm',
}) {
  return showModalBottomSheet<RefundPick>(
    context: context,
    isScrollControlled: true,
    backgroundColor: CtColors.surface,
    builder: (_) => _RefundSheet(title: title, lead: lead, confirmLabel: confirmLabel),
  );
}

/// Asks where to send a refund the guide triggered, then sends it to the Tourism Office.
Future<bool> askRefundDetails(
  BuildContext context, {
  required int bookingId,
  required String code,
  required String amountLabel,
  String note = '',
}) async {
  final pick = await showRefundSheet(
    context,
    title: 'Where should we send your refund?',
    lead: '$code · $amountLabel${note.isEmpty ? '' : ' · $note'}\n'
        'Your booking did not push through, so the Tourism Office will refund your payment.',
    confirmLabel: 'Send details',
  );
  if (pick == null || !context.mounted) return false;
  final api = context.read<Session>().api;
  final fields = {'booking_id': bookingId, ...refundFields(pick)};
  final res = await runApi(
    context,
    () => pick.qrPath != null
        ? api.multipart('/bookings/refund.php', fields, {'refund_qr': pick.qrPath!})
        : api.postJson('/bookings/refund.php', fields),
    success: 'Refund details sent.',
  );
  return res != null;
}

class _RefundSheet extends StatefulWidget {
  const _RefundSheet({required this.title, required this.lead, required this.confirmLabel});

  final String title;
  final String lead;
  final String confirmLabel;

  @override
  State<_RefundSheet> createState() => _RefundSheetState();
}

class _RefundSheetState extends State<_RefundSheet> {
  final _number = TextEditingController();
  String? _qr;
  String? _error;
  String _savedQrUrl = '';
  bool _useSavedQr = false;
  bool _save = false;

  @override
  void initState() {
    super.initState();
    _loadSaved();
  }

  Future<void> _loadSaved() async {
    final session = context.read<Session>();
    try {
      final res = await session.api.getJson('/bookings/refund.php');
      if (!mounted) return;
      final saved = asMap(res['saved']);
      final number = asString(saved['number']);
      final qr = asString(saved['qr_url']);
      setState(() {
        if (number.isNotEmpty && _number.text.isEmpty) _number.text = number;
        _savedQrUrl = qr.isEmpty ? '' : session.api.mediaUrl(qr);
        _useSavedQr = qr.isNotEmpty && number.isEmpty;
        _save = number.isNotEmpty || qr.isNotEmpty;
      });
    } catch (_) {}
  }

  @override
  void dispose() {
    _number.dispose();
    super.dispose();
  }

  void _submit() {
    final digits = _number.text.replaceAll(RegExp(r'\D'), '');
    final numberOk = RegExp(r'^09\d{9}$').hasMatch(digits);
    if (digits.isNotEmpty && !numberOk) {
      setState(() => _error = 'Enter a valid GCash number');
      return;
    }
    final useSaved = _qr == null && _useSavedQr && _savedQrUrl.isNotEmpty;
    if (!numberOk && _qr == null && !useSaved) {
      setState(() => _error = 'Enter a GCash number or upload a QR');
      return;
    }
    Navigator.pop(context, (number: numberOk ? digits : '', qrPath: _qr, save: _save, useSavedQr: useSaved));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(widget.lead, style: const TextStyle(color: CtColors.muted)),
            const SizedBox(height: 14),
            TextField(
              controller: _number,
              keyboardType: TextInputType.number,
              maxLength: 11,
              decoration: const InputDecoration(labelText: 'GCash number', hintText: '09XXXXXXXXX'),
              onChanged: (_) => setState(() => _error = null),
            ),
            const Center(child: Text('or', style: TextStyle(color: CtColors.muted))),
            const SizedBox(height: 8),
            if (_savedQrUrl.isNotEmpty && _qr == null)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _useSavedQr,
                onChanged: (v) => setState(() {
                  _useSavedQr = v ?? false;
                  _error = null;
                }),
                title: const Text('Use my saved GCash QR'),
                secondary: Container(
                  color: Colors.white,
                  padding: const EdgeInsets.all(3),
                  child: RemotePhoto(url: _savedQrUrl, width: 48, height: 48, fit: BoxFit.contain),
                ),
              ),
            OutlinedButton.icon(
              onPressed: () async {
                final path = await pickPhoto(context);
                if (path != null) {
                  setState(() {
                    _qr = path;
                    _error = null;
                  });
                }
              },
              icon: const Icon(Icons.qr_code),
              label: Text(_qr == null
                  ? (_savedQrUrl.isNotEmpty ? 'Upload a different GCash QR' : 'Upload GCash QR')
                  : 'QR selected · change'),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(_error!, style: const TextStyle(color: CtColors.coral)),
            ],
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _save,
              onChanged: (v) => setState(() => _save = v ?? false),
              title: const Text('Save for future refunds'),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(child: OutlinedButton(onPressed: () => Navigator.pop(context), child: const Text('Back'))),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: CtColors.coral),
                    onPressed: _submit,
                    child: Text(widget.confirmLabel),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
