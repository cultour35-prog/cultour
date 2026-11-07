import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';

/// GCash checkout, same states as tourist/pay.php. "Pay now" stands in for scanning the web QR.
class PayScreen extends StatefulWidget {
  const PayScreen({super.key, required this.bookingId});

  final int bookingId;

  @override
  State<PayScreen> createState() => _PayScreenState();
}

class _PayScreenState extends State<PayScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  final _ref = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _ref.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final res =
          await context.read<Session>().api.getJson('/payments/checkout.php', {'booking_id': '${widget.bookingId}'});
      if (!mounted) return;
      setState(() {
        _data = asMap(res['data']);
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(String action, {String ref = ''}) async {
    final api = context.read<Session>().api;
    final res = await runApi(
      context,
      () => api.postJson('/payments/checkout.php', {
        'booking_id': widget.bookingId,
        'action': action,
        if (ref.isNotEmpty) 'payment_reference': ref,
      }),
    );
    if (res != null && mounted) {
      setState(() => _data = asMap(res['data']));
      context.read<Session>().pollLive();
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return Scaffold(
      appBar: AppBar(title: const Text('GCash payment')),
      body: d == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not open checkout', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(colors: [Color(0xFF0057E4), Color(0xFF007DFE)]),
                    borderRadius: BorderRadius.circular(18),
                  ),
                  child: const Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('CulTour checkout', style: TextStyle(color: Colors.white70, fontSize: 13)),
                            Text('GCash',
                                style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
                          ],
                        ),
                      ),
                      Icon(Icons.account_balance_wallet_outlined, color: Colors.white, size: 32),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                ..._body(d),
              ],
            ),
    );
  }

  List<Widget> _body(Map<String, dynamic> d) {
    final pay = asString(d['payment_status']);
    final ref = asString(d['reference']);
    if (pay == 'paid') {
      return [
        NoticeBox(
          'Already paid${ref.isNotEmpty ? ' · Ref $ref' : ''}.'
          '${asString(d['booking_status']) == 'pending' ? ' Your tour guide still needs to accept this booking.' : ''}',
          tone: CtColors.sea,
          icon: Icons.check_circle_outline,
        ),
        OutlinedButton(onPressed: () => Navigator.pop(context), child: const Text('Back to bookings')),
      ];
    }
    if (pay == 'pending_review') {
      return [
        NoticeBox(
          'Reference submitted${ref.isNotEmpty ? ' · Ref $ref' : ''}. Waiting for the Tourism Office to confirm this GCash payment.',
          tone: CtColors.sea,
        ),
        OutlinedButton(onPressed: () => Navigator.pop(context), child: const Text('Back to bookings')),
      ];
    }
    return [
      SectionCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Amount to pay', style: TextStyle(color: CtColors.muted)),
            Text(asString(d['amount_label']), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w800)),
            const SizedBox(height: 10),
            InfoRow('Pay to', asString(d['pay_to'])),
            InfoRow('Booking', asString(d['booking_code'])),
            InfoRow('Tour guide', asString(d['guide_name'])),
            InfoRow('Date', asString(d['date_label'])),
            InfoRow('Guests', '${asInt(d['party_size'], 1)}'),
          ],
        ),
      ),
      if (asBool(d['can_pay'])) ...[
        AsyncButton(
          label: 'Pay ${asString(d['amount_label'])} with GCash',
          busyLabel: 'Paying…',
          compact: false,
          icon: Icons.lock_outline,
          onPressed: () async {
            final ok = await confirmAction(
              context,
              title: 'Confirm payment',
              message:
                  'Pay ${asString(d['amount_label'])} to ${asString(d['pay_to'])} for ${asString(d['booking_code'])}?',
              confirmLabel: 'Pay now',
            );
            if (ok) await _act('pay');
          },
        ),
        const SizedBox(height: 10),
        AsyncButton(
          label: 'Pay at Tourism Office instead',
          outlined: true,
          compact: false,
          onPressed: () => _act('pay_at_office'),
        ),
        const SizedBox(height: 20),
        SectionCard(
          title: 'Already paid in GCash?',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Save your GCash / Maya reference so the Tourism Office can confirm it.',
                  style: TextStyle(color: CtColors.muted, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: _ref,
                maxLength: 120,
                decoration: const InputDecoration(labelText: 'Reference number'),
              ),
              AsyncButton(
                label: 'Save reference',
                outlined: true,
                onPressed: () async {
                  final r = _ref.text.trim();
                  if (r.isEmpty) {
                    showCulTourError(context, 'Enter the reference number.');
                    return;
                  }
                  await _act('reference', ref: r);
                },
              ),
            ],
          ),
        ),
      ] else
        const NoticeBox('This booking cannot be paid right now.'),
    ];
  }
}
