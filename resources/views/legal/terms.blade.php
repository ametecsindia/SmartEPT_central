@extends('legal.layout')
@section('title', 'Terms of Service')
@section('content')

<p>These terms govern the use of SmartEPT — the employee productivity tracking and intelligence
system — provided by Ametecs India Private Limited ("Ametecs"), Kondapur, Hyderabad, Telangana,
GSTIN 36AAHCT0971F1ZB. By starting a trial, buying a licence or using the client portal, you accept
them on behalf of your organisation.</p>

<h2>1. The service and licence types</h2>
<ul>
  <li><b>Subscription licences</b> (monthly or annual advance) — priced per <b>Licensed Monitored User</b>,
  on your own server (client-hosted) or on SmartEPT Managed Cloud. A Licensed Monitored User represents
  one employee authorised to use the SmartEPT Agent. An employee may move to another authorised
  workstation using personal credentials but may maintain only one active SmartEPT Agent session at a
  time. Dashboard-only administrators, HR users and managers do not consume monitored-user licences
  unless they are actively monitored. The licence is valid for the paid period and renews only when a
  renewal payment is made.</li>
  <li><b>Perpetual licences</b> — a one-time licence for the purchased monitored-user capacity. The
  software licence does not expire. The first 12 months of updates and support are included, followed
  by an optional Annual Maintenance Contract (AMC) for future updates and support.</li>
  <li><b>Free trial</b> — a 7-day full-platform evaluation, every standard feature included, up to 10
  monitored employees, no card required. Infrastructure-dependent integrations (biometric, AD/SSO,
  client-hosted installation) require assisted setup. Trial data is scheduled for deletion within 14
  days after trial expiry unless a paid service is activated.</li>
</ul>
<p>Licences are priced per Licensed Monitored User, are non-transferable between organisations, and the
licence key must be kept confidential. A one-time Remote Assisted Setup &amp; Onboarding fee may apply on
the first paid order. SmartEPT Managed Cloud includes 500&nbsp;MB of pooled storage per subscribed user
across the organisation; storage beyond the tenant's included pooled allowance is charged at &#8377;3 per
GB per month, and standard retention is configurable up to a maximum of three months.</p>

<h2>2. Payments, taxes and invoices</h2>
<p>Payments are processed by Razorpay (UPI, cards, NetBanking) and Stripe (international cards);
bank transfer (NEFT/UPI) is also accepted and recorded manually. All INR prices attract GST at the
prevailing rate (currently 18%, SAC 997331); a GST tax invoice is issued automatically when payment
is received. Quotations are valid for 15 days. Refunds are governed by our
<a href="/refunds">Refund Policy</a>.</p>

<h2>3. Your responsibilities as an employer</h2>
<p>SmartEPT is a transparent, consent-first workplace productivity and monitoring tool — it is
never covert surveillance software. You are responsible for using it lawfully: informing
your employees, obtaining any consent your jurisdiction requires, and configuring monitoring
policies proportionately. SmartEPT is transparent and policy-driven by design — you must not attempt
to use it as covert surveillance, on devices you do not manage, or against individuals outside an
employment relationship. You are responsible for the accuracy of your billing details (including
GSTIN — required if you wish to claim input tax credit) and for all activity under your portal login.</p>

<h2>4. Data</h2>
<p>On client-hosted deployments, all monitoring data stays on your infrastructure and is entirely
your responsibility. On SmartEPT-Managed Cloud, we host that data for you as described in the
<a href="/privacy">Privacy Policy</a>; you remain its owner. We may suspend accounts with overdue
cloud invoices after notice. Trial data is scheduled for deletion within 14 days after trial expiry
unless a paid service is activated; data of closed accounts is deleted according to employer policy,
service termination and legal obligations.</p>

<h2>5. Support, availability and updates</h2>
<p>Support is provided on business days via WhatsApp (90000 98877) and email
(<a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a>). We aim for high availability
of Central and Managed Cloud but do not warrant uninterrupted operation; planned maintenance is
announced in advance. Product updates are included in active subscriptions and valid AMC.</p>

<h2>6. Liability</h2>
<p>To the maximum extent permitted by Indian law, Ametecs' aggregate liability under these terms is
limited to the fees paid by you in the 12 months preceding the claim, and we are not liable for
indirect or consequential losses, or for decisions you take based on productivity data. Nothing
limits liability that cannot be limited under law.</p>

<h2>7. Termination and changes</h2>
<p>You may stop using the service at any time; paid periods run to their end per the
<a href="/refunds">Refund Policy</a>. We may terminate for material breach (including unlawful
monitoring or licence-key abuse) after notice. We may update these terms; material changes are
notified by email or portal notice 15 days in advance, and continued use constitutes acceptance.</p>

<h2>8. Governing law</h2>
<p>These terms are governed by the laws of India; courts at Hyderabad, Telangana have exclusive
jurisdiction. Contact for legal notices:
<a href="mailto:support@ametecsindia.com">support@ametecsindia.com</a>.</p>

@endsection
