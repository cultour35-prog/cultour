import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/kit.dart';

/// Same fields and validation as tourist/profile.php and tourguide/profile.php.
class ProfileEditScreen extends StatefulWidget {
  const ProfileEditScreen({super.key});

  @override
  State<ProfileEditScreen> createState() => _ProfileEditScreenState();
}

class _ProfileEditScreenState extends State<ProfileEditScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  bool _saving = false;
  String? _avatarPath;
  final Map<String, TextEditingController> _c = {};
  final Map<String, String> _v = {};
  bool _sameAddress = true;

  static const _text = [
    'first_name',
    'middle_name',
    'last_name',
    'phone',
    'date_of_birth',
    'nationality',
    'languages',
    'address_street',
    'current_address_street',
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_phone',
  ];
  static const _picks = [
    'sex',
    'address_region',
    'address_city',
    'address_barangay',
    'current_address_region',
    'current_address_city',
    'current_address_barangay',
  ];

  TextEditingController _ctl(String k) => _c.putIfAbsent(k, TextEditingController.new);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  void _apply(Map<String, dynamic> d) {
    _data = d;
    for (final k in _text) {
      _ctl(k).text = asString(d[k]);
    }
    for (final k in _picks) {
      _v[k] = asString(d[k]);
    }
    _sameAddress = asInt(d['current_same_as_permanent'], 1) == 1;
    final g = d['guide'] == null ? null : asMap(d['guide']);
    if (g != null) {
      _ctl('bio').text = asString(g['bio']);
      _ctl('guide_languages').text = asString(g['languages']);
      _ctl('years_experience').text = '${asInt(g['years_experience'])}';
      _ctl('license_number').text = asString(g['license_number']);
      if (!_sameAddress) {
        _v['current_address_region'] = asString(g['locked_province']);
        _v['current_address_city'] = asString(g['locked_city'], 'Ormoc City');
      }
    }
  }

  Future<void> _load() async {
    try {
      final res = await context.read<Session>().api.getJson('/account/profile.php');
      if (!mounted) return;
      setState(() {
        _apply(asMap(res['data']));
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _save() async {
    final session = context.read<Session>();
    final body = <String, dynamic>{
      for (final k in _text) k: _ctl(k).text.trim(),
      for (final k in _picks) k: _v[k] ?? '',
      'current_same_as_permanent': _sameAddress ? 1 : 0,
    };
    if (_data?['guide'] != null) {
      body['bio'] = _ctl('bio').text.trim();
      body['guide_languages'] = _ctl('guide_languages').text.trim();
      body['years_experience'] = int.tryParse(_ctl('years_experience').text.trim()) ?? 0;
      body['license_number'] = _ctl('license_number').text.trim();
    }
    setState(() => _saving = true);
    final res = await runApi(
      context,
      () => _avatarPath != null
          ? session.api.multipart('/account/profile.php', body, {'avatar': _avatarPath!})
          : session.api.postJson('/account/profile.php', body),
    );
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (res != null && res['data'] != null) {
        _avatarPath = null;
        _apply(asMap(res['data']));
      }
    });
    if (res != null) await session.refreshUser();
  }

  Future<void> _pickPlace(String key, {String? province, String? city}) async {
    final api = context.read<Session>().api;
    final query = <String, String>{
      if (province != null) 'province': province,
      if (city != null) 'city': city,
    };
    List<String> options;
    try {
      final res = await api.getJson('/address/options.php', query);
      options = asList(res['data']).map((e) => '$e').toList();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      return;
    }
    if (!mounted) return;
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: CtColors.surface,
      builder: (_) => _SearchList(options: options),
    );
    if (picked == null) return;
    setState(() {
      _v[key] = picked;
      final prefix = key.startsWith('current_') ? 'current_address_' : 'address_';
      if (key.endsWith('region')) {
        _v['${prefix}city'] = '';
        _v['${prefix}barangay'] = '';
      } else if (key.endsWith('city')) {
        _v['${prefix}barangay'] = '';
      }
    });
  }

  Widget _field(String k, String label, {TextInputType? type, int lines = 1, String? hint}) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: TextField(
          controller: _ctl(k),
          keyboardType: type,
          maxLines: lines,
          decoration: InputDecoration(labelText: label, hintText: hint),
        ),
      );

  Widget _picker(String k, String label, VoidCallback? onTap) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: InkWell(
          onTap: onTap,
          child: InputDecorator(
            decoration:
                InputDecoration(labelText: label, suffixIcon: const Icon(Icons.expand_more), enabled: onTap != null),
            child: Text((_v[k] ?? '').isEmpty ? 'Select' : _v[k]!,
                style: TextStyle(color: (_v[k] ?? '').isEmpty ? CtColors.muted : CtColors.ink)),
          ),
        ),
      );

  List<Widget> _addressBlock(String prefix, {bool lockProvince = false}) {
    final region = _v['${prefix}region'] ?? '';
    final city = _v['${prefix}city'] ?? '';
    return [
      _picker('${prefix}region', 'Province', lockProvince ? null : () => _pickPlace('${prefix}region')),
      _picker('${prefix}city', 'City / municipality',
          (lockProvince || region.isEmpty) ? null : () => _pickPlace('${prefix}city', province: region)),
      _picker('${prefix}barangay', 'Barangay',
          city.isEmpty ? null : () => _pickPlace('${prefix}barangay', province: region, city: city)),
      _field('${prefix}street', 'Street / house no.'),
    ];
  }

  Future<void> _pickDob() async {
    final now = DateTime.now();
    final current = DateTime.tryParse(_ctl('date_of_birth').text) ?? DateTime(now.year - 25);
    final d = await showDatePicker(
      context: context,
      initialDate: current,
      firstDate: DateTime(1920),
      lastDate: DateTime(now.year - 18, now.month, now.day),
    );
    if (d != null) setState(() => _ctl('date_of_birth').text = ymd(d));
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    final guide = d?['guide'] == null ? null : asMap(d!['guide']);
    final sexOptions = asMap(d?['sex_options']);
    return Scaffold(
      appBar: AppBar(title: Text(guide != null ? 'Guide profile' : 'Edit profile')),
      body: d == null
          ? Center(child: _error != null ? Text('$_error') : const CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (asInt(d['must_complete_profile']) == 1)
                  const NoticeBox('Please complete your profile to keep using CulTour.'),
                Center(
                  child: GestureDetector(
                    onTap: () async {
                      final p = await pickPhoto(context);
                      if (p != null) setState(() => _avatarPath = p);
                    },
                    child: Stack(
                      children: [
                        _avatarPath != null
                            ? CircleAvatar(radius: 44, backgroundImage: FileImage(File(_avatarPath!)))
                            : Avatar(
                                name: '${asString(d['first_name'])} ${asString(d['last_name'])}',
                                url: asString(d['avatar_url']),
                                radius: 44,
                              ),
                        const Positioned(
                          right: 0,
                          bottom: 0,
                          child: CircleAvatar(
                              radius: 14,
                              backgroundColor: CtColors.teal,
                              child: Icon(Icons.camera_alt, size: 16, color: Colors.white)),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 6),
                Center(child: Text(asString(d['email']), style: const TextStyle(color: CtColors.muted))),
                if (guide != null) ...[
                  const SizedBox(height: 10),
                  Center(
                    child: Text(
                      '${asString(guide['approval_status'])} · ★ ${guide['avg_rating']} · ${guide['total_tours']} tours · ${asString(guide['daily_rate_label'])}/day',
                      style: const TextStyle(color: CtColors.muted, fontSize: 12),
                    ),
                  ),
                ],
                const SizedBox(height: 16),
                SectionCard(
                  title: 'Personal details',
                  child: Column(children: [
                    _field('first_name', 'First name'),
                    _field('middle_name', 'Middle name (optional)'),
                    _field('last_name', 'Last name'),
                    _field('phone', 'Mobile number', type: TextInputType.phone, hint: '09XXXXXXXXX'),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: DropdownButtonFormField<String>(
                        initialValue: sexOptions.containsKey(_v['sex']) ? _v['sex'] : null,
                        decoration: const InputDecoration(labelText: 'Sex'),
                        items: sexOptions.entries
                            .map((e) => DropdownMenuItem(value: e.key, child: Text('${e.value}')))
                            .toList(),
                        onChanged: (v) => setState(() => _v['sex'] = v ?? ''),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: TextField(
                        controller: _ctl('date_of_birth'),
                        readOnly: true,
                        onTap: _pickDob,
                        decoration: const InputDecoration(labelText: 'Date of birth', suffixIcon: Icon(Icons.event)),
                      ),
                    ),
                    _field('nationality', 'Nationality'),
                    _field('languages', 'Languages you speak', hint: 'English, Filipino, Waray'),
                  ]),
                ),
                SectionCard(
                  title: 'Permanent address',
                  child: Column(children: _addressBlock('address_')),
                ),
                SectionCard(
                  title: 'Current address',
                  child: Column(children: [
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _sameAddress,
                      title: const Text('Same as permanent address'),
                      onChanged: (v) => setState(() {
                        _sameAddress = v;
                        if (!v && guide != null) {
                          _v['current_address_region'] = asString(guide['locked_province']);
                          _v['current_address_city'] = asString(guide['locked_city'], 'Ormoc City');
                        }
                      }),
                    ),
                    if (guide != null && !_sameAddress)
                      Text('Guides must currently live in ${asString(guide['locked_city'], 'Ormoc City')}.',
                          style: const TextStyle(color: CtColors.muted, fontSize: 12)),
                    if (!_sameAddress) ..._addressBlock('current_address_', lockProvince: guide != null),
                  ]),
                ),
                SectionCard(
                  title: 'Emergency contact',
                  child: Column(children: [
                    _field('emergency_contact_name', 'Full name'),
                    _field('emergency_contact_relationship', 'Relationship'),
                    _field('emergency_contact_phone', 'Mobile number', type: TextInputType.phone),
                  ]),
                ),
                if (guide != null)
                  SectionCard(
                    title: 'Guide details',
                    child: Column(children: [
                      _field('bio', 'Bio', lines: 4, hint: 'Tell tourists about your tours'),
                      _field('guide_languages', 'Tour languages', hint: 'English, Cebuano, Waray'),
                      _field('years_experience', 'Years of experience', type: TextInputType.number),
                      _field('license_number', 'DOT license number'),
                    ]),
                  ),
                const SizedBox(height: 8),
                FilledButton(
                  onPressed: _saving ? null : _save,
                  child: Text(_saving ? 'Saving…' : 'Save profile'),
                ),
                const SizedBox(height: 8),
                if (_avatarPath == null && asString(d['avatar_url']).isEmpty)
                  Text(
                    guide != null
                        ? 'Upload a profile photo so tourists can book you.'
                        : 'Tap the photo to add a profile picture.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: CtColors.muted, fontSize: 12),
                  ),
              ],
            ),
    );
  }
}

class _SearchList extends StatefulWidget {
  const _SearchList({required this.options});
  final List<String> options;

  @override
  State<_SearchList> createState() => _SearchListState();
}

class _SearchListState extends State<_SearchList> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final list = widget.options.where((o) => o.toLowerCase().contains(_q.toLowerCase())).toList();
    return SizedBox(
      height: MediaQuery.of(context).size.height * 0.75,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: TextField(
              autofocus: true,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Search'),
              onChanged: (v) => setState(() => _q = v),
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: list.length,
              itemBuilder: (_, i) => ListTile(title: Text(list[i]), onTap: () => Navigator.pop(context, list[i])),
            ),
          ),
        ],
      ),
    );
  }
}

/// account/change-password: current password, new password, confirm.
class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();
  bool _show = false;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    InputDecoration deco(String label) => InputDecoration(
          labelText: label,
          suffixIcon: IconButton(
            icon: Icon(_show ? Icons.visibility_off : Icons.visibility),
            onPressed: () => setState(() => _show = !_show),
          ),
        );
    return Scaffold(
      appBar: AppBar(title: const Text('Change password')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(controller: _current, obscureText: !_show, decoration: deco('Current password')),
          const SizedBox(height: 12),
          TextField(controller: _next, obscureText: !_show, decoration: deco('New password')),
          const SizedBox(height: 12),
          TextField(controller: _confirm, obscureText: !_show, decoration: deco('Confirm new password')),
          const SizedBox(height: 8),
          const Text(
            'At least 5 characters with a letter and a number, no spaces.',
            style: TextStyle(color: CtColors.muted, fontSize: 12),
          ),
          const SizedBox(height: 16),
          AsyncButton(
            label: 'Update password',
            busyLabel: 'Updating…',
            onPressed: () async {
              final res = await runApi(
                context,
                () => api.postJson('/auth/change_password.php', {
                  'current_password': _current.text,
                  'password': _next.text,
                  'password_confirm': _confirm.text,
                }),
              );
              if (res != null && context.mounted) Navigator.pop(context);
            },
          ),
        ],
      ),
    );
  }
}
