import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../live_reload.dart';
import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/ui.dart';
import 'business_detail_screen.dart';
import 'destination_detail_screen.dart';

class MapScreen extends StatefulWidget {
  const MapScreen({super.key, this.asPage = false});
  final bool asPage;

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> with LiveReloadMixin {
  static const _ormoc = LatLng(11.0064, 124.6075);
  late Future<Map<String, dynamic>> _future;
  Map<String, dynamic>? _cache;
  bool _dest = true;
  bool _biz = true;

  @override
  void initState() {
    super.initState();
    _future = context.read<Session>().api.mapState();
  }

  @override
  String liveKey(Session session) => session.exploreVersion;

  @override
  void onLiveReload() {
    setState(() => _future = context.read<Session>().api.mapState());
  }

  Color _parseColor(String hex, Color fallback) {
    var h = hex.replaceAll('#', '');
    if (h.length == 3) h = '${h[0]}${h[0]}${h[1]}${h[1]}${h[2]}${h[2]}';
    if (h.length != 6) return fallback;
    return Color(int.parse('FF$h', radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    final map = FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasData) _cache = snap.data;
        final data = snap.data ?? _cache;
        if (data == null) {
          if (snap.hasError) {
            return EmptyState(icon: Icons.map_outlined, title: 'Map unavailable', detail: snap.error.toString());
          }
          return const Center(child: CircularProgressIndicator());
        }
        final destPins = asList(data['destinations']).map((e) => MapPin.fromJson(asMap(e))).toList();
        final bizPins = asList(data['businesses']).map((e) => MapPin.fromJson(asMap(e))).toList();
        final pins = [
          if (_dest) ...destPins,
          if (_biz) ...bizPins,
        ];
        return Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
              child: Row(
                children: [
                  FilterChip(
                    selected: _dest,
                    label: const Text('Places'),
                    onSelected: (v) => setState(() => _dest = v),
                  ),
                  const SizedBox(width: 8),
                  FilterChip(
                    selected: _biz,
                    label: const Text('Businesses'),
                    onSelected: (v) => setState(() => _biz = v),
                  ),
                ],
              ),
            ),
            Expanded(
              child: FlutterMap(
                options: const MapOptions(initialCenter: _ormoc, initialZoom: 13),
                children: [
                  TileLayer(
                    urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    userAgentPackageName: 'com.ormoc.cultour',
                  ),
                  MarkerLayer(
                    markers: pins
                        .where((p) => p.lat != 0 && p.lng != 0)
                        .map(
                          (p) => Marker(
                            point: LatLng(p.lat, p.lng),
                            width: 36,
                            height: 36,
                            child: GestureDetector(
                              onTap: () {
                                if (p.kind == 'business') {
                                  Navigator.push(
                                      context, MaterialPageRoute(builder: (_) => BusinessDetailScreen(id: p.id)));
                                } else {
                                  Navigator.push(
                                      context, MaterialPageRoute(builder: (_) => DestinationDetailScreen(id: p.id)));
                                }
                              },
                              child: Icon(Icons.location_on,
                                  color: _parseColor(p.color, p.kind == 'business' ? CtColors.gold : CtColors.sea),
                                  size: 34),
                            ),
                          ),
                        )
                        .toList(),
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );

    if (widget.asPage) {
      return Scaffold(appBar: AppBar(title: const Text('Ormoc map')), body: map);
    }
    return SafeArea(child: map);
  }
}
