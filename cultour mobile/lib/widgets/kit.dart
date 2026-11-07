import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import 'ui.dart';

final _picker = ImagePicker();

class PickedPhoto {
  const PickedPhoto({required this.path, required this.bytes, required this.name});

  final String path;
  final Uint8List bytes;
  final String name;
}

/// Camera-only when [cameraOnly]; otherwise asks camera or gallery.
Future<PickedPhoto?> pickPhotoAsset(BuildContext context, {bool cameraOnly = false}) async {
  ImageSource? source = cameraOnly && !kIsWeb ? ImageSource.camera : null;
  source ??= await showModalBottomSheet<ImageSource>(
    context: context,
    backgroundColor: CtColors.surface,
    builder: (ctx) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ListTile(
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('Take a photo'),
            onTap: () => Navigator.pop(ctx, ImageSource.camera),
          ),
          ListTile(
            leading: const Icon(Icons.photo_library_outlined),
            title: const Text('Choose from gallery'),
            onTap: () => Navigator.pop(ctx, ImageSource.gallery),
          ),
        ],
      ),
    ),
  );
  if (source == null) return null;
  try {
    final file = await _picker.pickImage(source: source, imageQuality: 82, maxWidth: 1800);
    if (file == null) return null;
    final bytes = await file.readAsBytes();
    if (bytes.isEmpty) {
      if (context.mounted) showCulTourError(context, 'That photo was empty. Try another picture.');
      return null;
    }
    final name = file.name.trim().isEmpty ? 'photo.jpg' : file.name;
    return PickedPhoto(path: file.path, bytes: bytes, name: name);
  } catch (e) {
    if (context.mounted) {
      showCulTourError(context, 'Could not open the ${source == ImageSource.camera ? 'camera' : 'gallery'}.');
    }
    return null;
  }
}

/// Camera-only when [cameraOnly]; otherwise asks camera or gallery. Returns a local file path.
Future<String?> pickPhoto(BuildContext context, {bool cameraOnly = false}) async {
  final photo = await pickPhotoAsset(context, cameraOnly: cameraOnly);
  return photo?.path;
}

ButtonStyle compactFilled({Color? color}) => FilledButton.styleFrom(
      minimumSize: const Size(0, 44),
      padding: const EdgeInsets.symmetric(horizontal: 16),
      backgroundColor: color,
    );

ButtonStyle compactOutlined({Color? color}) => OutlinedButton.styleFrom(
      minimumSize: const Size(0, 44),
      padding: const EdgeInsets.symmetric(horizontal: 16),
      foregroundColor: color,
      side: BorderSide(color: (color ?? CtColors.ink).withOpacity(color == null ? 0.12 : 0.6)),
    );

/// Runs an API call, shows its message (or error). Returns the response on success.
Future<Map<String, dynamic>?> runApi(
  BuildContext context,
  Future<Map<String, dynamic>> Function() call, {
  String? success,
}) async {
  try {
    final res = await call();
    if (context.mounted) {
      final msg = asString(res['message']);
      final text = msg.isNotEmpty ? msg : success;
      if (text != null && text.isNotEmpty) showCulTourMessage(context, text);
    }
    return res;
  } catch (e) {
    if (context.mounted) showCulTourError(context, e);
    return null;
  }
}

Future<bool> confirmAction(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Continue',
  bool danger = false,
}) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      backgroundColor: CtColors.surface,
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Back')),
        FilledButton(
          style: compactFilled(color: danger ? CtColors.coral : null),
          onPressed: () => Navigator.pop(ctx, true),
          child: Text(confirmLabel),
        ),
      ],
    ),
  );
  return ok == true;
}

typedef ReasonPick = ({String preset, String detail});

/// Preset reasons with a required note when "Other" is chosen (same rule as the website).
Future<ReasonPick?> askReason(
  BuildContext context, {
  required String title,
  required List<String> presets,
  String? lead,
  String confirmLabel = 'Submit',
  bool danger = true,
  Widget? extra,
}) {
  return showModalBottomSheet<ReasonPick>(
    context: context,
    isScrollControlled: true,
    backgroundColor: CtColors.surface,
    builder: (ctx) => _ReasonSheet(
      title: title,
      presets: presets.isEmpty ? const ['Other'] : presets,
      lead: lead,
      confirmLabel: confirmLabel,
      danger: danger,
      extra: extra,
    ),
  );
}

class _ReasonSheet extends StatefulWidget {
  const _ReasonSheet({
    required this.title,
    required this.presets,
    required this.lead,
    required this.confirmLabel,
    required this.danger,
    this.extra,
  });

  final String title;
  final List<String> presets;
  final String? lead;
  final String confirmLabel;
  final bool danger;
  final Widget? extra;

  @override
  State<_ReasonSheet> createState() => _ReasonSheetState();
}

class _ReasonSheetState extends State<_ReasonSheet> {
  late String _preset = widget.presets.first;
  final _detail = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _detail.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isOther = _preset == 'Other';
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            if (widget.lead != null) ...[
              const SizedBox(height: 6),
              Text(widget.lead!, style: const TextStyle(color: CtColors.muted)),
            ],
            if (widget.extra != null) ...[const SizedBox(height: 12), widget.extra!],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: widget.presets
                  .map(
                    (p) => ChoiceChip(
                      label: Text(p),
                      selected: _preset == p,
                      onSelected: (_) => setState(() {
                        _preset = p;
                        _error = null;
                      }),
                    ),
                  )
                  .toList(),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _detail,
              maxLines: 3,
              minLines: 2,
              maxLength: 255,
              decoration: InputDecoration(
                labelText: isOther ? 'Tell us why (required)' : 'Details (optional)',
                errorText: _error,
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(onPressed: () => Navigator.pop(context), child: const Text('Back')),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: widget.danger ? CtColors.coral : null),
                    onPressed: () {
                      final detail = _detail.text.trim();
                      if (isOther && detail.isEmpty) {
                        setState(() => _error = 'Add a short reason.');
                        return;
                      }
                      Navigator.pop(context, (preset: _preset, detail: detail));
                    },
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

/// A button that shows a spinner while its async action runs.
class AsyncButton extends StatefulWidget {
  const AsyncButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.outlined = false,
    this.color,
    this.compact = true,
    this.busyLabel,
  });

  final String label;
  final String? busyLabel;
  final Future<void> Function()? onPressed;
  final IconData? icon;
  final bool outlined;
  final Color? color;
  final bool compact;

  @override
  State<AsyncButton> createState() => _AsyncButtonState();
}

class _AsyncButtonState extends State<AsyncButton> {
  bool _busy = false;

  Future<void> _run() async {
    if (_busy || widget.onPressed == null) return;
    setState(() => _busy = true);
    try {
      await widget.onPressed!();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final child = _busy
        ? Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
              const SizedBox(width: 10),
              Text(widget.busyLabel ?? 'Please wait…'),
            ],
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (widget.icon != null) ...[Icon(widget.icon, size: 18), const SizedBox(width: 8)],
              Flexible(child: Text(widget.label, overflow: TextOverflow.ellipsis)),
            ],
          );
    final onPressed = widget.onPressed == null || _busy ? null : _run;
    if (widget.outlined) {
      return OutlinedButton(
        style: widget.compact ? compactOutlined(color: widget.color) : null,
        onPressed: onPressed,
        child: child,
      );
    }
    return FilledButton(
      style:
          widget.compact ? compactFilled(color: widget.color) : FilledButton.styleFrom(backgroundColor: widget.color),
      onPressed: onPressed,
      child: child,
    );
  }
}

class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, required this.child, this.trailing, this.padding});

  final String? title;
  final Widget child;
  final Widget? trailing;
  final EdgeInsets? padding;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      child: Padding(
        padding: padding ?? const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (title != null) ...[
              Row(
                children: [
                  Expanded(
                    child: Text(title!, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  ),
                  if (trailing != null) trailing!,
                ],
              ),
              const SizedBox(height: 10),
            ],
            child,
          ],
        ),
      ),
    );
  }
}

class InfoRow extends StatelessWidget {
  const InfoRow(this.label, this.value, {super.key});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    if (value.trim().isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 110, child: Text(label, style: const TextStyle(color: CtColors.muted))),
          Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600))),
        ],
      ),
    );
  }
}

class NoticeBox extends StatelessWidget {
  const NoticeBox(this.text, {super.key, this.tone = CtColors.gold, this.icon = Icons.info_outline, this.action});

  final String text;
  final Color tone;
  final IconData icon;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: tone.withOpacity(0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: tone.withOpacity(0.4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: tone, size: 20),
              const SizedBox(width: 10),
              Expanded(child: Text(text)),
            ],
          ),
          if (action != null) ...[const SizedBox(height: 10), action!],
        ],
      ),
    );
  }
}

/// Image that needs the Bearer token (check-in photos, certificates).
class AuthImage extends StatelessWidget {
  const AuthImage(this.url, {super.key, this.height = 180, this.fit = BoxFit.cover, this.errorLabel});

  final String url;
  final double height;
  final BoxFit fit;
  final String? errorLabel;

  @override
  Widget build(BuildContext context) {
    final full = context.read<Session>().api.mediaUrl(url);
    if (full.isEmpty) {
      return const SizedBox.shrink();
    }
    return GestureDetector(
      onTap: () => showDialog<void>(
        context: context,
        builder: (ctx) => Dialog(
          backgroundColor: Colors.black,
          insetPadding: const EdgeInsets.all(12),
          child: InteractiveViewer(
            child: RemotePhoto(url: full, auth: true, fit: BoxFit.contain, height: MediaQuery.of(ctx).size.height * 0.75),
          ),
        ),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: RemotePhoto(url: full, auth: true, height: height, fit: fit, errorLabel: errorLabel),
      ),
    );
  }
}

class Avatar extends StatelessWidget {
  const Avatar({super.key, required this.name, this.url = '', this.radius = 22});

  final String name;
  final String url;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final full = context.read<Session>().api.mediaUrl(url);
    final initials =
        name.split(RegExp(r'\s+')).where((p) => p.isNotEmpty).take(2).map((p) => p[0].toUpperCase()).join();
    final label = Text(initials.isEmpty ? '?' : initials,
        style: TextStyle(fontSize: radius * 0.7, fontWeight: FontWeight.w700, color: Colors.white));
    return CircleAvatar(
      radius: radius,
      backgroundColor: CtColors.teal,
      child: full.isEmpty
          ? label
          : ClipOval(
              child: SizedBox(
                width: radius * 2,
                height: radius * 2,
                child: RemotePhoto(url: full, fit: BoxFit.cover, width: radius * 2, height: radius * 2),
              ),
            ),
    );
  }
}

/// "08:00:00" or "8:00 AM" to TimeOfDay.
TimeOfDay? parseTimeOfDay(String raw) {
  var s = raw.trim().replaceAll('\u00a0', ' ').replaceAll('—', '').replaceAll('–', '-');
  s = s.toUpperCase();
  if (s.isEmpty || s == '-' || s == '--') return null;
  final m = RegExp(r'^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$').firstMatch(s);
  if (m == null) return null;
  var h = int.parse(m.group(1)!);
  final min = int.parse(m.group(2)!);
  final ap = m.group(3);
  if (ap == 'PM' && h < 12) h += 12;
  if (ap == 'AM' && h == 12) h = 0;
  if (h > 23 || min > 59) return null;
  return TimeOfDay(hour: h, minute: min);
}

String formatTime12(TimeOfDay t) {
  final h = t.hourOfPeriod == 0 ? 12 : t.hourOfPeriod;
  final m = t.minute.toString().padLeft(2, '0');
  return '$h:$m ${t.period == DayPeriod.am ? 'AM' : 'PM'}';
}

String ymd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
