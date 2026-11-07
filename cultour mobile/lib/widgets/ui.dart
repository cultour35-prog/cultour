import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../session.dart';
import '../theme.dart';

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.detail});

  final IconData icon;
  final String title;
  final String? detail;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: CtColors.muted),
            const SizedBox(height: 12),
            Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
            if (detail != null) ...[
              const SizedBox(height: 6),
              Text(detail!, textAlign: TextAlign.center, style: const TextStyle(color: CtColors.muted)),
            ],
          ],
        ),
      ),
    );
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.tone});

  final String label;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final color = tone ?? _toneFor(label);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.18),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withOpacity(0.45)),
      ),
      child: Text(
        label,
        style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600),
      ),
    );
  }

  Color _toneFor(String raw) {
    switch (raw.toLowerCase()) {
      case 'approved':
      case 'completed':
      case 'paid':
      case 'active':
        return CtColors.sea;
      case 'pending':
      case 'unpaid':
        return CtColors.gold;
      case 'cancelled':
      case 'declined':
      case 'rejected':
      case 'suspended':
        return CtColors.coral;
      default:
        return CtColors.muted;
    }
  }
}

class CoverImage extends StatelessWidget {
  const CoverImage({super.key, required this.url, this.height = 160, this.radius = 16, this.fit = BoxFit.cover});

  final String url;
  final double height;
  final double radius;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: url.isEmpty
          ? Container(
              height: height,
              color: CtColors.tealDark,
              alignment: Alignment.center,
              child: const Icon(Icons.landscape_outlined, color: CtColors.muted, size: 40),
            )
          : RemotePhoto(url: url, height: height, fit: fit),
    );
  }
}

/// Fetches the image through the same host as the API so photos show on phones and Flutter web.
class RemotePhoto extends StatefulWidget {
  const RemotePhoto({
    super.key,
    required this.url,
    this.height,
    this.width,
    this.fit = BoxFit.cover,
    this.auth = false,
    this.errorLabel,
  });

  final String url;
  final double? height;
  final double? width;
  final BoxFit fit;
  final bool auth;
  final String? errorLabel;

  @override
  State<RemotePhoto> createState() => _RemotePhotoState();
}

class _RemotePhotoState extends State<RemotePhoto> {
  late Future<Uint8List> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void didUpdateWidget(covariant RemotePhoto oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.url != widget.url || oldWidget.auth != widget.auth) {
      _future = _load();
    }
  }

  Future<Uint8List> _load() {
    return context.read<Session>().api.getMediaBytes(widget.url, auth: widget.auth);
  }

  Widget _placeholder({required Widget child}) {
    return Container(
      height: widget.height,
      width: widget.width ?? double.infinity,
      color: CtColors.tealDark,
      alignment: Alignment.center,
      padding: const EdgeInsets.all(12),
      child: child,
    );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Uint8List>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasData) {
          return Image.memory(
            snap.data!,
            height: widget.height,
            width: widget.width ?? double.infinity,
            fit: widget.fit,
            gaplessPlayback: true,
          );
        }
        if (snap.hasError) {
          return _placeholder(
            child: widget.errorLabel == null
                ? const Icon(Icons.broken_image_outlined, color: CtColors.muted)
                : Text(widget.errorLabel!, textAlign: TextAlign.center, style: const TextStyle(color: CtColors.muted)),
          );
        }
        return _placeholder(child: const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)));
      },
    );
  }
}

void showCulTourError(BuildContext context, Object error) {
  final text = error.toString().replaceFirst('ApiException: ', '');
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
}

Future<void> showCulTourMessage(BuildContext context, String message) {
  return ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message))).closed.then((_) {});
}
