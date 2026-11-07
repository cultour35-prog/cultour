# CulTour: Response to Tester Feedback

This document answers the seven points raised by the software tester. For each point it lists what CulTour already had, what was added, how it was tested, and how to demonstrate it to a panel.

All measurements were taken on the development PC (XAMPP: Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32) on September 26, 2026.

---

## 1. Form validation and error handling

**What was added**

- **Live, field-by-field checking on every standard form.** When a user leaves a field (blur), CulTour checks it right away and shows a message directly under that field. The message clears as soon as the value becomes valid. This uses the browser's built-in rules (`required`, `type="email"`, `min`, `max`, `minlength`, `pattern`), so every rule already declared in the HTML is enforced in real time.
- **Descriptive messages that name the field and say how to fix it.** The browser's small generic pop-up bubbles are replaced with inline text such as:
  - "First name is required."
  - "Enter a complete email address, for example name@gmail.com."
  - "Enter the 10-digit mobile number after +63, starting with 9 (for example 9171234567)."
  - "Choose a date on or after Sep 26, 2026."
  - "Age must be 18 or more."
- **Registration** (`register.php`) now checks each field when the user leaves it, including the Philippine mobile-number format, and uses the same named messages.
- **Login** checks the email format and empty fields before contacting the server, and warns when Caps Lock is on in the password field.
- On submit, the first invalid field is scrolled into view and focused, and its message is read aloud to screen-reader users.

**Where:** `assets/js/app.js` (the `bindFriendlyValidation` section), `register.php`, `login.php`, `assets/js/form-required.js`.

**Demo:** On the Register page, type `juan@` in Email and press Tab. The message appears immediately. Finish the address and the message disappears.

---

## 2. UI consistency (design system)

CulTour already had a token-based theme in `assets/css/style.css` (colors, card radius, display and body fonts). It now also has:

| Token group | Tokens |
|---|---|
| Spacing scale | `--ct-space-1` to `--ct-space-6` (0.25rem to 2rem) |
| Type scale | `--ct-fs-xs`, `--ct-fs-sm`, `--ct-fs-base`, `--ct-fs-lg`, `--ct-fs-xl` |
| Radius | `--ct-radius-sm` (inputs, small buttons), `--ct-radius-btn` (buttons), `--ct-radius` (cards) |
| Feedback colors | `--ct-danger-text`, `--ct-warning-text`, `--ct-focus` |

Shared rules apply these tokens to every Bootstrap button, input, select, alert and modal, so pages that were built at different times use the same corner radius, button weight and error-text style. Loading, error and focus states also look the same on every page.

---

## 3. Loading indicators and duplicate-submission prevention

**What was added (applies automatically to every form in the system):**

- When a form is submitted, the clicked button shows a spinner and is disabled, and the other submit buttons in the same form are disabled too.
- A thin progress bar appears at the top of the page until the next page loads.
- A second submission of the same form is blocked while the first is still in flight. This prevents double bookings, double payments and duplicate records from double-clicks.
- Screen readers announce "Submitting, please wait."
- If the user presses Back, the buttons are restored. If a response never leaves the page (for example a file download), the buttons re-enable after 30 seconds.
- Forms that already handle their own sending with JavaScript (login, chat, favorites) keep their own behavior. The login button shows a spinner with "Signing in…".

**Technical note:** the button is disabled one moment *after* the submit event. Disabling it during the event would drop the clicked button's `name=value` (for example `action=delete`) from the POST. This was verified with a real form POST (see Testing).

**Where:** `assets/js/app.js` (`bindSubmitLoading`, `setButtonBusy`), `assets/css/style.css` (`.ct-btn-spinner`, `.ct-top-progress`).

---

## 4. Accessibility

| Measure | Details |
|---|---|
| Skip link | "Skip to main content" is the first item on every page. It appears when you press Tab and moves focus past the navigation. |
| Keyboard focus | Every link, button and form field shows a clear gold outline when reached with the keyboard (`:focus-visible`). |
| Labels | Form labels are linked to their inputs (clicking a label focuses its field, and screen readers read the label). Icon-only buttons such as the menu, notifications and messages have spoken names. Decorative icons are hidden from screen readers. |
| Errors | Invalid fields are marked `aria-invalid="true"` and linked to their message with `aria-describedby`. Error alerts use `role="alert"`, so they are announced immediately. |
| Busy states | Submitting forms are marked `aria-busy="true"`, and a polite live region announces progress. |
| Reduced motion | Users who ask their OS for reduced motion get animations and transitions turned off. Spinners keep turning so progress is still visible. |
| Language and titles | Every page declares `lang="en"` and has a unique `<title>`. |

**Color contrast (WCAG 2.1 AA needs 4.5:1 for normal text):**

| Text | On page background | On card surface |
|---|---:|---:|
| Body text `#e6f2f4` | 16.6:1 | 13.7:1 |
| Muted text `#a8c0c8` | 10.0:1 | 8.2:1 |
| Links `#7ed4c8` | 11.0:1 | 9.1:1 |
| Placeholder `#8eabb4` | 7.8:1 | 6.4:1 |
| Error text, old `#dc3545` | 4.2:1 (fail) | 3.5:1 (fail) |
| Error text, new `#ff8f8f` | 8.7:1 | 7.2:1 |
| White on primary button, old light end `#0e8a7a` | 4.3:1 (fail) | |
| White on primary button, new light end `#0d8475` | 4.6:1 | |

The two failing colors were corrected.

---

## 5. User guidance and error recovery

Error messages now say what happened and what to do next. Examples:

| Situation | Before | After |
|---|---|---|
| Wrong password | "Invalid email or password." | "Incorrect email or password. Passwords are case-sensitive, so check Caps Lock, or use "Forgot password?" to set a new one." When 2 or fewer tries remain, it adds "2 attempts left before sign-in is paused for 15 minutes." |
| Page open too long (security token expired) | "Invalid security token." | "This page was open too long or your session ended, so it could not be submitted safely. Refresh the page and try again." (now the same message in all 18 places that use it) |
| Email already registered | "An account with this email already exists for Tourist." | "…Sign in instead, or use "Forgot password?" on the login page if you can't remember the password." |
| Upload: wrong type | "That file type is not allowed." | "That file type is not accepted here. Upload one of: JPG, PNG, GIF, WEBP." |
| Upload: too big | "File must be under 8 MB." | "That file is larger than 8 MB. Choose a smaller file or compress it, then upload again." |
| Upload: renamed or damaged file | "The file contents do not match the file type." | "That file looks damaged or was renamed to .pdf. Open it, save or export it again as PDF, then upload the new copy." |
| Wrong reset code | "Invalid or expired code. Request a new one." | "That code is incorrect or has expired (codes last 15 minutes). Check the latest email from CulTour, or request a new code." |
| Network failure at login | "Could not sign in. Please try again." | "CulTour could not be reached. Check your internet connection, then try signing in again." |
| Pending approval | "Your account is pending approval." | "Your account is still waiting for Tourism Office approval. You can sign in as soon as it is approved." |

Existing recovery features that are worth demonstrating: the "Save your changes?" prompt before leaving an edited form, the Forgot-password flow with an emailed 6-digit code, and the suspension appeal flow.

---

## 6. Performance

### Optimizations made

1. **Compression.** HTML pages are now sent gzip-compressed.

   | Page | Before | After | Saved |
   |---|---:|---:|---:|
   | Register | 103.1 KB | 18.9 KB | 82% |
   | Login | 29.1 KB | 7.0 KB | 76% |
   | Home | 13.3 KB | 3.3 KB | 75% |
   | Explore | 9.3 KB | 2.6 KB | 71% |

2. **Browser caching.** CSS, JavaScript, fonts and images are cached for 30 days. Every asset URL carries a version number based on the file's last change (`?v=`), so users still get updates immediately.
3. **Removed schema changes that ran on every page load.** Three `ALTER TABLE … MODIFY` statements (announcements, content reports, business URLs) ran on every dashboard or admin page view. An `ALTER TABLE` locks the table, so concurrent users queued behind each other. They now run only when the column actually needs changing. A capture of 8 page loads across tourist and admin pages now shows 0 `ALTER` statements.
4. **Indexes verified.** The hot queries use indexes. For example, the login lookup by email uses `users_email_role(email, role_id)` and reads 1 row, and the unread-notifications count uses `idx_notif_user(user_id, is_read)` as a covering index.

### Load test

The tool `tools/loadtest.php` simulates concurrent users. Each virtual user signs in with its own session, exactly like separate people.

Command: `C:\xampp\php\php.exe tools\loadtest.php --n=50 --c=10` (10 concurrent users, 50 requests per page)

| Page | User | Requests | Errors | Avg ms | p95 ms | Req/s |
|---|---|---:|---:|---:|---:|---:|
| Home `/` | guest | 50 | 0 | 164 | 208 | 52.5 |
| `/login.php` | guest | 50 | 0 | 212 | 260 | 40.8 |
| `/register.php` | guest | 50 | 0 | 264 | 319 | 32.4 |
| `/explore.php` | guest | 50 | 0 | 168 | 224 | 52.2 |
| Tourist dashboard | tourist | 50 | 0 | 571 | 767 | 14.9 |
| Destinations | tourist | 50 | 0 | 576 | 702 | 15.2 |
| Businesses | tourist | 50 | 0 | 529 | 676 | 16.5 |
| Itinerary builder | tourist | 50 | 0 | 2423 | 2583 | 3.9 |
| Notifications | tourist | 50 | 0 | 421 | 476 | 20.3 |

Result: **450 requests, 0 errors.** A single, non-concurrent request takes about 120–170 ms for signed-in pages. The itinerary builder is the heaviest page (47 KB, about 73 database queries), so it is the first candidate for further optimization.

### OPcache measurement

`tools/opcache_compare.php` measures the same pages with PHP's OPcache off and on:

| Page | OPcache off | OPcache on | Faster |
|---|---:|---:|---:|
| Login | 66 ms | 40 ms | 1.7x |
| Register | 73 ms | 42 ms | 1.8x |
| Tourist dashboard | 136 ms | 117 ms | 1.2x |
| Destinations | 157 ms | 121 ms | 1.3x |

XAMPP ships with OPcache disabled. For deployment, enable it in `php.ini` (`zend_extension=opcache`, `opcache.enable=1`) and also enable `mod_deflate` and `mod_expires` in Apache. The `.htaccess` file already contains their settings, which switch on automatically when the modules are loaded.

### Next optimization identified

Profiling showed that each `SHOW COLUMNS` schema check costs 9–18 ms on this MariaDB. Signed-in pages run about eight of them to confirm the database structure, roughly 80–100 ms per page. Caching those checks between requests is the next step.

---

## 7. Security

### Protections that already existed

| Threat | Protection |
|---|---|
| Stolen passwords from a database leak | Passwords stored as bcrypt hashes (`password_hash` / `password_verify`). Reset codes stored as SHA-256 hashes, valid for 15 minutes. |
| SQL injection | Every query with user input uses PDO prepared statements with real server-side binding (`ATTR_EMULATE_PREPARES => false`). |
| Cross-site scripting (XSS) | All output passes through `e()` (`htmlspecialchars` with `ENT_QUOTES`, UTF-8). |
| Cross-site request forgery (CSRF) | Every state-changing form and web API carries a random 64-character token, compared with `hash_equals`. |
| Session fixation | The session ID is regenerated at login (`session_regenerate_id(true)`). |
| Unauthorized access | `require_login(ROLE_…)` checks the role on every protected page. Suspended and unapproved accounts are restricted. |
| Malicious uploads | Extension allow-list, real content check (image headers, PDF/DOCX signatures), 8 MB limit, random file names, and PHP execution blocked inside `uploads/`. |
| Mobile API | Random 64-character bearer tokens with a 30-day expiry. |

### Protections added

| Threat | Protection added |
|---|---|
| Password guessing (brute force) | After 5 wrong passwords for one email from one device, sign-in is paused for 15 minutes. After 20 failures from one IP address across all emails, that address is paused. Applies to the website and the mobile app (HTTP 429). A successful password reset lifts the pause. |
| Guessing the 6-digit reset code | The same limit now applies to reset-code checks, including direct submission of the code hash. |
| Session cookie theft | The session cookie is now `HttpOnly` (JavaScript cannot read it) and `SameSite=Lax` (not sent with cross-site form posts). It becomes `Secure` automatically on HTTPS. Strict session mode rejects unknown session IDs. |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` and CSP `frame-ancestors 'self'`. |
| MIME sniffing and plugin content | `X-Content-Type-Options: nosniff` and CSP `object-src 'none'; base-uri 'self'`. |
| Leaking URLs to other sites | `Referrer-Policy: strict-origin-when-cross-origin`. |
| Browser feature abuse | `Permissions-Policy` limits camera and location to CulTour and disables microphone and payment APIs. |
| HTTPS downgrade | `Strict-Transport-Security` is sent automatically when the site runs on HTTPS. |
| Server fingerprinting | The `X-Powered-By: PHP/…` header is removed. |
| **Database dump downloadable** | `database/cultour_db.sql` (containing password hashes) was downloadable at `/cultour/database/cultour_db.sql`. It is now blocked (HTTP 403). |
| **Password reset through setup script** | `setup_cli.php` reset every account's password when opened in a browser. It is now blocked from the web (command line only), and `install.php` / `seed_ormoc.php` only open from the server PC itself. |
| Source and config exposure | `config/`, `includes/`, `database/`, `storage/`, `tools/`, the mobile app source, `.sql`, `.md`, `.log` and `.env` files return 403. Directory listings are disabled. |

### Security test results

**Brute-force test** (6 wrong passwords, then the correct one):

```
try 1-2 : 401  "Incorrect email or password. Passwords are case-sensitive, ..."
try 3   : 401  "... 2 attempts left before sign-in is paused for 15 minutes."
try 4   : 401  "... 1 attempt left before sign-in is paused for 15 minutes."
try 5-6 : 429  "Too many failed sign-in attempts. For your security, please wait 15 minutes ..."
correct password while paused : 429 (still blocked)
```

**Exposure test** (HTTP status after the fix):

```
403  /database/cultour_db.sql      (was 200: downloadable)
403  /includes/                    (was 200: directory listing)
403  /includes/functions.php
403  /config/app.php
403  /setup_cli.php
403  /storage/   403 /uploads/     (no directory listing)
200  /login.php, /index.php, /assets/css/style.css   (normal pages unaffected)
```

**Response headers on `/login.php`:**

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(self), geolocation=(self), microphone=(), payment=()
Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'
Set-Cookie: cultour_session=…; path=/; HttpOnly; SameSite=Lax
```

**CSRF audit:** every PHP file that reads `$_POST` was checked for a CSRF check. The only files without `verify_csrf()` are protected another way: the QR payment page uses a signed one-time token, and the account-link APIs compare the session token inside the JSON body.

### How to demonstrate security to the panel

1. Enter a wrong password 5 times on the login page and show the lockout message.
2. Open `http://localhost/cultour/database/cultour_db.sql` and show "403 Forbidden".
3. In the browser DevTools Network tab, open any page and show the security headers and the `HttpOnly` cookie flag.
4. In phpMyAdmin, show the `users.password_hash` column (bcrypt `$2y$…`, no plain text).
5. Sign in as `admin@cultour.ormoc.gov.ph` with the password `' OR '1'='1' -- ` and show that it is rejected like any wrong password (prepared statements treat it as plain text). Tested: HTTP 401 "Incorrect email or password…".
6. Type `<script>alert(1)</script>` into a text field such as a review, save it, and show that it displays as text (all output goes through `e()`; rehearse this one before the defense).

---

## Testing performed

- PHP syntax check (`php -l`) on every changed PHP file: no errors.
- 29 pages opened as admin, tourist, tour guide, business and guest: all HTTP 200 with no PHP errors or warnings.
- Automated browser test in headless Microsoft Edge of the validation and loading behavior (18 checks, all passed): named messages, focus on the first invalid field, messages clearing while typing, email and number-range messages on blur, spinner and disabled buttons, duplicate submission blocked, buttons restored, and forms with their own JavaScript left untouched.
- Real form POST confirmed that the clicked button's value still reaches the server with loading enabled (`{"note":"hello","action":"delete"}`).
- Brute-force, exposure, header and load tests shown above.

**Not yet tested:** manual clicking through every form in a visible browser, and a screen-reader session (NVDA or Windows Narrator). Both are recommended before the defense.
