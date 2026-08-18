# Engineering Session Report

**SmartEPT Central · 17 August 2026**
Trial signup did not provision the client's branded console — investigation, fix, and handover

---

## At a glance

| | |
|---|---|
| **Issue** | Self-service 7-day trial clients received no branded console at `/<slug>`; the slug only appeared after an admin manually re-saved the tenant |
| **Severity** | High — affects every self-service trial; first impression of the product for a prospect |
| **Root cause** | `ProductProvisioner::ensureFor()` was never called on the trial signup path |
| **Status** | Fixed in the working copy and committed locally · **push + test pending with the team** |
| **Change size** | 1 file, +11 lines (1 statement + 9 comment lines), 0 files deleted, 0 migrations, 0 config changes |
| **Deploy risk** | Low — idempotent, fail-soft, no schema or data migration |
| **Rollback** | `git revert` of a single commit; no data to unwind |
| **Repo** | `github.com/ametecsindia/SmartEPT_central` · branch `fix/trial-console-provisioning` |

---

## 1. Scope of this session

The session began with a single question from Ejaz: a client signs up for the 7-day trial on the
landing page, lands in the client portal successfully, but the product has no `/xyz` slug — and
re-saving the tenant in admin creates it. Why is it not automatic?

Work carried out:

1. Traced the end-to-end provisioning chain across **both** applications — Central
   (`C:\laragon\www\smartept-central`) and the product (`C:\laragon\www\smartept` /
   `SmartEPT_Admin`).
2. Identified the root cause and ruled out five plausible alternative explanations.
3. Applied the fix to the Central working copy and verified it on disk.
4. Composed the commit against a clean clone of `origin/main` to prove the diff applies cleanly.
5. Produced a root-cause report (`docs/SmartEPT-Trial-Console-Slug-RCA-2026-08-17.md` / `.pdf`).
6. Produced this session report with deployment and test instructions for the team.

---

## 2. What exists today — how a console is provisioned

This is the as-is design, unchanged by this session. It is documented here because the defect is
only understandable against it, and because the chain crosses two applications.

```
CENTRAL (smartept-central)                  PRODUCT (SmartEPT_Admin)
──────────────────────────────────────      ────────────────────────────────────────────
ProductProvisioner::ensureFor($tenant)
  guard: deployment === 'cloud'
      && status IN ('active','trial')
  │
  └─ POST {product_base_url}/api/provision ► Api\ProvisionController::provision()
     header X-Provision-Secret: <shared>      │  (no auth guard — the shared secret is the gate)
     body: external_tenant_id, company_name,  ├─ Company::create([... 'slug' => uniqueSlug()])
           admin_email, admin_name, slug,     ├─ COMPANY_ADMIN user + temp password
           licence_key, storage_quota_mb,     ├─ adopts Central's SMTP if none set locally
           central_url, mail                  ├─ writes the tenant's licence key + validates
                                              └─ returns { slug, console_url, temp_password }
  ◄────────────────────────────────────────
  ├─ saves tenants.console_url
  └─ emails the temporary console password to the client (first provision only)
```

Key properties of `ensureFor()` — all of these were already true and are what make the fix safe:

- **Idempotent.** The product keys on `external_tenant_id`, so a repeat call re-uses the same
  Company and admin user and merely re-syncs slug, storage quota and licence key.
- **Fail-soft.** Everything is inside `try/catch`; a product outage logs an error and returns.
  It can never fail or roll back the caller.
- **Silent when unconfigured.** If `product_base_url` or `product_provision_secret` is blank it
  logs a warning and returns — visible only in the log.
- **Trial-aware.** `'trial'` is explicitly an allowed status.

---

## 3. What was found — the defect

`ensureFor()` is the only method in Central that reaches the product. Before this session it had
**exactly three callers**, and a self-service trial reaches none of them:

| # | Caller | Fires when | Trial? |
|---|---|---|---|
| 1 | `Admin\TenantApiController::store()` L143–145 | An admin creates a tenant in `/admin` | ✗ The client created it, not an admin |
| 2 | `Admin\TenantApiController::update()` L195–197 | An admin saves an existing tenant | ✗ Only when someone does it by hand — **this was the workaround** |
| 3 | `BillingService::recordPayment()` L683 | A payment is recorded | ✗ A free trial takes no money, so no order settles |

The self-service path (`POST /client/signup/verify` → `Client\AuthController::signupVerify()`)
creates the tenant with `deployment => 'cloud'` and `status => 'trial'` — both correct — then calls
`BillingService::provisionTrial()`, which sets the trial dates and issues the licence and **stops
there**. `BillingService` even holds a `ProductProvisioner $console` in its constructor; it is
simply not used on the trial branch.

**Ruled out** (each would have sent a fix in the wrong direction):

- The `trial` status is not filtered out — it is explicitly allowed.
- `deployment` is set correctly at signup.
- A blank `console_slug` is not fatal — there is a `Str::slug(company_name)` fallback.
- `ensureFor()` is not once-only — it is deliberately re-runnable.
- Not a settings problem — if the URL/secret were blank, the admin re-save would fail too.

---

## 4. What was fixed

**File:** `app/Http/Controllers/Client/AuthController.php` — one statement added immediately after
the signup transaction commits (now lines 136–145).

```php
        });

        // ROOT-CAUSE FIX (Ejaz, 17-Aug-2026): a self-service trial never reached the
        // product app, so the client had NO branded console at /<slug> until an admin
        // opened the tenant and pressed Save. ensureFor() was wired only to
        // TenantApiController (admin create/save) and BillingService::recordPayment()
        // — and a free trial records no payment. Stand the console up here instead.
        // Post-commit on purpose: provisioning is an 8s HTTP round trip and must not
        // be held open inside the signup transaction. Idempotent and fail-soft
        // (ensureFor swallows + logs), so a product outage still leaves a working
        // trial and the admin Save stays available as the recovery path.
        app(\App\Services\ProductProvisioner::class)->ensureFor($user->tenant()->first());

        Auth::guard('client')->login($user, true);
```

**Why after the transaction, not inside `provisionTrial()`.** Putting the call in
`BillingService::provisionTrial()` would be tidier and would cover any future trial path
automatically. It was rejected for one reason: `provisionTrial()` runs *inside* `DB::transaction`,
and `ensureFor()` makes an HTTP call with an 8-second timeout. Holding a write transaction open
across a network round trip to a second application — one that may be slow, down, or firewalled —
is a bad trade. Post-commit placement gets the same outcome with none of that exposure.

**What did NOT change:** no database schema, no migration, no configuration, no product-side code,
no billing logic, no existing call site. Nothing was deleted.

---

## 5. What was improved beyond the fix

| Area | Improvement |
|---|---|
| **Client experience** | The console credentials email (product temp password) now reaches the client during signup, instead of whenever an admin happened to press Save |
| **Traceability** | The fix carries a 9-line comment explaining the failure, the three original call sites, and why the placement is what it is — so the next reader does not re-derive it |
| **Documentation** | A full root-cause report added at `docs/SmartEPT-Trial-Console-Slug-RCA-2026-08-17.md` (+ PDF), following the existing `docs/` naming convention |
| **Commit quality** | The commit message records the symptom, the three call sites, the reasoning for the placement, and the ruled-out causes |
| **Verified clean base** | The change was diffed against `origin/main` before committing — proving no unrelated local edits were swept in |
| **Backfill path identified** | A SQL query that lists exactly the affected existing tenants (§8) |

---

## 6. Impact

### Before the fix

| Who | Effect |
|---|---|
| **Trial client** | Signed up, reached the portal, then found no console to actually try the product. Never received console credentials. The trial clock ran while they waited |
| **Sales** | Had to notice the new trial and ask someone to open `/admin` and press Save before the prospect could evaluate anything |
| **Support / admin** | A silent manual step, invisible unless someone knew to look — no error, no alert, nothing in the UI to indicate the console was missing |
| **Product** | `tenants.console_url` stayed null, so "Open my Console" and portal SSO had no target |

### After the fix

| Who | Effect |
|---|---|
| **Trial client** | Console exists and is reachable at `/<slug>` from the moment signup completes; credentials email arrives in the same minute |
| **Sales** | Zero-touch trial. A prospect self-serves end to end |
| **Support / admin** | The admin Save remains as a recovery path if the product app was down at signup |

### Residual exposure

Every trial that signed up **before** this fix is still missing its console. Those tenants need
either a manual admin Save each or the backfill in §8. This is the only remaining customer-facing
gap from this defect.

---

## 7. Deployment instructions

**Environment:** SmartEPT Central. **Prerequisite:** Central → Settings → SmartEPT Product must
have `product_base_url` and `product_provision_secret` populated. If they are blank the fix runs
but does nothing (and logs a warning) — verify this first.

The working copy at `C:\laragon\www\smartept-central` is already patched. The commit message is
staged at `storage/app/trial-console-fix.commit.txt` (Laravel gitignores `storage/`).

### Step 1 — commit and push

```
cd C:\laragon\www\smartept-central
git status                                   # confirm only AuthController.php is intended
git checkout -b fix/trial-console-provisioning
git add app/Http/Controllers/Client/AuthController.php
git commit -F storage/app/trial-console-fix.commit.txt
git push -u origin fix/trial-console-provisioning
```

Omit the `checkout -b` line to land directly on `main`, as recent commits do. The `git add` is
deliberately scoped to the one file — other work in progress may be present.

*Backup route:* a `trial-console-fix.patch` (`git format-patch`) was also provided. To use it:
`git checkout app/Http/Controllers/Client/AuthController.php` then `git am trial-console-fix.patch`.

### Step 2 — review and merge

Single-file diff, +11 lines. Reviewer should confirm the call sits **after** the closing `});` of
`DB::transaction` and **before** `Auth::guard('client')->login(...)`.

### Step 3 — deploy to the server

```
git pull
php artisan config:clear
php artisan cache:clear
php artisan optimize
```

No migration. No queue worker restart required (the call is synchronous). No product-side deploy.

### Step 4 — post-deploy smoke test

Run test case **T1** below on the live site with an internal email address, then delete or mark
that tenant as internal.

---

## 8. Backfill for existing affected trials

Run the query first — it tells you whether this is a handful of manual Saves or worth automating.

```sql
SELECT id, company_name, email, status, created_at
FROM tenants
WHERE deployment = 'cloud'
  AND status IN ('active','trial')
  AND (console_url IS NULL OR console_url = '')
ORDER BY created_at DESC;
```

- **A few rows:** open each tenant in `/admin` and press Save. Done.
- **Many rows:** an artisan command looping that query and calling `ensureFor()` on each is roughly
  fifteen lines and is safe to re-run. Ask and it will be written.

**Important:** provisioning a backfilled tenant is a *first* provision, so the product returns a
temporary password and Central emails it to that client. This is correct behaviour, but it is
outbound mail to real customers — do it deliberately, during working hours, not as an unattended
sweep. Consider notifying sales first so they expect the client replies.

---

## 9. Test plan

Environment: Central + product both reachable; `product_base_url` and `product_provision_secret`
configured. Watch `storage/logs/laravel.log` on Central throughout.

| ID | Area | Steps | Expected result | Pass |
|---|---|---|---|---|
| **T1** | Trial signup — the fix | Sign up a new 7-day trial from the landing page using an email not present in `tenant_users`. Complete OTP | Signup succeeds; log shows `Cloud console provisioned` with a `console_url`; `tenants.console_url` populated | ☐ |
| **T2** | Slug reachability | Open the `console_url` from T1 | The branded console loads at `/<slug>` — no 404 | ☐ |
| **T3** | Credentials email | Check the T1 inbox | Email "Your SmartEPT console sign-in" received with a temporary password; log shows `Console credentials emailed to tenant owner` | ☐ |
| **T4** | Forced password change | Sign in to the console with the T1 temp password | Product forces a password change on first sign-in (`must_change_password`) | ☐ |
| **T5** | Portal SSO | From the client portal, click "Open my Console" | Lands signed in, no second password prompt | ☐ |
| **T6** | Idempotency | Open the T1 tenant in `/admin` and press Save | No duplicate company or user is created; slug unchanged; **no second credentials email** | ☐ |
| **T7** | Regression — admin create | Create a cloud tenant manually in `/admin` | Console provisioned exactly as before this change | ☐ |
| **T8** | Regression — paid order | Record a payment against a cloud order | Console provisioned exactly as before this change | ☐ |
| **T9** | Fail-soft — product down | Stop the product app (or blank `product_base_url`), then sign up a trial | Signup still succeeds, client still reaches the portal, trial licence still issued; log shows the provisioning error/warning only | ☐ |
| **T10** | Recovery after T9 | Restart the product, open that tenant in `/admin`, press Save | Console provisioned; `console_url` fills in | ☐ |
| **T11** | Duplicate email guard | Attempt a second trial signup with the T1 email | Rejected with "This email already has a SmartEPT account" — unchanged behaviour | ☐ |
| **T12** | Existing tests | `php artisan test` (or `test.bat`) | No new failures versus the pre-change baseline | ☐ |

**T9 is the important one.** It proves the fix cannot damage a signup when the product is
unavailable — the property that justifies making this call synchronously inside the signup request.

**Sign-off**

| Role | Name | Date | Result |
|---|---|---|---|
| Developer | | | |
| Tester | | | |
| Approved by | | | |

---

## 10. Risk and rollback

| Risk | Assessment |
|---|---|
| Signup breaks if the product is down | **Mitigated** — `ensureFor()` is fail-soft; covered by T9 |
| Signup slows down | Up to 8s worst case (HTTP timeout) added to the signup request, and only when the product is unreachable. Normal case is well under a second |
| Duplicate companies / users | **Not possible** — the product is idempotent on `external_tenant_id`; covered by T6 |
| Duplicate credential emails | **Not possible** — `temp_password` is returned only on first provision |
| Data loss | None — no schema, migration or destructive operation |

**Rollback:** `git revert <commit>` and redeploy. Behaviour returns to admin-Save-only, and any
console already provisioned stays provisioned and working.

---

## 11. Open items

| # | Item | Owner | Priority |
|---|---|---|---|
| 1 | Push the branch and merge (§7 Step 1) | Dev | Now |
| 2 | Confirm `product_base_url` + `product_provision_secret` are set in Central Settings | Dev / Ops | Before testing |
| 3 | Execute the test plan (§9), especially T9 | Tester | With the deploy |
| 4 | Run the backfill query and decide manual vs command (§8) | Ejaz | This week |
| 5 | Audit other cloud-tenant creation paths (prospect quotes, plan changes) for the same missing call | Dev | Next session — **not covered by this session** |
| 6 | Consider moving the call into `provisionTrial()` if a second trial path is ever added | Dev | Backlog |

---

## Appendix A — evidence trail

| File | Lines | What it shows |
|---|---|---|
| `Central: app/Http/Controllers/Client/AuthController.php` | 67, 108–134 | Trial signup path; tenant created cloud/trial; only `provisionTrial()` called |
| `Central: app/Services/BillingService.php` | 32, 683, 1043 | `ProductProvisioner` injected; called on payment; **not** called in `provisionTrial()` |
| `Central: app/Http/Controllers/Admin/TenantApiController.php` | 143–145, 195–197 | The two admin call sites — why the manual Save worked |
| `Central: app/Services/ProductProvisioner.php` | 25–115 | `ensureFor()`: cloud+trial guard, slug fallback, idempotency, fail-soft `try/catch` |
| `Central: routes/web.php` | 31 | `POST /client/signup/verify` → `signupVerify` |
| `Product: app/Http/Controllers/Api/ProvisionController.php` | — | Receives the call; `Company::create` with `uniqueSlug()`; returns `console_url` |
| `Product: database/migrations/2026_08_04_000100_add_slug_to_companies.php` | — | `companies.slug`, nullable + unique |

## Appendix B — environment notes for the team

- **Live working copies:** Central `C:\laragon\www\smartept-central` → `ametecsindia/SmartEPT_central`;
  Product `C:\laragon\www\smartept` → `ametecsindia/SmartEPT_Admin`. Both are git clones on `main`.
- **Do not use** `Documents\Claude\Projects\SmartEPT` as a source — it is a snapshot with no `.git`,
  roughly a month behind, and its `central/` copy does not even contain `ProductProvisioner.php`.
- **Baseline for this work:** Central `origin/main` @ `97e4b52` ("17-08-2026 update 1").
- The commit was authored in this session but **could not be pushed** from it — the sandbox git
  proxy declines credentials for repositories not attached as session sources. The push is a
  human step (§7).

## Appendix C — deliverables from this session

| File | Location |
|---|---|
| Patched source | `app/Http/Controllers/Client/AuthController.php` |
| Commit message | `storage/app/trial-console-fix.commit.txt` |
| Standalone patch | `trial-console-fix.patch` (delivered in chat) |
| Root-cause report | `docs/SmartEPT-Trial-Console-Slug-RCA-2026-08-17.md` + `.pdf` |
| This session report | `docs/SmartEPT-Session-Report-2026-08-17.md` + `.pdf` |
