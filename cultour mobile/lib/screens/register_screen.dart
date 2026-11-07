import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/ui.dart';
import 'login_screen.dart';
import 'shell.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _page = PageController();
  int _index = 0;
  bool _busy = false;

  final _first = TextEditingController();
  final _last = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  final _phone = TextEditingController();
  final _nationality = TextEditingController();
  final _languages = TextEditingController();
  final _address = TextEditingController();
  final _emergName = TextEditingController();
  final _emergRel = TextEditingController();
  final _emergPhone = TextEditingController();
  String _sex = 'prefer_not';
  DateTime? _dob;

  int _ageYears(DateTime dob) {
    final today = DateTime.now();
    var age = today.year - dob.year;
    final birthday = DateTime(today.year, dob.month, dob.day);
    if (today.isBefore(birthday)) age--;
    return age;
  }

  @override
  void dispose() {
    _page.dispose();
    _first.dispose();
    _last.dispose();
    _email.dispose();
    _password.dispose();
    _confirm.dispose();
    _phone.dispose();
    _nationality.dispose();
    _languages.dispose();
    _address.dispose();
    _emergName.dispose();
    _emergRel.dispose();
    _emergPhone.dispose();
    super.dispose();
  }

  Future<void> _next() async {
    if (_index == 0) {
      if (_first.text.trim().isEmpty || _last.text.trim().isEmpty) {
        showCulTourError(context, 'First and last name are required.');
        return;
      }
      if (!_email.text.contains('@')) {
        showCulTourError(context, 'Enter a valid email address.');
        return;
      }
      if (_password.text.length < 5) {
        showCulTourError(context, 'Password must be at least 5 characters.');
        return;
      }
      if (RegExp(r'\s').hasMatch(_password.text)) {
        showCulTourError(context, 'Password cannot contain spaces.');
        return;
      }
      if (!RegExp(r'[A-Za-z]').hasMatch(_password.text)) {
        showCulTourError(context, 'Password must include at least one letter.');
        return;
      }
      if (!RegExp(r'[0-9]').hasMatch(_password.text)) {
        showCulTourError(context, 'Password must include at least one number.');
        return;
      }
      if (_password.text != _confirm.text) {
        showCulTourError(context, 'Passwords do not match.');
        return;
      }
    }
    if (_index == 1) {
      if (_dob == null) {
        showCulTourError(context, 'Date of birth is required.');
        return;
      }
      if (_ageYears(_dob!) < 18) {
        showCulTourError(context, 'You must be at least 18 years old to register.');
        return;
      }
      if (_nationality.text.trim().isEmpty) {
        showCulTourError(context, 'Nationality is required.');
        return;
      }
      if (_languages.text.trim().isEmpty) {
        showCulTourError(context, 'Please list the language(s) you speak.');
        return;
      }
      if (_phone.text.trim().isEmpty || _address.text.trim().isEmpty) {
        showCulTourError(context, 'Phone and address are required.');
        return;
      }
    }
    if (_index < 2) {
      await _page.nextPage(duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      return;
    }
    await _submit();
  }

  Future<void> _submit() async {
    if (_emergName.text.trim().isEmpty || _emergRel.text.trim().isEmpty || _emergPhone.text.trim().isEmpty) {
      showCulTourError(context, 'Complete your emergency contact.');
      return;
    }
    setState(() => _busy = true);
    try {
      final dob = _dob!;
      final payload = {
        'first_name': _first.text.trim(),
        'last_name': _last.text.trim(),
        'email': _email.text.trim(),
        'password': _password.text,
        'password_confirm': _confirm.text,
        'sex': _sex,
        'date_of_birth':
            '${dob.year.toString().padLeft(4, '0')}-${dob.month.toString().padLeft(2, '0')}-${dob.day.toString().padLeft(2, '0')}',
        'nationality': _nationality.text.trim(),
        'tourist_languages': _languages.text.trim(),
        'address': _address.text.trim(),
        'phone': _phone.text.trim(),
        'emergency_contact_name': _emergName.text.trim(),
        'emergency_relationship': _emergRel.text.trim(),
        'emergency_contact_phone': _emergPhone.text.trim(),
      };
      await context.read<Session>().registerTourist(payload);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const AppShell()),
        (_) => false,
      );
    } on ApiException catch (e) {
      if (mounted) showCulTourError(context, e);
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
          title: Text(_index == 0
              ? 'Create account'
              : _index == 1
                  ? 'About you'
                  : 'Emergency contact')),
      body: Column(
        children: [
          LinearProgressIndicator(value: (_index + 1) / 3, color: CtColors.gold, backgroundColor: CtColors.surface),
          Expanded(
            child: PageView(
              controller: _page,
              physics: const NeverScrollableScrollPhysics(),
              onPageChanged: (i) => setState(() => _index = i),
              children: [
                _pad([
                  Text('Join CulTour', style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: CtColors.sea)),
                  const SizedBox(height: 6),
                  const Text('Create a tourist account to plan tours and book licensed guides.',
                      style: TextStyle(color: CtColors.muted)),
                  const SizedBox(height: 18),
                  TextField(
                      controller: _first,
                      textCapitalization: TextCapitalization.words,
                      decoration: const InputDecoration(labelText: 'First name')),
                  const SizedBox(height: 12),
                  TextField(
                      controller: _last,
                      textCapitalization: TextCapitalization.words,
                      decoration: const InputDecoration(labelText: 'Last name')),
                  const SizedBox(height: 12),
                  TextField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(labelText: 'Email')),
                  const SizedBox(height: 12),
                  TextField(
                      controller: _password,
                      obscureText: true,
                      decoration: const InputDecoration(labelText: 'Password')),
                  const SizedBox(height: 12),
                  TextField(
                      controller: _confirm,
                      obscureText: true,
                      decoration: const InputDecoration(labelText: 'Confirm password')),
                  const SizedBox(height: 8),
                  const Text('At least 5 characters, one letter, one number, no spaces.',
                      style: TextStyle(color: CtColors.muted, fontSize: 12)),
                ]),
                _pad([
                  DropdownButtonFormField<String>(
                    value: _sex,
                    decoration: const InputDecoration(labelText: 'Sex'),
                    items: const [
                      DropdownMenuItem(value: 'female', child: Text('Female')),
                      DropdownMenuItem(value: 'male', child: Text('Male')),
                      DropdownMenuItem(value: 'prefer_not', child: Text('Prefer not to say')),
                    ],
                    onChanged: (v) => setState(() => _sex = v ?? 'prefer_not'),
                  ),
                  const SizedBox(height: 12),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Date of birth'),
                    subtitle: Text(_dob == null
                        ? 'Tap to choose'
                        : '${_dob!.year}-${_dob!.month.toString().padLeft(2, '0')}-${_dob!.day.toString().padLeft(2, '0')}'),
                    trailing: const Icon(Icons.cake_outlined),
                    onTap: () async {
                      final now = DateTime.now();
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime(now.year - 20),
                        firstDate: DateTime(1920),
                        lastDate: DateTime(now.year - 18, now.month, now.day),
                      );
                      if (picked != null) setState(() => _dob = picked);
                    },
                  ),
                  const SizedBox(height: 8),
                  TextField(controller: _nationality, decoration: const InputDecoration(labelText: 'Nationality')),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _languages,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'Languages',
                      hintText: 'English, Cebuano, Tagalog',
                      helperText: 'Languages you speak, separated by commas.',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(labelText: 'Mobile number')),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _address,
                    maxLines: 2,
                    decoration: const InputDecoration(labelText: 'Permanent address'),
                  ),
                ]),
                _pad([
                  const Text('Must be a different person and phone number from yours.',
                      style: TextStyle(color: CtColors.muted)),
                  const SizedBox(height: 16),
                  TextField(
                      controller: _emergName,
                      textCapitalization: TextCapitalization.words,
                      decoration: const InputDecoration(labelText: 'Contact name')),
                  const SizedBox(height: 12),
                  TextField(controller: _emergRel, decoration: const InputDecoration(labelText: 'Relationship')),
                  const SizedBox(height: 12),
                  TextField(
                      controller: _emergPhone,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(labelText: 'Contact number')),
                ]),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            child: Row(
              children: [
                if (_index > 0)
                  Expanded(
                    child: OutlinedButton(
                      onPressed: _busy
                          ? null
                          : () =>
                              _page.previousPage(duration: const Duration(milliseconds: 250), curve: Curves.easeOut),
                      child: const Text('Back'),
                    ),
                  ),
                if (_index > 0) const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    onPressed: _busy ? null : _next,
                    child: _busy
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : Text(_index == 2 ? 'Create account' : 'Continue'),
                  ),
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => const LoginScreen())),
            child: const Text('Already have an account? Login'),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Widget _pad(List<Widget> children) {
    return ListView(padding: const EdgeInsets.all(20), children: children);
  }
}
