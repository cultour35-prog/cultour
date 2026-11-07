import 'dart:convert';
import 'dart:typed_data';

import 'package:cross_file/cross_file.dart';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import 'models.dart';
import 'util/jsonx.dart';

class CulTourApi {
  CulTourApi({required this.baseUrl, this.token});

  String baseUrl;
  String? token;

  static String suggestedBaseUrl() {
    if (kIsWeb) {
      final host = Uri.base.host;
      if (host.isEmpty || host == 'localhost' || host == '127.0.0.1') {
        return 'http://localhost/cultour/api';
      }
      final scheme = Uri.base.scheme == 'https' ? 'https' : 'http';
      return '$scheme://$host/cultour/api';
    }
    if (defaultTargetPlatform == TargetPlatform.android) {
      return 'http://10.0.2.2/cultour/api';
    }
    return 'http://127.0.0.1/cultour/api';
  }

  String get origin {
    var root = baseUrl.trim();
    if (root.endsWith('/')) root = root.substring(0, root.length - 1);
    if (root.endsWith('/api')) {
      return root.substring(0, root.length - 4);
    }
    return root;
  }

  static const _loopbackHosts = {'localhost', '127.0.0.1', '::1', '10.0.2.2'};

  String mediaUrl(String? path) {
    if (path == null) return '';
    var raw = path.trim().replaceAll('\\', '/');
    if (raw.isEmpty) return '';

    final originUri = Uri.parse(origin);

    if (raw.startsWith('http://') || raw.startsWith('https://')) {
      final uri = Uri.tryParse(raw);
      if (uri == null) return raw;
      final host = uri.host.toLowerCase();
      final p = uri.path.toLowerCase();
      final localAsset = p.contains('/uploads/') ||
          p.endsWith('/stop-checkin-photo.php') ||
          p.endsWith('/booking-fitness-cert.php');
      if (!_loopbackHosts.contains(host) && !localAsset) return raw;
      return Uri(
        scheme: originUri.scheme,
        host: originUri.host,
        port: originUri.hasPort ? originUri.port : null,
        path: uri.path,
        query: uri.hasQuery ? uri.query : null,
      ).toString();
    }

    var rel = raw.replaceFirst(RegExp(r'^/+'), '');
    rel = rel.replaceFirst(RegExp(r'^cultour/+', caseSensitive: false), '');
    rel = rel.replaceFirst(RegExp(r'^uploads/+', caseSensitive: false), '');
    return '$origin/uploads/$rel';
  }

  Uri _uri(String path, [Map<String, String>? query]) {
    var root = baseUrl.trim();
    if (root.endsWith('/')) root = root.substring(0, root.length - 1);
    final clean = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$root$clean').replace(queryParameters: query);
  }

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        if (token != null && token!.isNotEmpty) 'Authorization': 'Bearer $token',
      };

  /// For protected images (check-in photos, fitness certificates).
  Map<String, String> get authHeaders => {
        if (token != null && token!.isNotEmpty) 'Authorization': 'Bearer $token',
      };

  /// Load image bytes so photos work on phones and Flutter web (CanvasKit + auth).
  Future<Uint8List> getMediaBytes(String pathOrUrl, {bool auth = false}) async {
    final full = mediaUrl(pathOrUrl);
    if (full.isEmpty) {
      throw ApiException('Missing image.');
    }
    late http.Response res;
    try {
      res = await http
          .get(Uri.parse(full), headers: {
            'Accept': 'image/*,*/*',
            if (auth) ...authHeaders,
          })
          .timeout(const Duration(seconds: 25));
    } catch (_) {
      throw ApiException('Cannot load photo from $full.');
    }
    if (res.statusCode >= 400 || res.bodyBytes.isEmpty) {
      throw ApiException('Could not load photo (${res.statusCode}).', status: res.statusCode);
    }
    return res.bodyBytes;
  }

  Future<Map<String, dynamic>> getJson(String path, [Map<String, String>? query]) {
    return _send('GET', path, query: query);
  }

  Future<Map<String, dynamic>> postJson(String path, Map<String, dynamic> body) {
    return _send('POST', path, body: body);
  }

  /// Multipart POST; [files] maps field name to a local file path.
  /// On web, pass [fileBytes] so the photo is sent even when the path is a blob URL.
  Future<Map<String, dynamic>> multipart(
    String path,
    Map<String, dynamic> fields,
    Map<String, String> files, {
    Map<String, Uint8List>? fileBytes,
    Map<String, String>? fileNames,
  }) async {
    final req = http.MultipartRequest('POST', _uri(path));
    req.headers.addAll({'Accept': 'application/json', ...authHeaders});
    fields.forEach((k, v) {
      if (v == null) return;
      if (v is List) {
        for (var i = 0; i < v.length; i++) {
          req.fields['$k[$i]'] = '${v[i]}';
        }
      } else if (v is bool) {
        req.fields[k] = v ? '1' : '0';
      } else {
        req.fields[k] = '$v';
      }
    });
    final sent = <String>{};
    if (fileBytes != null) {
      for (final entry in fileBytes.entries) {
        if (entry.value.isEmpty) continue;
        final name = (fileNames?[entry.key] ?? '${entry.key}.jpg').trim();
        req.files.add(http.MultipartFile.fromBytes(
          entry.key,
          entry.value,
          filename: name.isEmpty ? '${entry.key}.jpg' : name,
        ));
        sent.add(entry.key);
      }
    }
    for (final entry in files.entries) {
      if (entry.value.isEmpty || sent.contains(entry.key)) continue;
      final x = XFile(entry.value);
      final bytes = await x.readAsBytes();
      if (bytes.isEmpty) continue;
      var name = x.name.trim();
      if (name.isEmpty || name.contains(':') || name.contains('\\') || name.contains('/')) {
        name = '${entry.key}.jpg';
      }
      req.files.add(http.MultipartFile.fromBytes(entry.key, bytes, filename: name));
    }
    late http.Response res;
    try {
      final streamed = await req.send().timeout(const Duration(seconds: 60));
      res = await http.Response.fromStream(streamed);
    } catch (e) {
      throw ApiException(
        'Cannot reach CulTour at $baseUrl. Check XAMPP and the API URL in Profile → Settings.',
      );
    }
    return _decode(res);
  }

  Map<String, dynamic> _decode(http.Response res) {
    Map<String, dynamic> data;
    try {
      data = asMap(jsonDecode(res.body));
    } catch (_) {
      throw ApiException('Unexpected response from the server (${res.statusCode}).', status: res.statusCode);
    }
    if (res.statusCode >= 400 || data['ok'] == false) {
      throw ApiException(
        asString(data['message'], 'Request failed (${res.statusCode}).'),
        status: res.statusCode,
        data: data,
      );
    }
    return data;
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Object? body,
  }) async {
    final uri = _uri(path, query);
    late http.Response res;
    try {
      switch (method) {
        case 'POST':
          res = await http
              .post(uri, headers: _headers, body: body == null ? null : jsonEncode(body))
              .timeout(const Duration(seconds: 25));
          break;
        default:
          res = await http.get(uri, headers: _headers).timeout(const Duration(seconds: 25));
      }
    } catch (e) {
      throw ApiException(
        'Cannot reach CulTour at $baseUrl. Check XAMPP and the API URL in Profile → Settings.',
      );
    }

    Map<String, dynamic> data;
    try {
      final decoded = jsonDecode(res.body);
      data = asMap(decoded);
    } catch (_) {
      throw ApiException('Unexpected response from the server (${res.statusCode}).', status: res.statusCode);
    }

    if (res.statusCode == 409 && asBool(data['need_role'])) {
      throw ApiException(
        asString(data['message'], 'Choose an account type.'),
        status: 409,
        needRole: true,
        roles: asList(data['roles']).map(LinkedRole.fromJson).toList(),
      );
    }

    if (res.statusCode >= 400 || data['ok'] == false) {
      throw ApiException(
        asString(data['message'], 'Request failed (${res.statusCode}).'),
        status: res.statusCode,
        data: data,
      );
    }
    return data;
  }

  Future<Map<String, dynamic>> login(String email, String password, {int? roleId}) {
    return _send('POST', '/auth/login.php', body: {
      'email': email,
      'password': password,
      if (roleId != null) 'role_id': roleId,
    });
  }

  Future<Map<String, dynamic>> registerTourist(Map<String, dynamic> payload) {
    return _send('POST', '/auth/register.php', body: {
      'role_id': 2,
      ...payload,
    });
  }

  Future<void> logout() async {
    try {
      await _send('POST', '/auth/logout.php');
    } catch (_) {}
  }

  Future<CtUser> me() async {
    final data = await _send('GET', '/auth/me.php');
    return CtUser.fromJson(asMap(data['user']));
  }

  Future<Map<String, dynamic>> forgotPassword({
    required String action,
    required String email,
    String? code,
    String? password,
    String? passwordConfirm,
  }) {
    return _send('POST', '/auth/forgot.php', body: {
      'action': action,
      'email': email,
      if (code != null) 'code': code,
      if (password != null) 'password': password,
      if (passwordConfirm != null) 'password_confirm': passwordConfirm,
    });
  }

  Future<Map<String, dynamic>> changeEmail({required String newEmail, required String password}) {
    return _send('POST', '/auth/change_email.php', body: {
      'new_email': newEmail,
      'password': password,
    });
  }

  Future<Map<String, dynamic>> linkedAccounts() {
    return _send('GET', '/auth/linked.php');
  }

  Future<Map<String, dynamic>> switchLinkedAccount(int userId) {
    return _send('POST', '/auth/switch.php', body: {'user_id': userId});
  }

  Future<Map<String, dynamic>> linkRole({
    required String password,
    String? email,
    int? targetRoleId,
    int? yearsExperience,
    String? languages,
  }) {
    return _send('POST', '/auth/link_role.php', body: {
      'password': password,
      if (email != null && email.isNotEmpty) 'email': email,
      if (targetRoleId != null) 'target_role_id': targetRoleId,
      if (yearsExperience != null) 'years_experience': yearsExperience,
      if (languages != null && languages.isNotEmpty) 'languages': languages,
    });
  }

  Future<List<Place>> destinations({String q = '', int categoryId = 0}) async {
    final query = <String, String>{};
    if (q.isNotEmpty) query['q'] = q;
    if (categoryId > 0) query['category_id'] = '$categoryId';
    final data = await _send('GET', '/destinations/list.php', query: query.isEmpty ? null : query);
    return asList(data['data']).map((e) => Place.fromJson(asMap(e))).toList();
  }

  Future<Map<String, dynamic>> destinationDetail(int id) {
    return _send('GET', '/destinations/detail.php', query: {'id': '$id'});
  }

  Future<void> reviewDestination(int id, int rating, String comment) async {
    await _send('POST', '/destinations/review.php', body: {
      'destination_id': id,
      'rating': rating,
      'comment': comment,
    });
  }

  Future<List<Business>> businesses({String? from, String? to}) async {
    final data = await _send('GET', '/businesses/list.php', query: {
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
    });
    return asList(data['data']).map((e) => Business.fromJson(asMap(e))).toList();
  }

  Future<Business> businessDetail(int id) async {
    final data = await _send('GET', '/businesses/detail.php', query: {'id': '$id'});
    return Business.fromJson(asMap(data['data']));
  }

  Future<List<Place>> favorites() async {
    final data = await _send('GET', '/favorites/list.php');
    return asList(data['data']).map((e) => Place.fromJson(asMap(e))).toList();
  }

  Future<bool> toggleFavorite(int destinationId) async {
    final data = await _send('POST', '/favorites/toggle.php', body: {'destination_id': destinationId});
    return asBool(data['favorited']);
  }

  Future<bool> toggleBusinessFavorite(int businessId) async {
    final data = await _send('POST', '/favorites/toggle.php', body: {'business_id': businessId});
    return asBool(data['favorited']);
  }

  Future<List<Guide>> guides({String? date, String? startTime, int? itineraryId}) async {
    final query = <String, String>{};
    if (date != null && date.isNotEmpty) query['booking_date'] = date;
    if (startTime != null && startTime.isNotEmpty) query['start_time'] = startTime;
    if (itineraryId != null && itineraryId > 0) query['itinerary_id'] = '$itineraryId';
    final data = await _send('GET', '/guides/list.php', query: query.isEmpty ? null : query);
    return asList(data['data']).map((e) => Guide.fromJson(asMap(e))).toList();
  }

  Future<Map<String, dynamic>> guideAvailability({
    required int guideId,
    required String date,
    required String startTime,
    int? itineraryId,
  }) {
    return _send('POST', '/guides/availability.php', body: {
      'guide_id': guideId,
      'booking_date': date,
      'start_time': startTime,
      if (itineraryId != null) 'itinerary_id': itineraryId,
    });
  }

  Future<Map<String, dynamic>> createBooking({
    required int guideId,
    required String date,
    required String startTime,
    required int partySize,
    int? itineraryId,
    int? destinationId,
    String? notes,
    int? accommodationBusinessId,
    int? accommodationServiceId,
    bool accommodationDeclined = false,
    int? transportationBusinessId,
    int? transportationServiceId,
    bool transportationDeclined = false,
    List<Map<String, dynamic>>? accommodationLines,
    List<Map<String, dynamic>>? transportationLines,
    bool isPwd = false,
    String pwdNotes = '',
    String? certificatePath,
  }) {
    final body = <String, dynamic>{
      'guide_id': guideId,
      'booking_date': date,
      'start_time': startTime,
      'party_size': partySize,
      if (itineraryId != null) 'itinerary_id': itineraryId,
      if (destinationId != null) 'destination_id': destinationId,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
      if (itineraryId != null) 'accommodation_business_id': accommodationBusinessId ?? 0,
      if (itineraryId != null) 'accommodation_service_id': accommodationServiceId ?? 0,
      if (itineraryId != null) 'accommodation_declined': accommodationDeclined ? 1 : 0,
      if (itineraryId != null) 'transportation_business_id': transportationBusinessId ?? 0,
      if (itineraryId != null) 'transportation_service_id': transportationServiceId ?? 0,
      if (itineraryId != null) 'transportation_declined': transportationDeclined ? 1 : 0,
      if (itineraryId != null && accommodationLines != null) 'accommodation_lines': accommodationLines,
      if (itineraryId != null && transportationLines != null) 'transportation_lines': transportationLines,
      if (isPwd) 'is_pwd': 1,
      if (isPwd && pwdNotes.isNotEmpty) 'pwd_notes': pwdNotes,
    };
    if (isPwd && certificatePath != null && certificatePath.isNotEmpty) {
      return multipart('/bookings/create.php', body, {'fitness_certificate': certificatePath});
    }
    return _send('POST', '/bookings/create.php', body: body);
  }

  Future<Map<String, dynamic>> quoteBooking({
    required int guideId,
    required String date,
    required String startTime,
    required int partySize,
    int? itineraryId,
    int? destinationId,
    int? accommodationBusinessId,
    int? accommodationServiceId,
    bool accommodationDeclined = false,
    int? transportationBusinessId,
    int? transportationServiceId,
    bool transportationDeclined = false,
    List<Map<String, dynamic>>? accommodationLines,
    List<Map<String, dynamic>>? transportationLines,
  }) {
    return _send('POST', '/bookings/create.php', body: {
      'action': 'quote',
      'guide_id': guideId,
      'booking_date': date,
      'start_time': startTime,
      'party_size': partySize,
      if (itineraryId != null) 'itinerary_id': itineraryId,
      if (destinationId != null) 'destination_id': destinationId,
      if (itineraryId != null) 'accommodation_business_id': accommodationBusinessId ?? 0,
      if (itineraryId != null) 'accommodation_service_id': accommodationServiceId ?? 0,
      if (itineraryId != null) 'accommodation_declined': accommodationDeclined ? 1 : 0,
      if (itineraryId != null) 'transportation_business_id': transportationBusinessId ?? 0,
      if (itineraryId != null) 'transportation_service_id': transportationServiceId ?? 0,
      if (itineraryId != null) 'transportation_declined': transportationDeclined ? 1 : 0,
      if (itineraryId != null && accommodationLines != null) 'accommodation_lines': accommodationLines,
      if (itineraryId != null && transportationLines != null) 'transportation_lines': transportationLines,
    });
  }

  Future<List<Booking>> myBookings() async {
    final data = await _send('GET', '/bookings/my.php');
    return asList(data['data']).map((e) => Booking.fromJson(asMap(e))).toList();
  }

  Future<Map<String, dynamic>> updateBooking(
    int id,
    String action, {
    Map<String, dynamic> extra = const {},
    String? filePath,
    String fileField = 'refund_qr',
  }) {
    final body = {'booking_id': id, 'action': action, ...extra};
    if (filePath != null && filePath.isNotEmpty) {
      return multipart('/bookings/update.php', body, {fileField: filePath});
    }
    return _send('POST', '/bookings/update.php', body: body);
  }

  Future<Map<String, dynamic>> bookingQr(int id) {
    return _send('GET', '/qr/generate.php', query: {'booking_id': '$id'});
  }

  Future<List<Itinerary>> itineraries() async {
    final data = await _send('GET', '/itineraries/list.php');
    return asList(data['data']).map((e) => Itinerary.fromJson(asMap(e))).toList();
  }

  Future<Itinerary> itineraryDetail(int id) async {
    final data = await _send('GET', '/itineraries/detail.php', query: {'id': '$id'});
    return Itinerary.fromJson(asMap(data['data']));
  }

  Future<Itinerary> saveItinerary(Map<String, dynamic> body) async {
    final data = await _send('POST', '/itineraries/save.php', body: body);
    if (body['action'] == 'delete') {
      return Itinerary(id: 0, title: '');
    }
    return Itinerary.fromJson(asMap(data['data']));
  }

  Future<Map<String, dynamic>> mapState() {
    return _send('GET', '/map/state.php');
  }

  Future<Map<String, dynamic>> liveSync({String since = ''}) {
    return _send('GET', '/sync.php', query: since.isEmpty ? null : {'since': since});
  }

  Future<({List<AnnouncementBanner> banners, List<AnnouncementCard> cards})> announcements() async {
    final data = await _send('GET', '/announcements/list.php');
    return (
      banners: asList(data['banners']).map((e) => AnnouncementBanner.fromJson(asMap(e))).toList(),
      cards: asList(data['cards']).map((e) => AnnouncementCard.fromJson(asMap(e))).toList(),
    );
  }

  Future<List<Notice>> notifications() async {
    final data = await _send('GET', '/notifications/list.php');
    return asList(data['data']).map((e) => Notice.fromJson(asMap(e))).toList();
  }

  Future<void> markNotificationsRead({bool all = false, List<int>? ids}) async {
    await _send('POST', '/notifications/read.php', body: {
      if (all) 'all': true,
      if (ids != null) 'ids': ids,
    });
  }

  Future<Map<String, dynamic>> chatThread({int withUserId = 0, int after = 0}) {
    final query = <String, String>{};
    if (withUserId > 0) query['with'] = '$withUserId';
    if (after > 0) query['after'] = '$after';
    return _send('GET', '/chat/thread.php', query: query.isEmpty ? null : query);
  }

  Future<void> sendChat(int receiverId, String body, {String? imagePath}) async {
    if (imagePath != null && imagePath.isNotEmpty) {
      await multipart('/chat/send.php', {'receiver_id': receiverId, 'body': body}, {'image': imagePath});
      return;
    }
    await _send('POST', '/chat/send.php', body: {
      'receiver_id': receiverId,
      'body': body,
    });
  }

  Future<void> reportContent({
    required String targetType,
    required int targetId,
    required String reason,
    String details = '',
  }) async {
    await _send('POST', '/reports/submit.php', body: {
      'target_type': targetType,
      'target_id': targetId,
      'reason': reason,
      'details': details,
    });
  }
}
