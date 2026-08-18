# Trial signups get no branded console — root cause and fix

**SmartEPT Central · 17 August 2026**
Reported by Ejaz Hussain · Investigated against `C:\laragon\www\smartept-central` @ `origin/main` `97e4b52`

---

## 1. Summary

A client who signed up for the 7-day trial on the landing page was created correctly, was
logged into the client portal correctly, and was issued a trial licence correctly — but had
**no branded console at `/<slug>` on the product app**. The slug only appeared after an admin
opened that tenant in `/admin` and pressed Save.

The cause is not slug generation. Slug generation works. The cause is that **the trial signup
path never asks the product app to provision anything.** `ProductProvisioner::ensureFor()` — the
one method in Central that calls the product's `/api/provision` — had three callers, and a
self-service trial hits none of them.

Fixed by calling `ensureFor()` from `Client\AuthController::signupVerify()` after its transaction
commits. One call, eleven lines including the comment. Committed; the push to GitHub is still
outstanding (see §8).

---

## 2. Symptom

| Behaviour | Detail |
|---|---|
| **Works** | Trial signup completes, OTP verifies, tenant row created, portal login works, trial licence issued, sales lead + WhatsApp alert fire |
| **Broken** | No `companies` row on the product app → no `slug` → `admin.smartept.com/<xyz>` 404s. Central's `tenants.console_url` stays null, so "Open my Console" has nothing to point at |
| **Workaround in use** | Admin opens the tenant in `/admin` → Save. Slug appears immediately |

The workaround being reliable is itself the clue: the same tenant row, unchanged, provisions fine
a minute later. So nothing about the tenant's *data* is wrong — only the code path that created it.

---

## 3. How a slug is supposed to come into existence

The slug lives on the **product** side, not on Central.

```
Central                                   Product (SmartEPT_Admin)
───────────────────────────────────────   ─────────────────────────────────────────
ProductProvisioner::ensureFor($tenant)
  └─ POST {product}/api/provision   ────►  Api\ProvisionController::provision()
     X-Provision-Secret: <shared>            ├─ Company::create([... 'slug' => uniqueSlug()])
     { external_tenant_id, company_name,     ├─ COMPANY_ADMIN user + temp password
       admin_email, slug, licence_key,       └─ returns { slug, console_url, temp_password }
       storage_quota_mb, central_url,
       mail }
  ◄────────────────────────────────────
  └─ saves tenants.console_url
  └─ emails the temp password to the client
```

Every part of this chain was healthy. It was simply never invoked for a trial.

---

## 4. Root cause

`ProductProvisioner::ensureFor()` had **exactly three call sites** in Central:

| # | Caller | Fires when | Reached by a self-service trial? |
|---|---|---|---|
| 1 | `Admin\TenantApiController::store()` L143–145 | An admin creates a tenant in `/admin` (including admin-created trials) | ✗ — the client created it, not an admin |
| 2 | `Admin\TenantApiController::update()` L195–197 | An admin saves an existing tenant | ✗ — until someone manually does it. **This is the workaround** |
| 3 | `BillingService::recordPayment()` L683 | A payment is recorded against an order | ✗ — a free trial takes no payment, so no order is ever settled |

The self-service path is:

```
POST /client/signup/verify
  └─ Client\AuthController::signupVerify()          AuthController.php L67
       └─ DB::transaction:                          L108
            ├─ Tenant::create([...                  L109
            │     'deployment' => 'cloud',          L116  ✓ correct
            │     'status'     => 'trial',          L117  ✓ correct
            │  ])
            ├─ BillingService::provisionTrial()     L123
            │     └─ sets trial_ends_at / purge_after
            │     └─ LicenceService::issue()
            │     └─ (never touches $this->console) BillingService.php L1043
            └─ TenantUser::create()                 L125
       └─ login, lead capture, WhatsApp, emails
       └─ return
```

`provisionTrial()` is the natural place a reader would *expect* the console to be stood up, and it
is the one thing in the chain that does not do it. `BillingService` even holds a
`ProductProvisioner $console` in its constructor (L32) — it just isn't used on the trial branch.

**So: no admin action, no payment → `ensureFor()` never ran → no company, no slug.**

---

## 5. What was *not* the cause

Ruling these out matters, because each is a plausible-looking suspect that would have sent a fix
in the wrong direction:

- **The `trial` status is not filtered out.** `ensureFor()`'s guard is
  `deployment === 'cloud' && status ∈ ['active','trial']`. Trials are explicitly allowed, with a
  comment saying so.
- **`deployment` is set correctly.** Signup hardcodes `'cloud'` (L116, the 6-Aug-2026 Phase 0 fix).
- **An empty `console_slug` is not fatal.** `ensureFor()` falls back to
  `Str::slug($tenant->company_name, '')`, and the product's `uniqueSlug()` de-duplicates and
  sanitises whatever it receives.
- **`ensureFor()` is not "once only".** It is deliberately re-runnable and idempotent on the
  product side (keyed on `external_tenant_id`), which is exactly why the admin re-save works.
- **Not a settings problem.** If `product_base_url` or `product_provision_secret` were blank,
  the admin re-save would fail too — it doesn't.

The gate was fine. It was never reached.

---

## 6. The fix

`app/Http/Controllers/Client/AuthController.php`, immediately after the signup transaction commits
(now L136–145):

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

### Why there, and not inside `provisionTrial()`

Putting it in `BillingService::provisionTrial()` is the tidier "single source of truth" option and
would cover any future trial path automatically. It was rejected for one reason: `provisionTrial()`
is called *inside* `DB::transaction` at AuthController L108, and `ensureFor()` makes an HTTP call
with an 8-second timeout. Holding a write transaction open across a network round trip to another
application — one that may be down, slow, or behind a firewall — is a bad trade for the tidiness.
Post-commit placement gets the same result with none of that exposure.

That choice is worth revisiting if a second trial path is ever added; at that point moving the call
into `provisionTrial()` and refactoring `signupVerify()` to commit before provisioning becomes the
better shape.

### Why it is safe

- **Idempotent.** Re-running against an existing tenant re-uses the same `Company` and
  `COMPANY_ADMIN` (`external_tenant_id` is the key) and only syncs the slug/storage.
- **Fail-soft.** `ensureFor()` wraps everything in `try/catch` and logs; a product outage cannot
  fail or roll back a signup. The client still gets a working trial and portal.
- **No double-provisioning.** The admin path (`TenantApiController::store()`) still calls it for
  admin-created tenants; a duplicate call is a no-op by the same idempotency.
- **The temp-password email now fires at the right moment.** On first provision the product returns
  a `temp_password`, which `ensureFor()` emails to the client. Because the call now happens during
  the signup request, the client gets their console credentials as part of signing up rather than
  whenever an admin happens to press Save.

---

## 7. Verification

**Applied and checked:**

- `php -l` clean on the patched file.
- The file on disk at `C:\laragon\www\smartept-central` was re-read after writing and matches the
  intended content byte-for-byte.
- Diffed against `origin/main`: the only difference in that file is the 11 added lines — confirming
  the change sits on a clean base and no unrelated local edits were swept in.

**Still to do on your side — the functional test:**

1. Sign up a fresh trial from the landing page with an email not already in `tenant_users`.
2. Tail `storage/logs/laravel.log` on Central. Expect:
   `Cloud console provisioned {"tenant":N,"console_url":"https://.../<slug>"}`
   and `Console credentials emailed to tenant owner`.
3. Confirm `tenants.console_url` is populated for that tenant, and that `/<slug>` loads on the
   product app.
4. Confirm the client received the console sign-in email with a temporary password, and that the
   product forces a password change on first sign-in (`must_change_password`).

**If step 2 instead logs** `Cloud console not provisioned — product URL/secret not set`, fill in
Central → Settings → SmartEPT Product (`product_base_url`, `product_provision_secret`) and retry.
`ensureFor()` returns silently in that case by design, so this failure is only ever visible in the log.

---

## 8. Open items

| # | Item | Status |
|---|---|---|
| 1 | **Push the commit.** The fix is committed but not pushed — the sandbox's git proxy refuses `ametecsindia/SmartEPT_central` (403, repo not in the session's authorized set), and there is no shell access to your machine. Commands are below | **Needs you** |
| 2 | **Backfill existing trials.** Every trial that signed up before this fix still has no console. Each one needs an admin Save, or a small artisan command | **Needs a decision** |
| 3 | **Functional test** per §7 | **Needs you** |
| 4 | Consider whether other cloud-tenant creation paths (prospect quotes, plan changes) have the same gap | Not audited — out of scope for this report |

### Item 1 — the push

The working file is already patched. The commit message is at
`storage/app/trial-console-fix.commit.txt` (Laravel gitignores `storage/`, so it stays out of the diff):

```
cd C:\laragon\www\smartept-central
git checkout -b fix/trial-console-provisioning
git add app/Http/Controllers/Client/AuthController.php
git commit -F storage/app/trial-console-fix.commit.txt
git push -u origin fix/trial-console-provisioning
```

Drop the `checkout -b` line to land it straight on `main`, as your recent commits do. The `git add`
is scoped to the single file on purpose — `git status` may show other work in progress.

A `trial-console-fix.patch` (`git format-patch`) was also delivered as a backup route:
`git checkout app/Http/Controllers/Client/AuthController.php` to revert, then `git am trial-console-fix.patch`.

### Item 2 — the backfill

Affected tenants are exactly:

```sql
SELECT id, company_name, email, status, created_at
FROM tenants
WHERE deployment = 'cloud'
  AND status IN ('active','trial')
  AND (console_url IS NULL OR console_url = '');
```

Run that first to see the size of the problem. If it's a handful, an admin Save on each is fine.
If it's more, an artisan command that loops that query calling `ensureFor()` on each tenant is
about fifteen lines and is safe to re-run — say the word and I'll write it.

Note that provisioning a backfilled tenant emails that client their console credentials, since it
will be a first provision. That is correct behaviour but it is outbound mail to real customers, so
it is worth doing deliberately rather than as a background sweep.

---

## Appendix — evidence trail

| File | Lines | What it shows |
|---|---|---|
| `Central: app/Http/Controllers/Client/AuthController.php` | 67, 108–134 | The trial signup path; tenant created cloud/trial; only `provisionTrial()` called |
| `Central: app/Services/BillingService.php` | 32, 683, 1043 | `ProductProvisioner` injected; called on payment; **not** called in `provisionTrial()` |
| `Central: app/Http/Controllers/Admin/TenantApiController.php` | 143–145, 195–197 | The two admin call sites — why the manual Save works |
| `Central: app/Services/ProductProvisioner.php` | 25–115 | `ensureFor()`: the cloud+trial guard, the slug fallback, idempotency, fail-soft `try/catch` |
| `Product: app/Http/Controllers/Api/ProvisionController.php` | — | Receives the call; `Company::create` with `uniqueSlug()`; returns `console_url` |
| `Product: database/migrations/2026_08_04_000100_add_slug_to_companies.php` | — | `companies.slug`, nullable + unique |
| `Central: routes/web.php` | 31 | `POST /client/signup/verify` → `signupVerify` |

**Repositories.** Central: `github.com/ametecsindia/SmartEPT_central`, working copy
`C:\laragon\www\smartept-central`. Product: `github.com/ametecsindia/SmartEPT_Admin`, working copy
`C:\laragon\www\smartept`. The snapshot in `Documents\Claude\Projects\SmartEPT` is a stale copy
(no `.git`, ~1 month behind, and its `central/` has no `ProductProvisioner` at all) and was not used
as a source for this work.
