# SmartEPT — Session Handoff (11/12-Aug-2026)

**Prepared for:** next working session (Ejaz sir / team / Claude)
**Repos:** Central `C:\laragon\www\smartept-central` (smartept.com) · Product `C:\laragon\www\smartept` (admin.smartept.com) · Live VPS `35.200.195.98`, apps at `/var/www/client_apps/`

---

## 1. What this session delivered

### Central (smartept-central) — commits, oldest → newest

| Commit | What |
|---|---|
| `e6f5839` + `d8279a5` | **Progressive On-Premise Lifetime pricing** — band prices are milestone prices, interpolated in between; Custom Quote = NULL price (₹0 impossible); AMC = 18% of interpolated price; applied to backend + /buy + client auth + portal + landing slider; band-editor validation; full acceptance test suite (64/64) |
| `a03f228` | ™/® removed everywhere (nothing registered); © 2026 kept |
| `73888fc` | Super-only **Delete** for garbage quotes / unpaid orders (only when ledger = 0 and no licence) and invoices (audit-logged). Money-bearing orders never deletable. No Edit by design |
| `72c0059` | Coupon box on /buy hidden unless a coupon is actually live (`Coupon::anyLive()`) |
| `2d9d8a6` | **Owner override:** licence signing keypair committed so live gets it via pull — **repo must stay PRIVATE forever** |
| `385836a` | Hourly dunning crash fixed: `tenants.status` ENUM lacked 'purged' → VARCHAR(20) (migration `2026_08_11_000300`) |
| `3ce729f` | SEO T1 — sitemap.xml/robots.txt always HTTPS via `canonicalBase()`; explicit 7-page allow-list |
| `706b642` | SEO T2 — self-referencing canonical tags on all 7 public pages |
| `44f71d2` | SEO T3 — `X-Robots-Tag: noindex, follow` on workflow pages (client auth/portal, /buy, /pay, /cms-preview); deliberately NO robots.txt Disallow |
| `2fa3fab` | SEO T5 — **GA4 root cause fixed:** publish rendered from empty `landing_sections` and never rewrote landing.html. Publish now self-heals (auto `landing:import`); GA4/GTM/Pixel/Ads extended to legal pages + /buy via `trackingTags()`; duplicate-safe re-publish. **All SEO editor fields now flow on Publish, not just analytics** |
| `d948fc9` | Your own "12-08-2026 update1" (current HEAD) |

SEO T4 (www→non-www 301) has **no commit** — it is a server-level Nginx change only (steps in §3).

### Product (smartept)

| Commit | What |
|---|---|
| `22ed12e` | **Login fix:** seeder double-hash ('hashed' cast + Hash::make) meant no seeded login ever worked. **Client-wise SMTP:** company relay → global super-admin SMTP → .env fallback, editable by COMPANY_ADMIN + Super, with Send-Test. **Forgot-password email OTP** for all roles incl. employees (hashed OTP, 10-min expiry, 5 attempts, throttled). Migration `2026_08_12_000100` |
| `1f6be35` | 3 live-log bugs: attendance `lessThan() on string` (3 missing datetime casts — self-heals after deploy); `action_on_blocked` ENUM strike four → both policy tables VARCHAR(20) (migration `2026_08_12_000200`); restoreArchive key error (already fixed locally, just undeployed) |

### Documents (12 branded files)

In `C:\laragon\www\smartept-central\docs\SmartEPT-Session-2026-08-12\`:
SR-01 Session Report (Word+PDF) · SR-02 Test Suite, 77 cases (Excel+PDF) · SR-03 Live Bug-Fix Report (Word+PDF) · BF-01 Business Flow, 9 flowcharts (Word+PDF) · BF-02 Business-Flow Testing Guide, 14 journeys / 68 steps (Word+PDF+Excel tracker).
Sign-off rule: BF-01…BF-07 all PASS = launch-approved; any P1 fail → report immediately.

---

## 2. Already confirmed working (no re-check needed)

Landing page + SEO editor working live (your confirmation, 12-Aug) — GA4 `G-PD29GRTY1B` on the page. Razorpay smartept.com verified. Provisioning secrets + Interakt key set in Central Settings. Central migrations ran locally; product `migrate.bat` ran locally.

---

## 3. GO-LIVE CHECKLIST — do these next (in order)

### A. Product → live (if not already done)
```
# on PC: push smartept repo (22ed12e + 1f6be35)
# on VPS, in the product app dir:
git pull
php artisan migrate --force        # runs BOTH pending migrations
php artisan config:clear
sudo systemctl reload php8.3-fpm
```
Then, once, in `php artisan tinker` — unlock super and set a real password:
```php
$u = \App\Models\User::where('email','super@smartept.io')->first();
$u->forceFill(['password' => 'NEW-STRONG-PASSWORD'])->save();
```
**Change ALL seeded users' passwords** — before the double-hash fix they were effectively unset.

### B. Central → live (verify each; some may already be done via d948fc9 pull)
```
git pull
php artisan migrate --force        # tenants.status VARCHAR
chmod 600 storage/app/keys/*  &&  chown www-data:www-data storage/app/keys/*
# .env: APP_URL=https://smartept.com   (currently http on live → sitemap/canonicals depend on it)
php artisan config:clear && php artisan route:clear
```
Landing: press **Publish → make live** once after deploy (publish now self-heals even if `landing:import` was never run). Reminder: **Save stores, Publish applies.**

### C. Nginx www → non-www 301 (manual, server-only — likely still pending)
```
grep -rl "www.smartept.com" /etc/nginx/sites-enabled/
```
In that 443 block: keep the `listen`/`ssl_certificate` lines (cert CN *.smartept.com is valid), replace the body with:
```
return 301 https://smartept.com$request_uri;
```
Ensure the port-80 block for BOTH `smartept.com www.smartept.com` also returns `301 https://smartept.com$request_uri` (single hop — never a redirect chain). Then:
```
nginx -t && systemctl reload nginx
curl -I http://www.smartept.com/ ; curl -I https://www.smartept.com/ ; curl -I http://smartept.com/
```
Each must answer one single `301` → `https://smartept.com/`. No DNS or certificate changes needed.

### D. Verification sweep
1. Run `test.bat` in both repos — green.
2. Central → System Health — all green.
3. Live log clean: no `AttendanceDerivation failed`, no 1265 on dunning or Rules save (CLOSE **and** BLOCK), archive restore works.
4. Console forgot-password: OTP arrives via company SMTP when configured, global relay otherwise; `mail_logs` rows written.
5. Google Search Console: submit `https://smartept.com/sitemap.xml`; watch www URLs drop out and canonicals consolidate.
6. Team executes SR-02 (77 cases) then BF-02 (14 journeys) with the Excel trackers.

---

## 4. Offered but NOT built (say the word and it happens)

Super editing any company's SMTP via a company picker · coupon-box treatment on the client portal (buy page done) · resend-OTP cooldown · silencing PHP 8.5 deprecation warnings · sqlite guard for migration `2026_08_07_000300` · invoice/quote print logo fix · the interrupted "complete sitemap/screen-map" document.

---

## 5. Standing lessons reinforced this session

1. **ENUM strikes 3 & 4** — prefer VARCHAR; the application owns the value set.
2. New timestamp column ⇒ model cast **in the same commit**.
3. Never `Hash::make()` into a `'hashed'`-cast attribute; use `forceFill()->save()`, never query-builder `update()`.
4. Central repo stays **PRIVATE** (signing keys in git).
5. Laragon: Stop All → Start All after every PHP change (OPcache).
6. Report first, implement after approval; exact scope only; ask before any composer/version change.

*Handoff also saved to project memory (`project_smartept_handoff_2026-08-12.md` + updated index) — the next session picks it up automatically.*
