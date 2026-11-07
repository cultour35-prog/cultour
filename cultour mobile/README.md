# CulTour Mobile (Flutter)

Native Android/iOS app for the CulTour Ormoc City tourism system. It uses the same MySQL data as the PHP website through `/cultour/api`.

## What it includes

**Tourist**
- Welcome, login (with linked-role picker), and tourist registration
- Home with featured Ormoc places
- Destinations and businesses
- City map (OpenStreetMap pins)
- Favorites, itineraries, and licensed-guide booking
- Bookings, ticket QR, and cancel
- Chat and notifications

**Tour guide**
- Incoming bookings (approve / decline / complete)
- Chat with tourists
- Profile

Admin and business accounts can sign in, then are pointed to the website.

## One-time setup

1. Install [Flutter](https://docs.flutter.dev/get-started/install/windows) and make sure `flutter` is on your PATH.
2. XAMPP Apache + MySQL running, CulTour reachable at `http://localhost/cultour`.
3. From this folder:

```powershell
cd "c:\xampp\htdocs\cultour\cultour mobile"
.\setup.ps1
flutter run
```

`setup.ps1` runs `flutter create`, installs packages, and allows cleartext HTTP so the app can talk to XAMPP.

## API URL

| Where the app runs | Default API |
| --- | --- |
| Android emulator | `http://10.0.2.2/cultour/api` |
| iOS simulator / Windows desktop | `http://127.0.0.1/cultour/api` |
| Physical phone | `http://YOUR_PC_LAN_IP/cultour/api` |

On a real phone, the PC and phone must be on the same Wi-Fi. Find the PC IP (`ipconfig`) and set it in **Profile → API server**.

Example: `http://192.168.1.10/cultour/api`

## Project layout

```
lib/
  main.dart
  api.dart          REST client
  session.dart      login token + user
  models.dart
  screens/          tourist + guide UI
```

## Backend notes

These PHP endpoints were added or adjusted for the app:

- `GET /api/auth/me.php`
- `POST /api/auth/logout.php`
- `GET /api/chat/thread.php` (Bearer token chat)
- `GET /api/businesses/detail.php`
- `POST /api/destinations/review.php`
- `GET /api/map/state.php` now accepts Bearer tokens
- Destination/business lists include coordinates and `cover_url`
- Tourist register accepts a simple `address` string (no region picker required)
