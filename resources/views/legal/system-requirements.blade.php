@extends('legal.layout')
@section('title', 'System Requirements')
@section('content')

<p>This page lists the technical requirements for running SmartEPT. Confirmed items are shown as
stated; items marked <b>&#9873; to confirm</b> are being finalised with Ametecs engineering and should
be verified with our team before you size hardware or plan a rollout.</p>

<h2>1. SmartEPT Agent (employee workstation)</h2>
<ul>
  <li><b>Operating system</b> — Microsoft Windows (installer: <code>SmartEPT-Agent-Setup.exe</code>).
  Supported Windows versions: <b>&#9873; to confirm</b>. macOS and Linux agents are on the product
  roadmap.</li>
  <li><b>Runtime</b> — required .NET/runtime version: <b>&#9873; to confirm</b>.</li>
  <li><b>CPU / RAM footprint</b> — the agent is designed to run quietly in the background; exact
  minimums: <b>&#9873; to confirm</b>.</li>
  <li><b>Network</b> — LAN access to your SmartEPT server (Client Hosted) or an encrypted internet
  connection to SmartEPT Managed Cloud.</li>
  <li><b>Antivirus</b> — recommended allow-list entries for the agent: <b>&#9873; to confirm</b>.</li>
</ul>

<h2>2. SmartEPT Server (Client Hosted)</h2>
<ul>
  <li><b>Operating system</b> — Windows Admin Server (installer:
  <code>SmartEPT-Admin-Server-Setup.exe</code>); the installer bundles what it needs and sets up the
  console. Supported Windows Server versions: <b>&#9873; to confirm</b>.</li>
  <li><b>CPU / RAM / storage</b> — sized to your user count and screenshot retention; recommended
  specification: <b>&#9873; to confirm</b>. Screenshot volume is the main driver of disk usage, so
  plan storage against your retention window (up to three months standard).</li>
  <li><b>Database</b> — engine and version: <b>&#9873; to confirm</b>.</li>
  <li><b>Ports</b> — the ports the console and agents use: <b>&#9873; to confirm</b>.</li>
  <li><b>LAN / internet</b> — agents reach the server over your LAN. Outbound internet (HTTPS) is
  required for licence validation with the Ametecs licence server.</li>
</ul>

<h2>3. Managed Cloud (hosted by Ametecs)</h2>
<ul>
  <li>No customer server to maintain — workstations connect to the Managed Cloud over an encrypted
  (HTTPS/TLS) connection.</li>
  <li>Managed hosting, standard backups and updates are included; storage is pooled at 500&nbsp;MB per
  subscribed user, with additional storage at &#8377;3 per GB per month and standard retention
  configurable up to three months.</li>
</ul>

<h2>4. Integrations &amp; peripherals</h2>
<ul>
  <li><b>Biometric attendance</b> — eSSL and compatible push-capable devices feed gate punches to
  SmartEPT for the Gate-to-PC flow. Full supported-device list: <b>&#9873; to confirm</b>.</li>
  <li><b>AD / SSO &amp; standard API</b> — available; Active Directory, SSO and custom API integrations
  require assisted setup.</li>
</ul>

<h2>5. Offline behaviour, upgrades &amp; rollback</h2>
<ul>
  <li><b>Offline / licence-server unavailable</b> — the exact grace behaviour when the licence server
  is temporarily unreachable: <b>&#9873; to confirm</b> (we will not claim cached offline verification
  unless it is a confirmed product feature).</li>
  <li><b>Upgrade method</b> — via the provided installer; a managed self-update channel is on the
  roadmap. Confirmed upgrade steps: <b>&#9873; to confirm</b>.</li>
  <li><b>Installer signing</b> — code-signing details: <b>&#9873; to confirm</b>.</li>
  <li><b>Backup &amp; rollback</b> — on Client Hosted, back up the SmartEPT database and storage on your
  own schedule; recommended backup/rollback procedure: <b>&#9873; to confirm</b>. Managed Cloud is
  backed up by Ametecs.</li>
</ul>

<div class="note">Need exact figures for a tender or IT review? WhatsApp 90000&nbsp;98877 or email
sales@ametecsindia.com and our team will confirm the items marked above for your environment.</div>

<p style="margin-top:16px"><a href="/security">Back to Security &amp; Data Architecture &rarr;</a></p>

@endsection
