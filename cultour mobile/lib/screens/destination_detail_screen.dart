import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/ui.dart';
import 'book_guide_screen.dart';

class DestinationDetailScreen extends StatefulWidget {
  const DestinationDetailScreen({super.key, required this.id});
  final int id;

  @override
  State<DestinationDetailScreen> createState() => _DestinationDetailScreenState();
}

class _DestinationDetailScreenState extends State<DestinationDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  bool _favBusy = false;
  String? _favNote;

  @override
  void initState() {
    super.initState();
    _future = context.read<Session>().api.destinationDetail(widget.id);
  }

  Future<void> _toggleFav() async {
    setState(() => _favBusy = true);
    try {
      final on = await context.read<Session>().api.toggleFavorite(widget.id);
      setState(() {
        _favNote = on ? 'Added to favorite' : 'Removed from favorites';
        _future = context.read<Session>().api.destinationDetail(widget.id);
      });
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _favBusy = false);
    }
  }

  Future<void> _review() async {
    var rating = 5;
    final comment = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          backgroundColor: CtColors.surface,
          title: const Text('Write a review'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(
                  5,
                  (i) => IconButton(
                    onPressed: () => setLocal(() => rating = i + 1),
                    icon: Icon(i < rating ? Icons.star : Icons.star_border, color: CtColors.gold),
                  ),
                ),
              ),
              TextField(
                  controller: comment, maxLines: 3, decoration: const InputDecoration(hintText: 'Share your visit')),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Save')),
          ],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await context.read<Session>().api.reviewDestination(widget.id, rating, comment.text.trim());
      if (mounted) {
        showCulTourMessage(context, 'Review saved.');
        setState(() => _future = context.read<Session>().api.destinationDetail(widget.id));
      }
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = context.watch<Session>().api;
    return Scaffold(
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) {
            return EmptyState(icon: Icons.error_outline, title: 'Could not load place', detail: snap.error.toString());
          }
          if (!snap.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          final place = Place.fromJson(asMap(snap.data!['data']));
          final reviews = asList(snap.data!['reviews']).map((e) => PlaceReview.fromJson(asMap(e))).toList();
          final favorited = asBool(snap.data!['favorited']);
          final url = api.mediaUrl(place.coverUrl ?? place.coverImage);
          return CustomScrollView(
            slivers: [
              SliverAppBar(
                expandedHeight: 240,
                pinned: true,
                flexibleSpace: FlexibleSpaceBar(
                  title: Text(place.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                  background: url.isEmpty ? Container(color: CtColors.tealDark) : RemotePhoto(url: url, height: 240),
                ),
                actions: [
                  IconButton(
                    onPressed: _favBusy ? null : () => _toggleFav(),
                    icon: Icon(favorited ? Icons.favorite : Icons.favorite_border,
                        color: favorited ? CtColors.coral : null),
                  ),
                ],
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (_favNote != null) ...[
                        Text(_favNote!,
                            style: const TextStyle(color: CtColors.gold, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 10),
                      ],
                      Wrap(spacing: 8, runSpacing: 8, children: [
                        if ((place.categoryName ?? '').isNotEmpty) Chip(label: Text(place.categoryName!)),
                        if (place.avgRating > 0)
                          Chip(
                              avatar: const Icon(Icons.star, size: 16, color: CtColors.gold),
                              label: Text(place.avgRating.toStringAsFixed(1))),
                        if ((place.entranceFee ?? '').isNotEmpty) Chip(label: Text(place.entranceFee!)),
                      ]),
                      if ((place.location ?? '').isNotEmpty) ...[
                        const SizedBox(height: 10),
                        Text(place.location!, style: const TextStyle(color: CtColors.muted)),
                      ],
                      const SizedBox(height: 14),
                      Text(place.description?.isNotEmpty == true ? place.description! : 'No description yet.',
                          style: const TextStyle(height: 1.4)),
                      if ((place.openingHours ?? '').isNotEmpty) ...[
                        const SizedBox(height: 12),
                        Text('Hours: ${place.openingHours}', style: const TextStyle(color: CtColors.muted)),
                      ],
                      const SizedBox(height: 18),
                      FilledButton.icon(
                        onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => BookGuideScreen(destinationId: place.id, destinationName: place.name)),
                        ),
                        icon: const Icon(Icons.person_pin_circle_outlined),
                        label: const Text('Book a licensed guide'),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'You’ll pick a date and a guide who is free that day — same flow as the website.',
                        style: TextStyle(color: CtColors.muted, fontSize: 13),
                      ),
                      const SizedBox(height: 10),
                      OutlinedButton.icon(
                        onPressed: _review,
                        icon: const Icon(Icons.rate_review_outlined),
                        label: const Text('Write a review'),
                      ),
                      const SizedBox(height: 22),
                      const Text('Reviews', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                      const SizedBox(height: 8),
                      if (reviews.isEmpty) const Text('No reviews yet.', style: TextStyle(color: CtColors.muted)),
                      ...reviews.map((r) => ListTile(
                            contentPadding: EdgeInsets.zero,
                            title: Text('${r.firstName} ${r.lastName} · ${r.rating}/5'),
                            subtitle: Text(r.comment.isEmpty ? 'Rated this place.' : r.comment),
                          )),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
