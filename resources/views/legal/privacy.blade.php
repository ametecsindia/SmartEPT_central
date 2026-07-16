@extends('legal.layout')
@section('title', 'Privacy Policy')
@section('content')

<p>SmartEPT is an employee productivity tracking and intelligence system built and operated by
Ametecs India Private Limited ("Ametecs", "we"), Hyderabad, India. This policy explains, plainly,
what data we hold and what we do with it. It covers two very different things: <b>SmartEPT Central</b>
(this website — accounts, licences, billing) and the <b>SmartEPT product</b> your employer runs.</p>

<h2>1. What SmartEPT Central stores (our servers)</h2>
<ul>
  <li><b>Account data</b> — company name, contact name, work email, phone, and password (stored hashed,
  never in plain text). One-time verification codes are stored hashed and expire in 10 minutes.</li>
  <li><b>Billing data</b> — orders, quotations, GST invoices, your GSTIN, state code and billing address.
  Card/UPI details are handled entirely by our payment gateways (Razorpay for India, Stripe for
  international cards); we never see or store card numbers.</li>
  <li><b>Licence telemetry</b> — your licence key, the number of activated endpoint devices, device
  identifiers/hostnames, and (for SmartEPT-Managed Cloud customers) daily storage-usage readings used
  for metered billing. This is counting data, not employee-activity content.</li>
  <li><b>Operational logs</b> — audit trails of admin/portal actions and transactional emails we sent
  you (receipts, OTPs, quotations).</li>
</ul>

<h2>2. What the SmartEPT product stores (your infrastructure)</h2>
<p>The monitoring data SmartEPT produces — screenshots, application and website activity, idle time,
attendance and camera events — is stored on the deployment your employer chooses:</p>
<ul>
  <li><b>Client-hosted</b> (the default): everything stays on your organisation's own server.
  Ametecs has no access to it. "Your Infrastructure. Your Data. Our Intelligence."</li>
  <li><b>SmartEPT-Managed Cloud</b>: we host that data on the customer's behalf, isolated per tenant,
  and process it only to provide the service. The employer remains the owner and controller of it;
  retention is set by the employer's policy settings.</li>
</ul>
<div class="note">SmartEPT is transparent, consent-based and policy-driven — it is never stealth
software. Questions about what your employer monitors should go to your employer, who controls
those policies.</div>

<h2>3. How we use and share Central data</h2>
<p>We use account and billing data to provide the service, issue GST-compliant invoices, send
transactional emails (verification codes, payment receipts, quotations, renewal reminders) and meet
Indian tax and accounting obligations. We share it only with: payment gateways (to process your
payment), our infrastructure providers (to run the service), and authorities where the law requires
(e.g. GST records). We do not sell personal data, ever.</p>

<h2>4. Retention and deletion</h2>
<p>Trial accounts that do not convert are purged, including their data, within 14 days of trial
expiry. Active account data is kept while your subscription or licence is live. Invoices and tax
records are retained for 8 years as required by Indian law. You may ask us to delete your account
data (except records we must keep) by writing to
<a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a>.</p>

<h2>5. Security</h2>
<p>Passwords are hashed, OTPs are hashed and short-lived, portal access is rate-limited, all traffic
runs over HTTPS, and every billing action is audit-logged. Licence keys should be treated like
passwords — keep them inside your SmartEPT server settings.</p>

<h2>6. Your rights and contact</h2>
<p>You can view and correct your company, billing and GST details in the client portal at any time.
For access, correction or deletion requests, or any privacy concern, contact our grievance contact:
<a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a>, Ametecs India Private Limited,
Modern Profound Techpark, Ground Floor, Hive Space, opp. Google, Whitefields, Kondapur, Hyderabad,
Telangana 500084. We answer within 7 working days. This policy is governed by Indian law, including
the Digital Personal Data Protection Act, 2023.</p>

@endsection
