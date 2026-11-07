import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'models.dart';
import 'util/jsonx.dart';

class Session extends ChangeNotifier {
  Session();

  static const _tokenKey = 'cultour_token';
  static const _userKey = 'cultour_user_json';
  static const _apiKey = 'cultour_api_base';

  late CulTourApi api;
  CtUser? user;
  bool ready = false;
  bool livePaused = false;

  int unreadMessages = 0;
  int unreadNotifications = 0;
  int pendingBookings = 0;
  String liveVersion = '';
  String homeVersion = '';
  String exploreVersion = '';
  String bookingsVersion = '';
  String itinerariesVersion = '';
  String favoritesVersion = '';
  String notificationsVersion = '';
  String chatVersion = '';
  String userVersion = '';

  Timer? _liveTimer;
  bool _liveBusy = false;

  bool get isLoggedIn => user != null && (api.token ?? '').isNotEmpty;

  Future<void> bootstrap() async {
    final prefs = await SharedPreferences.getInstance();
    final storedApi = prefs.getString(_apiKey);
    api = CulTourApi(
      baseUrl: (storedApi != null && storedApi.isNotEmpty) ? storedApi : CulTourApi.suggestedBaseUrl(),
      token: prefs.getString(_tokenKey),
    );
    final rawUser = prefs.getString(_userKey);
    if (rawUser != null && api.token != null) {
      try {
        user = CtUser.fromJson(jsonDecode(rawUser) as Map<String, dynamic>);
      } catch (_) {
        user = null;
      }
    }
    if (isLoggedIn) {
      try {
        user = await api.me();
        await _persistUser();
      } on ApiException catch (e) {
        if (e.status == 401) {
          await logout(remote: false);
        }
      } catch (_) {}
    }
    ready = true;
    notifyListeners();
  }

  Future<void> login(String email, String password, {int? roleId}) async {
    final data = await api.login(email, password, roleId: roleId);
    await applyAuth(data);
  }

  Future<void> applyAuth(Map<String, dynamic> data) async {
    api.token = data['token']?.toString();
    user = CtUser.fromJson(Map<String, dynamic>.from(data['user'] as Map));
    liveVersion = '';
    homeVersion = '';
    exploreVersion = '';
    bookingsVersion = '';
    itinerariesVersion = '';
    favoritesVersion = '';
    notificationsVersion = '';
    chatVersion = '';
    userVersion = '';
    await _persist();
    startLive();
    notifyListeners();
  }

  void startLive() {
    _liveTimer?.cancel();
    _liveTimer = Timer.periodic(const Duration(milliseconds: 2500), (_) => pollLive());
    pollLive();
  }

  void stopLive() {
    _liveTimer?.cancel();
    _liveTimer = null;
  }

  void setLivePaused(bool paused) {
    livePaused = paused;
    if (!paused) pollLive();
  }

  Future<void> pollLive() async {
    if (!isLoggedIn || livePaused || _liveBusy) return;
    _liveBusy = true;
    try {
      final data = await api.liveSync(since: liveVersion);
      if (!isLoggedIn) return;
      if (!asBool(data['changed'], true)) return;
      liveVersion = asString(data['version']);
      unreadMessages = asInt(data['unread_messages']);
      unreadNotifications = asInt(data['unread_notifications']);
      pendingBookings = asInt(data['pending_bookings']);
      homeVersion = asString(data['home']);
      exploreVersion = asString(data['explore']);
      bookingsVersion = asString(data['bookings']);
      itinerariesVersion = asString(data['itineraries']);
      favoritesVersion = asString(data['favorites']);
      notificationsVersion = asString(data['notifications']);
      chatVersion = asString(data['chat']);
      final nextUser = asString(data['user']);
      final status = asString(data['status'], user?.status ?? '');
      if (nextUser.isNotEmpty && nextUser != userVersion) {
        userVersion = nextUser;
        try {
          user = await api.me();
          await _persistUser();
        } catch (_) {}
      } else if (status.isNotEmpty && user != null && status != user!.status) {
        try {
          user = await api.me();
          await _persistUser();
        } catch (_) {}
      }
      notifyListeners();
    } on ApiException catch (e) {
      if (e.status == 401) {
        await logout(remote: false);
      }
    } catch (_) {
    } finally {
      _liveBusy = false;
    }
  }

  void _resetLive() {
    stopLive();
    livePaused = false;
    unreadMessages = 0;
    unreadNotifications = 0;
    pendingBookings = 0;
    liveVersion = '';
    homeVersion = '';
    exploreVersion = '';
    bookingsVersion = '';
    itinerariesVersion = '';
    favoritesVersion = '';
    notificationsVersion = '';
    chatVersion = '';
    userVersion = '';
  }

  Future<void> refreshUser() async {
    user = await api.me();
    await _persistUser();
    notifyListeners();
  }

  Future<void> switchLinkedAccount(int userId) async {
    final data = await api.switchLinkedAccount(userId);
    await applyAuth(data);
  }

  Future<void> registerTourist(Map<String, dynamic> payload) async {
    await api.registerTourist(payload);
    await login(payload['email'] as String, payload['password'] as String, roleId: 2);
  }

  Future<void> setApiBase(String url) async {
    var next = url.trim();
    if (next.endsWith('/')) next = next.substring(0, next.length - 1);
    api.baseUrl = next;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_apiKey, next);
    notifyListeners();
  }

  Future<void> logout({bool remote = true}) async {
    if (remote) {
      try {
        await api.logout();
      } catch (_) {}
    }
    _resetLive();
    api.token = null;
    user = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    await prefs.remove(_userKey);
    notifyListeners();
  }

  Future<void> _persist() async {
    final prefs = await SharedPreferences.getInstance();
    if (api.token != null) await prefs.setString(_tokenKey, api.token!);
    await prefs.setString(_apiKey, api.baseUrl);
    await _persistUser();
  }

  Future<void> _persistUser() async {
    if (user == null) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _userKey,
      jsonEncode({
        'id': user!.id,
        'role_id': user!.roleId,
        'role_name': user!.roleName,
        'email': user!.email,
        'first_name': user!.firstName,
        'last_name': user!.lastName,
        'status': user!.status,
        'phone': user!.phone,
        'avatar_url': user!.avatarUrl,
      }),
    );
  }
}
