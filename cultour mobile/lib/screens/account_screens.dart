import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../session.dart';
import '../theme.dart';
import '../util/jsonx.dart';
import '../widgets/ui.dart';
import 'shell.dart';

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key, this.prefillEmail = ''});
  final String prefillEmail;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  int _step = 0;
  bool _busy = false;
  bool _obscure = true;

  @override
  void initState() {
    super.initState();
    _email.text = widget.prefillEmail;
    if (widget.prefillEmail.contains('@')) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _request());
    }
  }

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _request() async {
    final email = _email.text.trim();
    if (!email.contains('@')) {
      showCulTourError(context, 'Enter a valid account email.');
      return;
    }
    setState(() => _busy = true);
    try {
      final data = await context.read<Session>().api.forgotPassword(action: 'request', email: email);
      if (!mounted) return;
      setState(() => _step = 1);
      showCulTourMessage(context, asString(data['message'], 'If that email is registered, we sent a 6-digit code.'));
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    final code = _code.text.replaceAll(RegExp(r'\D'), '');
    if (code.length != 6) {
      showCulTourError(context, 'Enter the 6-digit code from your email.');
      return;
    }
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.forgotPassword(action: 'verify', email: _email.text.trim(), code: code);
      if (!mounted) return;
      setState(() => _step = 2);
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reset() async {
    if (_password.text.length < 5 ||
        !RegExp(r'[A-Za-z]').hasMatch(_password.text) ||
        !RegExp(r'[0-9]').hasMatch(_password.text) ||
        _password.text.contains(' ')) {
      showCulTourError(context, 'Password needs 5+ characters, a letter, and a number, with no spaces.');
      return;
    }
    if (_password.text != _confirm.text) {
      showCulTourError(context, 'Passwords do not match.');
      return;
    }
    setState(() => _busy = true);
    try {
      final data = await context.read<Session>().api.forgotPassword(
            action: 'reset',
            email: _email.text.trim(),
            code: _code.text.replaceAll(RegExp(r'\D'), ''),
            password: _password.text,
            passwordConfirm: _confirm.text,
          );
      if (!mounted) return;
      await showCulTourMessage(context, asString(data['message'], 'Password updated. You can now log in.'));
      if (!mounted) return;
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final lockedEmail = widget.prefillEmail.contains('@');
    return Scaffold(
      appBar: AppBar(title: const Text('Reset password')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            _step == 0
                ? 'We’ll email a 6-digit code so you can choose a new password.'
                : _step == 1
                    ? 'Enter the 6-digit code from your email. Codes expire in 15 minutes.'
                    : 'Choose a new password for your account.',
            style: const TextStyle(color: CtColors.muted),
          ),
          const SizedBox(height: 22),
          if (_step == 0) ...[
            TextField(
              controller: _email,
              enabled: !lockedEmail,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'Account email'),
            ),
            const SizedBox(height: 22),
            FilledButton(onPressed: _busy ? null : _request, child: _busyLabel('Send code')),
          ] else if (_step == 1) ...[
            TextField(
              controller: _email,
              enabled: false,
              decoration: const InputDecoration(labelText: 'Account email'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              decoration: const InputDecoration(labelText: 'Reset code', counterText: ''),
            ),
            const SizedBox(height: 22),
            FilledButton(onPressed: _busy ? null : _verify, child: _busyLabel('Continue')),
            TextButton(onPressed: _busy ? null : _request, child: const Text('Resend code')),
          ] else ...[
            TextField(
              controller: _password,
              obscureText: _obscure,
              decoration: InputDecoration(
                labelText: 'New password',
                suffixIcon: IconButton(
                  icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                  onPressed: () => setState(() => _obscure = !_obscure),
                ),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _confirm,
              obscureText: _obscure,
              decoration: const InputDecoration(labelText: 'Confirm password'),
            ),
            const SizedBox(height: 8),
            const Text('At least 5 characters, one letter, one number, no spaces.',
                style: TextStyle(color: CtColors.muted, fontSize: 12)),
            const SizedBox(height: 22),
            FilledButton(onPressed: _busy ? null : _reset, child: _busyLabel('Update password')),
          ],
        ],
      ),
    );
  }

  Widget _busyLabel(String text) {
    return _busy
        ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
        : Text(text);
  }
}

class ChangeEmailScreen extends StatefulWidget {
  const ChangeEmailScreen({super.key});

  @override
  State<ChangeEmailScreen> createState() => _ChangeEmailScreenState();
}

class _ChangeEmailScreenState extends State<ChangeEmailScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_email.text.contains('@')) {
      showCulTourError(context, 'Enter a valid email address.');
      return;
    }
    if (_password.text.isEmpty) {
      showCulTourError(context, 'Enter your password to confirm this change.');
      return;
    }
    setState(() => _busy = true);
    try {
      final session = context.read<Session>();
      final data = await session.api.changeEmail(newEmail: _email.text.trim(), password: _password.text);
      await session.refreshUser();
      if (!mounted) return;
      await showCulTourMessage(context, asString(data['message'], 'Email updated.'));
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = context.watch<Session>().user?.email ?? '';
    return Scaffold(
      appBar: AppBar(title: const Text('Change email')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text('Current email: $current', style: const TextStyle(color: CtColors.muted)),
          const SizedBox(height: 18),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(labelText: 'New email'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _password,
            obscureText: _obscure,
            decoration: InputDecoration(
              labelText: 'Password',
              suffixIcon: IconButton(
                icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                onPressed: () => setState(() => _obscure = !_obscure),
              ),
            ),
          ),
          const SizedBox(height: 22),
          FilledButton(
            onPressed: _busy ? null : _save,
            child: _busy
                ? const SizedBox(
                    height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Save new email'),
          ),
        ],
      ),
    );
  }
}

class LinkRoleScreen extends StatefulWidget {
  const LinkRoleScreen({super.key, this.fromLogin = false, this.prefillEmail = ''});
  final bool fromLogin;
  final String prefillEmail;

  @override
  State<LinkRoleScreen> createState() => _LinkRoleScreenState();
}

class _LinkRoleScreenState extends State<LinkRoleScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _languages = TextEditingController();
  final _years = TextEditingController(text: '0');
  bool _busy = false;
  bool _loading = false;
  int _targetRole = 3;
  Map<String, dynamic>? _linked;

  @override
  void initState() {
    super.initState();
    _email.text = widget.prefillEmail;
    if (!widget.fromLogin) {
      _load();
    }
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _languages.dispose();
    _years.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final data = await context.read<Session>().api.linkedAccounts();
      if (!mounted) return;
      setState(() {
        _linked = data;
        _targetRole = asInt(data['counterpart_role_id'], 3);
      });
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _switchTo(int userId) async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().switchLinkedAccount(userId);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const AppShell()),
        (_) => false,
      );
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _create() async {
    if (_password.text.isEmpty) {
      showCulTourError(context, 'Enter your password to continue.');
      return;
    }
    if (widget.fromLogin && !_email.text.contains('@')) {
      showCulTourError(context, 'Enter the email of your existing account.');
      return;
    }
    setState(() => _busy = true);
    try {
      final years = int.tryParse(_years.text.trim()) ?? 0;
      final data = await context.read<Session>().api.linkRole(
            password: _password.text,
            email: widget.fromLogin ? _email.text.trim() : null,
            targetRoleId: widget.fromLogin ? _targetRole : null,
            yearsExperience: _creatingGuide ? years : null,
            languages: _creatingGuide && _languages.text.trim().isNotEmpty ? _languages.text.trim() : null,
          );
      if (!mounted) return;
      await showCulTourMessage(context, asString(data['message'], 'Linked account created.'));
      if (!mounted) return;
      if (widget.fromLogin) {
        Navigator.pop(context, true);
        return;
      }
      await _load();
      _password.clear();
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _creatingGuide {
    if (widget.fromLogin) return _targetRole == 3;
    return asInt(_linked?['counterpart_role_id']) == 3;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Link another role')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(20),
              children: [
                const Text(
                  'Tourist and Tour Guide accounts that share an email can switch inside CulTour.',
                  style: TextStyle(color: CtColors.muted),
                ),
                const SizedBox(height: 18),
                if (!widget.fromLogin) ..._loggedInBody(),
                if (widget.fromLogin) ..._loginBody(),
              ],
            ),
    );
  }

  List<Widget> _loggedInBody() {
    final accounts = asList(_linked?['accounts']);
    final canCreate = asBool(_linked?['can_create']);
    final counterpart = asString(_linked?['counterpart_role_name'], 'linked');
    return [
      if (accounts.isNotEmpty) ...[
        const Text('Switch account', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        const SizedBox(height: 8),
        ...accounts.map((raw) {
          final row = asMap(raw);
          return Card(
            child: ListTile(
              title: Text(asString(row['role_name'], 'Account')),
              subtitle: Text(asString(row['status'], '')),
              trailing: const Icon(Icons.swap_horiz),
              onTap: _busy ? null : () => _switchTo(asInt(row['id'])),
            ),
          );
        }),
        const SizedBox(height: 18),
      ],
      if (canCreate) ...[
        Text('Add a $counterpart account', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        const SizedBox(height: 8),
        Text(
          'We’ll copy your name, email, and contact details into a $counterpart account.',
          style: const TextStyle(color: CtColors.muted),
        ),
        const SizedBox(height: 14),
        ..._createFields(),
      ] else if (accounts.isEmpty)
        const Text('A linked account is not available for this login.', style: TextStyle(color: CtColors.muted)),
    ];
  }

  List<Widget> _loginBody() {
    return [
      const Text('I already have', style: TextStyle(fontWeight: FontWeight.w700)),
      const SizedBox(height: 8),
      SegmentedButton<int>(
        segments: const [
          ButtonSegment(value: 3, label: Text('Add Guide')),
          ButtonSegment(value: 2, label: Text('Add Tourist')),
        ],
        selected: {_targetRole},
        onSelectionChanged: (s) => setState(() => _targetRole = s.first),
      ),
      const SizedBox(height: 14),
      TextField(
        controller: _email,
        keyboardType: TextInputType.emailAddress,
        decoration: const InputDecoration(labelText: 'Existing account email'),
      ),
      const SizedBox(height: 12),
      ..._createFields(),
    ];
  }

  List<Widget> _createFields() {
    return [
      TextField(
        controller: _password,
        obscureText: true,
        decoration: const InputDecoration(labelText: 'Password'),
      ),
      if (_creatingGuide) ...[
        const SizedBox(height: 12),
        TextField(
          controller: _languages,
          decoration: const InputDecoration(
            labelText: 'Languages you can guide in',
            hintText: 'English, Cebuano, Tagalog',
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _years,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Years of experience'),
        ),
      ],
      const SizedBox(height: 22),
      FilledButton(
        onPressed: _busy ? null : _create,
        child: _busy
            ? const SizedBox(
                height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : const Text('Create linked account'),
      ),
    ];
  }
}
