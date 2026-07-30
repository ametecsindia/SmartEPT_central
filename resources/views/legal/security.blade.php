@extends('legal.layout')
@section('title', 'Security & Data Architecture')
@section('content')

<p>SmartEPT is built so that your operational data stays where you decide. This page explains, in
plain terms, where data lives, how it moves, and what Ametecs can and cannot see, for both
deployment models. Items marked <b>&#9873; to confirm</b> are being verified with Ametecs
engineering and will be finalised before they are presented as guarantees.</p>

<h2>1. Client Hosted — your infrastructure</h2>
<p>Screenshots, activity records, camera events and productivity data remain within your
organisation&rsquo;s own server or private cloud. Ametecs receives only the licence and support
metadata needed to keep the product activated.</p>

<div style="display:flex;flex-wrap:wrap;align-items:stretch;gap:10px;margin:14px 0 6px">
  <div style="flex:1;min-width:150px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">Employee PC<br><span style="font-weight:400;font-size:12px;color:#3A3E48">SmartEPT Agent</span></div>
  <div style="align-self:center;font-size:20px;color:#0E7C8F">&rarr;</div>
  <div style="flex:1;min-width:150px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">Customer SmartEPT Server<br><span style="font-weight:400;font-size:12px;color:#3A3E48">on your infrastructure</span></div>
  <div style="align-self:center;font-size:20px;color:#0E7C8F">&rarr;</div>
  <div style="flex:1;min-width:150px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">Customer Database &amp; Storage<br><span style="font-weight:400;font-size:12px;color:#3A3E48">your disk, your control</span></div>
</div>
<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 8px">
  <div style="background:#FBF3E2;border:1px solid #E6D6A8;border-radius:10px;padding:10px 14px;font-weight:700;color:#7A5614">Ametecs</div>
  <div style="font-size:18px;color:#B7791F">&rarr;</div>
  <div style="font-size:13px;color:#3A3E48">Licence verification &amp; support metadata only — no employee screenshots or productivity data.</div>
</div>

<h2>2. Managed Cloud — hosted by Ametecs</h2>
<p>For organisations that prefer Ametecs to host and manage the platform, operational data is stored
in the SmartEPT Managed Cloud under controlled, per-tenant access. You remain the owner and
controller of the data.</p>

<div style="display:flex;flex-wrap:wrap;align-items:stretch;gap:10px;margin:14px 0 6px">
  <div style="flex:1;min-width:140px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">Employee PC<br><span style="font-weight:400;font-size:12px;color:#3A3E48">SmartEPT Agent</span></div>
  <div style="align-self:center;font-size:20px;color:#0E7C8F">&rarr;</div>
  <div style="flex:1;min-width:140px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">Encrypted connection<br><span style="font-weight:400;font-size:12px;color:#3A3E48">HTTPS/TLS in transit</span></div>
  <div style="align-self:center;font-size:20px;color:#0E7C8F">&rarr;</div>
  <div style="flex:1;min-width:140px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">SmartEPT Managed Cloud<br><span style="font-weight:400;font-size:12px;color:#3A3E48">per-tenant isolation</span></div>
  <div style="align-self:center;font-size:20px;color:#0E7C8F">&rarr;</div>
  <div style="flex:1;min-width:140px;background:#E3F4F7;border:1px solid #B9E2E9;border-radius:10px;padding:12px 14px;text-align:center;font-weight:700;color:#0B6373">Tenant-controlled portal &amp; storage</div>
</div>

<h2>3. What we cover</h2>
<ul>
  <li><b>Data location</b> — Client Hosted: your own server or private cloud. Managed Cloud:
  Ametecs-managed, isolated per tenant.</li>
  <li><b>Encryption in transit</b> — the client portal and the licence-server API run over HTTPS/TLS.
  Managed Cloud connections are encrypted in transit.</li>
  <li><b>Encryption at rest</b> — <b>&#9873; to confirm</b> per deployment; we will only state at-rest
  encryption where it is enabled and verifiable.</li>
  <li><b>Authentication</b> — portal passwords are stored hashed; one-time verification codes are
  hashed and short-lived; portal access is rate-limited.</li>
  <li><b>Role-based access</b> — administrative functions are separated by role (for example
  super-admin and sales), so staff see only what their role permits.</li>
  <li><b>Audit logs</b> — sensitive administrative and billing actions are written to an audit log.</li>
  <li><b>Tenant separation</b> — on Managed Cloud, each customer&rsquo;s data is isolated per tenant.</li>
  <li><b>Backups</b> — Managed Cloud includes standard managed backups. On Client Hosted, backups are
  your responsibility. Backup schedule and geography: <b>&#9873; to confirm</b>.</li>
  <li><b>Retention</b> — standard SmartEPT Managed Cloud retention is configurable up to a maximum of
  three months.</li>
  <li><b>Data deletion</b> — Managed Cloud data may be deleted according to employer policy, service
  termination and legal obligations. Trial data is scheduled for deletion within 14 days after trial
  expiry unless a paid service is activated.</li>
  <li><b>Licence-server communication</b> — SmartEPT product servers phone home to the Ametecs licence
  API for validation and device activation only. This is licence metadata, not employee-activity
  content.</li>
  <li><b>Support access controls</b> — Ametecs staff access to Managed Cloud tenant data is limited to
  what is required to provide support and is audit-logged.</li>
  <li><b>Customer responsibilities</b> — defining lawful monitoring policies, informing employees,
  keeping licence keys confidential, and (on Client Hosted) securing and backing up your own server.</li>
</ul>

<div class="note">SmartEPT does not claim any specific security certification on this page. Where a
certification, audit or at-rest-encryption guarantee is required, Ametecs will provide the supporting
documentation on request rather than assert it here.</div>

<p style="margin-top:16px"><a href="/system-requirements">See System Requirements &rarr;</a></p>

@endsection
