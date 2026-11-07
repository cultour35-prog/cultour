import 'util/jsonx.dart';

class CtUser {
  final int id;
  final int roleId;
  final String roleName;
  final String email;
  final String firstName;
  final String lastName;
  final String status;
  final String? phone;
  final String? avatarUrl;

  const CtUser({
    required this.id,
    required this.roleId,
    required this.roleName,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.status,
    this.phone,
    this.avatarUrl,
  });

  String get fullName => '$firstName $lastName'.trim();
  String get initials {
    final a = firstName.isNotEmpty ? firstName[0] : '';
    final b = lastName.isNotEmpty ? lastName[0] : '';
    final s = '$a$b'.toUpperCase();
    return s.isEmpty ? '?' : s;
  }

  bool get isTourist => roleId == 2;
  bool get isGuide => roleId == 3;
  bool get isAdmin => roleId == 1;
  bool get isBusiness => roleId == 4;
  bool get isSuspended => status == 'suspended';

  factory CtUser.fromJson(Map<String, dynamic> json) {
    return CtUser(
      id: asInt(json['id']),
      roleId: asInt(json['role_id']),
      roleName: asString(json['role_name'], 'Account'),
      email: asString(json['email']),
      firstName: asString(json['first_name']),
      lastName: asString(json['last_name']),
      status: asString(json['status'], 'active'),
      phone: json['phone']?.toString(),
      avatarUrl: json['avatar_url']?.toString() ?? json['avatar']?.toString(),
    );
  }
}

class LinkedRole {
  final int roleId;
  final String label;
  LinkedRole({required this.roleId, required this.label});

  factory LinkedRole.fromJson(dynamic raw) {
    final m = asMap(raw);
    return LinkedRole(
      roleId: asInt(m['role_id'] ?? m['id']),
      label: asString(m['label'] ?? m['name'] ?? m['role_name'], 'Account'),
    );
  }
}

class Place {
  final int id;
  final String name;
  final String? location;
  final String? description;
  final String? categoryName;
  final String? coverUrl;
  final String? coverImage;
  final double avgRating;
  final int reviewCount;
  final bool featured;
  final double? lat;
  final double? lng;
  final String? openingHours;
  final String? entranceFee;
  final int categoryId;

  const Place({
    required this.id,
    required this.name,
    this.location,
    this.description,
    this.categoryName,
    this.coverUrl,
    this.coverImage,
    this.avgRating = 0,
    this.reviewCount = 0,
    this.featured = false,
    this.lat,
    this.lng,
    this.openingHours,
    this.entranceFee,
    this.categoryId = 0,
  });

  factory Place.fromJson(Map<String, dynamic> json) {
    return Place(
      id: asInt(json['id']),
      name: asString(json['name']),
      location: json['location']?.toString(),
      description: json['description']?.toString(),
      categoryName: json['category_name']?.toString(),
      coverUrl: json['cover_url']?.toString(),
      coverImage: json['cover_image']?.toString(),
      avgRating: asDouble(json['avg_rating']),
      reviewCount: asInt(json['review_count']),
      featured: asBool(json['is_featured']),
      lat: asDoubleOrNull(json['latitude'] ?? json['lat']),
      lng: asDoubleOrNull(json['longitude'] ?? json['lng']),
      openingHours: json['opening_hours']?.toString(),
      entranceFee: json['entrance_fee']?.toString(),
      categoryId: asInt(json['category_id']),
    );
  }
}

class PlaceReview {
  final int rating;
  final String comment;
  final String createdAt;
  final String firstName;
  final String lastName;

  PlaceReview({
    required this.rating,
    required this.comment,
    required this.createdAt,
    required this.firstName,
    required this.lastName,
  });

  factory PlaceReview.fromJson(Map<String, dynamic> json) {
    return PlaceReview(
      rating: asInt(json['rating']),
      comment: asString(json['comment']),
      createdAt: asString(json['created_at']),
      firstName: asString(json['first_name']),
      lastName: asString(json['last_name']),
    );
  }
}

class BusinessOffering {
  final int id;
  final String name;
  final String description;
  final String priceLabel;
  final double price;
  final double extraGuestFee;
  final int? extraGuestLimit;
  final int capacity;
  final int? unitCount;
  final int? available;
  final String amenities;
  final String imageUrl;

  const BusinessOffering({
    required this.id,
    required this.name,
    this.description = '',
    this.priceLabel = '',
    this.price = 0,
    this.extraGuestFee = 0,
    this.extraGuestLimit,
    this.capacity = 0,
    this.unitCount,
    this.available,
    this.amenities = '',
    this.imageUrl = '',
  });

  factory BusinessOffering.fromJson(Map<String, dynamic> json) {
    return BusinessOffering(
      id: asInt(json['id']),
      name: asString(json['name']),
      description: asString(json['description']),
      priceLabel: asString(json['price_label']),
      price: asDouble(json['price']),
      extraGuestFee: asDouble(json['extra_guest_fee']),
      extraGuestLimit: json['extra_guest_limit'] == null ? null : asInt(json['extra_guest_limit']),
      capacity: asInt(json['capacity']),
      unitCount: json['unit_count'] == null ? null : asInt(json['unit_count']),
      available: json['available'] == null ? null : asInt(json['available']),
      amenities: asString(json['amenities']),
      imageUrl: asString(json['image_url']),
    );
  }
}

class Business {
  final int id;
  final String name;
  final String? typeName;
  final String? description;
  final String? address;
  final String? phone;
  final String? email;
  final String? openingHours;
  final String? coverUrl;
  final String? coverImage;
  final double? lat;
  final double? lng;
  final List<BusinessOffering> offerings;

  const Business({
    required this.id,
    required this.name,
    this.typeName,
    this.description,
    this.address,
    this.phone,
    this.email,
    this.openingHours,
    this.coverUrl,
    this.coverImage,
    this.lat,
    this.lng,
    this.offerings = const [],
  });

  factory Business.fromJson(Map<String, dynamic> json) {
    return Business(
      id: asInt(json['id']),
      name: asString(json['name']),
      typeName: json['type_name']?.toString(),
      description: json['description']?.toString(),
      address: json['address']?.toString(),
      phone: json['phone']?.toString(),
      email: json['email']?.toString(),
      openingHours: json['opening_hours']?.toString(),
      coverUrl: json['cover_url']?.toString(),
      coverImage: json['cover_image']?.toString(),
      lat: asDoubleOrNull(json['latitude'] ?? json['lat']),
      lng: asDoubleOrNull(json['longitude'] ?? json['lng']),
      offerings: asList(json['offerings']).map((e) => BusinessOffering.fromJson(asMap(e))).toList(),
    );
  }
}

class Guide {
  final int id;
  final int userId;
  final String fullName;
  final String? bio;
  final String? languages;
  final String? specialties;
  final int yearsExperience;
  final double avgRating;
  final int reviewCount;
  final String? hourlyRate;
  final String? avatarUrl;
  final bool available;
  final String? hint;

  const Guide({
    required this.id,
    required this.userId,
    required this.fullName,
    this.bio,
    this.languages,
    this.specialties,
    this.yearsExperience = 0,
    this.avgRating = 0,
    this.reviewCount = 0,
    this.hourlyRate,
    this.avatarUrl,
    this.available = true,
    this.hint,
  });

  factory Guide.fromJson(Map<String, dynamic> json) {
    final name = asString(json['full_name']);
    return Guide(
      id: asInt(json['id']),
      userId: asInt(json['user_id']),
      fullName: name.isNotEmpty ? name : '${asString(json['first_name'])} ${asString(json['last_name'])}'.trim(),
      bio: json['bio']?.toString(),
      languages: json['languages']?.toString(),
      specialties: json['specialties']?.toString(),
      yearsExperience: asInt(json['years_experience']),
      avgRating: asDouble(json['avg_rating']),
      reviewCount: asInt(json['review_count']),
      hourlyRate: json['hourly_rate']?.toString(),
      avatarUrl: json['avatar_url']?.toString() ?? json['avatar']?.toString(),
      available: json.containsKey('available') ? asBool(json['available'], true) : true,
      hint: json['hint']?.toString(),
    );
  }
}

class Booking {
  final int id;
  final String code;
  final String status;
  final String paymentStatus;
  final String date;
  final String startTime;
  final int partySize;
  final String? notes;
  final String? guideName;
  final String? touristName;
  final int? guideUserId;
  final int? itineraryId;
  final int? destinationId;
  final String? destinationName;
  final String? itineraryTitle;
  final bool rated;
  final bool needsReplacement;
  final bool isPwd;
  final String? pwdNotes;
  final String? createdAt;
  final String? websitePayUrl;
  final String? fitnessCertificateUrl;

  const Booking({
    required this.id,
    required this.code,
    required this.status,
    required this.paymentStatus,
    required this.date,
    required this.startTime,
    required this.partySize,
    this.notes,
    this.guideName,
    this.touristName,
    this.guideUserId,
    this.itineraryId,
    this.destinationId,
    this.destinationName,
    this.itineraryTitle,
    this.rated = false,
    this.needsReplacement = false,
    this.isPwd = false,
    this.pwdNotes,
    this.createdAt,
    this.websitePayUrl,
    this.fitnessCertificateUrl,
  });

  factory Booking.fromJson(Map<String, dynamic> json) {
    return Booking(
      id: asInt(json['id']),
      code: asString(json['booking_code']),
      status: asString(json['status']),
      paymentStatus: asString(json['payment_status'], 'unpaid'),
      date: asString(json['booking_date']),
      startTime: asString(json['start_time']),
      partySize: asInt(json['party_size'], 1),
      notes: json['notes']?.toString(),
      guideName: json['guide_name']?.toString(),
      touristName: json['tourist_name']?.toString(),
      guideUserId: asInt(json['guide_user_id']) == 0 ? null : asInt(json['guide_user_id']),
      itineraryId: asInt(json['itinerary_id']) == 0 ? null : asInt(json['itinerary_id']),
      destinationId: asInt(json['destination_id']) == 0 ? null : asInt(json['destination_id']),
      destinationName: json['destination_name']?.toString(),
      itineraryTitle: json['itinerary_title']?.toString(),
      rated: asBool(json['rated']),
      needsReplacement: asBool(json['needs_replacement']),
      isPwd: asBool(json['is_pwd']),
      pwdNotes: json['pwd_notes']?.toString(),
      createdAt: json['created_at']?.toString(),
      websitePayUrl: json['website_pay_url']?.toString(),
      fitnessCertificateUrl: json['fitness_certificate_url']?.toString(),
    );
  }
}

class ItineraryStop {
  final int id;
  final String name;
  final String? location;
  final String kind;
  final String? coverUrl;
  final double? lat;
  final double? lng;
  final int visitMinutes;
  final int travelMinutes;
  final String? startTime;
  final String? endTime;
  final int? destinationId;
  final int? businessId;

  ItineraryStop({
    required this.id,
    required this.name,
    this.location,
    this.kind = 'destination',
    this.coverUrl,
    this.lat,
    this.lng,
    this.visitMinutes = 60,
    this.travelMinutes = 0,
    this.startTime,
    this.endTime,
    this.destinationId,
    this.businessId,
  });

  factory ItineraryStop.fromJson(Map<String, dynamic> json) {
    final destId = asInt(json['destination_id']);
    final bizId = asInt(json['business_id']);
    return ItineraryStop(
      id: asInt(json['id']),
      name: asString(json['stop_name'] ?? json['destination_name'] ?? json['name'], 'Stop'),
      location: (json['stop_location'] ?? json['location'])?.toString(),
      kind: asString(json['stop_kind'], destId > 0 || bizId == 0 ? 'destination' : 'business'),
      coverUrl: json['cover_url']?.toString(),
      lat: asDoubleOrNull(json['latitude']),
      lng: asDoubleOrNull(json['longitude']),
      visitMinutes: asInt(json['visit_minutes'], 60),
      travelMinutes: asInt(json['travel_minutes']),
      startTime: json['start_time']?.toString(),
      endTime: json['end_time']?.toString(),
      destinationId: destId == 0 ? null : destId,
      businessId: bizId == 0 ? null : bizId,
    );
  }
}

class Itinerary {
  final int id;
  final String title;
  final String? notes;
  final bool suggested;
  final List<ItineraryStop> stops;
  final String? createdAt;
  final String? startDate;
  final String? meetupTime;
  final String? status;
  final int dayCount;
  final bool locked;
  final bool draft;

  Itinerary({
    required this.id,
    required this.title,
    this.notes,
    this.suggested = false,
    this.stops = const [],
    this.createdAt,
    this.startDate,
    this.meetupTime,
    this.status,
    this.dayCount = 1,
    this.locked = false,
    this.draft = false,
  });

  factory Itinerary.fromJson(Map<String, dynamic> json) {
    return Itinerary(
      id: asInt(json['id']),
      title: asString(json['title'], 'Itinerary'),
      notes: json['notes']?.toString() ?? json['description']?.toString(),
      suggested: asBool(json['is_suggested']),
      stops: asList(json['stops']).map((e) => ItineraryStop.fromJson(asMap(e))).toList(),
      createdAt: json['created_at']?.toString(),
      startDate: json['start_date']?.toString(),
      meetupTime: json['meetup_label']?.toString() ?? json['meetup_time']?.toString(),
      status: json['status']?.toString(),
      dayCount: asInt(json['day_count'], 1),
      locked: asBool(json['locked']),
      draft: asBool(json['is_draft']),
    );
  }
}

class MapPin {
  final int id;
  final String title;
  final String subtitle;
  final String kind;
  final String color;
  final double lat;
  final double lng;
  final String group;

  MapPin({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.kind,
    required this.color,
    required this.lat,
    required this.lng,
    required this.group,
  });

  factory MapPin.fromJson(Map<String, dynamic> json) {
    return MapPin(
      id: asInt(json['id']),
      title: asString(json['title'] ?? json['name']),
      subtitle: asString(json['subtitle']),
      kind: asString(json['kind'], 'destination'),
      color: asString(json['color'], '#0e8a7a'),
      lat: asDouble(json['lat'] ?? json['latitude']),
      lng: asDouble(json['lng'] ?? json['longitude']),
      group: asString(json['group']),
    );
  }
}

class AnnouncementBanner {
  final int id;
  final String title;
  final String? bannerUrl;
  final String? bannerImage;

  const AnnouncementBanner({
    required this.id,
    required this.title,
    this.bannerUrl,
    this.bannerImage,
  });

  factory AnnouncementBanner.fromJson(Map<String, dynamic> json) {
    return AnnouncementBanner(
      id: asInt(json['id']),
      title: asString(json['title']),
      bannerUrl: json['banner_url']?.toString(),
      bannerImage: json['banner_image']?.toString(),
    );
  }
}

class AnnouncementCard {
  final int id;
  final String title;
  final String body;
  final String publishedAt;
  final String publishedLabel;

  const AnnouncementCard({
    required this.id,
    required this.title,
    required this.body,
    this.publishedAt = '',
    this.publishedLabel = '',
  });

  factory AnnouncementCard.fromJson(Map<String, dynamic> json) {
    return AnnouncementCard(
      id: asInt(json['id']),
      title: asString(json['title']),
      body: asString(json['body']),
      publishedAt: asString(json['published_at']),
      publishedLabel: asString(json['published_label']),
    );
  }
}

class Notice {
  final int id;
  final String type;
  final String title;
  final String body;
  final String? link;
  final bool isRead;
  final String createdAt;

  Notice({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    this.link,
    required this.isRead,
    required this.createdAt,
  });

  factory Notice.fromJson(Map<String, dynamic> json) {
    return Notice(
      id: asInt(json['id']),
      type: asString(json['type']),
      title: asString(json['title']),
      body: asString(json['body']),
      link: json['link']?.toString(),
      isRead: asBool(json['is_read']),
      createdAt: asString(json['created_at']),
    );
  }
}

class ChatPeer {
  final int id;
  final String name;
  final String? avatar;
  final int unread;

  ChatPeer({required this.id, required this.name, this.avatar, this.unread = 0});

  factory ChatPeer.fromJson(Map<String, dynamic> json) {
    return ChatPeer(
      id: asInt(json['id']),
      name: asString(json['name']),
      avatar: json['avatar']?.toString(),
      unread: asInt(json['unread']),
    );
  }
}

class ChatMessage {
  final int id;
  final int senderId;
  final bool mine;
  final String body;
  final String? imagePath;
  final String createdAt;

  ChatMessage({
    required this.id,
    required this.senderId,
    required this.mine,
    required this.body,
    this.imagePath,
    required this.createdAt,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    return ChatMessage(
      id: asInt(json['id']),
      senderId: asInt(json['sender_id']),
      mine: asBool(json['mine']),
      body: asString(json['body']),
      imagePath: json['image_path']?.toString(),
      createdAt: asString(json['created_at']),
    );
  }
}

class ApiException implements Exception {
  final String message;
  final int status;
  final bool needRole;
  final List<LinkedRole> roles;
  final Map<String, dynamic> data;
  ApiException(
    this.message, {
    this.status = 400,
    this.needRole = false,
    this.roles = const [],
    this.data = const {},
  });
  @override
  String toString() => message;
}
