import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';
import '../widgets/ui.dart';
import 'pay_screen.dart';

class BookGuideScreen extends StatefulWidget {
  const BookGuideScreen({
    super.key,
    this.destinationId,
    this.destinationName,
    this.itineraryId,
    this.itineraryTitle,
    this.initialDate = '',
    this.initialTime = '',
    this.initialParty = 0,
    this.initialGuideId = 0,
    this.dayCount = 1,
    this.accommodationBusinessId = 0,
    this.accommodationServiceId = 0,
    this.accommodationDeclined = false,
    this.transportationBusinessId = 0,
    this.transportationServiceId = 0,
    this.transportationDeclined = false,
    this.accommodationLines = const [],
    this.transportationLines = const [],
  });

  final int? destinationId;
  final String? destinationName;
  final int? itineraryId;
  final String? itineraryTitle;
  final String initialDate;
  final String initialTime;
  final int initialParty;
  final int initialGuideId;
  final int dayCount;
  final int accommodationBusinessId;
  final int accommodationServiceId;
  final bool accommodationDeclined;
  final int transportationBusinessId;
  final int transportationServiceId;
  final bool transportationDeclined;
  final List<Map<String, dynamic>> accommodationLines;
  final List<Map<String, dynamic>> transportationLines;

  @override
  State<BookGuideScreen> createState() => _BookGuideScreenState();
}

class _BookGuideScreenState extends State<BookGuideScreen> {
  List<Guide>? _guides;
  Object? _loadError;
  Guide? _guide;
  late DateTime _date;
  late TimeOfDay _time;
  late int _party;
  bool _isPwd = false;
  String? _certPath;
  final _notes = TextEditingController();
  final _pwdNotes = TextEditingController();
  bool _busy = false;
  String? _availability;
  bool _available = false;
  Map<String, String>? _bill;
  String? _billError;
  int _billToken = 0;
  int? _itineraryId;
  Timer? _timer;
  PageController? _guidePager;
  int _guidePage = 0;

  bool get _datesFromItinerary => widget.itineraryId != null && widget.initialDate.isNotEmpty;
  bool get _partyLocked => widget.itineraryId != null;

  @override
  void initState() {
    super.initState();
    _itineraryId = widget.itineraryId;
    _date = DateTime.tryParse(widget.initialDate) ?? DateTime.now().add(const Duration(days: 1));
    _time = parseTimeOfDay(widget.initialTime) ?? const TimeOfDay(hour: 8, minute: 0);
    _party = widget.initialParty > 0 ? widget.initialParty : 1;
    _loadGuides();
    _timer = Timer.periodic(const Duration(seconds: 12), (_) => _loadGuides());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _guidePager?.dispose();
    _notes.dispose();
    _pwdNotes.dispose();
    super.dispose();
  }

  String get _dateStr => ymd(_date);
  String get _timeStr => formatTime12(_time);

  Future<void> _loadGuides() async {
    try {
      final list = await context.read<Session>().api.guides(
            date: _dateStr,
            startTime: _timeStr,
            itineraryId: _itineraryId,
          );
      if (!mounted) return;
      setState(() {
        _guides = list;
        _loadError = null;
        if (_guide != null) {
          Guide? next;
          for (final g in list) {
            if (g.id == _guide!.id) next = g;
          }
          _guide = next;
        } else if (widget.initialGuideId > 0) {
          for (final g in list) {
            if (g.id == widget.initialGuideId) _guide = g;
          }
        }
        _placeGuidePager(list);
        if (_guide == null) {
          _bill = null;
          _billError = null;
        }
      });
      if (_guide != null) {
        await _checkAvailability();
      }
    } catch (e) {
      if (mounted && _guides == null) setState(() => _loadError = e);
    }
  }

  void _placeGuidePager(List<Guide> list) {
    if (list.isEmpty) return;
    var index = _guide == null ? _guidePage : list.indexWhere((g) => g.id == _guide!.id);
    if (index < 0) index = _guidePage;
    if (index >= list.length) index = 0;
    _guidePage = index;
    final pager = _guidePager;
    if (pager == null) {
      _guidePager = PageController(initialPage: index);
      return;
    }
    if (pager.hasClients && (pager.page?.round() ?? 0) >= list.length) {
      pager.jumpToPage(index);
    }
  }

  void _onGuidePage(int index, List<Guide> guides) {
    if (index < 0 || index >= guides.length) return;
    final guide = guides[index];
    setState(() => _guidePage = index);
    if (!guide.available) return;
    if (_guide?.id == guide.id) return;
    setState(() => _guide = guide);
    _checkAvailability();
  }

  Future<void> _checkAvailability() async {
    if (_guide == null) return;
    try {
      final res = await context.read<Session>().api.guideAvailability(
            guideId: _guide!.id,
            date: _dateStr,
            startTime: _timeStr,
            itineraryId: _itineraryId,
          );
      if (!mounted) return;
      setState(() {
        _available = asBool(res['available']);
        _availability = _available
            ? 'Available for this schedule.'
            : asString(res['message'], 'Not available for this schedule.');
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _available = false;
        _availability = e.toString();
      });
    }
    await _loadBill();
  }

  Future<void> _loadBill() async {
    final guide = _guide;
    if (guide == null) {
      if (mounted) {
        setState(() {
          _bill = null;
          _billError = null;
        });
      }
      return;
    }
    final token = ++_billToken;
    try {
      final res = await context.read<Session>().api.quoteBooking(
            guideId: guide.id,
            date: _dateStr,
            startTime: _timeStr,
            partySize: _party,
            itineraryId: _itineraryId,
            destinationId: widget.destinationId,
            accommodationBusinessId: widget.itineraryId == null ? null : widget.accommodationBusinessId,
            accommodationServiceId: widget.itineraryId == null ? null : widget.accommodationServiceId,
            accommodationDeclined: widget.accommodationDeclined,
            transportationBusinessId: widget.itineraryId == null ? null : widget.transportationBusinessId,
            transportationServiceId: widget.itineraryId == null ? null : widget.transportationServiceId,
            transportationDeclined: widget.transportationDeclined,
            accommodationLines: widget.accommodationDeclined ? null : widget.accommodationLines,
            transportationLines: widget.transportationDeclined ? null : widget.transportationLines,
          );
      if (!mounted || token != _billToken) return;
      setState(() {
        _bill = {
          'Guide': asString(res['guide_amount_label']),
          'Stay': asString(res['accommodation_amount_label']),
          'Transportation': asString(res['transportation_amount_label']),
          'Total': asString(res['payment_amount_label']),
        };
        _billError = null;
      });
    } catch (e) {
      if (!mounted || token != _billToken) return;
      setState(() {
        _bill = null;
        _billError = e.toString();
      });
    }
  }

  Future<int?> _ensureItinerary() async {
    final itinId = _itineraryId;
    if (itinId == null) return null;
    final api = context.read<Session>().api;
    await api.saveItinerary({
      'action': 'update_meetup',
      'itinerary_id': itinId,
      'start_date': _dateStr,
      'meetup_time': _timeStr,
    });
    if (!_partyLocked) {
      try {
        await api.saveItinerary({'action': 'update_party_size', 'itinerary_id': itinId, 'party_size': _party});
      } catch (_) {}
    }
    return itinId;
  }

  Future<void> _submit() async {
    if (_guide == null) {
      showCulTourError(context, 'Choose a licensed guide.');
      return;
    }
    if (!_available) {
      showCulTourError(context, _availability ?? 'This guide is not available for that date and time.');
      return;
    }
    setState(() => _busy = true);
    try {
      final api = context.read<Session>().api;
      final itinId = await _ensureItinerary();
      if (itinId != null) {
        try {
          await api.saveItinerary({'action': 'update_draft_guide', 'itinerary_id': itinId, 'guide_id': _guide!.id});
        } catch (_) {}
      }
      if (_guide != null) {
        final live = await api.guideAvailability(
          guideId: _guide!.id,
          date: _dateStr,
          startTime: _timeStr,
          itineraryId: itinId,
        );
        if (!asBool(live['available'])) {
          throw ApiException(asString(live['message'], 'This guide is not available for that schedule.'));
        }
      }
      final res = await api.createBooking(
        guideId: _guide!.id,
        date: _dateStr,
        startTime: _timeStr,
        partySize: _party,
        itineraryId: itinId,
        destinationId: widget.destinationId,
        notes: _notes.text.trim(),
        accommodationBusinessId: widget.itineraryId == null ? null : widget.accommodationBusinessId,
        accommodationServiceId: widget.itineraryId == null ? null : widget.accommodationServiceId,
        accommodationDeclined: widget.accommodationDeclined,
        transportationBusinessId: widget.itineraryId == null ? null : widget.transportationBusinessId,
        transportationServiceId: widget.itineraryId == null ? null : widget.transportationServiceId,
        transportationDeclined: widget.transportationDeclined,
        accommodationLines: widget.accommodationDeclined ? null : widget.accommodationLines,
        transportationLines: widget.transportationDeclined ? null : widget.transportationLines,
        isPwd: _isPwd,
        pwdNotes: _pwdNotes.text.trim(),
        certificatePath: _certPath,
      );
      if (!mounted) return;
      final code = asString(res['booking_code'], 'saved');
      final bookingId = asInt(asMap(res['booking'])['id']);
      context.read<Session>().pollLive();
      final pay = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          title: Text('Booking $code requested'),
          content: const Text(
            'Pay with GCash so your guide can accept it. You can also pay later from Bookings.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Later')),
            FilledButton(
                style: compactFilled(), onPressed: () => Navigator.pop(ctx, true), child: const Text('Pay now')),
          ],
        ),
      );
      if (!mounted) return;
      if (pay == true && bookingId > 0) {
        await Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => PayScreen(bookingId: bookingId)));
      } else {
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final subject = widget.itineraryTitle ?? widget.destinationName ?? 'Ormoc tour';
    final guides = _guides;
    if (guides != null && guides.isNotEmpty && _guidePager == null) {
      _placeGuidePager(guides);
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Book a guide')),
      body: guides == null
          ? (_loadError != null
              ? EmptyState(icon: Icons.error_outline, title: 'Could not load guides', detail: '$_loadError')
              : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
              children: [
                Text(subject, style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 6),
                const Text('Same licensed-guide booking as the CulTour website.',
                    style: TextStyle(color: CtColors.muted)),
                const SizedBox(height: 16),
                SectionCard(
                  title: 'When',
                  child: _datesFromItinerary
                      ? Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            InfoRow('Start date', _dateStr),
                            if (widget.dayCount > 1) InfoRow('Length', '${widget.dayCount} days'),
                            InfoRow('Meetup', _timeStr),
                            const SizedBox(height: 4),
                            const Text('Dates and meetup come from your itinerary. Change them in the tour builder.',
                                style: TextStyle(color: CtColors.muted, fontSize: 12)),
                          ],
                        )
                      : Column(
                          children: [
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: const Text('Tour date'),
                              subtitle: Text(_dateStr),
                              trailing: const Icon(Icons.event, color: CtColors.gold),
                              onTap: () async {
                                final now = DateTime.now();
                                final picked = await showDatePicker(
                                  context: context,
                                  initialDate: _date.isBefore(now) ? now : _date,
                                  firstDate: DateTime(now.year, now.month, now.day),
                                  lastDate: now.add(const Duration(days: 365)),
                                );
                                if (picked != null) {
                                  setState(() => _date = picked);
                                  await _loadGuides();
                                }
                              },
                            ),
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: const Text('Meetup time'),
                              subtitle: Text(_timeStr),
                              trailing: const Icon(Icons.schedule, color: CtColors.gold),
                              onTap: () async {
                                final picked = await showTimePicker(context: context, initialTime: _time);
                                if (picked != null) {
                                  setState(() => _time = picked);
                                  await _loadGuides();
                                }
                              },
                            ),
                          ],
                        ),
                ),
                SectionCard(
                  title: 'Choose a guide',
                  padding: const EdgeInsets.fromLTRB(12, 16, 12, 14),
                  child: guides.isEmpty
                      ? const Text(
                          'No licensed guides are free for this date and meetup. Try another day, or set weekly hours on a guide account.',
                          style: TextStyle(color: CtColors.muted),
                        )
                      : _GuideCarousel(
                          guides: guides,
                          page: _guidePage,
                          controller: _guidePager!,
                          selectedId: _guide?.id,
                          onPage: (index) => _onGuidePage(index, guides),
                          onChoose: (guide) {
                            setState(() {
                              _guide = guide;
                              _guidePage = guides.indexWhere((g) => g.id == guide.id);
                            });
                            _checkAvailability();
                          },
                        ),
                ),
                if (_availability != null)
                  NoticeBox(
                    _availability!,
                    tone: _available ? CtColors.sea : CtColors.coral,
                    icon: _available ? Icons.check_circle_outline : Icons.error_outline,
                  ),
                SectionCard(
                  title: 'Guests and notes',
                  child: Column(
                    children: [
                      Row(
                        children: [
                          const Expanded(child: Text('Party size')),
                          if (!_partyLocked)
                            IconButton(
                              onPressed: _party > 1
                                  ? () {
                                      setState(() => _party--);
                                      _loadBill();
                                    }
                                  : null,
                              icon: const Icon(Icons.remove_circle_outline),
                            ),
                          Text('$_party', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                          if (!_partyLocked)
                            IconButton(
                              onPressed: _party < 20
                                  ? () {
                                      setState(() => _party++);
                                      _loadBill();
                                    }
                                  : null,
                              icon: const Icon(Icons.add_circle_outline),
                            ),
                        ],
                      ),
                      if (_partyLocked)
                        const Padding(
                          padding: EdgeInsets.only(top: 4),
                          child: Text(
                            'This matches the guests already placed in the rooms and seats.',
                            style: TextStyle(color: CtColors.muted, fontSize: 12),
                          ),
                        ),
                      TextField(
                          controller: _notes,
                          maxLines: 3,
                          decoration: const InputDecoration(labelText: 'Notes for the guide')),
                    ],
                  ),
                ),
                SectionCard(
                  title: 'Accessibility',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        activeTrackColor: CtColors.sea,
                        title: const Text('A guest has a disability (PWD)'),
                        subtitle: const Text('The guide plans for extra assistance'),
                        value: _isPwd,
                        onChanged: (v) => setState(() => _isPwd = v),
                      ),
                      if (_isPwd) ...[
                        TextField(
                          controller: _pwdNotes,
                          maxLength: 255,
                          maxLines: 2,
                          decoration: const InputDecoration(labelText: 'What support is needed? (optional)'),
                        ),
                        const Text(
                          'Upload a doctor’s fitness-to-travel certificate (photo, up to 8 MB).',
                          style: TextStyle(color: CtColors.muted, fontSize: 13),
                        ),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(
                          onPressed: () async {
                            final path = await pickPhoto(context);
                            if (path != null) setState(() => _certPath = path);
                          },
                          icon: Icon(_certPath == null ? Icons.upload_file : Icons.check_circle,
                              color: _certPath == null ? null : CtColors.sea),
                          label: Text(_certPath == null ? 'Add certificate photo' : 'Certificate added · change'),
                        ),
                      ],
                    ],
                  ),
                ),
                if (_guide != null) ...[
                  const SizedBox(height: 4),
                  SectionCard(
                    title: 'Your bill',
                    child: _bill == null
                        ? Text(
                            _billError ?? 'Getting the total from the office…',
                            style: TextStyle(color: _billError == null ? CtColors.muted : CtColors.coral, fontSize: 13),
                          )
                        : Column(
                            children: [
                              InfoRow('Guide', _bill!['Guide'] ?? ''),
                              InfoRow('Stay', _bill!['Stay'] ?? ''),
                              InfoRow('Transportation', _bill!['Transportation'] ?? ''),
                              const Divider(height: 16),
                              InfoRow('Total', _bill!['Total'] ?? ''),
                            ],
                          ),
                  ),
                ],
                const SizedBox(height: 4),
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: _busy
                      ? const SizedBox(
                          height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Text('Send booking request'),
                ),
              ],
            ),
    );
  }
}

class _GuideCarousel extends StatelessWidget {
  const _GuideCarousel({
    required this.guides,
    required this.page,
    required this.controller,
    required this.selectedId,
    required this.onPage,
    required this.onChoose,
  });

  final List<Guide> guides;
  final int page;
  final PageController controller;
  final int? selectedId;
  final ValueChanged<int> onPage;
  final ValueChanged<Guide> onChoose;

  void _go(int index) {
    if (index < 0 || index >= guides.length) return;
    controller.animateToPage(index, duration: const Duration(milliseconds: 320), curve: Curves.easeOut);
  }

  @override
  Widget build(BuildContext context) {
    final index = page.clamp(0, guides.length - 1);
    return Column(
      children: [
        SizedBox(
          height: 312,
          child: PageView.builder(
            controller: controller,
            itemCount: guides.length,
            onPageChanged: onPage,
            itemBuilder: (context, i) {
              final guide = guides[i];
              final selected = selectedId == guide.id;
              final bookable = guide.available;
              final bio = (guide.bio ?? '').trim();
              final bits = <String>[
                if (guide.avgRating > 0) '${guide.avgRating.toStringAsFixed(1)} ★',
                if (guide.reviewCount > 0) '${guide.reviewCount} reviews',
                if ((guide.languages ?? '').isNotEmpty && guide.languages != '—') guide.languages!,
                if (guide.yearsExperience > 0) '${guide.yearsExperience} yrs',
                if ((guide.hourlyRate ?? '').isNotEmpty) '₱${guide.hourlyRate}/day',
              ];
              return Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                child: Opacity(
                  opacity: bookable ? 1 : 0.62,
                  child: Container(
                    width: double.infinity,
                    height: double.infinity,
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
                    decoration: CtDecor.card(selected: selected),
                    child: Column(
                      children: [
                        Avatar(name: guide.fullName, url: guide.avatarUrl ?? '', radius: 36),
                        const SizedBox(height: 10),
                        Text(
                          guide.fullName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
                        ),
                        if (bits.isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text(
                            bits.join(' · '),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            textAlign: TextAlign.center,
                            style: const TextStyle(color: CtColors.muted, fontSize: 13),
                          ),
                        ],
                        if (bio.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          Text(
                            bio,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            textAlign: TextAlign.center,
                            style: const TextStyle(fontSize: 13, height: 1.3),
                          ),
                        ],
                        if ((guide.hint ?? '').isNotEmpty) ...[
                          const SizedBox(height: 8),
                          Text(
                            guide.hint!,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            textAlign: TextAlign.center,
                            style: TextStyle(color: bookable ? CtColors.sea : CtColors.coral, fontSize: 12),
                          ),
                        ],
                        const Spacer(),
                        SizedBox(
                          width: double.infinity,
                          child: selected
                              ? const Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    Icon(Icons.check_circle, color: CtColors.gold, size: 18),
                                    SizedBox(width: 6),
                                    Text('Selected', style: TextStyle(color: CtColors.gold, fontWeight: FontWeight.w700)),
                                  ],
                                )
                              : FilledButton(
                                  style: compactFilled(),
                                  onPressed: bookable ? () => onChoose(guide) : null,
                                  child: Text(bookable ? 'Choose this guide' : 'Not available'),
                                ),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            },
          ),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            IconButton(
              onPressed: index > 0 ? () => _go(index - 1) : null,
              icon: const Icon(Icons.chevron_left),
              color: CtColors.gold,
            ),
            Expanded(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(guides.length, (i) {
                  final on = i == index;
                  return Container(
                    width: on ? 16 : 7,
                    height: 7,
                    margin: const EdgeInsets.symmetric(horizontal: 3),
                    decoration: BoxDecoration(
                      color: on ? CtColors.gold : CtColors.line,
                      borderRadius: BorderRadius.circular(8),
                    ),
                  );
                }),
              ),
            ),
            IconButton(
              onPressed: index < guides.length - 1 ? () => _go(index + 1) : null,
              icon: const Icon(Icons.chevron_right),
              color: CtColors.gold,
            ),
          ],
        ),
        Text(
          '${index + 1} of ${guides.length}',
          style: const TextStyle(color: CtColors.muted, fontSize: 12),
        ),
      ],
    );
  }
}
