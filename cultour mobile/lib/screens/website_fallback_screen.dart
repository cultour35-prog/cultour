import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../session.dart';
import '../theme.dart';

class WebsiteFallbackScreen extends StatelessWidget {
  const WebsiteFallbackScreen({
    super.key,
    required this.title,
    required this.detail,
    this.path = '/',
  });

  final String title;
  final String detail;
  final String path;

  String _url(String origin) {
    final suffix = path.startsWith('/') ? path : '/$path';
    return '$origin$suffix';
  }

  @override
  Widget build(BuildContext context) {
    final origin = context.watch<Session>().api.origin;
    final url = _url(origin);
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(detail, style: const TextStyle(fontSize: 16, height: 1.4)),
          const SizedBox(height: 16),
          SelectableText(url, style: const TextStyle(color: CtColors.muted)),
          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: () async {
              final uri = Uri.parse(url);
              final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
              if (!opened && context.mounted) {
                await Clipboard.setData(ClipboardData(text: url));
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Could not open a browser. URL copied.')),
                  );
                }
              }
            },
            icon: const Icon(Icons.open_in_browser),
            label: const Text('Open on website'),
          ),
          TextButton(
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: url));
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Website URL copied.')),
                );
              }
            },
            child: const Text('Copy URL'),
          ),
        ],
      ),
    );
  }
}
