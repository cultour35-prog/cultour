import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'stay_ride_screen.dart';
import 'booking_detail_screen.dart';

/// Multi-day tour builder. Tourists edit their own tours; guides edit suggested-tour templates (no dates).
class ItineraryEditorScreen extends StatefulWidget {
  const ItineraryEditorScreen({super.key, required this.id, this.guideTemplate = false});

  final int id;
  final bool guideTemplate;

  @override
  State<ItineraryEditorScreen> createState() => _ItineraryEditorScreenState();
}

class _ItineraryEditorScreenState extends State<ItineraryEditorScreen> with LiveReloadMixin {
  Map<String, dynamic>? _it;
  Object? _error;
  bool _saving = false;
  bool _reordering = false;
  bool _allowPop = false;
  bool _leaveDialog = false;
  int _day = 1;
  final _title = TextEditingController();
  final _titleFocus = FocusNode();

  bool get _guide => widget.guideTemplate;
  String get _postPath => _guide ? '/guide/itineraries.php' : '/itineraries/save.php';

  @override
  String liveKey(Session session) => session.itinerariesVersion;

  @override
  void onLiveReload() {
    if (_titleFocus.hasFocus || _saving) return;
    _load();
  }

  @override
  void initState() {
    super.initState();
    _titleFocus.addListener(() {
      if (!_titleFocus.hasFocus && _it != null && _title.text.trim() != asString(_it!['title'])) {
        _save({'action': 'update_title', 'title': _title.text.trim()});
      }
    });
    _load();
  }

  @override
  void dispose() {
    _title.dispose();
    _titleFocus.dispose();
    super.dispose();
  }

  void _apply(Map<String, dynamic> data) {
    _it = data;
    if (!_titleFocus.hasFocus) _title.text = asString(data['title']);
    final count = asInt(data['day_count'], 1);
    if (_day > count) _day = count;
    if (_day < 1) _day = 1;
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final res = _guide
          ? await api.getJson('/guide/itineraries.php', {'id': '${widget.id}'})
          : await api.getJson('/itineraries/detail.php', {'id': '${widget.id}'});
      if (!mounted) return;
      setState(() {
        _apply(asMap(res['data']));
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<bool> _save(Map<String, dynamic> body, {bool quiet = true}) async {
    final api = context.read<Session>().api;
    setState(() => _saving = true);
    try {
      final res = await api.postJson(_postPath, {'itinerary_id': widget.id, ...body});
      if (!mounted) return true;
      if (body['action'] == 'delete') {
        return true;
      }
      if (res['data'] != null) setState(() => _apply(asMap(res['data'])));
      final warn = asString(res['warning']);
      final msg = asString(res['message']);
      if (warn.isNotEmpty) {
        showCulTourMessage(context, warn);
      } else if (!quiet && msg.isNotEmpty) {
        showCulTourMessage(context, msg);
      }
      return true;
    } on ApiException catch (e) {
      if (!mounted) return false;
      if (e.data['data'] != null) setState(() => _apply(asMap(e.data['data'])));
      if (e.status == 409 && body['action'] == 'add_stop' && asBool(e.data['duplicate'])) {
        final again =
            await confirmAction(context, title: 'Already on this tour', message: e.message, confirmLabel: 'Add anyway');
        if (again) return _save({...body, 'allow_duplicate': true});
        return false;
      }
      showCulTourError(context, e);
      return false;
    } catch (e) {
      if (mounted) showCulTourError(context, e);
      return false;
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  List<Map<String, dynamic>> get _stops => asList(_it?['stops']).map(asMap).toList();
  List<Map<String, dynamic>> get _days => asList(_it?['days']).map(asMap).toList();
  int get _dayCount => asInt(_it?['day_count'], 1);
  bool get _locked => asBool(_it?['locked']);
  String get _startDate => asString(_it?['start_date']);
  String get _meetup => asString(_it?['meetup_label']);

  Map<String, dynamic>? _dayData(int n) {
    for (final d in _days) {
      if (asInt(d['day']) == n) return d;
    }
    return null;
  }

  List<Map<String, dynamic>> _stopsForDay(int n) {
    final d = _dayData(n);
    if (d == null) return n == 1 ? _stops : const [];
    final byId = {for (final s in _stops) asInt(s['id']): s};
    return asList(d['stop_ids']).map((id) => byId[asInt(id)]).whereType<Map<String, dynamic>>().toList();
  }

  String _t12(String raw) {
    final t = parseTimeOfDay(raw);
    return t == null ? raw : formatTime12(t);
  }

  Future<void> _pickStartDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime.tryParse(_startDate) ?? now.add(const Duration(days: 1)),
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 365)),
    );
    if (picked == null) return;
    await _save({
      'action': 'update_meetup',
      'start_date': ymd(picked),
      'meetup_time': _meetup.isEmpty ? '8:00 AM' : _meetup,
    });
  }

  Future<void> _pickMeetup() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: parseTimeOfDay(_meetup) ?? const TimeOfDay(hour: 8, minute: 0),
    );
    if (picked == null) return;
    await _save({
      'action': 'update_meetup',
      if (_startDate.isNotEmpty) 'start_date': _startDate,
      'meetup_time': formatTime12(picked),
      if (_guide) 'is_multiday': _dayCount > 1,
      if (_guide) 'day_count': _dayCount,
    });
  }

  Future<void> _askDayCount() async {
    final controller = TextEditingController(text: '${_dayCount < 8 ? 8 : _dayCount}');
    final n = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: const Text('How many days?'),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(hintText: '8 to 30'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, int.tryParse(controller.text.trim())),
            child: const Text('Set days'),
          ),
        ],
      ),
    );
    if (n == null || n < 1 || n > 30) {
      if (n != null && mounted) showCulTourMessage(context, 'Enter a number from 1 to 30.');
      return;
    }
    await _setDayCount(n);
  }

  Future<void> _setDayCount(int n) async {
    if (n == _dayCount || n < 1 || n > 30) return;
    var drop = false;
    if (n < _dayCount) {
      final later = [for (var d = n + 1; d <= _dayCount; d++) ..._stopsForDay(d)];
      if (later.isNotEmpty) {
        final ok = await confirmAction(
          context,
          title: 'Shorten this tour?',
          message: 'Days after Day $n have ${later.length} stop(s). They will be removed.',
          confirmLabel: 'Remove them',
          danger: true,
        );
        if (!ok) return;
        drop = true;
      }
    }
    await _save({
      'action': 'update_meetup',
      if (_startDate.isNotEmpty) 'start_date': _startDate,
      'meetup_time': _meetup.isEmpty ? '8:00 AM' : _meetup,
      'is_multiday': n > 1 ? 1 : 0,
      'day_count': n,
      if (drop) 'drop_later_stops': 1,
    });
  }

  Future<void> _pickDayDate(int day) async {
    final d = _dayData(day);
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime.tryParse(asString(d?['date'])) ?? DateTime.tryParse(_startDate) ?? now,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 365)),
    );
    if (picked == null) return;
    await _save({'action': 'update_day_date', 'day_number': day, 'date': ymd(picked)});
  }

  Future<void> _dayMeetup(int day) async {
    final d = _dayData(day);
    final meet = asMap(d?['meetup']);
    final stops = _stopsForDay(day);
    final choice = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      backgroundColor: CtColors.surface,
      isScrollControlled: true,
      builder: (ctx) => SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(8, 16, 8, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: Text('Day $day meetup', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              ),
              ListTile(
                leading: const Icon(Icons.schedule),
                title: const Text('Change meetup time'),
                subtitle: Text(asString(meet['time'], _meetup)),
                onTap: () => Navigator.pop(ctx, {'time_only': 1}),
              ),
              ListTile(
                leading: const Icon(Icons.directions_boat_outlined),
                title: const Text('Meet at Ormoc City Port'),
                onTap: () => Navigator.pop(ctx, {'use_port': 1}),
              ),
              if (stops.isNotEmpty)
                const Padding(
                  padding: EdgeInsets.fromLTRB(16, 8, 16, 4),
                  child: Text('Or meet at a stop', style: TextStyle(color: CtColors.muted)),
                ),
              ...stops.map((s) => ListTile(
                    leading: const Icon(Icons.place_outlined),
                    title: Text(asString(s['stop_name'])),
                    onTap: () => Navigator.pop(ctx, {
                      if (asInt(s['destination_id']) > 0) 'destination_id': asInt(s['destination_id']),
                      if (asInt(s['business_id']) > 0) 'business_id': asInt(s['business_id']),
                    }),
                  )),
              if (!asBool(meet['is_default']))
                ListTile(
                  leading: const Icon(Icons.restart_alt),
                  title: const Text('Reset to default'),
                  onTap: () => Navigator.pop(ctx, {'clear': 1}),
                ),
            ],
          ),
        ),
      ),
    );
    if (choice == null || !mounted) return;
    if (choice['time_only'] == 1) {
      final picked = await showTimePicker(
        context: context,
        initialTime: parseTimeOfDay(asString(meet['time'], _meetup)) ?? const TimeOfDay(hour: 8, minute: 0),
      );
      if (picked == null) return;
      await _save(
          {'action': 'update_day_meetup', 'day_number': day, 'time_only': 1, 'meetup_time': formatTime12(picked)});
      return;
    }
    await _save({'action': 'update_day_meetup', 'day_number': day, ...choice});
  }

  Future<void> _addStop(int day) async {
    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: CtColors.bg,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _AddStopSheet(day: day, dayCount: _dayCount),
    );
    if (result == null) return;
    await _save({'action': 'add_stop', 'day_number': day, ...result});
  }

  Future<void> _reorderDay(int day, int oldIndex, int newIndex) async {
    final dayStops = _stopsForDay(day).map((s) => asInt(s['id'])).toList();
    if (newIndex > oldIndex) newIndex -= 1;
    final moved = dayStops.removeAt(oldIndex);
    dayStops.insert(newIndex, moved);
    final order = <int>[];
    for (var d = 1; d <= _dayCount; d++) {
      order.addAll(d == day ? dayStops : _stopsForDay(d).map((s) => asInt(s['id'])));
    }
    for (final s in _stops) {
      final id = asInt(s['id']);
      if (!order.contains(id)) order.add(id);
    }
    await _save({'action': 'reorder_stops', 'order': order});
  }

  Future<void> _stay(Map<String, dynamic> s) async {
    var v = asInt(s['visit_minutes'], 60).clamp(15, 240);
    final mins = await showModalBottomSheet<int>(
      context: context,
      backgroundColor: CtColors.surface,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(asString(s['stop_name']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              const SizedBox(height: 4),
              Text('Stay $v minutes', style: const TextStyle(color: CtColors.muted)),
              Slider(
                  value: v.toDouble(),
                  min: 15,
                  max: 240,
                  divisions: 15,
                  onChanged: (n) => setLocal(() => v = n.round())),
              FilledButton(onPressed: () => Navigator.pop(ctx, v), child: const Text('Save')),
            ],
          ),
        ),
      ),
    );
    if (mins != null) await _save({'action': 'update_stop_stay', 'item_id': asInt(s['id']), 'visit_minutes': mins});
  }

  void _viewStop(Map<String, dynamic> stop) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: CtColors.surface,
      builder: (_) => _StopDetailSheet(stop: stop),
    );
  }

  bool get _needsLeavePrompt {
    if (_locked || _it == null || _allowPop) return false;
    final savedTitle = asString(_it?['title']);
    final typed = _title.text.trim();
    final titleDirty = typed.isNotEmpty && typed != savedTitle;
    final named = savedTitle.isNotEmpty && savedTitle.toLowerCase() != 'unnamed';
    final hasContent = _stops.isNotEmpty || _startDate.isNotEmpty || named || titleDirty;
    return titleDirty || (hasContent && !asBool(_it?['kept_as_draft']));
  }

  Future<void> _tryLeave() async {
    if (!_needsLeavePrompt) {
      setState(() => _allowPop = true);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pop(context);
      });
      return;
    }
    if (_leaveDialog) return;
    _leaveDialog = true;
    final choice = await showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: const Text('Save this itinerary as a draft?'),
        content: const Text(
          'You’ve added details to this tour. Save it as a draft to keep it in your list, or discard it.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, 'keep'), child: const Text('Keep editing')),
          TextButton(
            onPressed: () => Navigator.pop(ctx, 'discard'),
            child: const Text('Discard itinerary', style: TextStyle(color: CtColors.coral)),
          ),
          FilledButton(
            style: compactFilled(),
            onPressed: () => Navigator.pop(ctx, 'save'),
            child: const Text('Save draft'),
          ),
        ],
      ),
    );
    if (!mounted || choice == null || choice == 'keep') {
      _leaveDialog = false;
      return;
    }
    if (choice == 'discard') {
      final ok = await _save({'action': 'delete'});
      if (!ok || !mounted) {
        _leaveDialog = false;
        return;
      }
    } else {
      final title = _title.text.trim();
      if (title.isNotEmpty && title != asString(_it?['title'])) {
        final titled = await _save({'action': 'update_title', 'title': title});
        if (!titled || !mounted) {
        _leaveDialog = false;
        return;
      }
      }
      final ok = await _save({'action': 'save_draft'});
      if (!ok || !mounted) {
        _leaveDialog = false;
        return;
      }
    }
    setState(() => _allowPop = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) Navigator.pop(context);
    });
  }

  Future<void> _menu(String v) async {
    switch (v) {
      case 'regen':
        final dayLabel = _dayCount > 1 ? 'Day $_day' : 'this day';
        final ok = await confirmAction(
          context,
          title: 'Auto-generate $dayLabel?',
          message: _dayCount > 1
              ? 'This replaces the stops on $dayLabel. Other days stay the same.'
              : 'This replaces the current stops with a suggested route.',
          confirmLabel: 'Generate',
        );
        if (!ok) break;
        final meet = asString(asMap(_dayData(_day)?['meetup'])['time']);
        await _save({
          'action': 'auto_regenerate',
          'day_number': _day,
          if (_dayCount > 1) 'is_multiday': 1,
          if (_dayCount > 1) 'day_count': _dayCount,
          if (meet.isNotEmpty) 'meetup_time': meet,
          if (meet.isEmpty && _meetup.isNotEmpty) 'meetup_time': _meetup,
        });
        if (mounted) setState(() => _reordering = false);
        break;
      case 'clear':
        final ok = await confirmAction(
          context,
          title: 'Clear all stops?',
          message: 'Every stop on every day will be removed.',
          confirmLabel: 'Clear',
          danger: true,
        );
        if (ok) await _save({'action': 'clear_stops'});
        break;
      case 'draft':
        if (await _save({'action': 'save_draft'}, quiet: false) && mounted) {
          showCulTourMessage(context, 'Saved as a draft.');
        }
        break;
      case 'delete':
        final ok = await confirmAction(
          context,
          title: 'Delete this tour?',
          message: 'This cannot be undone.',
          confirmLabel: 'Delete',
          danger: true,
        );
        if (ok && await _save({'action': 'delete'}) && mounted) Navigator.pop(context);
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final it = _it;
    return PopScope(
      canPop: _allowPop || !_needsLeavePrompt,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        _tryLeave();
      },
      child: Scaffold(
      appBar: AppBar(
        title: Text(_guide ? 'Suggested tour' : 'Tour'),
        bottom: _saving
            ? const PreferredSize(preferredSize: Size.fromHeight(2), child: LinearProgressIndicator(minHeight: 2))
            : null,
        actions: [
          if (it != null)
            PopupMenuButton<String>(
              onSelected: _menu,
              itemBuilder: (_) => [
                if (!_locked) const PopupMenuItem(value: 'clear', child: Text('Clear all stops')),
                if (!_locked && !(_guide && asBool(it['published'])))
                  const PopupMenuItem(value: 'draft', child: Text('Save as draft')),
                const PopupMenuItem(value: 'delete', child: Text('Delete tour')),
              ],
            ),
        ],
      ),
      body: it == null
          ? (_error != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load this tour', detail: '$_error')
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                children: [
                  if (_locked) _lockedBanner(it),
                  TextField(
                    controller: _title,
                    focusNode: _titleFocus,
                    enabled: !_locked,
                    maxLength: 120,
                    decoration: const InputDecoration(labelText: 'Tour name', counterText: ''),
                    onSubmitted: (_) => _titleFocus.unfocus(),
                  ),
                  const SizedBox(height: 12),
                  _scheduleCard(),
                  const SizedBox(height: 12),
                  _dayTabs(),
                  const SizedBox(height: 8),
                  _dayPanel(_day),
                  const SizedBox(height: 16),
                  ..._footer(it),
                ],
              ),
            ),
      ),
    );
  }

  Widget _lockedBanner(Map<String, dynamic> it) {
    final active = it['active_booking'] == null ? null : asMap(it['active_booking']);
    return NoticeBox(
      active == null
          ? 'This tour is booked and can no longer be edited.'
          : 'This tour is booked (${asString(active['booking_code'])} · ${asString(active['status'])}) and can no longer be edited.',
      icon: Icons.lock_outline,
      action: active == null
          ? null
          : OutlinedButton(
              style: compactOutlined(),
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => BookingDetailScreen(
                    bookingId: asInt(active['id']),
                    code: asString(active['booking_code']),
                  ),
                ),
              ),
              child: const Text('View booking'),
            ),
    );
  }

  Widget _chipButton(IconData icon, String label, VoidCallback? onTap) {
    return InkWell(
      onTap: _locked ? null : onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(color: CtColors.bg, borderRadius: BorderRadius.circular(12)),
        child: Row(
          children: [
            Icon(icon, size: 18, color: CtColors.muted),
            const SizedBox(width: 8),
            Expanded(child: Text(label, overflow: TextOverflow.ellipsis)),
          ],
        ),
      ),
    );
  }

  Widget _scheduleCard() {
    final party = asInt(_it?['party_size'], 0);
    return SectionCard(
      title: 'Schedule',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              if (!_guide) ...[
                Expanded(
                  child: _chipButton(
                    Icons.event,
                    _startDate.isEmpty ? 'Tour date' : _startDate,
                    _pickStartDate,
                  ),
                ),
                const SizedBox(width: 10),
              ],
              Expanded(
                  child: _chipButton(Icons.schedule, _meetup.isEmpty ? 'Meetup time' : 'Meet $_meetup', _pickMeetup)),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              const Expanded(child: Text('Days', style: TextStyle(fontWeight: FontWeight.w700))),
              IconButton(
                onPressed: _locked || _dayCount <= 1 ? null : () => _setDayCount(_dayCount - 1),
                icon: const Icon(Icons.remove_circle_outline),
              ),
              Text('$_dayCount', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              IconButton(
                onPressed: _locked || _dayCount >= 30
                    ? null
                    : () => _dayCount >= 7 ? _askDayCount() : _setDayCount(_dayCount + 1),
                icon: const Icon(Icons.add_circle_outline),
              ),
            ],
          ),
          if (!_guide)
            Row(
              children: [
                const Expanded(child: Text('Guests', style: TextStyle(fontWeight: FontWeight.w700))),
                IconButton(
                  onPressed: _locked || party <= 1
                      ? null
                      : () => _save({'action': 'update_party_size', 'party_size': party - 1}),
                  icon: const Icon(Icons.remove_circle_outline),
                ),
                Text(party < 1 ? '—' : '$party', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                IconButton(
                  onPressed: _locked || party >= 20
                      ? null
                      : () => _save({'action': 'update_party_size', 'party_size': party < 1 ? 1 : party + 1}),
                  icon: const Icon(Icons.add_circle_outline),
                ),
              ],
            ),
          if (_guide)
            const Text(
              'Suggested tours have no dates. Tourists pick the dates when they copy it.',
              style: TextStyle(color: CtColors.muted, fontSize: 13),
            ),
        ],
      ),
    );
  }

  Widget _dayTabs() {
    if (_dayCount <= 1) return const SizedBox.shrink();
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (var d = 1; d <= _dayCount; d++)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Text('Day $d · ${_stopsForDay(d).length}'),
                selected: _day == d,
                onSelected: (_) => setState(() => _day = d),
              ),
            ),
        ],
      ),
    );
  }

  Widget _stopTile(Map<String, dynamic> stop, int index, {required bool reorder}) {
    final api = context.read<Session>().api;
    final time = [
      _t12(asString(stop['start_time'])),
      _t12(asString(stop['end_time'])),
    ].where((e) => e.isNotEmpty).join(' – ');
    final tile = ListTile(
      contentPadding: const EdgeInsets.fromLTRB(8, 4, 4, 4),
      leading: SizedBox(
        width: 52,
        child: CoverImage(url: api.mediaUrl(asString(stop['cover_url'])), height: 48, radius: 10),
      ),
      title: Text('${index + 1}. ${asString(stop['stop_name'])}', maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        [
          if (!_guide && time.isNotEmpty) time,
          '${asInt(stop['visit_minutes'], 60)} min',
          if (asInt(stop['travel_minutes']) > 0) '${asInt(stop['travel_minutes'])} min travel',
        ].join(' · '),
        maxLines: 2,
      ),
      trailing: reorder
          ? const Icon(Icons.drag_handle, color: CtColors.gold)
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  tooltip: 'View',
                  icon: const Icon(Icons.visibility_outlined, size: 20),
                  onPressed: () => _viewStop(stop),
                ),
                if (!_locked)
                  IconButton(
                    icon: const Icon(Icons.close, size: 20),
                    onPressed: () => _save({'action': 'remove_stop', 'item_id': asInt(stop['id'])}),
                  ),
              ],
            ),
      onTap: _locked || reorder ? null : () => _stay(stop),
    );
    return Container(
      key: ValueKey(asInt(stop['id'])),
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: CtColors.bg, borderRadius: BorderRadius.circular(12)),
      child: reorder ? ReorderableDragStartListener(index: index, child: tile) : tile,
    );
  }

  Widget _dayPanel(int day) {
    final d = _dayData(day);
    final meet = asMap(d?['meetup']);
    final stops = _stopsForDay(day);
    final date = asString(d?['date']);
    final reordering = _reordering && !_locked && stops.length > 1;
    return SectionCard(
      title: _dayCount > 1 ? 'Day $day' : 'Stops',
      trailing: _locked
          ? null
          : TextButton.icon(onPressed: () => _addStop(day), icon: const Icon(Icons.add), label: const Text('Add stop')),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (!_locked) ...[
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: () => _menu('regen'),
                icon: const Icon(Icons.auto_awesome_outlined),
                label: Text(_dayCount > 1 ? 'Auto-generate Day $day' : 'Auto-generate stops'),
              ),
            ),
            const SizedBox(height: 8),
          ],
          if (!_guide && _startDate.isNotEmpty && _dayCount > 1) ...[
            _chipButton(Icons.event, date.isEmpty ? 'Choose date' : date, () => _pickDayDate(day)),
            const SizedBox(height: 8),
          ],
          InkWell(
            onTap: _locked || _guide ? null : () => _dayMeetup(day),
            borderRadius: BorderRadius.circular(12),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: CtColors.tealDark, borderRadius: BorderRadius.circular(12)),
              child: Row(
                children: [
                  const Icon(Icons.flag_outlined, color: CtColors.gold, size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Meetup · ${asString(meet['label'], 'Ormoc City Port')}${asString(meet['time']).isNotEmpty ? ' · ${asString(meet['time'])}' : ''}',
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                  ),
                  if (!_locked && !_guide) const Icon(Icons.edit_outlined, size: 18, color: CtColors.muted),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          if (!_locked && stops.length > 1) ...[
            OutlinedButton.icon(
              onPressed: () => setState(() => _reordering = !_reordering),
              icon: Icon(_reordering ? Icons.check : Icons.swap_vert),
              label: Text(_reordering ? 'Done' : 'Reorder stops'),
            ),
            const SizedBox(height: 8),
          ],
          if (stops.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 18),
              child: Text('Add destinations or businesses in the order you’ll visit them.',
                  style: TextStyle(color: CtColors.muted)),
            )
          else if (!reordering)
            Column(
              children: [for (var i = 0; i < stops.length; i++) _stopTile(stops[i], i, reorder: false)],
            )
          else
            ReorderableListView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              buildDefaultDragHandles: false,
              itemCount: stops.length,
              onReorder: (a, b) => _reorderDay(day, a, b),
              itemBuilder: (_, i) => _stopTile(stops[i], i, reorder: true),
            ),
          if (reordering)
            const Text('Drag the handles to rearrange these stops.',
                style: TextStyle(color: CtColors.muted, fontSize: 12))
          else if (!_locked && stops.isNotEmpty)
            const Text('Tap a stop to change how long you stay.',
                style: TextStyle(color: CtColors.muted, fontSize: 12)),
        ],
      ),
    );
  }

  List<Widget> _footer(Map<String, dynamic> it) {
    if (_guide) {
      final published = asBool(it['published']);
      return [
        if (published)
          const NoticeBox('Published. Tourists can see and copy this tour.', tone: CtColors.sea, icon: Icons.public),
        AsyncButton(
          label: published ? 'Update published tour' : 'Publish tour',
          compact: false,
          icon: Icons.publish_outlined,
          onPressed: _stops.isEmpty
              ? null
              : () async {
                  final ok = await _save({'action': 'publish', 'title': _title.text.trim()}, quiet: false);
                  if (ok && mounted && !published) Navigator.pop(context);
                },
        ),
      ];
    }
    if (_locked) return const [];
    return [
      FilledButton.icon(
        onPressed: _stops.isEmpty
            ? null
            : () {
                if (_startDate.isEmpty) {
                  showCulTourMessage(context, 'Choose a tour date first.');
                  return;
                }
                final party = asInt(it['party_size'], 0);
                if (party < 1) {
                  showCulTourMessage(context, 'Enter the number of guests first.');
                  return;
                }
                final dates = <int, String>{
                  for (final d in _days)
                    if (asString(d['date']).isNotEmpty) asInt(d['day']): asString(d['date']),
                };
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => StayRideScreen(
                      itineraryId: widget.id,
                      itineraryTitle: asString(it['title']),
                      initialDate: _startDate,
                      initialTime: asString(_it?['meetup_time'], _meetup),
                      initialParty: party,
                      initialGuideId: asInt(it['draft_guide_id'], 0),
                      dayCount: _dayCount,
                      dayDates: dates,
                      rideDeclined: asInt(it['transportation_declined']) == 1,
                      rideBusinessId: asInt(it['transportation_business_id']),
                      rideServiceId: asInt(it['transportation_service_id']),
                      rideLines: asList(it['transportation_lines']).map(asMap).toList(),
                    ),
                  ),
                ).then((_) => _load());
              },
        icon: const Icon(Icons.arrow_forward),
        label: const Text('Proceed'),
      ),
    ];
  }
}

class _AddStopSheet extends StatefulWidget {
  const _AddStopSheet({required this.day, required this.dayCount});

  final int day;
  final int dayCount;

  @override
  State<_AddStopSheet> createState() => _AddStopSheetState();
}

class _AddStopSheetState extends State<_AddStopSheet> {
  String _q = '';
  int _visit = 60;
  int _kind = 0;
  late Future<List<Place>> _dests;
  late Future<List<Business>> _biz;

  @override
  void initState() {
    super.initState();
    final api = context.read<Session>().api;
    _dests = api.destinations();
    _biz = api.businesses();
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.85,
      builder: (_, scroll) {
        return Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          child: Column(
            children: [
              Container(
                  width: 36,
                  height: 4,
                  decoration: BoxDecoration(color: CtColors.muted, borderRadius: BorderRadius.circular(99))),
              const SizedBox(height: 12),
              Text(widget.dayCount > 1 ? 'Add a stop to Day ${widget.day}' : 'Add a stop',
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const SizedBox(height: 12),
              TextField(
                decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Search places'),
                onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
              ),
              const SizedBox(height: 10),
              SegmentedButton<int>(
                segments: const [
                  ButtonSegment(value: 0, label: Text('Places')),
                  ButtonSegment(value: 1, label: Text('Businesses')),
                ],
                selected: {_kind},
                onSelectionChanged: (s) => setState(() => _kind = s.first),
              ),
              const SizedBox(height: 8),
              Text('Stay $_visit min', style: const TextStyle(color: CtColors.muted)),
              Slider(
                  value: _visit.toDouble(),
                  min: 15,
                  max: 240,
                  divisions: 15,
                  onChanged: (n) => setState(() => _visit = n.round())),
              Expanded(
                child: _kind == 0
                    ? FutureBuilder<List<Place>>(
                        future: _dests,
                        builder: (context, snap) {
                          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
                          final items = snap.data!
                              .where((p) =>
                                  _q.isEmpty ||
                                  p.name.toLowerCase().contains(_q) ||
                                  (p.location ?? '').toLowerCase().contains(_q))
                              .toList();
                          return ListView.builder(
                            controller: scroll,
                            itemCount: items.length,
                            itemBuilder: (_, i) {
                              final p = items[i];
                              return ListTile(
                                leading: SizedBox(
                                  width: 48,
                                  child:
                                      CoverImage(url: api.mediaUrl(p.coverUrl ?? p.coverImage), height: 44, radius: 8),
                                ),
                                title: Text(p.name),
                                subtitle: Text(p.location ?? ''),
                                onTap: () => Navigator.pop(context, {'destination_id': p.id, 'visit_minutes': _visit}),
                              );
                            },
                          );
                        },
                      )
                    : FutureBuilder<List<Business>>(
                        future: _biz,
                        builder: (context, snap) {
                          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
                          final items = snap.data!.where((b) {
                            final type = (b.typeName ?? '').trim().toLowerCase();
                            const hidden = {
                              'accommodation',
                              'hotel',
                              'resort',
                              'transportation',
                              'tour and travel',
                              'tours and travel',
                              'travel and tours',
                              'travel agency',
                            };
                            if (hidden.contains(type)) return false;
                            return _q.isEmpty || b.name.toLowerCase().contains(_q);
                          }).toList();
                          return ListView.builder(
                            controller: scroll,
                            itemCount: items.length,
                            itemBuilder: (_, i) {
                              final b = items[i];
                              return ListTile(
                                title: Text(b.name),
                                subtitle: Text(b.typeName ?? b.address ?? ''),
                                onTap: () => Navigator.pop(context, {'business_id': b.id, 'visit_minutes': _visit}),
                              );
                            },
                          );
                        },
                      ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _StopDetailSheet extends StatefulWidget {
  const _StopDetailSheet({required this.stop});
  final Map<String, dynamic> stop;

  @override
  State<_StopDetailSheet> createState() => _StopDetailSheetState();
}

class _StopDetailSheetState extends State<_StopDetailSheet> {
  Map<String, dynamic>? _data;
  List<Map<String, dynamic>> _services = const [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    final biz = asInt(widget.stop['business_id']);
    final dest = asInt(widget.stop['destination_id']);
    try {
      if (biz > 0) {
        final res = await api.getJson('/businesses/detail.php', {'id': '$biz'});
        final data = asMap(res['data']);
        if (!mounted) return;
        setState(() {
          _data = data;
          _services = asList(data['services']).map(asMap).toList();
        });
        return;
      }
      final res = await api.getJson('/destinations/detail.php', {'id': '$dest'});
      if (!mounted) return;
      setState(() {
        _data = asMap(res['data']);
        _services = asList(res['services']).map(asMap).toList();
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final name = asString(_data?['name'], asString(widget.stop['stop_name'], 'Place'));
    final about = asString(_data?['description']);
    final where = asString(_data?['location'], asString(_data?['address'], asString(widget.stop['location'])));
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
        child: _data == null && _error == null
            ? const SizedBox(height: 160, child: Center(child: CircularProgressIndicator()))
            : ListView(
                shrinkWrap: true,
                children: [
                  Text(name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                  if (where.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text(where, style: const TextStyle(color: CtColors.muted)),
                  ],
                  if (about.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Text(about),
                  ],
                  const SizedBox(height: 16),
                  const Text('Services offered', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  const SizedBox(height: 8),
                  if (_services.isEmpty)
                    const Text('No services offered.', style: TextStyle(color: CtColors.muted))
                  else
                    ..._services.map((s) {
                      final price = asDouble(s['price']);
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(asString(s['name'])),
                        subtitle: asString(s['description']).isEmpty ? null : Text(asString(s['description'])),
                        trailing: price > 0 ? Text('₱${price.toStringAsFixed(2)}') : null,
                      );
                    }),
                  if (_error != null) Text('$_error', style: const TextStyle(color: CtColors.coral)),
                ],
              ),
      ),
    );
  }
}
