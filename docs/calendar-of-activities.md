# Calendar of Activities

**System:** CulTour — Web and Mobile Tourism Management System for the Ormoc City Tourism Office  
**Platform:** PHP/MySQL on XAMPP (`http://localhost/cultour`) and Flutter (`cultour mobile`) sharing `/cultour/api`  
**Period:** 1 June 2026 – 30 September 2026  
**Stack:** Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32, Flutter (Android/iOS/web)

This is the **project** calendar (what was built, in which week). It is not the in-app business visit calendar or the guide availability calendar.

Roles in the finished system: **Admin (1), Tourist (2), Tour Guide (3), Business (4).**

---

## Gantt chart (work packages)

| No. | Activity | Jun | Jul | Aug | Sep |
|---:|---|:---:|:---:|:---:|:---:|
| 1 | Planning, Tourism Office interviews, four-role requirements | X | | | |
| 2 | ERD, `cultour_db`, auth, roles, email verification, linked accounts | X | X | | |
| 3 | Tourist: destinations, categories, map, favorites, reviews | X | | | |
| 4 | Tourist: itinerary editor, auto-generate, drafts | X | X | | |
| 5 | Tourist: book guide, GCash QR / office pay, HMAC QR e-ticket | | X | | |
| 6 | Guide: application, weekly hours, calendar blocks, suggested tours | | X | | |
| 7 | Guide: accept/decline, schedule, meetup/stop check-in, photos, end tour | | X | X | |
| 8 | Business: application, permits, profile, pin, services, hours, photos | | X | X | |
| 9 | Business: inquiries, visit calendar, visit report | | | X | |
| 10 | Admin: applications, destinations, bookings, payments | | X | X | |
| 11 | Admin: refunds, replacement guide, announcements, reports, suspensions | | | X | X |
| 12 | Chat, notifications, live unread badges | | | X | X |
| 13 | Flutter tourist (home, explore, map, itinerary, book, pay, ticket) | | | X | X |
| 14 | Flutter guide (bookings, schedule, availability, check-in map + QR) | | | | X |
| 15 | Tester feedback, form validation, UI tokens, hardening | | | | X |
| 16 | Design model, tester response, this calendar, defense rehearsal | | | | X |

---

## Weekly schedule

### June 2026 — planning and tourist core

| Week | Dates | Specific work | Deliverable in the system |
|---|---|---|---|
| 1 | 1–7 Jun | Problem statement; Ormoc City Tourism Office as client; four roles; list of pages per role; tools (XAMPP, Git, Flutter later) | Written requirements (tourist book → pay → ticket; guide apply → hours → check-in; business list → calendar; admin approve → refund) |
| 2 | 8–14 Jun | Use cases and process flows; ERD (users, roles, destinations, itineraries, bookings, payments); screen list | Draft design; same flows later drawn in [`design-model.md`](design-model.md) |
| 3 | 15–21 Jun | Create `cultour_db`; `users`, `roles`; `register.php` / `login.php`; email verify; password reset; role dashboards | Working sign-up and login; Admin / Tourist / Guide / Business land on the correct shell |
| 4 | 22–28 Jun | Destination CRUD and categories (`admin/destinations.php`, `admin/categories.php`); public/tourist browse; Leaflet/OSM map; favorites; reviews | Tourist can open destinations, pin them on `/map.php`, save favorites, post a review |

### July 2026 — booking, guide, and admin core

| Week | Dates | Specific work | Deliverable in the system |
|---|---|---|---|
| 5 | 29 Jun–5 Jul | Itinerary builder: add stops, auto-generate, save draft vs discard; `tourist/itineraries.php` | Tourist can plan a multi-stop day and keep it in Drafts |
| 6 | 6–12 Jul | Licensed-guide booking; date vs guide weekly hours; `tourist/pay.php` and `tourist/pay-scan.php` (GCash QR + reference); pay-at-office path; booking codes (`CTxxxxxxxx`) | Paid (or office-pending) booking waiting for the guide |
| 7 | 13–19 Jul | Guide application (`tourguide` apply + `admin/guides.php` approve/reject); weekly hours; `tourguide/availability.php` and `guide_calendar_blocks`; suggested tours (`tourguide/itineraries.php`) | Approved guide can publish hours and suggested tours |
| 8 | 20–26 Jul | Guide accept/decline (`tourguide/bookings.php`); HMAC QR e-ticket URL; tourist ticket view; in-app chat (`tourist/chat.php`, `tourguide/chat.php`) | After accept, tourist has a QR ticket; chat thread exists |
| 9 | 27 Jul–2 Aug | Check-in (`tourguide/checkin.php`): route map, meetup QR/photo, per-stop photo, “Start this stop”, end-of-day, mark complete; tourist rating (`tourguide/ratings.php`) | One tour day can be walked stop-by-stop with photos |

### August 2026 — business, refunds, mobile start

| Week | Dates | Specific work | Deliverable in the system |
|---|---|---|---|
| 10 | 3–9 Aug | Business application + permits; `admin/businesses.php`; profile, map pin, services, hours, photos (`business/profile.php`, `services.php`, `hours.php`, `photos.php`) | Listed business appears on Explore and the map |
| 11 | 10–16 Aug | Tourist inquiry (`tourist/business.php`); `business/inquiries.php` reply; visit calendar and visit report (`business/dashboard.php?view=calendar` / `report`); `admin/bookings.php` payment verify | Business sees which booked tours include them; admin can verify payment |
| 12 | 17–23 Aug | Guide decline/cancel of a **paid** booking → refund `needs_details`; tourist GCash number/QR (`tourist/refund-details.php`); admin refund queue (`admin/refunds.php`: remind, mark sent, reference); replacement guide when assigned guide cannot continue | Refund states: `needs_details` → `requested` → `sent`; tourist notified `refund_sent` |
| 13 | 24–30 Aug | Flutter project `cultour mobile`; API bootstrap + token login; welcome, login, register, linked-role picker; tourist home, explore, destination/business detail, map | App talks to `http://localhost/cultour/api` (or `10.0.2.2` on Android emulator) |

### September 2026 — mobile finish, test, defend

| Week | Dates | Specific work | Deliverable in the system |
|---|---|---|---|
| 14 | 31 Aug–6 Sep | Flutter itineraries, book guide, GCash pay, bookings, ticket QR, cancel + refund fields, chat, notifications | Tourist can complete a booking on the phone |
| 15 | 7–13 Sep | Flutter guide home, bookings (accept/decline/complete), schedule, availability, check-in **map + full-screen QR** (aligned with web); replacement popup | Guide can run the tour day on mobile the same way as `/tourguide/checkin.php` |
| 16 | 14–20 Sep | Notifications bell + `/api/chat/unread.php` live poll; announcements; content reports (`admin/content-reports.php`); suspensions and appeals (`admin/appeals.php`); activity logs (`admin/logs.php`) | Unread badges; admin can suspend, handle appeals, and post announcements |
| 17 | 21–27 Sep | Software-tester pass; live field validation (`assets/js/app.js`); UI tokens in `assets/css/style.css`; XSS via `e()`; measurements **26 September 2026** | [`tester-feedback-response.md`](tester-feedback-response.md) |
| 18 | 28–30 Sep | Freeze docs; demo script (register → book → pay → accept → scan → check-in → refund); oral defense | [`design-model.md`](design-model.md), this calendar, live localhost demo |

---

## What each week actually produced (by module)

**Tourist web** — `tourist/dashboard.php`, `destinations.php`, `destination.php`, `businesses.php`, `business.php`, `guides.php`, `itineraries.php`, `favorites.php`, `bookings.php`, `pay.php`, `pay-scan.php`, `refund-details.php`, `chat.php`, `profile.php`, plus `/map.php`.

**Guide web** — `tourguide/dashboard.php`, `bookings.php`, `schedule.php`, `availability.php`, `itineraries.php`, `checkin.php`, `ratings.php`, `chat.php`, `profile.php`.

**Business web** — `business/dashboard.php` (calendar + report), `profile.php`, `services.php`, `hours.php`, `photos.php`, `inquiries.php`, `chat.php`.

**Admin web** — `admin/dashboard.php`, `guides.php`, `businesses.php`, `tourists.php`, `users.php`, `destinations.php`, `categories.php`, `bookings.php`, `refunds.php`, `announcements.php`, `content-reports.php`, `appeals.php`, `reports.php`, `logs.php`.

**Mobile** — tourist screens under `cultour mobile/lib/screens/`; guide screens under `cultour mobile/lib/screens/guide/`; shared API in `cultour mobile/lib/api.dart`.

**Payment (demo, not live GCash API)** — tourist scans office QR, types reference; admin verifies; refunds are recorded in the refund queue and marked sent with a reference such as `RF-xxxxxxxx`.

---

## Milestone dates

| Date | Milestone |
|---|---|
| 7 June 2026 | Requirements closed (four roles, booking + tour-day + refund scope) |
| 21 June 2026 | Auth and `cultour_db` running on XAMPP |
| 28 June 2026 | Tourist can browse destinations and the map |
| 12 July 2026 | Tourist can book a guide and submit GCash or office payment |
| 26 July 2026 | Guide can accept a booking; tourist receives QR e-ticket |
| 2 August 2026 | Guide check-in with map, QR, and stop photos |
| 16 August 2026 | Business listed; visit calendar and inquiries working |
| 23 August 2026 | Admin can send a refund; replacement-guide path in place |
| 30 August 2026 | Flutter tourist browse/map talking to `/cultour/api` |
| 13 September 2026 | Flutter tourist booking + guide check-in usable |
| **26 September 2026** | Tester measurements on the development PC |
| 30 September 2026 | Documentation freeze and oral defense |

---

## End-to-end path used for the defense demo

1. Register as tourist → verify email → log in.  
2. Build or pick an itinerary → book a licensed guide → pay (GCash QR + reference **or** pay at office).  
3. Switch to guide → accept → tourist opens QR e-ticket.  
4. Guide scans ticket → check-in on the route map (meetup + each stop, photo) → complete tour → tourist rates.  
5. Alternate path: guide declines a paid booking → tourist enters GCash details → admin **Mark as sent** on Refunds → tourist bell shows “Your refund was sent”.  
6. Business: approved listing → tourist inquiry → visit calendar for a booked date.  
7. Mobile: same booking and check-in on Flutter (tourist + guide).
