# SmartEPT Release and Update Runbook
How every build reaches a client — Agent, Admin Server and Central
Version 1.0

---

## 1. Who this document is for

This runbook has two halves and they are meant to be read by different people.

**Part A (sections 2 to 12) is internal.** It assumes you have the Ametecs repositories,
Laragon on your machine, access to the live VPS, and a Super Admin login on SmartEPT
Central. It contains exact paths, commands and the traps that have already cost us time.

**Part B (sections 13 to 19) is for the client's IT person.** It assumes none of the above.
It can be printed or sent on its own without leaking anything internal. If you are sending
Part B to a client, send sections 13 to 19 only.

> Rule of thumb: if a step mentions a repository, a build command or the VPS, it belongs to
> Part A and a client should never see it.

---

## 2. PART A — The three parts of SmartEPT, and where each one lives

SmartEPT is three separate programs. They are versioned, built and released independently,
which is exactly why releases go wrong when someone assumes updating one updates the others.

| Part | What it is, and where it runs |
|---|---|
| **Agent** | The Electron desktop client that employees run, installed on every monitored PC. |
| **Admin Server** (the Product) | The Laravel console the client's managers sign into — dashboards, attendance, policies, reports. Runs on the client's own server, or on the Ametecs cloud. |
| **Central** | Our own Laravel business system — clients, licences, orders, invoices, the client portal and the download catalogue. Lives on the Ametecs VPS and is never installed at a client. |

Their source trees on the build machine:

| Part | Repository |
|---|---|
| Agent | `C:\Users\ADMIN\Documents\Claude\Projects\SmartEPT\agent` |
| Admin Server | `C:\laragon\www\smartept` |
| Central | `C:\laragon\www\smartept-central` |

A fourth thing matters and is not a program: **the download catalogue**. It is the folder
`storage/app/downloads` inside Central, plus a `download_artifacts` table that can override
it. Everything a client ever downloads comes from there.

---

## 3. The delivery chain — what actually has to happen

Nothing reaches a client because you built it. It reaches them because it travelled the
whole chain below. Most failed releases are a chain that stopped at step 3.

1. **Change the source** in the relevant repository and commit it.
2. **Bump the version** in that project.
3. **Build the artifact** — an `.exe` for the Agent, a `.zip` for the Admin Server.
4. **Copy the artifact into Central's downloads folder** on your machine, renamed correctly.
5. **Publish it** in Central admin so the portal will serve it.
6. **Repeat steps 4 and 5 on the LIVE VPS.** This is the step that gets skipped.
7. **The client downloads and installs it**, and for the Admin Server, runs the migration.
8. **Verify** the new version is actually running.

> The single most common release failure at Ametecs: everything is done perfectly on the
> local Laragon machine, and nothing changes for any real client, because the live VPS was
> never touched. Your local `C:\laragon\www\smartept-central\storage\app\downloads` is not
> what customers download from.

---

## 4. Rule one — local is not live

There are three environments and they share no files.

| Environment | Where | What it is for |
|---|---|---|
| **Local Laragon** | `C:\laragon\www\smartept` and `...\smartept-central` | Development and testing. This is also the LIVE TREE for editing per the Ametecs deployment policy — we edit here, we do not edit elsewhere and copy in. |
| **Live Central** | The Ametecs VPS — `smartept.com` and `admin.smartept.com` | What customers actually touch: the website, the client portal, licence validation, downloads. |
| **Client install** | The client's own server (or their tenant on our cloud) | The Admin Server console their staff sign into. |

Consequences worth internalising:

- Copying an installer into the local downloads folder changes nothing for customers.
- Running a migration locally does not migrate the live database.
- A licence key issued on your local Central does not exist on the live Central.
- `storage/app/downloads` is not in git, so `git pull` on the VPS will never bring an
  installer with it. Installers move by SFTP, by hand, every time.

---

## 5. Releasing the Agent

The Agent has **no auto-updater**. There is no `electron-updater` in the build. Every
update is a manual reinstall on every employee PC. Plan the rollout accordingly, and do not
promise a client a silent update.

**Step 1 — commit and bump.** In the agent repository, commit your work, then raise
`version` in `package.json`. The version is the filename, so if you forget, the build
silently overwrites the previous `.exe` and you can no longer tell the two apart.

**Step 2 — build.** Requires Node and internet — electron-builder downloads the Electron
binaries on first run.

```
cd C:\Users\ADMIN\Documents\Claude\Projects\SmartEPT\agent
npm install
npm run dist
```

This writes `dist\SmartEPT Agent Setup <version>.exe` — roughly 77 MB. Note the **spaces**
in that filename; they matter in the next step.

**Step 3 — publish it into Central's downloads folder**, renamed to hyphens:

```
copy "C:\Users\ADMIN\Documents\Claude\Projects\SmartEPT\agent\dist\SmartEPT Agent Setup 0.12.0.exe" "C:\laragon\www\smartept-central\storage\app\downloads\SmartEPT-Agent-Setup-0.12.0.exe"
```

> The rename is not cosmetic. Central's fallback lookup globs for
> `SmartEPT-Agent-Setup*.exe`. A file with spaces sits in that folder completely invisible
> to the portal, and nobody notices until a client says the download link is broken.

**Step 4 — publish it in Central admin** (see section 7).

**Step 5 — repeat steps 3 and 4 on the live VPS** by SFTP.

**Step 6 — verify the rollout.** The Agent sends `app_version` on every device
registration and heartbeat, and the Admin Server stores it. Open the client's console →
**Devices** and read the version column per machine. That is how you know who has actually
updated, rather than who was told to.

### Agent build targets other than Windows

`npm run dist:mac` and `npm run dist:linux` exist. Central recognises `SmartEPT-Agent*.dmg`
and `.pkg` for macOS, and `.deb`, `.AppImage` and `.tar.gz` for Linux, under the
`agent-mac` and `agent-linux` slugs. macOS builds must be produced on a Mac.

---

## 6. Releasing the Admin Server package

The client-installable Admin Server is a ZIP built by a script in the product repository.

```
cd C:\laragon\www\smartept\deployment
rebuild-server-zip.bat 1.2
```

Pass the version as the first argument; with no argument it defaults to `1.1` and will
overwrite the existing file of that name. The script stages the product folder, excludes
the things a client must never receive, compresses with the built-in `tar`, and writes
straight into Central's downloads folder:

`C:\laragon\www\smartept-central\storage\app\downloads\SmartEPT-Admin-Server-Setup-<ver>.zip`

Because it writes there directly, the naming is already correct — unlike the Agent, no
rename is needed.

**What it deliberately excludes:** `.git`, `node_modules`, `_to_delete`, `_cloudsync`,
`storage\logs`, the framework caches, `storage\app\evidence`, `storage\app\private`,
`storage\app\public`, `.env` and any backup of it, `license.lic` and `.machine_fp`.

**What it includes on purpose:** `vendor`, so the client never needs Composer, plus
`INSTALL.bat`, `install-linux.sh` and `install-macos.sh`.

> **Known gap, not yet fixed (14-Aug-2026):** the script does not exclude `bootstrap\cache`.
> If anyone has run `php artisan config:cache` or `route:cache` on the build machine before
> packaging, that cache — containing your development database credentials and application
> key — ships inside the client ZIP. Until the exclusion is added, run `cache.bat` in the
> product folder immediately before building the ZIP.

**Before wide distribution**, the SmartPRS2 standard applies: SourceGuardian-encode
`app\Services\LicenseFile.php` and `app\Http\Middleware\EnsureLicensed.php` in the staged
copy, so the embedded public key and the licence checks cannot be edited by the client.

---

## 7. Publishing in Central — the two mechanisms

Central resolves a download in `PortalController::artifactPath()`, and it checks **two**
places in a fixed order. Understanding the order prevents an hour of confusion.

**First: the managed catalogue.** If a `download_artifacts` row exists for the slug, that
row decides, and its `is_published` flag decides whether anything is served at all. The
downloads folder is not consulted.

**Second, only if no row exists: the folder.** Central globs `storage/app/downloads` for
the patterns below and serves the **newest file by modification date**.

| Slug | Patterns matched in the downloads folder |
|---|---|
| `agent-windows` | `SmartEPT-Agent-Setup*.exe`, `SmartEPT-Agent*.exe`, `SmartEPT-Agent*.zip` |
| `agent-mac` | `SmartEPT-Agent*.dmg`, `SmartEPT-Agent*.pkg` |
| `agent-linux` | `SmartEPT-Agent*.deb`, `SmartEPT-Agent*.AppImage`, `SmartEPT-Agent*.tar.gz` |
| `server-windows` | `SmartEPT-Admin-Server-Setup*.exe`, `SmartEPT-Admin-Server*.exe`, `SmartEPT-Admin-Server*.zip` |

Older links using `agent` and `admin` still work; they alias to `agent-windows` and
`server-windows`.

### Three traps in the managed catalogue

**Trap one — a published row makes the folder invisible.** Before you copy a file into the
folder and assume you are done, open Central admin → Downloads and check whether a managed
entry already exists for that slug. If it does, copying is pointless; you must update the
entry.

**Trap two — never add a second entry for the same platform.** New artifacts take their
slug from `uniqueSlug()`, so a second Agent-Windows entry is stored as `agent-windows-2`.
The portal only ever asks for `agent-windows`, so your new entry is never served and the
old one keeps being handed out. **Edit the existing entry.**

**Trap three — do not upload large files through the browser.** A 77 MB Agent will exceed
`upload_max_filesize` and `post_max_size` on a default PHP install; the request arrives
empty and the screen reports it. Instead, place the file in `storage/app/downloads` first,
then in the Downloads screen choose **"Use a file already on the server"** and pick it.
This is also faster and avoids PHP renaming your file — an upload passes through a
sanitiser that turns spaces into underscores, which breaks the folder fallback.

### Download quotas

Client downloads are auth-walled, logged and quota-limited per tenant, with separate daily
and monthly allowances for free and paying clients. The limits are editable in the same
Downloads screen. If a client says a download is refused, check their quota before
assuming the file is missing.

---

## 8. Releasing Central itself

Central is a Laravel application on the Ametecs VPS. It is never installed at a client.

1. Commit and push from the local tree, `C:\laragon\www\smartept-central`.
2. On the VPS: `git pull`.
3. `php artisan migrate --force` if the release contains migrations.
4. `php artisan optimize:clear`.
5. Reload PHP-FPM or restart the web server so OPcache picks up the new code.

Locally, the equivalent of step 5 is Laragon → **Stop All → Start All**. A reload is not
enough; PHP's OPcache will keep serving the old code and you will chase a bug that no
longer exists in the file you are reading.

---

## 9. Updating a client who is already running

How an existing client is updated depends on how they are hosted.

### A cloud tenant (SmartEPT-Managed Cloud)

They share our Admin Server install, so updating that install updates them. There is
nothing for the client to do. Licence keys, storage quota, the Central URL and SMTP are
pushed to the tenant automatically by Central's provisioning call. Their staff will,
however, still need the new **Agent** installed on their PCs — that is never automatic.

### A client-hosted (on-premises) install

They run their own copy, so the update is theirs to apply, using Part B section 17. In
short: download the new ZIP from the client portal, unzip it over the existing folder,
keeping their `.env`, then run `migrate.bat` and `cache.bat`.

> There is deliberately **no `UPDATE.bat`** in the package today. Unzip-over-the-top plus
> `migrate.bat` is the supported path. Note this is not a contradiction of the Ametecs
> "no overlay deploy scripts" rule — that rule forbids copying between *our* development
> and live trees, where it destroys newer work. Shipping a new build to a client is a
> different act.

---

## 10. Pre-release checklist

Run through this before you tell anyone a release is out.

1. Source committed in the correct repository, on `main`.
2. Version bumped, and the version appears in the built filename.
3. `test.bat` run in the affected Laravel repo, and the failures understood — not merely
   observed. `phpunit.xml` forces SQLite in memory, so running tests never touches a real
   database, including on a client machine.
4. Any new migration run locally and confirmed reversible enough to be safe.
5. Artifact built and its file size sane — an Agent well under 70 MB usually means a
   broken build.
6. Artifact copied into the local downloads folder with the correct hyphenated name.
7. Artifact uploaded to the **live VPS** downloads folder.
8. Published in the **live** Central admin, with the version number filled in.
9. Downloaded once from the live client portal, as a client, and the file opens.
10. Release notes written where the client will see them.

---

## 11. Verifying a release actually landed

- **Agent:** the client's console → Devices → the version column, per machine.
- **Admin Server:** the client's console → Help → Troubleshooting → System Health, plus
  confirming the new feature is present. A screen that errors after an update almost always
  means `migrate.bat` was not run.
- **Central:** the client portal download page shows the version you published.

---

## 12. Troubleshooting the release itself

| Symptom | Cause | Fix |
|---|---|---|
| New Agent in the folder, portal still serves the old one | A published managed entry exists, so the folder is ignored | Update the entry in Central admin → Downloads |
| New Agent in the folder, portal serves nothing new, no managed entry | Filename has spaces, so the glob misses it | Rename to `SmartEPT-Agent-Setup-<ver>.exe` |
| You added a new Downloads entry and nothing changed | It was saved as `agent-windows-2` | Delete it and edit the original entry instead |
| Upload screen fails with an empty error | File larger than PHP's post limit | Copy to the folder, then "Use a file already on the server" |
| Everything correct locally, clients see no change | The live VPS was never updated | SFTP the file up and publish it on live |
| Client's download is refused | Their monthly or daily quota is spent | Raise it in Downloads, or send the file directly |
| A screen errors right after an update | Migrations not run | `migrate.bat` |
| Your fix does not take effect at all | OPcache | Laragon Stop All → Start All; on the VPS reload PHP-FPM |

---

## 13. PART B — For the client's IT team: what you have been given

You have been given up to three things. Which of them apply depends on how your SmartEPT
was purchased.

- **The SmartEPT Admin Server** — the console your managers sign into. If Ametecs hosts
  your SmartEPT for you, you do not have this and do not need it; skip to section 16.
- **The SmartEPT Agent** — installed on each employee PC you wish to track.
- **A licence key or licence file**, issued by Ametecs.

All downloads come from your client portal on `smartept.com`, using the login Ametecs gave
you. Downloads are logged and subject to a fair-use limit; if a download is refused, contact
Ametecs rather than retrying.

---

## 14. Installing the Admin Server — the simple route

This is the recommended route for a single office. It uses Laragon, which bundles the web
server, PHP and MySQL in one installer.

1. Install **Laragon** on the machine that will act as your SmartEPT server, and start it.
2. Unzip `SmartEPT-Admin-Server-Setup-<version>.zip` into `C:\laragon\www\smartept`.
3. Open an **administrator** Command Prompt in that folder and run **`INSTALL.bat`**.
   It prepares the configuration, creates the database, creates the tables, and asks you
   for your company name, an admin email and an admin password.
4. Open `http://smartept.test/admin` and sign in with what you just created.
5. Allow TCP port 80 (and 443 if you have a certificate) through the Windows firewall, so
   employee PCs can reach this server.

---

## 15. Installing the Admin Server on IIS

Use this only if your organisation requires IIS. It has one requirement that is easy to
miss and causes a confusing failure.

1. Enable **IIS** with **CGI** (Server Manager → Add Roles and Features → Web Server (IIS)
   → Application Development → CGI).
2. Install the **URL Rewrite** module from `iis.net/downloads/microsoft/url-rewrite`.
3. **Turn WebDAV off.** The shipped `public\web.config` disables it for the site, which is
   normally enough. If you still see errors described below, remove the feature itself:
   Roles and Features → Web Server (IIS) → Common HTTP Features → untick **WebDAV
   Publishing**, then run `iisreset`.
4. Install **PHP 8.2 or newer**, NTS x64, with `pdo_mysql`, `openssl`, `mbstring`, `curl`,
   `zip`, `gd` and `fileinfo` enabled.
5. Install **MySQL 8** or MariaDB.
6. Unzip the package to a folder **outside** `C:\inetpub\wwwroot` — `C:\smartept` is the
   recommended location.
7. Run `INSTALL.bat` from an administrator Command Prompt in that folder.
8. In IIS Manager, add a Handler Mapping for `*.php` to `php-cgi.exe` using FastCGI.
9. Add a website whose physical path is the **`public` subfolder**, never the application
   root. Application pool: No Managed Code.
10. Grant `IIS_IUSRS` Modify permission on `storage` and `bootstrap\cache`.
11. Set `APP_URL` in `.env` to your server's address, then run `php artisan config:clear`
    and `php artisan storage:link`.
12. Create a Task Scheduler job running every minute as SYSTEM:
    `php.exe <app>\artisan schedule:run`. Attendance, reports and alerts depend on it.

> **If every page loads and you can create records, but every Edit and Save returns
> HTTP 405 — that is WebDAV, not SmartEPT.** IIS enables WebDAV by default and it takes over
> the PUT and DELETE requests before the application ever sees them. Complete step 3 and run
> `iisreset`. To confirm: `curl.exe -i -X PUT http://<server>/api/org/branch/1` should answer
> `401 Unauthenticated`. If it answers 405 with an `Allow:` header, WebDAV is still on.

**Security check before going live:** open `http://<your-server>/.env` in a browser. If it
downloads a file, your website is pointed at the application root instead of the `public`
subfolder, and your database password is public. Fix the site path immediately.

---

## 16. Installing the Agent on employee PCs

1. Download `SmartEPT-Agent-Setup-<version>.exe` from your client portal.
2. Run it on each employee PC as an administrator. It installs for all users of that
   machine and creates a desktop and Start Menu shortcut.
3. On first launch, enter your **SmartEPT server address** — the same address your managers
   use for the console — and use **Test connection** to confirm the PC can reach it.
4. The employee signs in with their own SmartEPT login and accepts the monitoring notice.
   The Agent is deliberately visible: employees can always see that it is running and what
   it is doing.

Uninstalling the Agent is password-protected, so employees cannot remove it themselves.

---

## 17. Updating to a new version

### Updating the Admin Server

1. Take a backup of your database and of your `.env` file.
2. Tell staff the console will be briefly unavailable.
3. Download the new `SmartEPT-Admin-Server-Setup-<version>.zip` from your client portal.
4. Unzip it **over** your existing installation folder, choosing to replace files.
   **Keep your existing `.env`** — do not let it be overwritten. Your data lives in MySQL
   and is untouched by this.
5. Run **`migrate.bat`** in the application folder. This adds any new tables and columns.
   Skipping it is the single most common cause of a screen erroring after an update.
6. Run **`cache.bat`**.
7. Restart the web server. On Laragon: Stop All, then Start All — a reload is not enough.
   On IIS: `iisreset`.
8. Sign in and check Help → Troubleshooting → System Health.

### Updating the Agent

The Agent does not update itself. A new version must be installed on each PC.

1. Download the new `SmartEPT-Agent-Setup-<version>.exe`.
2. Run it on each employee PC. It installs over the existing version; the employee's login
   and settings are preserved.
3. Roll out to a few machines first and confirm they appear correctly in the console before
   doing the rest.

---

## 18. Confirming an update worked

- **Agent:** in the console, open **Devices**. Each machine lists the Agent version it is
  running. This tells you which PCs have genuinely been updated.
- **Admin Server:** open **Help → Troubleshooting → System Health**. Everything should be
  green. If a screen shows a database error, `migrate.bat` was not run in step 5.
- Confirm that employee activity is still arriving on the Live Dashboard within a few
  minutes of an employee signing in.

---

## 19. Licensing, and when to call Ametecs

A fresh installation runs a **7-day evaluation** with every feature enabled and no key
required. To license it permanently, open `http://<your-server>/activate`, copy the machine
fingerprint shown there, send it to Ametecs, and upload the `.lic` file you receive on the
same page. The licence file is locked to that machine.

If the server is later rebuilt, formatted or replaced, contact Ametecs — the licence can be
moved to the new machine, and your old fingerprint is kept on record.

Contact Ametecs if any of the following apply, rather than trying to work around them:

- Every Edit or Save returns HTTP 405, and section 15 step 3 has not resolved it.
- The console reports that the licence has expired or is not active.
- You need to add more users than your licence covers.
- A server has to be replaced or its fingerprint has changed.
- Employee activity stops arriving from PCs that are switched on and signed in.

**Ametecs India Private Limited, Hyderabad** — WhatsApp 90000 98877.
