import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import 'bookings_screen.dart' show askRefundDetails, refundFields, showRefundSheet;

/// Polls the replacement pop-up every 4 s (same as assets/js/replacement-popup.js) and shows it app-wide.
class ReplacementHost extends StatefulWidget {
  const ReplacementHost({super.key, required this.child});

  final Widget child;

  static ReplacementHostState? of(BuildContext context) => context.findAncestorStateOfType<ReplacementHostState>();

  @override
  State<ReplacementHost> createState() => ReplacementHostState();
}

class ReplacementHostState extends State<ReplacementHost> {
  static const _pollEvery = Duration(seconds: 4);

  Timer? _timer;
  bool _busy = false;
  bool _showing = false;
  String _key = '';
  Map<String, dynamic>? _popup;
  DateTime _refundCheckedAt = DateTime.fromMillisecondsSinceEpoch(0);
  final Set<int> _refundLater = {};

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(_pollEvery, (_) => _poll());
    WidgetsBinding.instance.addPostFrameCallback((_) => _poll(force: true));
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  /// Re-open the current pop-up (e.g. from the booking card), or fetch it.
  Future<void> openNow() async {
    if (_popup != null && !_showing) {
      _show(_popup!);
      return;
    }
    await _poll(force: true);
  }

  Future<void> _poll({bool force = false}) async {
    final session = context.read<Session>();
    if (_busy || _showing || !session.isLoggedIn) return;
    if (!force && session.livePaused) return;
    final user = session.user;
    if (user == null || !(user.isGuide || user.isTourist)) return;
    _busy = true;
    try {
      final res = await session.api.getJson('/bookings/replacement.php');
      if (!mounted) return;
      final popup = res['popup'] == null ? null : asMap(res['popup']);
      final key = asString(popup?['key']);
      if (key != _key || (force && popup != null && !_showing)) {
        _key = key;
        _popup = popup;
        if (popup != null && !_showing) _show(popup);
      }
      if (popup == null && user.isTourist) await _checkRefund(force: force);
    } catch (_) {
    } finally {
      _busy = false;
    }
  }

  /// Guide declined or cancelled a paid booking: ask where to send the refund.
  Future<void> _checkRefund({bool force = false}) async {
    final now = DateTime.now();
    if (!force && now.difference(_refundCheckedAt) < const Duration(seconds: 20)) return;
    _refundCheckedAt = now;
    final res = await context.read<Session>().api.getJson('/bookings/refund.php');
    if (!mounted || _showing) return;
    final pending = asList(res['pending']).map(asMap).where((r) => !_refundLater.contains(asInt(r['id']))).toList();
    if (pending.isEmpty) return;
    final r = pending.first;
    _showing = true;
    final sent = await askRefundDetails(
      context,
      bookingId: asInt(r['id']),
      code: asString(r['booking_code']),
      amountLabel: asString(r['amount_label']),
      note: asString(r['note']),
    );
    _showing = false;
    if (!mounted) return;
    if (sent) {
      context.read<Session>().pollLive();
    } else {
      _refundLater.add(asInt(r['id']));
    }
  }

  Future<void> _show(Map<String, dynamic> popup) async {
    if (_showing) return;
    _showing = true;
    final guide = asString(popup['audience']) == 'guide';
    final changed = await showDialog<bool>(
      context: context,
      barrierDismissible: !guide,
      builder: (_) => PopScope(
        canPop: !guide,
        child: _ReplacementDialog(popup: popup),
      ),
    );
    _showing = false;
    if (!mounted) return;
    if (changed == true) {
      _key = '';
      _popup = null;
      context.read<Session>().pollLive();
      _poll(force: true);
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

class _ReplacementDialog extends StatefulWidget {
  const _ReplacementDialog({required this.popup});

  final Map<String, dynamic> popup;

  @override
  State<_ReplacementDialog> createState() => _ReplacementDialogState();
}

class _ReplacementDialogState extends State<_ReplacementDialog> {
  static const _loadingLabels = {
    'guide_accept': 'Accepting…',
    'guide_decline': 'Declining…',
    'tourist_accept': 'Confirming…',
    'tourist_other': 'Finding another guide…',
    'tourist_move': 'Moving days…',
    'cancel': 'Cancelling…',
  };

  Timer? _tick;
  late DateTime _endsAt;
  int _left = 0;
  String? _sending;
  bool? _ok;
  String _message = '';
  String? _moveStart;

  Map<String, dynamic> get p => widget.popup;
  bool get _guide => asString(p['audience']) == 'guide';

  @override
  void initState() {
    super.initState();
    final moves = asList(p['moves']);
    if (moves.isNotEmpty) _moveStart = asString(asMap(moves.first)['start']);
    if (_guide) {
      _endsAt = DateTime.now().add(Duration(seconds: asInt(p['secs_left'])));
      _left = asInt(p['secs_left']);
      _tick = Timer.periodic(const Duration(seconds: 1), (_) {
        if (!mounted) return;
        setState(() => _left = _endsAt.difference(DateTime.now()).inSeconds.clamp(0, 1 << 30));
        if (_left <= 0) _tick?.cancel();
      });
    }
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _send(String action, {Map<String, dynamic> extra = const {}}) async {
    if (_sending != null) return;
    setState(() => _sending = action);
    final api = context.read<Session>().api;
    try {
      final body = <String, dynamic>{'action': action, ...extra};
      if (_guide) {
        body['offer_id'] = asInt(p['offer_id']);
      } else {
        body['booking_id'] = asInt(p['booking_id']);
      }
      final res = await api.postJson('/bookings/replacement.php', body);
      _finish(true, asString(res['message'], 'Done.'));
    } catch (e) {
      _finish(false, e.toString());
    }
  }

  Future<void> _refund() async {
    final paid = asBool(p['paid']);
    final api = context.read<Session>().api;
    Map<String, dynamic> fields = const {};
    String? qr;
    if (paid) {
      final pick = await showRefundSheet(
        context,
        title: 'Request a refund',
        lead:
            'We will refund ${asString(p['refund_amount_label'])} for the days you did not use. Where should we send it?',
      );
      if (pick == null) return;
      fields = refundFields(pick);
      qr = pick.qrPath;
    } else {
      final ok = await confirmAction(
        context,
        title: 'Cancel remaining days',
        message: 'Cancel the remaining days of this tour? Finished days stay as they are.',
        confirmLabel: 'Yes, cancel',
        danger: true,
      );
      if (!ok) return;
    }
    if (!mounted) return;
    setState(() => _sending = 'cancel');
    try {
      final res = await api.updateBooking(
        asInt(p['booking_id']),
        'cancel',
        extra: fields,
        filePath: qr,
      );
      _finish(true, asString(res['message'], 'Booking cancelled.'));
    } catch (e) {
      _finish(false, e.toString());
    }
  }

  void _finish(bool ok, String message) {
    if (!mounted) return;
    _tick?.cancel();
    setState(() {
      _sending = null;
      _ok = ok;
      _message = message;
    });
  }

  String _fmt(int secs) => '${secs ~/ 60}:${(secs % 60).toString().padLeft(2, '0')}';

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 118, child: Text(label, style: const TextStyle(color: CtColors.muted))),
            Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w700))),
          ],
        ),
      );

  Widget _button(String action, String label, {bool outlined = false, VoidCallback? onTap}) {
    final busy = _sending == action;
    final disabled = _sending != null || (_guide && _left <= 0);
    final child = busy
        ? Row(mainAxisSize: MainAxisSize.min, children: [
            const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
            const SizedBox(width: 8),
            Flexible(child: Text(_loadingLabels[action] ?? 'Please wait…')),
          ])
        : Text(label);
    final tap = disabled
        ? null
        : (onTap ?? () => _send(action, extra: action == 'tourist_move' ? {'new_start': _moveStart} : const {}));
    return outlined
        ? OutlinedButton(style: compactOutlined(), onPressed: tap, child: child)
        : FilledButton(style: compactFilled(), onPressed: tap, child: child);
  }

  @override
  Widget build(BuildContext context) {
    final done = _ok != null;
    final guideCard = asMap(p['guide']);
    final state = asString(p['state']);
    final moves = asList(p['moves']).map(asMap).toList();

    final body = <Widget>[
      Text(asString(p['lead']), style: const TextStyle(height: 1.4)),
      const SizedBox(height: 12),
    ];

    if (_guide) {
      body.add(Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: CtColors.tealDark, borderRadius: BorderRadius.circular(12)),
        child: Column(children: [
          if (asString(p['tour']).isNotEmpty) _row('Tour', asString(p['tour'])),
          _row('Days to cover', asList(p['days']).map((e) => '$e').join('\n')),
          _row('Tourist', asString(p['tourist'], '—')),
          _row('Guests', '${asInt(p['party_size'], 1)}'),
          if (asBool(p['is_pwd'])) _row('Accessibility', 'Accessibility needs'),
        ]),
      ));
      if (!done) {
        body.add(const SizedBox(height: 12));
        body.add(Row(children: [
          Icon(_left > 0 ? Icons.hourglass_top : Icons.hourglass_bottom,
              color: _left <= 120 ? CtColors.coral : CtColors.gold, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              _left > 0 ? 'Time left to answer: ${_fmt(_left)}' : 'This request expired. No action is needed.',
              style: TextStyle(color: _left <= 120 ? CtColors.coral : CtColors.ink, fontWeight: FontWeight.w600),
            ),
          ),
        ]));
      }
    } else if (state == 'awaiting_tourist') {
      final rating = asDouble(guideCard['rating']);
      body.add(Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: CtColors.tealDark, borderRadius: BorderRadius.circular(12)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Avatar(name: asString(guideCard['name']), url: asString(guideCard['avatar_url']), radius: 26),
            const SizedBox(width: 12),
            Expanded(
              child: Column(children: [
                _row('Replacement', asString(guideCard['name'], 'Tour guide')),
                _row('Languages', asString(guideCard['languages'], '—')),
                _row('Experience', asString(guideCard['experience'], '—')),
                if (rating > 0) _row('Rating', '${rating.toStringAsFixed(1)} ★'),
              ]),
            ),
          ],
        ),
      ));
      body.add(Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Text('Booking #${asString(p['booking_code'])} · ${asString(p['when'])}',
            style: const TextStyle(color: CtColors.muted, fontSize: 13)),
      ));
    } else if (!done) {
      body.add(const Text('Move to another day', style: TextStyle(fontWeight: FontWeight.w800)));
      if (moves.isEmpty) {
        body.add(Text(
          'No guide is free in the next ${asInt(p['move_window_days'])} days. The Tourism Office has been told and may still assign one.',
          style: const TextStyle(color: CtColors.muted),
        ));
      } else {
        body.add(const Text('A guide is free on these dates:', style: TextStyle(color: CtColors.muted, fontSize: 13)));
        body.add(const SizedBox(height: 6));
        body.add(Wrap(
          spacing: 6,
          runSpacing: 6,
          children: moves
              .map((m) => ChoiceChip(
                    label: Text(asString(m['label'])),
                    selected: _moveStart == asString(m['start']),
                    onSelected: _sending != null ? null : (_) => setState(() => _moveStart = asString(m['start'])),
                  ))
              .toList(),
        ));
        body.add(const SizedBox(height: 8));
        body.add(Align(alignment: Alignment.centerLeft, child: _button('tourist_move', 'Move remaining days')));
      }
      body.add(const Divider(height: 28));
      body.add(const Text('Request a refund', style: TextStyle(fontWeight: FontWeight.w800)));
      body.add(Text(
        asBool(p['paid'])
            ? 'We will refund ${asString(p['refund_amount_label'])} for the days you did not use.'
            : 'This cancels the remaining days. Finished days stay as they are.',
        style: const TextStyle(color: CtColors.muted, fontSize: 13),
      ));
      body.add(const SizedBox(height: 8));
      body.add(Align(
        alignment: Alignment.centerLeft,
        child: _button(
          'cancel',
          asBool(p['paid']) ? 'Cancel remaining days and refund' : 'Cancel remaining days',
          outlined: true,
          onTap: _refund,
        ),
      ));
      body.add(const SizedBox(height: 10));
      body.add(
          const Text('The Tourism Office has been notified.', style: TextStyle(color: CtColors.muted, fontSize: 13)));
    }

    if (done) {
      body.add(const SizedBox(height: 12));
      body.add(NoticeBox(
        _message,
        tone: _ok! ? CtColors.sea : CtColors.coral,
        icon: _ok! ? Icons.check_circle_outline : Icons.error_outline,
      ));
    }

    List<Widget> actions;
    if (done) {
      actions = [
        FilledButton(style: compactFilled(), onPressed: () => Navigator.pop(context, true), child: const Text('Close'))
      ];
    } else if (_guide) {
      actions = [
        _button('guide_decline', 'Decline', outlined: true),
        _button('guide_accept', 'Accept'),
      ];
      if (_left <= 0) {
        actions = [
          FilledButton(
              style: compactFilled(), onPressed: () => Navigator.pop(context, true), child: const Text('Close'))
        ];
      }
    } else if (state == 'awaiting_tourist') {
      actions = [
        _button('tourist_other', 'Choose another guide', outlined: true),
        _button('tourist_accept', 'Accept replacement'),
      ];
    } else {
      actions = [
        TextButton(onPressed: _sending != null ? null : () => Navigator.pop(context, false), child: const Text('Later'))
      ];
    }

    return AlertDialog(
      backgroundColor: CtColors.surface,
      title: Row(children: [
        Icon(_guide ? Icons.front_hand_outlined : Icons.swap_horiz, color: CtColors.gold),
        const SizedBox(width: 10),
        Expanded(child: Text(asString(p['title'], 'Replacement'), style: const TextStyle(fontSize: 18))),
      ]),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: body),
      ),
      actionsOverflowButtonSpacing: 8,
      actions: actions,
    );
  }
}
