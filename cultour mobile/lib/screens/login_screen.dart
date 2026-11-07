import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/ui.dart';
import 'account_screens.dart';
import 'register_screen.dart';
import 'shell.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
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

  Future<void> _submit({int? roleId}) async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().login(_email.text.trim(), _password.text, roleId: roleId);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const AppShell()),
        (_) => false,
      );
    } on ApiException catch (e) {
      if (e.needRole && e.roles.isNotEmpty) {
        final picked = await _pickRole(e);
        if (picked != null) {
          await _submit(roleId: picked);
          return;
        }
      } else {
        if (mounted) showCulTourError(context, e);
      }
    } catch (e) {
      if (mounted) showCulTourError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<int?> _pickRole(ApiException e) {
    return showModalBottomSheet<int>(
      context: context,
      backgroundColor: CtColors.surface,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) {
        return Padding(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Choose an account', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              Text(e.message, style: const TextStyle(color: CtColors.muted)),
              const SizedBox(height: 16),
              ...e.roles.map(
                (r) => ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(r.label),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.pop(ctx, r.roleId),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Log in')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text('CulTour', style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: CtColors.sea)),
          const SizedBox(height: 6),
          const Text('Sign in to your CulTour account.', style: TextStyle(color: CtColors.muted)),
          const SizedBox(height: 22),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.email],
            decoration: const InputDecoration(labelText: 'Email'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _password,
            obscureText: _obscure,
            autofillHints: const [AutofillHints.password],
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
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Login'),
          ),
          const SizedBox(height: 16),
          TextButton(
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => ForgotPasswordScreen(prefillEmail: _email.text.trim())),
            ),
            child: const Text('Forgot password?'),
          ),
          TextButton(
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => LinkRoleScreen(fromLogin: true, prefillEmail: _email.text.trim())),
            ),
            child: const Text('Link another role'),
          ),
          TextButton(
            onPressed: () => Navigator.pushReplacement(
              context,
              MaterialPageRoute(builder: (_) => const RegisterScreen()),
            ),
            child: const Text('No account? Register'),
          ),
        ],
      ),
    );
  }
}
