import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/ui.dart';
import 'book_guide_screen.dart';

/// Phone layout for the same stay and ride builder as the website.
/// Each day is its own night or day. Rooms can be mixed. Vehicles stay at their seats.
class StayRideScreen extends StatefulWidget {
  const StayRideScreen({
    super.key,
    required this.itineraryId,
    this.itineraryTitle,
    this.initialDate = '',
    this.initialTime = '',
    this.initialParty = 1,
    this.initialGuideId = 0,
    this.dayCount = 1,
    this.dayDates = const {},
    this.rideLines = const [],
    this.rideDeclined = false,
    this.rideBusinessId = 0,
    this.rideServiceId = 0,
  });

  final int itineraryId;
  final String? itineraryTitle;
  final String initialDate;
  final String initialTime;
  final int initialParty;
  final int initialGuideId;
  final int dayCount;
  final Map<int, String> dayDates;
  final List<Map<String, dynamic>> rideLines;
  final bool rideDeclined;
  final int rideBusinessId;
  final int rideServiceId;

  @override
  State<StayRideScreen> createState() => _StayRideScreenState();
}

class _Line {
  _Line({
    required this.businessId,
    required this.businessName,
    required this.serviceId,
    required this.name,
    required this.day,
    required this.ride,
    required this.price,
    required this.capacity,
    required this.extraFee,
    required this.extraLimit,
    required this.guests,
  });

  final int businessId;
  final String businessName;
  final int serviceId;
  final String name;
  final int day;
  final bool ride;
  final double price;
  final int capacity;
  final double extraFee;
  final int? extraLimit;
  final List<int> guests;

  int get _extraPer {
    if (ride || capacity < 1 || extraFee <= 0) return 0;
    return extraLimit ?? capacity;
  }

  int get maxGuests {
    if (ride) return capacity < 1 ? 1 : capacity;
    if (capacity < 1) return 20;
    return capacity + _extraPer;
  }

  int get extras {
    if (_extraPer < 1) return 0;
    var n = 0;
    for (final g in guests) {
      if (g > capacity) n += (g - capacity).clamp(0, _extraPer);
    }
    return n;
  }

  double get amount => guests.length * price + extras * extraFee;

  Map<String, dynamic> toJson() => {
        'business_id': businessId,
        'service_id': serviceId,
        'day': day,
        'units': guests.length,
        'extra_guests': extras,
        'guests': guests,
        'label': '$businessName · $name',
      };
}

class _StayRideScreenState extends State<StayRideScreen> {
  List<Business>? _businesses;
  Object? _error;
  int _step = 0;
  int _activeDay = 1;
  bool _busy = false;
  bool _rideHydrated = false;
  bool _rideDeclined = false;
  bool _stayDeclined = false;
  final List<_Line> _lines = [];

  bool get _stay => _step == 0;
  bool get _multi => widget.dayCount > 1;
  int get _party => widget.initialParty < 1 ? 1 : widget.initialParty;
  int get _dayKey => _multi ? _activeDay : 0;

  @override
  void initState() {
    super.initState();
    _rideDeclined = widget.rideDeclined;
    _load();
  }

  String? get _openDate {
    if (!_multi) return widget.initialDate.isEmpty ? null : widget.initialDate;
    final dated = widget.dayDates[_activeDay];
    if (dated != null && dated.isNotEmpty) return dated;
    final start = DateTime.tryParse(widget.initialDate);
    if (start == null) return null;
    final day = start.add(Duration(days: _activeDay - 1));
    return '${day.year.toString().padLeft(4, '0')}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';
  }

  Future<void> _load() async {
    try {
      final date = _openDate;
      final rows = await context.read<Session>().api.businesses(from: date, to: date);
      if (!mounted) return;
      setState(() {
        _businesses = rows;
        _error = null;
        _hydrateRide();
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  BusinessOffering? _offer(int businessId, int serviceId) {
    for (final b in _businesses ?? const <Business>[]) {
      if (b.id != businessId) continue;
      for (final o in b.offerings) {
        if (o.id == serviceId) return o;
      }
    }
    return null;
  }

  Business? _biz(int id) {
    for (final b in _businesses ?? const <Business>[]) {
      if (b.id == id) return b;
    }
    return null;
  }

  void _hydrateRide() {
    if (_rideHydrated || _rideDeclined) {
      _rideHydrated = true;
      return;
    }
    _rideHydrated = true;
    if (widget.rideLines.isNotEmpty) {
      for (final raw in widget.rideLines) {
        final bid = asInt(raw['business_id']);
        final sid = asInt(raw['service_id']);
        final offer = _offer(bid, sid);
        final biz = _biz(bid);
        final seats = offer == null ? 1 : (offer.capacity < 1 ? 1 : offer.capacity);
        final guests = asList(raw['guests']).map(asInt).where((n) => n > 0).map((n) => n > seats ? seats : n).toList();
        if (offer == null || biz == null || guests.isEmpty) continue;
        _lines.add(_Line(
          businessId: bid,
          businessName: biz.name,
          serviceId: sid,
          name: offer.name,
          day: _multi ? (asInt(raw['day'], 1) < 1 ? 1 : asInt(raw['day'], 1)) : 0,
          ride: true,
          price: offer.price,
          capacity: offer.capacity,
          extraFee: 0,
          extraLimit: 0,
          guests: guests,
        ));
      }
      return;
    }
    if (widget.rideBusinessId > 0 && widget.rideServiceId > 0) {
      final offer = _offer(widget.rideBusinessId, widget.rideServiceId);
      final biz = _biz(widget.rideBusinessId);
      if (offer == null || biz == null) return;
      final seats = offer.capacity < 1 ? 1 : offer.capacity;
      _lines.add(_Line(
        businessId: biz.id,
        businessName: biz.name,
        serviceId: offer.id,
        name: offer.name,
        day: _multi ? 1 : 0,
        ride: true,
        price: offer.price,
        capacity: offer.capacity,
        extraFee: 0,
        extraLimit: 0,
        guests: [seats < _party ? seats : _party],
      ));
    }
  }

  List<Business> get _places {
    return (_businesses ?? []).where((b) {
      final type = (b.typeName ?? '').toLowerCase();
      return _stay ? type == 'accommodation' || type == 'hotel' || type == 'resort' : type == 'transportation';
    }).toList();
  }

  List<_Line> _dayLines(int day, bool ride) =>
      _lines.where((l) => l.ride == ride && l.day == day).toList();

  int _assigned(int day, bool ride) {
    var n = 0;
    for (final l in _dayLines(day, ride)) {
      for (final g in l.guests) {
        n += g;
      }
    }
    return n;
  }

  int _types(int day, bool ride) => _dayLines(day, ride).length;

  _Line? _lineFor(int day, int serviceId, bool ride) {
    for (final l in _lines) {
      if (l.ride == ride && l.day == day && l.serviceId == serviceId) return l;
    }
    return null;
  }

  int _units(int day, int serviceId, bool ride) => _lineFor(day, serviceId, ride)?.guests.length ?? 0;

  bool _soldOut(BusinessOffering offer, int day, bool ride) {
    if (offer.available == null) return false;
    return _units(day, offer.id, ride) >= offer.available!;
  }

  int _spare(int day, bool ride) {
    var spare = 0;
    for (final l in _dayLines(day, ride)) {
      for (final g in l.guests) {
        if (g > 1) spare += g - 1;
      }
    }
    return spare;
  }

  void _borrow(int day, bool ride, int need) {
    var left = need;
    while (left > 0) {
      _Line? donor;
      var index = -1;
      for (final l in _dayLines(day, ride).reversed) {
        for (var j = l.guests.length - 1; j >= 0; j--) {
          if (l.guests[j] > 1) {
            donor = l;
            index = j;
            break;
          }
        }
        if (donor != null) break;
      }
      if (donor == null || index < 0) return;
      donor.guests[index]--;
      left--;
    }
  }

  void _add(Business biz, BusinessOffering offer) {
    final ride = !_stay;
    final day = _dayKey;
    if (_soldOut(offer, day, ride)) return;
    final existing = _lineFor(day, offer.id, ride);
    if (existing == null && _types(day, ride) >= 12) {
      showCulTourMessage(context, ride ? 'This day already has 12 vehicles.' : 'This day already has 12 rooms.');
      return;
    }
    final included = offer.capacity < 1 ? 1 : offer.capacity;
    final seats = offer.capacity < 1 ? 1 : offer.capacity;
    final remaining = _party - _assigned(day, ride);
    if (ride) {
      if (remaining <= 0 && _spare(day, true) < 1) {
        showCulTourMessage(context, 'Everyone already has their own room or seat.');
        return;
      }
    } else if (remaining <= 0 && _spare(day, false) < included) {
      showCulTourMessage(context, 'Everyone already has their own room or seat.');
      return;
    }
    setState(() {
      if (rideDeclinedReset(ride)) {
        if (ride) _rideDeclined = false;
        if (!ride) _stayDeclined = false;
      }
      final line = existing ??
          _Line(
            businessId: biz.id,
            businessName: biz.name,
            serviceId: offer.id,
            name: offer.name,
            day: day,
            ride: ride,
            price: offer.price,
            capacity: offer.capacity,
            extraFee: ride ? 0 : offer.extraGuestFee,
            extraLimit: ride ? 0 : offer.extraGuestLimit,
            guests: [],
          );
      if (ride) {
        if (remaining > 0) {
          final take = remaining < seats ? remaining : seats;
          line.guests.add(take < 1 ? 1 : take);
        } else {
          _borrow(day, true, 1);
          line.guests.add(1);
        }
      } else {
        final short = included - remaining;
        if (remaining >= included) {
          line.guests.add(included);
        } else if (remaining > 0 && short > _spare(day, false)) {
          line.guests.add(remaining);
        } else {
          if (short > 0) _borrow(day, false, short);
          line.guests.add(included);
        }
      }
      if (existing == null) _lines.add(line);
    });
  }

  bool rideDeclinedReset(bool ride) => ride ? _rideDeclined : _stayDeclined;

  double _sum({int? day, required bool ride}) {
    var n = 0.0;
    for (final l in _lines) {
      if (l.ride != ride) continue;
      if (day != null && l.day != day) continue;
      n += l.amount;
    }
    return n;
  }

  int? _firstShort(bool ride) {
    if (ride ? _rideDeclined : _stayDeclined) return null;
    if (_multi) {
      for (var d = 1; d <= widget.dayCount; d++) {
        if (_assigned(d, ride) < _party) return d;
      }
      return null;
    }
    return _assigned(0, ride) < _party ? 0 : null;
  }

  Future<void> _continue() async {
    final ride = !_stay;
    if (ride ? _rideDeclined : _stayDeclined) {
      _goNext();
      return;
    }
    if (_dayLines(_dayKey, ride).isEmpty && _lines.where((l) => l.ride == ride).isEmpty) {
      showCulTourMessage(
        context,
        ride ? 'Add a vehicle, or mark transportation as not needed.' : 'Add a room, or mark accommodation as not needed.',
      );
      return;
    }
    final short = _firstShort(ride);
    if (short != null) {
      final dayLabel = short > 0 ? 'day $short' : 'this tour';
      final left = _party - _assigned(short, ride);
      final place = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: CtColors.surface,
          title: Text(ride ? 'Guests still need a seat' : 'Guests still need a room'),
          content: Text(
            '$left guest${left == 1 ? '' : 's'} still to accommodate on $dayLabel. Place them before you continue.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Close')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Place guests')),
          ],
        ),
      );
      if (place == true) {
        setState(() {
          if (short > 0) _activeDay = short;
        });
        if (short > 0) await _load();
        if (mounted) _openTray();
      }
      return;
    }
    _goNext();
  }

  void _goNext() {
    if (_stay) {
      setState(() => _step = 1);
      return;
    }
    _finish();
  }

  Future<void> _finish() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final stayLines = _lines.where((l) => !l.ride).map((l) => l.toJson()).toList();
      final rideLines = _lines.where((l) => l.ride).map((l) => l.toJson()).toList();
      await context.read<Session>().api.saveItinerary({
        'action': 'update_stay_ride',
        'itinerary_id': widget.itineraryId,
        'accommodation_declined': _stayDeclined ? 1 : 0,
        if (!_stayDeclined) 'accommodation_lines': stayLines,
        'transportation_declined': _rideDeclined ? 1 : 0,
        if (!_rideDeclined) 'transportation_lines': rideLines,
      });
      if (!mounted) return;
      final firstStay = stayLines.isEmpty ? null : stayLines.first;
      final firstRide = rideLines.isEmpty ? null : rideLines.first;
      await Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => BookGuideScreen(
            itineraryId: widget.itineraryId,
            itineraryTitle: widget.itineraryTitle,
            initialDate: widget.initialDate,
            initialTime: widget.initialTime,
            initialParty: _party,
            initialGuideId: widget.initialGuideId,
            dayCount: widget.dayCount,
            accommodationBusinessId: asInt(firstStay?['business_id']),
            accommodationServiceId: asInt(firstStay?['service_id']),
            accommodationDeclined: _stayDeclined,
            transportationBusinessId: asInt(firstRide?['business_id']),
            transportationServiceId: asInt(firstRide?['service_id']),
            transportationDeclined: _rideDeclined,
            accommodationLines: _stayDeclined ? const [] : stayLines,
            transportationLines: _rideDeclined ? const [] : rideLines,
          ),
        ),
      );
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _openTray() {
    final ride = !_stay;
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: CtColors.surface,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          final lines = _dayLines(_dayKey, ride);
          return SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(ride ? 'Your ride' : 'Your stay',
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(
                    '${_peso(_sum(ride: ride))} · all days',
                    style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 12),
                  if (lines.isEmpty)
                    Text(
                      _multi
                          ? 'No ${ride ? 'vehicle' : 'room'} on day $_activeDay yet. This day is separate from the other days.'
                          : 'Nothing added yet.',
                      style: const TextStyle(color: CtColors.muted),
                    )
                  else
                    ConstrainedBox(
                      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(ctx).height * 0.55),
                      child: ListView(
                        shrinkWrap: true,
                        children: [for (final line in lines) _guestEditor(line, setSheet)],
                      ),
                    ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.pop(ctx),
                          child: Text(ride ? 'Add another vehicle' : 'Add another place'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Done')),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _guestEditor(_Line line, void Function(void Function()) setSheet) {
    final noun = line.ride ? 'Vehicle' : 'Room';
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text('${line.businessName} · ${line.name}', style: const TextStyle(fontWeight: FontWeight.w700))),
                IconButton(
                  onPressed: () {
                    setState(() => _lines.remove(line));
                    setSheet(() {});
                  },
                  icon: const Icon(Icons.close, size: 18),
                ),
              ],
            ),
            for (var g = 0; g < line.guests.length; g++)
              Row(
                children: [
                  Expanded(child: Text('$noun ${g + 1}, ${line.guests[g]} guest${line.guests[g] == 1 ? '' : 's'}')),
                  IconButton(
                    onPressed: line.guests[g] <= 1
                        ? null
                        : () {
                            setState(() => line.guests[g]--);
                            setSheet(() {});
                          },
                    icon: const Icon(Icons.remove_circle_outline),
                  ),
                  IconButton(
                    onPressed: line.guests[g] >= line.maxGuests || _assigned(line.day, line.ride) >= _party
                        ? null
                        : () {
                            setState(() => line.guests[g]++);
                            setSheet(() {});
                          },
                    icon: const Icon(Icons.add_circle_outline),
                  ),
                ],
              ),
            Text(_peso(line.amount), style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w700)),
          ],
        ),
      ),
    );
  }

  void _amenities(BusinessOffering offer, bool ride) {
    final tags = offer.amenities.split(',').map((s) => s.trim()).where((s) => s.isNotEmpty).toList();
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: CtColors.surface,
        title: Text(ride ? 'Features' : 'Amenities'),
        content: tags.isEmpty
            ? const Text('None listed.')
            : Wrap(spacing: 8, runSpacing: 8, children: [for (final t in tags) Chip(label: Text(t))]),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Close'))],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rows = _businesses;
    final ride = !_stay;
    final declined = ride ? _rideDeclined : _stayDeclined;
    final dayLines = _dayLines(_dayKey, ride);
    final left = _party - _assigned(_dayKey, ride);
    return PopScope(
      canPop: _step == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && _step == 1) setState(() => _step = 0);
      },
      child: Scaffold(
        appBar: AppBar(title: Text(ride ? 'Transportation' : 'Accommodation')),
        body: rows == null
            ? (_error != null
                ? EmptyState(icon: Icons.error_outline, title: 'Could not load listings', detail: '$_error')
                : const Center(child: CircularProgressIndicator()))
            : Column(
                children: [
                  if (_multi)
                    SizedBox(
                      height: 52,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                        children: [
                          for (var d = 1; d <= widget.dayCount; d++)
                            Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: ChoiceChip(
                                label: Text('Day $d'),
                                selected: _activeDay == d,
                                onSelected: (_) {
                                  setState(() => _activeDay = d);
                                  _load();
                                },
                              ),
                            ),
                        ],
                      ),
                    ),
                  if (!declined)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              left <= 0
                                  ? 'Everyone is placed${_multi ? ' on day $_activeDay' : ''}'
                                  : '$left guest${left == 1 ? '' : 's'} still to accommodate${_multi ? ' on day $_activeDay' : ''}',
                              style: const TextStyle(fontWeight: FontWeight.w700),
                            ),
                          ),
                          Text(_peso(_sum(day: _dayKey, ride: ride)),
                              style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w800)),
                        ],
                      ),
                    ),
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
                      children: [
                        _skip(
                          ride ? 'I don’t need transportation' : 'I don’t need accommodation',
                          declined,
                          () => setState(() {
                            if (ride) {
                              _rideDeclined = !_rideDeclined;
                              if (_rideDeclined) _lines.removeWhere((l) => l.ride);
                            } else {
                              _stayDeclined = !_stayDeclined;
                              if (_stayDeclined) _lines.removeWhere((l) => !l.ride);
                            }
                          }),
                        ),
                        if (!declined)
                          ..._places.map((b) => _placeCard(b)),
                        if (!declined && _places.isEmpty)
                          const Padding(
                            padding: EdgeInsets.only(top: 8),
                            child: Text('Nothing is listed for these dates yet.', style: TextStyle(color: CtColors.muted)),
                          ),
                      ],
                    ),
                  ),
                  _bar(dayLines.length, ride),
                ],
              ),
      ),
    );
  }

  Widget _bar(int count, bool ride) {
    return Material(
      color: CtColors.surface,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      ride ? 'Your ride · $count' : 'Your stay · $count',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                  Text(_peso(_sum(ride: ride)), style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w800)),
                  TextButton(onPressed: _openTray, child: const Text('View')),
                ],
              ),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _busy ? null : _continue,
                  child: Text(_busy
                      ? 'Saving…'
                      : ride
                          ? 'Continue to tour guide'
                          : 'Continue to transportation'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _skip(String label, bool selected, VoidCallback onTap) {
    return Card(
      color: selected ? CtColors.sea.withOpacity(0.16) : null,
      child: ListTile(
        leading: Icon(selected ? Icons.check_circle : Icons.circle_outlined, color: CtColors.sea),
        title: Text(label),
        onTap: onTap,
      ),
    );
  }

  Widget _placeCard(Business biz) {
    final ride = !_stay;
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if ((biz.coverUrl ?? '').isNotEmpty)
            Image.network(biz.coverUrl!, height: 120, width: double.infinity, fit: BoxFit.cover),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(biz.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                if ((biz.address ?? '').trim().isNotEmpty)
                  Text(biz.address!.trim(), style: const TextStyle(color: CtColors.muted, fontSize: 13)),
                const SizedBox(height: 8),
                if (biz.offerings.isEmpty)
                  const Text('No rooms or vehicles listed.', style: TextStyle(color: CtColors.muted))
                else
                  ...biz.offerings.map((o) => _offerTile(biz, o, ride)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _offerTile(Business biz, BusinessOffering offer, bool ride) {
    final full = _soldOut(offer, _dayKey, ride);
    final bits = <String>[
      if (offer.price > 0) '${_peso(offer.price)} / ${ride ? 'day' : 'night'}',
      if (offer.capacity > 0) '${offer.capacity} ${ride ? 'seats' : 'included'}',
      if (offer.available != null) (offer.available! < 1 ? 'Sold out this day' : '${offer.available} left'),
    ];
    return Container(
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: CtColors.bg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: CtColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(offer.name, style: const TextStyle(fontWeight: FontWeight.w700)),
          if (bits.isNotEmpty)
            Text(bits.join(' · '), style: const TextStyle(color: CtColors.muted, fontSize: 12)),
          const SizedBox(height: 8),
          Row(
            children: [
              OutlinedButton(
                onPressed: () => _amenities(offer, ride),
                child: Text(ride ? 'Features' : 'Amenities'),
              ),
              const Spacer(),
              FilledButton(
                onPressed: full ? null : () => _add(biz, offer),
                child: Text(full ? 'Sold out' : (ride ? 'Add vehicle' : 'Add room')),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

String _peso(double amount) {
  final neg = amount < 0;
  final fixed = amount.abs().toStringAsFixed(2).split('.');
  final whole = fixed[0];
  final buf = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) buf.write(',');
    buf.write(whole[i]);
  }
  return '${neg ? '-' : ''}₱$buf.${fixed[1]}';
}
