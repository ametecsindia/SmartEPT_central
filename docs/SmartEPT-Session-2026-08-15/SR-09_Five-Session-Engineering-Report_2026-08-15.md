# Five-Session Engineering Report

Sessions of 11–14 August 2026 · SmartEPT Product, Central and Agent
Version 1.0

---

## 1. Purpose and Scope

This report reconstructs the last five working sessions on SmartEPT — 11, 12,
13 (day), 13 (evening) and 14 August 2026 — and states the verified condition of
both source repositories as at 15 August 2026.

It was prepared after the development workstation was replaced. The new machine
carries the same working trees and the same project memory as the old one, but the
running knowledge of what had been finished, what had been pushed and what was still
waiting had not travelled with it. Everything below is drawn from the project memory
files and from direct inspection of the two Git repositories, not from recollection.

Two categories of statement appear in this document and they are deliberately kept
apart. **Recorded** means it was written down by the session that did the work.
**Verified** means it was checked against the repository on 15 August 2026 and found
to be true. Where the two disagree, the disagreement is called out rather than
silently resolved — section 9 contains one such case.

## 2. How to Read This Report

Sections 3 to 7 describe each session in turn: what was reported, what was found,
what was built. Section 8 is the consolidated commit register. Section 9 states the
verified repository condition. Section 10 records repository hygiene problems found
during that verification. Sections 11 and 12 are the two lists that matter for the
week ahead — work still outstanding, and decisions still awaiting the Managing
Director's sign-off. Section 13 collects the engineering lessons the five sessions
produced, because several of them are the kind that repeat if they are not written
down.

## 3. Session S1 — 11 August 2026

### 3.1 Progressive lifetime pricing

On-Premise lifetime pricing was changed from flat per-band prices to milestone
pricing with straight-line interpolation between milestones. A band's stated price
is now the price at that band's maximum user count; a customer between two
milestones pays the interpolated figure. The first band remains a flat minimum
package and is never reduced.

An open-ended top band means Custom Quote, with the price stored as NULL. A zero
rupee lifetime licence is now impossible — `BillingService::createOrder` throws
rather than accepting one. AMC is charged at eighteen per cent of the interpolated
price rather than the band price, a decision taken during the session.

The interpolation is mirrored in four places that must agree: the pricing engine,
the `/buy` page, the client authentication screen and the landing page widget. The
landing page is a static file rebuilt from the CMS, so `php artisan landing:import`
must be run or a later CMS publish will silently revert the fix.

### 3.2 The seeded login defect

No seeded login had ever worked. The seeder applied `Hash::make` to a field that
already carried Laravel's `hashed` cast, double-hashing every password it wrote.
The defect was invisible because it presents as an ordinary credential rejection.

Fixing the code does not repair the rows already written. Existing accounts must be
unlocked through tinker with `forceFill()->save()`, and every seeded password should
be treated as having been effectively unset and changed accordingly.

### 3.3 Client-wise SMTP and password recovery

Mail now resolves company SMTP first, then global SMTP, then the environment file.
Console forgot-password by email OTP was added for all roles.

### 3.4 Live server triage

Four faults were found on the production VPS and closed. The licence signing keys
existed only on the development machine, having been deliberately excluded from
version control; on the Managing Director's explicit instruction they were committed
to the repository, **which must therefore remain private permanently**. The hosted
console link was unset. The Interakt WhatsApp key was supplied. The hourly dunning
job had been failing twice an hour because `tenants.status` was an ENUM that did not
include the value `purged` the job was trying to write.

That ENUM failure was the third of its kind. Two more followed in the sessions
covered by this report.

### 3.5 Commercial

Razorpay verified smartept.com on 11 August. The remediation work that led to
verification — subscription framing, consent-framed copy and the legal pages — was
applied and committed. Super-admin-only deletion was added for quotations and unpaid
orders, with money-bearing orders never deletable.

## 4. Session S2 — 12 August 2026

### 4.1 Why the super admin saw another company's licence

The Managing Director reported that the super admin console at
admin.smartept.com/admin was displaying Khan Incorporation's licence. The cause was
structural rather than cosmetic. The `installation_licenses` table held a single row
per installation, but the cloud installation hosts several companies. Khan's key
occupied the only slot, so the entire installation was running on Khan's twenty-five
seats and reporting all installation storage under Khan's key.

The model was corrected and the concept fixed: Central is the business super admin
and the sole commercial truth; the Product super admin is a host operator who owns no
licence; each cloud tenant carries its own licence row. A Tenants screen was added
for cross-tenant operations.

One consequence needs a decision before the live migration runs. When the key moves
to Khan, the installation slot goes empty, and non-SaaS companies — including
Ametecs' own — begin a fresh seven-day evaluation, after which their agents block.
That must be licensed or accepted deliberately.

### 4.2 Licence change flows

A survey found that upgrade, downgrade and seat changes were largely absent. Cloud
pro-rata upgrade existed but only in the portal, behind a hidden card. Admin had no
billed upgrade at all, only silent limit edits. Downgrade had no mechanism —
"reductions at renewal" was a comment in the source, nothing more. Perpetual upgrades
were hard-blocked.

Four policies were settled and built. Perpetual licences upgrade but never downgrade
and are never refunded. A perpetual upgrade is priced as the difference between
interpolated lifetime prices, with AMC re-basing automatically. Cloud downgrades
apply at renewal only and never below the count of active devices. Clients may
re-download their own licence file, but only against the fingerprint already stored —
moving a licence to a different machine stays an administrative action.

### 4.3 The test suite had been broken since 19 July

A test run returning "5 failed, 0 assertions" led to three stacked causes. A July
migration used raw MySQL `ALTER TABLE` with no driver guard. A machine-level
`DB_CONNECTION` variable was overriding phpunit's configuration. Underneath both,
`config/database.php` hard-coded the default connection to MySQL, so phpunit's SQLite
setting could never take effect.

The consequence is worth stating plainly: **every Product feature test had been
failing silently since 19 July**, and nobody had run the suite in the interval. Once
revived, the tests immediately caught a genuine defect — a 500 error in
`ProvisionController` for any caller omitting an optional field.

### 4.4 Other work

The agent's HTTP 500 was traced to a global scope hiding a cross-company device row,
producing a duplicate-key error that presented as a server fault. SEO work covered
the sitemap, canonicals and the root cause of the GA4 tag not appearing. Documents
SR-04 through SR-07, the MR-01 consolidated report, the MR-02 120-case test suite,
the OPS-01 recovery guide and the redesigned WhatsApp campaign report were produced.

## 5. Session S3 — 13 August 2026 (day)

Six faults were root-caused and closed.

OTP codes were being returned in API responses because production was running with
debug mode enabled. Cloud provisioning discarded the console's temporary password
instead of sending it, locking out every cloud client. Shared PHP workers were
leaking the database name between the two applications through `putenv`, which
explains the July wrong-database incidents and the folklore about restarting Laragon
that grew up around them. The agent's 405 error came from a redirect the Windows
agent followed as a GET. Licence validation failed for the same reason on a different
redirect. Console installations had no mail configuration at all, so they now inherit
Central's SMTP relay at provisioning.

The on-premise client package was built in the same session: the SmartPRS2 licence
logic ported across unchanged — cached machine fingerprint, floating licence files
refused, fail-soft enforcement gate, pre-login activation page — together with a
three-platform installer kit for Windows, Linux and macOS.

## 6. Session S4 — 13 August 2026 (evening)

Custom pricing was added for cases the bands cannot express. Administrators may set
a custom price, setup charge and AMC on orders, quotations and prospect quotes, at
any user count.

Beyond the last priced band, `/buy` no longer quotes. It captures a request — details
only, no price — which lands in the orders queue badged by origin, where it is
edited, priced and converted in place with a payment link. Requests take an order
number but no quotation number until conversion, which keeps the financial-year
quotation series clean.

Three decisions were recorded. Perpetual terms remain lifetime; the "minimum fifteen
years" line is a commercial talking point and must not become an expiry date in the
system. Requests are fully editable; converted numbered quotations are not. Custom
pricing is available to super admins and to staff holding billing rights.

The session ended with the Central suite at **69 passed, 391 assertions**. Two rounds
of failures preceded that: four SQLite constraint failures, two from a security change
made earlier the same day, and one genuine defect where perpetual quotes added a setup
fee even when setup had been explicitly declined.

## 7. Session S5 — 14 August 2026

Run by Altaf, working from four findings reported by the Managing Director.

### 7.1 The four findings

A trial licence stayed active after the customer subscribed. Licences now carry a
`superseded` status and paid issuance closes the trial automatically, with a backfill
for clients already in that state. Every route by which a superseded trial could come
back to life was closed.

The client card showed licence status but offered no action. Suspend, Reactivate and
Revoke now sit beside it.

Central displayed zero of twenty-five seats for a live client because consoles never
reported their counts. Consoles now send user, employee and device counts on the daily
check-in, and Central shows them, marked red when over limit.

Seat count was a label rather than a rule. A new seat service is now the single
authority on seat availability, and every path that could consume one is guarded —
including the update paths, which an adversarial review found were the ones that
mattered. One seat means one monitored person on one PC. The cap is not retrospective:
clients already over it keep everyone but cannot add more.

### 7.2 Expiry enforcement

Licence enforcement had been applied only to agent routes. It now covers the whole
authenticated API. Non-administrative sign-in is refused when the licence is dead, and
the interface shows a single licence wall rather than every screen failing separately.
An administrative rescue route is preserved.

### 7.3 The IIS incident

A new client, the first hosted on IIS rather than Laragon, could open every screen and
create records, but every Edit and Save returned HTTP 405.

IIS ships with WebDAV enabled. WebDAV claims PUT and DELETE and answers 405 before URL
Rewrite or PHP see the request. GET and POST pass through untouched, which is exactly
the reported symptom. **Nothing was wrong with SmartEPT.** The fix removes both the
WebDAV module and its handler in the site configuration; both lines are required. The
installation guide gained the step, the one-line curl command that proves the
diagnosis, and a warning about the site root.

### 7.4 The agent is six versions behind

Clients are downloading version 0.5.0, built on 20 July, because it is the newest
build published to Central. Version 0.11.0 was built on 25 July and never published.
The version has been bumped to 0.12.0, but the build and the publish — to both the
local and live download sources — have not been done.

The agent has no auto-updater. Every update is a manual reinstall, and rollout can
only be confirmed by checking reported application versions in the console.

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

## 9. Verified Repository State — 15 August 2026

Both repositories were inspected directly on 15 August by reading the Git index and
comparing every tracked entry against the file on disk.

| Repository | Local main | Remote | Working tree |
|---|---|---|---|
| smartept-central | `2cf456c` | identical | clean |
| smartept | `5d62cb9` | identical | one untracked file |

The last push to Central was made on 14 August at 19:53, and to Product at 19:54.
All 283 tracked files in Central and all 348 in Product match the index in both size
and modification time. Nothing on disk is newer than the index in either repository.
The single untracked file is a build log in the Product deployment folder.

**This corrects the project memory.** The 14 August session note records that nothing
had been pushed, because it was written while the session was still in progress. Both
repositories were in fact pushed the same evening. The memory has been amended so that
the next session does not chase work that is already done.

One further discrepancy is recorded but not resolved. Central's phpunit result cache
was written on 14 August at 18:05, which indicates the suite was run that evening —
whereas the session note states it was never run. The cache is excluded from version
control, so its contents cannot be inspected here and no conclusion should be drawn
about what passed.

## 10. Repository Hygiene Findings

Verification surfaced three problems that are unrelated to the sessions' subject
matter but should not be left unrecorded.

**Central is carrying 5.4 MB of development residue in version control** — twenty-two
files in total. Eleven are timestamped backups of the landing page, together about
4.3 MB. Four are artefacts created by the file-bridge mount. The remainder are backup
copies of a Blade template, a renderer, a seeder, and two test output files.

The reason they keep accumulating is that **Central's ignore file has no rule for
mount artefacts, although the Product repository does.** Adding the rule stops the
recurrence; removing the existing files from tracking is a separate, and optional,
decision.

**The Product repository tracks four archive files totalling 65 MB** in a cloud-sync
folder. These are almost certainly not meant to be in version control. Removing them
from the working tree does not reduce the repository size, since the history retains
them; that would require rewriting history, which is not recommended lightly and is
not proposed here.

**The client packaging script omits one cache directory from its exclusions.** If
configuration or route caching is run before a client package is built, development
configuration — including database credentials — is shipped to the client. This was
raised on 14 August and has not been fixed.

## 11. Outstanding Actions

| # | Action | State |
|---|---|---|
| 1 | Restart Laragon in both applications to clear the opcode cache | Not done |
| 2 | Run the Central migration, including the trial backfill | Not confirmed |
| 3 | Run the test suite in both repositories | Disputed — see 9 |
| 4 | Push both repositories | **Done — verified** |
| 5 | Deploy to live: pull and migrate Central, pull Product | Not confirmed |
| 6 | Re-check the affected client's trial status after deployment | Blocked on 5 |
| 7 | Build and publish agent 0.12.0, locally and to the live source | Not done |
| 8 | Correct the mislabelled perpetual licence and remove the wrong order | Not done |
| 9 | Rebuild the client server package; test one Windows and one Linux install | Not done |
| 10 | Apply code protection before wide distribution | Not done |
| 11 | Add the mount-artefact ignore rule to Central | Newly raised |
| 12 | Fix the packaging script's cache exclusion | Raised 14 Aug, open |

Items 1, 2, 3 and 5 are the deployment chain for the 14 August licence work. Until
they are complete, seat enforcement and expiry blocking exist in the repository but
are not in effect for any client.

## 12. Decisions Awaiting Sign-Off

**The super-admin exemption from licence blocking.** The super admin is exempt from
the console-wide block only on a shared cloud installation carrying no licence key of
its own. The reasoning is that on the shared box the super admin is an Ametecs
operator, and blocking that account would remove access to the tenant list; on a
client's own server the super admin is the client's owner and receives only the rescue
routes. This was chosen during the 14 August session and flagged for the Managing
Director's approval. **It has not been approved.**

**The empty installation slot after migration.** When the per-tenant licensing
migration runs on live, the installation-level slot empties and non-SaaS companies,
including Ametecs' own, begin a seven-day evaluation before their agents block. This
needs either a licence or a deliberate acceptance before the migration runs.

## 13. Lessons Recorded

**Enum columns have now failed five times.** Three of the five occurred within these
sessions. Every recurrence follows the same shape: code writes a value the column does
not permit, and the failure surfaces somewhere far from the change. New status values
belong in VARCHAR columns.

**A migration containing raw SQL must carry a driver guard.** Two separate incidents
traced back to this. Without the guard the migration cannot run under the test
database, and the whole suite fails at setup rather than at any individual assertion.

**"N failed, 0 assertions" means the setup failed, not the tests.** Recognising this
distinction shortened a debugging session that had already consumed most of an evening.

**A hard-coded configuration value defeats the test environment silently.** The
Product suite was broken for three weeks with no visible signal. When environment
overrides appear not to work, read the configuration files before anything else.

**When adding a cap, guard the update paths and not only creation.** The adversarial
review of the seat cap found six bypasses, and every one of them was an update path —
a role change, a re-enable, a restore, a rebind.

**Add the cast in the same commit as the column.** Three missing datetime casts
produced a live fault when a comparison ran against a string.

**A lookup on a globally unique column must ignore company scoping.** A scoped lookup
misses rows belonging to other companies, and the resulting insert fails on the unique
index as a server error rather than as the conflict it actually is.

---

*Prepared for Ejaz Hussain, Managing Director, Ametecs. Sources: SmartEPT project
memory, sessions of 11–14 August 2026; direct inspection of both repositories,
15 August 2026.*
