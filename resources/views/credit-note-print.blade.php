<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Note {{ $payment->credit_note_number }}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
@page{size:A4;margin:16mm}
body{font-family:'Inter','Segoe UI',sans-serif;color:#15171C;font-size:12px;line-height:1.55}
.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #B7791F;padding-bottom:12px}
h1{font-size:20px;color:#B7791F;margin-top:6px;letter-spacing:1px}
.co{font-size:11px;color:#565A66;text-align:right;max-width:330px}
.badge{display:inline-block;background:#FEF3E2;color:#B7791F;border:1px solid #F5D9A8;border-radius:6px;padding:2px 9px;font-size:10px;font-weight:800;letter-spacing:.5px;margin-top:5px}
.meta{display:flex;justify-content:space-between;margin:14px 0;gap:16px}
.meta .box{background:#FAFBFC;border:1px solid #E7E9EF;border-radius:10px;padding:11px 13px;flex:1;font-size:11.5px}
.meta b{color:#0B6373;display:block;font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px}
.kv{display:flex;justify-content:space-between;gap:10px;padding:1.5px 0}
.kv span:first-child{color:#878C99}
table{width:100%;border-collapse:collapse;margin:6px 0 12px}
th{background:#FEF3E2;color:#B7791F;text-align:left;padding:7px 9px;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px}
td{padding:7px 9px;border-bottom:1px solid #F0F1F4;vertical-align:top}
.num{text-align:right}
.tots{margin-left:auto;width:320px}
.tots .r{display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#565A66}
.tots .g{border-top:2px solid #B7791F;font-weight:800;font-size:15px;color:#15171C;padding-top:7px;margin-top:4px}
.words{margin-top:10px;background:#FAFBFC;border:1px solid #E7E9EF;border-radius:9px;padding:9px 12px;font-size:11.5px}
.words b{color:#0B6373}
.blocks{display:flex;gap:16px;margin-top:14px}
.blocks .bx{flex:1;border:1px solid #E7E9EF;border-radius:10px;padding:10px 13px;font-size:11px}
.blocks b{color:#0B6373;display:block;font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px}
.sig{text-align:right;margin-top:34px}
.sig .line{display:inline-block;border-top:1.5px solid #565A66;padding-top:5px;font-size:11px;color:#565A66;min-width:220px;text-align:center}
.notes{margin-top:14px;font-size:10.5px;color:#878C99;line-height:1.7}
.foot{margin-top:18px;border-top:1px solid #E7E9EF;padding-top:10px;font-size:10px;color:#878C99;display:flex;justify-content:space-between}
@media print{.noprint{display:none}}
.noprint{position:fixed;top:14px;right:14px}
.noprint button{padding:10px 18px;border:none;border-radius:8px;background:#B7791F;color:#fff;font-weight:700;cursor:pointer;font-family:inherit}
</style>
</head>
<body>
@php
    $sym = ($order->currency ?? 'INR') === 'INR' ? '₹' : '$';
    $refund = abs((float) $payment->amount);
    // Reverse GST proportionally at the original invoice's rate. No invoice (rare)
    // or 0% → the whole refund is treated as taxable value with no GST reversal.
    $rate = $invoice ? (float) $invoice->gst_rate : 0.0;
    $taxable = $rate > 0 ? round($refund / (1 + $rate / 100), 2) : $refund;
    $gst = round($refund - $taxable, 2);
    $isIgst = $invoice && (float) $invoice->igst > 0;
    $halfRate = rtrim(rtrim(number_format($rate / 2, 2), '0'), '.');
    $fullRate = rtrim(rtrim(number_format($rate, 2), '0'), '.');
    $tenant = $order->tenant;
@endphp
<div class="noprint"><button onclick="window.print()">Print / Save PDF</button></div>

<div class="top">
  <div><img src="/img/smartept-logo-h-light.png" alt="SmartEPT by Ametecs" style="height:42px;width:auto;display:block;margin-bottom:8px"><h1>CREDIT NOTE</h1>
    <div style="font-size:12px;color:#565A66">{{ $payment->credit_note_number }} · {{ $payment->paid_at->format('d M Y') }}</div>
    <span class="badge">REFUND / CREDIT</span></div>
  <div class="co"><b style="color:#15171C;font-size:13px">{{ $company['name'] }}</b><br>
    {{ $company['address'] }}<br>
    GSTIN: {{ $company['gstin'] }} · State: {{ $company['state'] }}<br>
    {{ $company['phone'] }} · {{ $company['email'] }}</div>
</div>

<div class="meta">
  <div class="box"><b>Credited To</b>
    <span style="font-weight:700">{{ $tenant->company_name }}</span><br>
    {{ $tenant->billing_address ?: $tenant->address }}<br>
    GSTIN: {{ ($invoice->buyer_gstin ?? null) ?: ($tenant->gstin ?: 'Unregistered') }}<br>
    {{ $tenant->email }}@if($tenant->phone) · {{ $tenant->phone }}@endif</div>
  <div class="box"><b>Credit Note Details</b>
    <div class="kv"><span>Credit note no.</span><span>{{ $payment->credit_note_number }}</span></div>
    <div class="kv"><span>Date</span><span>{{ $payment->paid_at->format('d M Y') }}</span></div>
    <div class="kv"><span>Against invoice</span><span>{{ $invoice->number ?? '—' }}</span></div>
    <div class="kv"><span>Order ref.</span><span>{{ $order->number }}</span></div>
    <div class="kv"><span>Place of supply</span><span>{{ ($invoice->place_of_supply ?? null) ?: $company['state'] }}</span></div>
    <div class="kv"><span>Refund method</span><span>{{ $payment->method ?: '—' }}@if($payment->reference) · {{ $payment->reference }}@endif</span></div>
  </div>
</div>

<table>
  <tr><th style="width:64%">Description</th><th>SAC</th><th class="num">Amount ({{ $order->currency ?? 'INR' }})</th></tr>
  <tr>
    <td>Refund / credit against Invoice {{ $invoice->number ?? $order->number }}@if($payment->note) — {{ $payment->note }}@endif</td>
    <td>{{ $invoice->sac_code ?? '997331' }}</td>
    <td class="num">{{ $sym }}{{ number_format($taxable, 2) }}</td>
  </tr>
</table>

<div class="tots">
  <div class="r"><span>Taxable value credited</span><span>{{ $sym }}{{ number_format($taxable, 2) }}</span></div>
  @if ($gst > 0)
    @if ($isIgst)
      <div class="r"><span>IGST @ {{ $fullRate }}% (reversed)</span><span>{{ $sym }}{{ number_format($gst, 2) }}</span></div>
    @else
      <div class="r"><span>CGST @ {{ $halfRate }}% (reversed)</span><span>{{ $sym }}{{ number_format($gst / 2, 2) }}</span></div>
      <div class="r"><span>SGST @ {{ $halfRate }}% (reversed)</span><span>{{ $sym }}{{ number_format($gst / 2, 2) }}</span></div>
    @endif
  @endif
  <div class="r g"><span>Total credited</span><span>{{ $sym }}{{ number_format($refund, 2) }}</span></div>
</div>

@if (($order->currency ?? 'INR') === 'INR')
<div class="words"><b>Amount in words:</b> {{ \App\Support\AmountInWords::convert((float) $refund) }}</div>
@endif

<div class="blocks">
  <div class="bx"><b>Reason for credit</b>
    {{ $payment->note ?: 'Refund processed by Ametecs.' }}
  </div>
  <div class="bx"><b>Note</b>
    This credit note reverses the tax charged on the original invoice to the extent of the amount credited. Retain for your GST records.
  </div>
</div>

<div class="sig"><span class="line">For {{ $company['name'] }} — Authorised Signatory</span></div>

<div class="notes">
  SAC 997331 — licensing services for the right to use computer software (SmartEPT). A credit note is issued under the GST rules against the referenced tax invoice.
</div>

<div class="foot">
  <span>SmartEPT — Employee Productivity Tracking &amp; Intelligence · by {{ $company['name'] }}</span>
  <span>{{ $payment->credit_note_number }}</span>
</div>
</body>
</html>
