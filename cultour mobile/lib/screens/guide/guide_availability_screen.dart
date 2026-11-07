import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../session.dart';
import '../../theme.dart';
import '../../util/jsonx.dart';
import '../../widgets/kit.dart';
import '../../widgets/ui.dart';

/// Weekly hours and blocked dates (tourguide/availability.php).
class GuideAvailabilityScreen extends StatefulWidget {
  const GuideAvailabilityScreen({super.key});

  @override
  State<GuideAvailabilityScreen> createState() => _GuideAvailabilityScreenState();
}

class _GuideAvailabilityScreenState extends State<GuideAvailabilityScreen> {
  Map<String, dynamic>? _data;
  List<Map<String, dynamic>> _days = [];
  Object? _error;
  final _reason = TextEditingController();
  DateTime? _blockDate;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  void _apply(Map<String, dynamic> data) {
    _data = data;
    _days = asList(data['days']).map((e) => Map<String, dynamic>.from(asMap(e))).toList();
  }

  Future<void> _load() async {
    try {
      final res = await context.read<Session>().api.getJson('/guide/availability.php');
      if (!mounted) return;
      setState(() {
        _apply(asMap(res['data']));
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _post(Map<String, dynamic> body) async {
    final api = context.read<Session>().api;
    final res = await runApi(context, () => api.postJson('/guide/availability.php', body));
    if (res != null && mounted) setState(() => _apply(asMap(res['data'])));
  }

  Future<void> _pickTime(Map<String, dynamic> day, String field) async {
    final current = parseTimeOfDay(asString(day[field])) ??
        (field == 'start' ? const TimeOfDay(hour: 8, minute: 0) : const TimeOfDay(hour: 17, minute: 0));
    final t = await showTimePicker(context: context, initialTime: current);
    if (t != null) setState(() => day[field] = formatTime12(t));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Availability')),
      body: _data == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load availability', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
                children: [
                  if (!asBool(_data!['has_schedule']))
                    const NoticeBox('Tourists cannot book you until you set weekly hours.'),
                  SectionCard(
                    title: 'Weekly hours',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Pick the days you guide and your start and end time. Bookings close before your end time so tours can finish.',
                          style: TextStyle(color: CtColors.muted, fontSize: 13),
                        ),
                        const SizedBox(height: 8),
                        ..._days.map(_dayRow),
                        const SizedBox(height: 12),
                        AsyncButton(
                          label: 'Save weekly hours',
                          busyLabel: 'Saving…',
                          compact: false,
                          onPressed: () => _post({
                            'action': 'weekly',
                            'days': _days
                                .map((d) => {
                                      'day': asInt(d['day']),
                                      'on': asBool(d['on']),
                                      'start': asString(d['start']),
                                      'end': asString(d['end']),
                                    })
                                .toList(),
                          }),
                        ),
                      ],
                    ),
                  ),
                  SectionCard(
                    title: 'Blocked dates',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Block a day you cannot guide (holiday, errand, sick day).',
                            style: TextStyle(color: CtColors.muted, fontSize: 13)),
                        const SizedBox(height: 10),
                        OutlinedButton.icon(
                          onPressed: () async {
                            final now = DateTime.now();
                            final d = await showDatePicker(
                              context: context,
                              firstDate: DateTime(now.year, now.month, now.day),
                              lastDate: now.add(const Duration(days: 730)),
                              initialDate: _blockDate ?? now,
                            );
                            if (d != null) setState(() => _blockDate = d);
                          },
                          icon: const Icon(Icons.event),
                          label: Text(_blockDate == null ? 'Choose date' : ymd(_blockDate!)),
                        ),
                        const SizedBox(height: 8),
                        TextField(
                            controller: _reason, decoration: const InputDecoration(labelText: 'Reason (optional)')),
                        const SizedBox(height: 8),
                        AsyncButton(
                          label: 'Block date',
                          outlined: true,
                          onPressed: _blockDate == null
                              ? null
                              : () async {
                                  await _post({
                                    'action': 'block',
                                    'block_date': ymd(_blockDate!),
                                    'reason': _reason.text.trim(),
                                  });
                                  if (mounted) {
                                    setState(() {
                                      _blockDate = null;
                                      _reason.clear();
                                    });
                                  }
                                },
                        ),
                        const SizedBox(height: 12),
                        if (asList(_data!['blocks']).isEmpty)
                          const Text('No blocked dates.', style: TextStyle(color: CtColors.muted)),
                        ...asList(_data!['blocks']).map(asMap).map(
                              (b) => ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: const Icon(Icons.block, color: CtColors.coral),
                                title: Text(asString(b['label'])),
                                subtitle: Text(asString(b['reason'])),
                                trailing: TextButton(
                                  onPressed: () => _post({'action': 'unblock', 'id': asInt(b['id'])}),
                                  child: const Text('Unblock'),
                                ),
                              ),
                            ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _dayRow(Map<String, dynamic> d) {
    final on = asBool(d['on']);
    final start = asString(d['start']);
    final end = asString(d['end']);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          SizedBox(
            width: 92,
            child: Row(children: [
              Checkbox(
                value: on,
                onChanged: (v) => setState(() {
                  d['on'] = v ?? false;
                  if (d['on'] == true) {
                    if (asString(d['start']).isEmpty) d['start'] = '8:00 AM';
                    if (asString(d['end']).isEmpty) d['end'] = '5:00 PM';
                  }
                }),
              ),
              Text(asString(d['label']), style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
          ),
          Expanded(
            child: OutlinedButton(
              style: compactOutlined(),
              onPressed: on ? () => _pickTime(d, 'start') : null,
              child: Text(start.isEmpty ? 'Start' : start),
            ),
          ),
          const Padding(padding: EdgeInsets.symmetric(horizontal: 6), child: Text('–')),
          Expanded(
            child: OutlinedButton(
              style: compactOutlined(),
              onPressed: on ? () => _pickTime(d, 'end') : null,
              child: Text(end.isEmpty ? 'End' : end),
            ),
          ),
        ],
      ),
    );
  }
}
