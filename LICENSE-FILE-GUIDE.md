# SmartEPT — Offline Node-Locked Licence File (EPT-29)

A `license.lic` is a **signed, offline** licence bound to one machine. The client's server
verifies it locally with an embedded public key — **no internet, no `SMARTEPT_LICENSE_URL`,
no SSL/CA-certificate setup**. Works the same on **Windows and Linux**. Because the product is
SourceGuardian-encrypted, the public key and the check can't be swapped, so a forged file can't
pass and copying the file to another PC fails.

---

## One-time setup (Ametecs, do once)

1. **Create the key pair** — on the Central machine, run:
   ```
   GENERATE-LICENSE-KEYS.bat
   ```
   It writes `storage/app/keys/license_private.pem` (**keep secret, never ship**) and
   `license_public.pem`, and prints the public key.
2. **Embed the public key in the product** — open
   `smartept\app\Services\LicenseFile.php`, and replace the `PUBLIC_KEY` block with your
   `license_public.pem` contents.
3. **Encrypt & package** — SourceGuardian-encrypt the product, then rebuild the installer zip.
   (The public key is now locked inside the encrypted code.)

> A working demo key pair already ships embedded so you can test immediately. **For production,
> run step 1–3 with your own keys** so the private key never leaves your control.

---

## Issuing a licence to a client

1. **Client installs** the server and opens **Licence** → they see **"This machine's
   fingerprint"** (a 40-character code). They send it to you (WhatsApp/email).
2. **You sign a file** — on Central:
   ```
   php artisan smartept:issue-license SEPT-AKEY-F5KW-LIZZ-C88F --fingerprint=<their fingerprint>
   ```
   It writes `storage/app/licenses/<key>.lic` using the licence's real plan, device limit and
   expiry from the Licences table.
3. **Send the `.lic` file** to the client.
4. **Client imports it** — Licence screen → **Import licence file** → choose the `.lic` →
   **Import & activate**. Status goes **active**, offline. Done.

If the licence has an expiry, renewal = issue a new `.lic` with a later date and re-import.

---

## Windows vs Linux clients — both supported

Same file, same flow. Only the machine fingerprint source differs (handled automatically):

| | Fingerprint source | Web server |
|---|---|---|
| **Windows** | SMBIOS/motherboard UUID (`wmic csproduct get uuid`) | IIS or `START-SMARTEPT.bat` |
| **Linux** | `/etc/machine-id` | Nginx/Apache + PHP-FPM, or `php artisan serve` |

For a **Linux** client:
1. Deploy the SourceGuardian-encrypted product (install the SourceGuardian **Linux** loader
   for the client's PHP — `ixed.*.lin` in php.ini).
2. Serve it with Nginx/Apache + PHP-FPM (or `php artisan serve` for a quick run).
3. The Licence screen shows the fingerprint (from `/etc/machine-id`); you issue the `.lic`
   the same way; the client drops it in and imports. No SSL, no phone-home.

> Note: `/etc/machine-id` is stable but is regenerated if the OS is reinstalled or a VM is
> cloned — a fresh install needs a re-issued file (same as a Windows motherboard change).

---

## Does this need a domain / static IP / Let's Encrypt SSL?

**No.** The offline file removes the network dependency entirely — the earlier
`cURL error 60 / SSL` problem can't happen because nothing is fetched. A client with a domain +
static IP + Let's Encrypt is fine too, but **not required**. The `.lic` file is all that's
needed to run.

(The online `SMARTEPT_LICENSE_URL` path still exists and is used only if you *want* live
revocation/seat-counting; it's now optional.)

---

## Where things live

| Item | Location |
|---|---|
| Private key (secret) | Central: `storage/app/keys/license_private.pem` |
| Public key (embedded) | Product: `app/Services/LicenseFile.php` → `PUBLIC_KEY` |
| Issue command | Central: `php artisan smartept:issue-license` |
| Client licence file | Product root: `license.lic` (or `SMARTEPT_LICENSE_FILE` path) |
| Client fingerprint / import | Product → **Licence** screen |

---

© 2026 SmartEPT, developed by Ametecs India Private Limited — all rights reserved.
