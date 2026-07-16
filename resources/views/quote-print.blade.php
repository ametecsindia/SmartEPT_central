<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quotation {{ $order->quote_number }}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
@page{size:A4;margin:18mm}
body{font-family:'Inter','Segoe UI',sans-serif;color:#15171C;font-size:12.5px;line-height:1.55}
.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0E7C8F;padding-bottom:14px}
.mk{display:inline-flex;width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);color:#fff;align-items:center;justify-content:center;font-weight:800;font-size:13px}
h1{font-size:20px;color:#0E7C8F;margin-top:6px}
.co{font-size:11.5px;color:#565A66;text-align:right}
.meta{display:flex;justify-content:space-between;margin:16px 0;gap:20px}
.meta .box{background:#FAFBFC;border:1px solid #E7E9EF;border-radius:10px;padding:12px 14px;flex:1;font-size:12px}
.meta b{color:#0B6373;display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px}
table{width:100%;border-collapse:collapse;margin:8px 0 14px}
th{background:#E3F4F7;color:#0B6373;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
td{padding:8px 10px;border-bottom:1px solid #F0F1F4}
.tots{margin-left:auto;width:280px}
.tots .r{display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px;color:#565A66}
.tots .g{border-top:2px solid #0E7C8F;font-weight:800;font-size:15px;color:#15171C;padding-top:8px;margin-top:4px}
.payline{margin-top:20px;background:#E3F4F7;border-left:4px solid #0E7C8F;border-radius:0 10px 10px 0;padding:13px 16px;font-size:12.5px;color:#0B6373}
.payline b{display:block;margin-bottom:3px}
.terms{margin-top:16px;font-size:11px;color:#878C99;line-height:1.7}
.foot{margin-top:24px;border-top:1px solid #E7E9EF;padding-top:12px;font-size:10.5px;color:#878C99;display:flex;justify-content:space-between}
@media print{.noprint{display:none}}
.noprint{position:fixed;top:14px;right:14px}
.noprint button{padding:10px 18px;border:none;border-radius:8px;background:#0E7C8F;color:#fff;font-weight:700;cursor:pointer;font-family:inherit}
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print / Save PDF</button></div>
<div class="top">
  <div><span class="mk">EPT</span><h1>QUOTATION</h1>
    <div style="font-size:12px;color:#565A66">{{ $order->quote_number }} · {{ $order->created_at->format('d M Y') }} · valid 15 days</div></div>
  <div class="co"><b style="color:#15171C;font-size:13px">{{ $company['name'] }}</b><br>
    {{ $company['address'] }}<br>GSTIN: {{ $company['gstin'] }}<br>{{ $company['phone'] }} · {{ $company['email'] }}</div>
</div>
<div class="meta">
  <div class="box"><b>Quoted To</b>{{ $order->tenant->company_name }}<br>
    {{ $order->tenant->address }}<br>
    @if($order->tenant->gstin) GSTIN: {{ $order->tenant->gstin }}<br>@endif
    {{ $order->tenant->email }} · {{ $order->tenant->phone }}</div>
  <div class="box"><b>Quotation Details</b>Reference order: {{ $order->number }}<br>
    @if($order->requested_by) Requested by: {{ $order->requested_by }}<br>@endif
    Status: {{ $order->status === 'quote' ? 'AWAITING MANAGEMENT APPROVAL' : strtoupper($order->status) }}</div>
</div>
@php
    // SAC shown per line (997331 = software licensing). The CGST/SGST-vs-IGST
    // split happens on the TAX INVOICE at payment time — a quote shows the
    // total 18% GST with a note, since the buyer's state may still change.
    $sac = \App\Models\Setting::get('sac_code', '997331');
@endphp
<table>
  <tr><th style="width:54%">Description</th><th>SAC</th><th>Qty</th><th style="text-align:right">Amount</th></tr>
  @foreach ($order->line_items as $line)
  <tr><td>{{ $line['description'] }}</td><td>{{ $line['sac'] ?? $sac }}</td><td>{{ $line['qty'] ?? 1 }}</td>
  <td style="text-align:right">{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($line['amount'], 2) }}</td></tr>
  @endforeach
</table>
<div class="tots">
  <div class="r"><span>Subtotal</span><span>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->subtotal, 2) }}</span></div>
  <div class="r"><span>GST{{ $order->currency === 'INR' ? ' @ ' . rtrim(rtrim(number_format((float) \App\Models\Setting::get('gst_rate', 18), 2), '0'), '.') . '%' : '' }}</span><span>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->tax_amount, 2) }}</span></div>
  <div class="r g"><span>Total</span><span>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->total, 2) }}</span></div>
</div>
@if ($order->currency === 'INR')
<div style="margin-top:10px;background:#FAFBFC;border:1px solid #E7E9EF;border-radius:9px;padding:9px 12px;font-size:11.5px">
  <b style="color:#0B6373">Amount in words:</b> {{ \App\Support\AmountInWords::convert((float) $order->total) }}</div>
<p style="margin-top:8px;font-size:11px;color:#878C99">GST 18% under SAC {{ $sac }} (software licensing). The tax invoice issued on payment
  shows CGST 9% + SGST 9% for Telangana customers, or IGST 18% for other states, based on your billing profile.</p>
@endif
<div class="payline"><b>For Management — approve &amp; pay online:</b>
  {{ $payUrl }}<br>UPI · Cards · NetBanking (Razorpay) or international card (Stripe). Payment activates the licence instantly and the GST tax invoice is issued automatically. Bank transfer (NEFT/UPI) is equally welcome — share the UTR on WhatsApp 90000 98877.</div>
<div class="terms">Terms: Prices exclude GST unless shown. Quotation valid 15 days from date above. Licence per active endpoint device; web-only managers free. One-time Setup &amp; Onboarding fee applies on first order only. Subject to SmartEPT standard commercial terms.</div>
<div class="foot">
  <span>SmartEPT — Employee Productivity Tracking & Intelligence · by {{ $company['name'] }}</span>
  <span>This is a computer-generated quotation.</span>
</div>
</body>
</html>
