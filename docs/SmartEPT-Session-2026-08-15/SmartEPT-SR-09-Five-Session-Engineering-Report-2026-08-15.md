# Five-Session Engineering Report

Sessions of 11–14 August 2026 · Product, Central and Agent
Version 1.0

---

## 1. Executive Summary

**Read this first to understand where SmartEPT stands after the workstation change,
what the last five sessions delivered, and what is waiting on you.**

Between 11 and 14 August 2026, five working sessions delivered thirty-eight commits
across the two SmartEPT repositories. The work falls into four themes: commercial
pricing became progressive and custom-quotable; licensing moved from a single-tenant
model to per-tenant with real seat enforcement; a long chain of live faults was
root-caused and closed; and the first on-premise client package was built.

Three findings deserve your attention before anything else.

**Everything is committed and pushed.** Verified against both repositories on
15 August. Central sits at `2cf456c`, Product at `5d62cb9`, both identical to their
remotes, both with clean working trees. The project memory said otherwise; it was
written mid-session and was wrong. That is corrected.

**None of it is live.** The 14 August licence work — seat caps, expiry blocking,
trial supersession — exists in the repository but has not been migrated or deployed.
Until it is, no client is affected by any of it.

**The agent your clients download is six versions old.** They are receiving version
0.5.0, built 20 July. Version 0.11.0 was built on 25 July and never published. The
agent has no auto-updater, so every correction reaches a client only through a manual
reinstall. This is the single item on the outstanding list with direct customer impact
today.

Two decisions are waiting on you and are set out in section 13. One concerns whether
the super admin should be exempt from licence blocking on the shared cloud server. The
other concerns what happens to Ametecs' own company when the per-tenant licensing
migration runs on live and the installation licence slot empties.

## 2. Purpose, Scope and Method

This report reconstructs the five working sessions of 11, 12, 13 (day), 13 (evening)
and 14 August 2026, and states the verified condition of both source repositories as
at 15 August 2026.

It was prepared after the development workstation was replaced. The new machine
carries the same working trees and the same project memory as the old one, but the
running knowledge of what had been finished, what had been pushed and what was still
waiting did not travel with it. This document restores that.

Two categories of statement appear below and they are deliberately kept apart.
**Recorded** means the session that did the work wrote it down. **Verified** means it
was checked against the repository on 15 August 2026 and found to be true. Where the
two disagree, the disagreement is stated rather than quietly resolved — section 12
contains one such case, and it is the reason section 1 opens with the push status.

The verification method, for the record: the Git index of each repository was read
directly and every tracked entry compared against the file on disk for size and
modification time, and local branch references compared against remote-tracking
references. No shell was available on the workstation, so this was done by reading the
repository's own metadata over the file bridge.

## 3. Session S1 — 11 August 2026

### 3.1 Progressive lifetime pricing

On-Premise lifetime pricing changed from flat per-band prices to milestone pricing
with straight-line interpolation. A band's stated price is now the price **at that
band's maximum user count**. A customer between two milestones pays the interpolated
figure rather than the higher band price.

Your configured bands are: 5–15 at ₹15,000 · 16–30 at ₹25,000 · 31–50 at ₹40,000 ·
51–100 at ₹60,000 · 101–150 at ₹75,000 · 151–200 at ₹90,000.

**Worked example — a 41-user licence.** Forty-one users sits between the 30-user
milestone (₹25,000) and the 50-user milestone (₹40,000). The gap is ₹15,000 across
20 users, so ₹750 per user. Eleven users past the milestone gives
₹25,000 + (11 × ₹750) = **₹33,250**. Under the old flat model the same customer paid
the full ₹40,000. AMC at eighteen per cent is charged on the interpolated figure:
18% of ₹33,250 = **₹5,985 per year**, not ₹7,200.

Prices inside a band are therefore lower than before by design. If the economics need
adjusting, the milestones themselves are edited in Super Admin — the interpolation
takes care of everything between them.

Three protections were built in. The first band is a flat minimum package and is never
reduced below its price. Below the first band's minimum the system refuses the order
with a message naming the minimum capacity. An open-ended top band means Custom Quote
with the price stored as NULL, and `BillingService::createOrder` throws rather than
ever writing a zero-rupee lifetime licence.

The interpolation is mirrored in four places that must agree: the pricing engine, the
`/buy` page, the client authentication screen, and the landing page widget. The landing
page is a static file rebuilt from the CMS, so `php artisan landing:import` must be run
or a later CMS publish will silently revert the fix.

### 3.2 The seeded login defect

No seeded login had ever worked. The seeder applied `Hash::make` to a field that
already carried Laravel's `hashed` cast, so every password it wrote was hashed twice.
The defect is invisible in testing because it presents as an ordinary "credentials do
not match" rejection rather than as an error.

Fixing the code does not repair rows already written. Existing accounts must be
unlocked through tinker using `forceFill()->save()` — never `where()->update()`, which
bypasses the cast and reintroduces the problem. Every seeded password should be treated
as having been effectively unset, and changed accordingly.

### 3.3 Client-wise SMTP and password recovery

Mail now resolves in a defined order: the company's own SMTP settings first, then the
global relay, then the environment file. Console forgot-password by email OTP was added
for all roles. On a fresh installation with no mail configured, OTP codes land in the
application log rather than an inbox, which is the fallback route when an administrator
is locked out.

### 3.4 Live server triage

Four faults on the production server were found and closed.

The licence signing keys existed only on the development machine, having been
deliberately excluded from version control. On your explicit instruction they were
committed to the repository. **The repository must therefore remain private
permanently** — this is recorded in the licensing standard as an owner override.

The hosted console link was unset, so cloud provisioning had nowhere to point. The
Interakt WhatsApp key was supplied and configured.

The hourly dunning job had been failing twice every hour. It writes a tenant status of
`purged`, but the column was an ENUM that did not include that value, so the write
failed and the job died. This was the third enum failure of its kind. Two more followed
within the sessions covered by this report; section 14 collects them.

### 3.5 Commercial and administrative

Razorpay verified smartept.com on 11 August. The remediation that led to verification —
subscription framing rather than rental, consent-framed copy, and the legal pages — was
applied and committed.

Super-admin-only deletion was added for quotations and unpaid orders, permitted only
where no ledger entry and no licence exist, and for invoices with a financial-year gap
warning. **Money-bearing orders are never deletable.** The coupon box on the buy page is
now hidden unless a coupon is actually live. A sweep removed trademark and registered
symbols from the product, per the standing rule that nothing is registered yet and only
the copyright line is permitted.

## 4. Session S2 — 12 August 2026

### 4.1 Why the super admin saw another company's licence

You reported that the super admin console was displaying Khan Incorporation's licence.
The cause was structural rather than cosmetic.

The `installation_licenses` table held a **single row per installation**. That is correct
for a client hosting SmartEPT on their own server. It is wrong for the cloud
installation, which hosts several companies behind one application. Khan's key occupied
the only slot, so the entire installation was running on Khan's twenty-five seats, and
all installation storage was being reported to Central under Khan's key.

The model was corrected and the concept fixed in your words — *"central is the super
admin, product have multiple tenants"*:

- **Central** is the business super admin and the sole commercial truth: orders,
  billing, licences.
- The **Product super admin** is a host operator. It performs cross-tenant operations
  and owns no licence of its own.
- **Each cloud tenant carries its own licence row.** The installation slot remains, but
  now serves only companies that are not cloud-hosted.

A Tenants screen was added at the top of the navigation for host operations, showing per
company: licence health, devices bound against seats, users, employees, storage used
against quota with a percentage, last heartbeat, and provisioning status.

**One consequence needs a decision before the live migration runs.** When the migration
moves the installation key to Khan, the installation slot empties. Companies that are
not cloud-hosted — including Ametecs' own — then begin a fresh seven-day evaluation,
after which their agents block. This is covered in section 13.

### 4.2 Licence change flows

A survey of the code against your question — *"upgrade, downgrade, seats and billing not
found anywhere; how do I reissue a licence file"* — found most of it genuinely absent.

Cloud pro-rata upgrade existed, but only in the client portal, behind a card that was
hidden. Administrators had no billed upgrade at all, only the ability to silently edit a
device limit without raising an invoice. Downgrade had no mechanism whatsoever —
"reductions at renewal" was a comment in the source and nothing more, and renewals always
billed the current size. Perpetual upgrades were hard-blocked. Licence file reissue was
administrator-only.

Four policies were settled with you and built in a single commit.

**Perpetual licences upgrade but never downgrade, and are never refunded.** Once
purchased, the capacity is owned.

**A perpetual upgrade is priced as the difference between interpolated lifetime prices.**
Worked example, the one used to validate the build: a customer on a 15-user perpetual
licence upgrading to 30 users. The 15-user price is ₹15,000 and the 30-user price is
₹25,000, so the upgrade order is ₹10,000 plus eighteen per cent GST of ₹1,800 —
**₹11,800 payable**. It is raised as a line of type `upgrade` on the same GST invoice
series, so the financial-year numbering stays continuous. AMC re-bases automatically:
the next AMC bill is calculated from 30 users, not 15.

**Cloud downgrades apply at renewal only, and never below the count of active devices.**
Both the client and an administrator can schedule one; mid-period reductions remain
impossible. The scheduled figure is billed at the next renewal and then cleared.

**Clients may re-download their own licence file, but only against the fingerprint
already stored.** Moving a licence to a different machine remains an administrative
action through Shift Machine. This is a security boundary, not a convenience limit.

### 4.3 The test suite had been broken since 19 July

A test run returning "5 failed, 0 assertions" led to three stacked causes, fixed from
the bottom up.

A July migration used raw MySQL `ALTER TABLE` with no driver guard, so it could not run
under the test database. A machine-level `DB_CONNECTION` environment variable on the
workstation was overriding phpunit's own configuration, which does not take precedence
over operating-system variables unless forced. Underneath both, `config/database.php`
hard-coded the default connection to MySQL — a fix made on 19 July against a rogue
environment variable — so phpunit's SQLite setting could never apply at all.

The consequence is worth stating plainly. **Every Product feature test had been failing
silently since 19 July**, and the suite had not been run in the interval, so nobody saw
it. Once revived, the tests immediately caught a genuine defect: a 500 error in
`ProvisionController` for any caller omitting an optional field. Central always sends
that field, so production never saw it — but a client's own integration would have.

### 4.4 The agent's HTTP 500

A device registration was failing with a server error. The log named a duplicate-key
violation on the device identifier. The device had registered earlier under a **different
company**, and the lookup was subject to company scoping, so it could not see the
existing row and attempted an insert.

The symptom pointed the wrong way entirely — "the main console works, the branded URLs
do not" — which was a coincidence of which accounts were used, not a URL problem. The
fix removes scoping from lookups on globally-unique columns, re-points the row to the new
company, and audits the transfer.

### 4.5 Search visibility and documentation

The sitemap was emitting insecure URLs because it was built from a configuration value
that had drifted; it now forces the secure scheme regardless. Canonical tags were added
to seven public pages, and workflow pages marked no-index without blocking crawlers
outright. The GA4 tag was absent because publishing rendered from an empty CMS table;
publishing now repairs itself first, and tracking tags extend to the legal pages and the
buy page.

Documents SR-04 through SR-07, the MR-01 consolidated report, the MR-02 120-case test
suite, the OPS-01 recovery guide, and the redesigned six-page WhatsApp campaign report
with its sixty-second status video were produced in this session.

## 5. Session S3 — 13 August 2026 (day)

Six faults were root-caused and closed. They are listed together because they formed a
chain: each one was masking the next.

**OTP codes were being returned in API responses.** A test-mode convenience was gated on
debug mode, and production was running with debug mode enabled. Both the leak and the
setting were corrected.

**Cloud provisioning discarded the console's temporary password** instead of sending it,
so every cloud client was locked out of their own console at the moment of provisioning.
It is now emailed.

**Shared PHP workers were leaking the database name between the two applications.** Both
applications run under the same web server, and `putenv` writes into the process
environment, which the next request on that worker inherits. This is the true root cause
behind the July wrong-database incidents, the agent and screenshot faults attributed to
them, and the folklore about restarting Laragon that grew up around them. It also
explains a licence fingerprint that had been bound during the affected period. Disabling
`putenv` in both applications closes it at the root.

**The agent's 405 error** came from a redirect that Windows followed as a GET request.
**Licence validation failed for the same reason** on a different redirect, from insecure
to secure. Both are now handled strictly rather than by redirect.

**Console installations had no mail configuration at all**, so they now inherit Central's
relay at provisioning and adopt it as their global relay only if none is set.

### 5.1 The on-premise client package

Built in the same session. The SmartPRS2 licence logic was ported across unchanged, as
you asked — the machine fingerprint is cached and persisted so a flaky reading cannot
invalidate a licence, floating licence files with no fingerprint are refused outright,
the enforcement gate fails soft so that any internal error lets traffic through while
real verdicts still block, and an activation page sits before login so a client can
upload their licence file without signing in first.

The installer kit covers three platforms: Windows through Laragon, Linux with a systemd
service, and macOS with a launch agent, all sharing one helper. The package rebuild
script produces a versioned archive that the portal serves automatically.

## 6. Session S4 — 13 August 2026 (evening)

### 6.1 Custom pricing

Administrators may now set a custom price, a custom setup charge and a custom AMC on
orders, quotations and prospect quotes, at any user count. The custom setup charge
replaces the calculated fee and is forced onto the order even where setup has already
been paid; the custom AMC is added as its own line. Access is limited to super admins and
to staff holding billing rights.

### 6.2 The request queue

Beyond the last priced band — above 200 users on your live configuration — the buy page
no longer quotes a price. It captures a **request**: the customer's details, their
requirement and their notes, with **no price shown at any point**. The request lands in
the orders queue badged by origin, so you can see at a glance whether it came from a
client or from an administrator. From there it is edited, priced, and converted in place,
which sends the quotation with print and payment links.

Requests take an order number but **no quotation number until conversion**. This keeps the
financial-year quotation series unbroken — an abandoned request never consumes a number.

### 6.3 Decisions recorded

**Perpetual terms remain lifetime.** The "minimum fifteen years" line is a commercial
talking point for conversations with clients and must not become an expiry date in the
system. No change was made.

**Requests are fully editable; converted numbered quotations are not.** The existing rule
stands for anything numbered — delete and re-create, never edit. A request is editable
precisely because it is pre-number and pre-money.

### 6.4 Test results

The session ended with the Central suite at **69 passed, 391 assertions, in 14.3 seconds**.
Two rounds of failure preceded that. The first produced six failures: four from SQLite
enforcing enum constraints that the MySQL-only migrations had never addressed, and two
from a security change made earlier the same day that removed OTPs from API responses,
which the tests had been reading. The second produced one, and it was a genuine defect —
perpetual quotes were adding a setup fee even when setup had been explicitly declined,
both from the administrator's "Include setup: No" and from the customer unticking the box.
The preview and the charge disagreed. It is fixed.

## 7. Session S5 — 14 August 2026

Run by **Altaf**, working from four findings you reported.

### 7.1 Trial licences stayed active after payment

A customer who subscribed kept an active trial alongside the paid licence. Licences now
carry a `superseded` status, and issuing a paid licence closes the trial automatically —
on first purchase, and after renewals and upgrades. Existing clients already in that
state are corrected by a backfill in the migration.

The review then closed every route by which a superseded trial could come back to life:
extending a trial no longer touches one, resuming is refused server-side while a paid
licence is live, the interface shows "closed by paid licence" instead of a Reactivate
button, and the purge sweep includes them.

### 7.2 No action beside status

The client card showed licence status but offered nothing to do about it. Suspend,
Reactivate and Revoke now sit beside it. Revoke was also surfaced in the licences list,
where the underlying action already existed but had no button.

### 7.3 Central showed zero of twenty-five seats for a live client

Consoles never reported their counts. They now send user, employee and device counts on
the daily check-in. Central stores them and shows a Users column in both the licences
list and the client card, marked red when over the limit. The counts are optional in the
payload, so consoles running older builds continue to validate normally.

### 7.4 Seat count was a label, not a rule

The number on the licence was displayed but never enforced. A new seat service is now the
single authority on whether a seat is free.

**One seat means one monitored person on one PC.** Active employees and employee-role
logins consume seats; administrator, HR and manager logins are free.

An adversarial review of the first implementation found six bypasses, and **every one of
them was an update path** rather than a creation path: rebinding a device, changing a role
to employee, re-enabling a disabled employee login, moving a relieved employee back to
active, restoring an archived employee, and a bulk import whose cap was dead code because
a closure had not captured the variables it needed. All are now guarded.

Counting is deliberately local. The device activation call fails open when Central is
unreachable, which is precisely how an offline server could otherwise bind machines
indefinitely. The cap is **not retrospective**: clients already over the count keep
everyone they have, they simply cannot add more.

### 7.5 Expired licences did not stop the client working

Enforcement had been applied only to agent routes. It now covers the whole authenticated
API, with a deliberate allow-list. Anyone may still fetch their own identity, log out,
refresh a token and change a password. A super admin or company administrator may
additionally reach the licence screens, licence import, diagnostics and logs — that is
the rescue route, chosen as "hard block, administrator-only rescue".

Non-administrative sign-in is refused when the licence is dead, and the interface shows a
single full-screen licence wall rather than every screen failing separately with its own
error.

### 7.6 The IIS incident

A new client — the first hosted on IIS rather than Laragon — could open every screen and
create records, but **every Edit and Save returned HTTP 405**, and Delete returned 405 or
404.

IIS ships with WebDAV enabled by default. WebDAV registers a handler for PUT, DELETE and
related methods, and answers 405 **before** URL Rewrite or PHP ever see the request. GET
and POST pass through untouched. That is exactly the reported symptom: everything works
except saving an edit.

**Nothing was wrong with SmartEPT.** The routes exist as PUT, the console sends a real
PUT, the HTTPS middleware returns a refusal rather than a redirect, and no route cache
ships in the build.

The fix removes both the WebDAV module and its handler in the site configuration. **Both
lines are required** — removing only one leaves the behaviour unchanged. The trailing
slash redirect was also restricted to GET and HEAD, because a redirect on a PUT is retried
by the browser as a GET, producing another 405. The installation guide gained the step,
the one-line command that proves the diagnosis in a single request, and a warning about
the site root.

Two related observations were made at that client. The package had been unzipped to a
location the guide says not to use, which is harmless only if the site's physical path
points at the public subfolder — if the environment file downloads over the web, it does
not, and that must be corrected before go-live. And the package rebuild script does not
exclude one cache directory, so if configuration caching is run before a package is built,
development configuration including database credentials ships to the client. This was
raised and has not been fixed.

### 7.7 The agent is six versions behind

Clients are downloading **version 0.5.0, built 20 July** — the newest build published to
Central's downloads. **Version 0.11.0 was built on 25 July and never published.**

The branded-slug fix was committed and the version bumped to 0.12.0, but the build and the
publish, to both the local and the live download sources, have not been done.

The agent has **no auto-updater**. Every update reaches a client only through a manual
reinstall, and rollout can be confirmed only by checking reported application versions in
the console's device list. The full procedure is in the RU-01 runbook produced the same
session.

### 7.8 Documentation

Two documents were produced: **RU-01 Release and Update Runbook**, fourteen pages, the
lasting reference for how any build reaches a client; and **SR-08 Session Report**, ten
pages, covering that session. Both carry an internal part and a client-facing part that
can be sent on its own.

## 8. Commit Register

**Central — smartept-central**

| Date | Commits |
|---|---|
| 11 Aug | `e6f5839` `d8279a5` `2d9d8a6` `385836a` `73888fc` `72c0059` `a03f228` `6360796` |
| 12 Aug | `3ce729f` `706b642` `44f71d2` `2fa3fab` `76ddf09` `face8e7` `a055ed3` `558417c` `b6be8c8` |
| 13 Aug | `d768719` `9a2e3f5` `e229a7b` `2c0aa61` `19a1b09` `00d929a` `ef29cd4` `34a3e77` `16640d1` |
| 14 Aug | `11b2d17` `3ad8a08` `2cf456c` |

**Product — smartept**

| Date | Commits |
|---|---|
| 11 Aug | `22ed12e` |
| 12 Aug | `1f6be35` `1fcb7f7` `01174cc` `aef81df` `408785c` `2ca7dec` `389b8a0` `979ccba` `1c12fb0` `1c4cdcb` |
| 13 Aug | `0b2e4e5` `9d1bae5` `ec1f78f` `63fc4bd` `f5e2d2b` |
| 14 Aug | `9a1854e` `436f0ef` `5d62cb9` |

Thirty-eight commits in total across the five sessions.

## 9. Verified Repository State — 15 August 2026

| Repository | Local main | Remote | Working tree |
|---|---|---|---|
| smartept-central | `2cf456c` | identical | clean |
| smartept | `5d62cb9` | identical | one untracked file |

The last push to Central was made on 14 August at 19:53, and to Product at 19:54. All
283 tracked files in Central and all 348 in Product match the index in both size and
modification time. Nothing on disk is newer than the index in either repository. The
single untracked file is a build log in the Product deployment folder.

**This corrects the project memory.** The 14 August session note records that nothing had
been pushed, because it was written while the session was still in progress. Both
repositories were in fact pushed the same evening. The memory has been amended so that the
next session does not chase work already done.

## 10. Repository Hygiene Findings

Verification surfaced three problems unrelated to the sessions' subject matter, recorded
here so they are not lost.

**Central is carrying 5.4 MB of development residue in version control** — twenty-two
files. Eleven are timestamped backups of the landing page, about 4.3 MB between them.
Four are artefacts created by the file bridge. The remainder are backup copies of a
template, a renderer and a seeder, plus two test output files.

The reason they keep accumulating is that **Central's ignore file has no rule for bridge
artefacts, although the Product repository does.** Adding that one rule stops the
recurrence. Removing the files already tracked is a separate and optional decision, and
one I would not take without asking.

**The Product repository tracks four archive files totalling 65 MB** in a cloud-sync
folder. These are almost certainly not meant to be under version control. Removing them
from the working tree does not shrink the repository, because the history retains them;
that would need history rewriting, which is not proposed here.

**The client packaging script omits one cache directory from its exclusions**, described in
section 7.6. Raised 14 August, still open.

## 11. What Changed

| Area | Before 11 August | After 14 August |
|---|---|---|
| Lifetime pricing | Flat price per band | Milestone prices, interpolated between |
| Above top band | No path | Request queue, priced by you, converted in place |
| Perpetual upgrade | Blocked | Priced as interpolated difference, GST invoiced |
| Cloud downgrade | No mechanism | Scheduled at renewal, floored at active devices |
| Licence per install | One row for all companies | One row per cloud tenant |
| Seat count | Displayed only | Enforced on every create and update path |
| Expired licence | Agent routes only | Whole API, with administrator rescue |
| Trial after payment | Stayed active | Automatically superseded |
| Console seat reporting | None | Daily counts, shown and flagged |
| On-premise install | Not packaged | Three-platform kit with activation page |
| Product test suite | Silently failing since 19 July | Running |

## 12. What Is Pending

| # | Action | State |
|---|---|---|
| 1 | Restart Laragon in both applications to clear the opcode cache | Not done |
| 2 | Run the Central migration, including the trial backfill | Not confirmed |
| 3 | Run the test suite in both repositories | Disputed — see below |
| 4 | Push both repositories | **Done — verified 15 August** |
| 5 | Deploy live: pull and migrate Central, pull Product | Not confirmed |
| 6 | Re-check the affected client's trial status after deployment | Blocked on 5 |
| 7 | Build and publish agent 0.12.0, locally and to the live source | Not done |
| 8 | Correct the mislabelled perpetual licence and remove the wrong order | Not done |
| 9 | Rebuild the client package; test one Windows and one Linux install | Not done |
| 10 | Apply code protection before wide distribution | Not done |
| 11 | Add the bridge-artefact ignore rule to Central | Newly raised |
| 12 | Fix the packaging script's cache exclusion | Raised 14 Aug, open |

**Items 1, 2, 3 and 5 are the deployment chain for the 14 August licence work. Until they
are complete, seat enforcement and expiry blocking exist in the repository but are not in
effect for any client.**

On item 3, the disagreement noted in section 2: the 14 August session recorded that the
test suite was never run, because that session had no PHP available. However, Central's
phpunit result cache was written on 14 August at 18:05, which means a run happened. The
cache is excluded from version control, so its contents cannot be inspected and no
conclusion should be drawn about what passed. **Re-run rather than assume.**

On item 8, for clarity: the "pro-rata on a perpetual licence" you reported is a data
problem, not a code problem. That licence is stored as a subscription with an expiry date
in 2029. The correction is to edit the licence to kind Perpetual with a blank expiry,
delete the incorrect unpaid upgrade order, and redo the upgrade.

## 13. Decisions Needed From You

**D1 — The super admin exemption from licence blocking.**
The super admin is exempt from the console-wide block only on a shared cloud installation
that carries no licence key of its own. The reasoning: on the shared box the super admin
is an Ametecs operator, and blocking that account would remove our own access to the
tenant list; on a client's own server the super admin is the client's owner, and receives
only the rescue routes. This was chosen during the 14 August session and flagged for your
approval. **It has not been approved.**

**D2 — The empty installation slot after migration.**
When the per-tenant licensing migration runs on live, the installation-level slot empties
as the key moves to its rightful company. Companies that are not cloud-hosted — including
Ametecs' own — then begin a seven-day evaluation, after which their agents block. This
needs either a licence issued deliberately, or a conscious acceptance, **before** the
migration runs.

**D3 — Repository cleanup (optional, low urgency).**
Whether to remove the 22 tracked residue files from Central and the four archives from
Product. The ignore rule alone stops new ones accumulating; removing the existing files is
a separate change to a repository that is currently clean, and I would not do it without
your word.

## 14. Lessons Recorded

**Enum columns have now failed five times.** Three of the five fell within these sessions.
Every recurrence has the same shape: code writes a value the column does not permit, and
the failure surfaces far away from the change that caused it. New status values belong in
VARCHAR columns, not enums.

**A migration containing raw SQL must carry a driver guard.** Two separate incidents trace
back to this. Without the guard the migration cannot run under the test database, and the
whole suite fails at setup rather than at any individual assertion.

**"N failed, 0 assertions" means the setup failed, not the tests.** Recognising that
distinction shortened a debugging session that had already consumed most of an evening.

**A hard-coded configuration value defeats the test environment silently.** The Product
suite was broken for three weeks with no visible signal at all. When environment overrides
appear not to work, read the configuration files before anything else.

**When adding a cap, guard the update paths, not only creation.** The adversarial review of
the seat cap found six bypasses and every one was an update path.

**Add the cast in the same commit as the column.** Three missing datetime casts produced a
live fault when a comparison ran against a string value.

**A lookup on a globally-unique column must ignore company scoping.** A scoped lookup
misses rows belonging to other companies, and the resulting insert fails on the unique
index as a server error rather than as the conflict it actually is.

**A session note written before the session ends is a snapshot, not a verdict.** The push
status in this report is the case in point. Repository state should be verified from the
repository at the start of the next session, not carried forward from a note.

---

*Prepared for Ejaz Hussain, Managing Director, M/s. Ametecs India Private Limited,
Hyderabad. Sources: SmartEPT project memory, sessions of 11–14 August 2026; direct
inspection of both repositories, 15 August 2026.*
