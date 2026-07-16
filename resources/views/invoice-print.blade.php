<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Tax Invoice {{ $invoice->number }}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
@page{size:A4;margin:16mm}
body{font-family:'Inter','Segoe UI',sans-serif;color:#15171C;font-size:12px;line-height:1.55}
.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0E7C8F;padding-bottom:12px}
.mk{display:inline-flex;width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);color:#fff;align-items:center;justify-content:center;font-weight:800;font-size:13px}
h1{font-size:20px;color:#0E7C8F;margin-top:6px;letter-spacing:1px}
.co{font-size:11px;color:#565A66;text-align:right;max-width:330px}
.meta{display:flex;justify-content:space-between;margin:14px 0;gap:16px}
.meta .box{background:#FAFBFC;border:1px solid #E7E9EF;border-radius:10px;padding:11px 13px;flex:1;font-size:11.5px}
.meta b{color:#0B6373;display:block;font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px}
.kv{display:flex;justify-content:space-between;gap:10px;padding:1.5px 0}
.kv span:first-child{color:#878C99}
table{width:100%;border-collapse:collapse;margin:6px 0 12px}
th{background:#E3F4F7;color:#0B6373;text-align:left;padding:7px 9px;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px}
td{padding:7px 9px;border-bottom:1px solid #F0F1F4;vertical-align:top}
.num{text-align:right}
.tots{margin-left:auto;width:300px}
.tots .r{display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#565A66}
.tots .g{border-top:2px solid #0E7C8F;font-weight:800;font-size:15px;color:#15171C;padding-top:7px;margin-top:4px}
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
.noprint button{padding:10px 18px;border:none;border-radius:8px;background:#0E7C8F;color:#fff;font-weight:700;cursor:pointer;font-family:inherit}
</style>
</head>
<body>
@php
    // Currency + tax presentation. cgst/sgst/igst are a breakdown of the SAME
    // gst_amount — legacy invoices created before the split have all three at 0
    // and fall back to the original single "GST @ rate" row (totals unchanged).
    $sym = $invoice->currency === 'INR' ? '₹' : '$';
    $cgst = (float) ($invoice->cgst ?? 0);
    $sgst = (float) ($invoice->sgst ?? 0);
    $igst = (float) ($invoice->igst ?? 0);
    $halfRate = rtrim(rtrim(number_format($invoice->gst_rate / 2, 2), '0'), '.');
    $fullRate = rtrim(rtrim(number_format($invoice->gst_rate + 0, 2), '0'), '.');
    $order = $invoice->order;
    $hasBank = $company['bank_account_no'] || $company['upi_id'];
@endphp
<div class="noprint"><button onclick="window.print()">Print / Save PDF</button></div>

<div class="top">
  <div><span class="mk">EPT</span><h1>TAX INVOICE</h1>
    <div style="font-size:12px;color:#565A66">{{ $invoice->number }} · {{ $invoice->date->format('d M Y') }}</div></div>
  <div class="co"><b style="color:#15171C;font-size:13px">{{ $company['name'] }}</b><br>
    {{ $company['address'] }}<br>
    GSTIN: {{ $company['gstin'] }} · State: {{ $company['state'] }}<br>
    {{ $company['phone'] }} · {{ $company['email'] }}</div>
</div>

<div class="meta">
  <div class="box"><b>Billed To</b>
    <span style="font-weight:700">{{ $invoice->tenant->company_name }}</span><br>
    {{ $invoice->tenant->billing_address ?: $invoice->tenant->address }}<br>
    GSTIN: {{ $invoice->buyer_gstin ?: ($invoice->tenant->gstin ?: 'Unregistered') }}<br>
    {{ $invoice->tenant->email }}@if($invoice->tenant->phone) · {{ $invoice->tenant->phone }}@endif</div>
  <div class="box"><b>Invoice Details</b>
    <div class="kv"><span>Invoice no.</span><span>{{ $invoice->number }}</span></div>
    <div class="kv"><span>Invoice date</span><span>{{ $invoice->date->format('d M Y') }}</span></div>
    <div class="kv"><span>Order ref.</span><span>{{ $order?->number ?? '—' }}</span></div>
    <div class="kv"><span>Place of supply</span><span>{{ $invoice->place_of_supply ?: $company['state'] }}</span></div>
    <div class="kv"><span>Reverse charge</span><span>No</span></div>
    <div class="kv"><span>Status</span><span>{{ strtoupper($invoice->status) }}</span></div></div>
</div>

<table>
  <tr><th style="width:52%">Description</th><th>SAC</th><th class="num">Qty</th><th class="num">Amount ({{ $invoice->currency }})</th></tr>
  @foreach ($invoice->line_items as $line)
  <tr><td>{{ $line['description'] }}</td>
  <td>{{ $line['sac'] ?? $invoice->sac_code }}</td>
  <td class="num">{{ $line['qty'] ?? 1 }}</td>
  <td class="num">{{ $sym }}{{ number_format($line['amount'], 2) }}</td></tr>
  @endforeach
</table>

<div class="tots">
  <div class="r"><span>Subtotal (taxable value)</span><span>{{ $sym }}{{ number_format($invoice->subtotal, 2) }}</span></div>
  @if ($cgst > 0 || $sgst > 0)
    <div class="r"><span>CGST @ {{ $halfRate }}%</span><span>{{ $sym }}{{ number_format($cgst, 2) }}</span></div>
    <div class="r"><span>SGST @ {{ $halfRate }}%</span><span>{{ $sym }}{{ number_format($sgst, 2) }}</span></div>
  @elseif ($igst > 0)
    <div class="r"><span>IGST @ {{ $fullRate }}%</span><span>{{ $sym }}{{ number_format($igst, 2) }}</span></div>
  @else
    <div class="r"><span>GST @ {{ $fullRate }}%</span><span>{{ $sym }}{{ number_format($invoice->gst_amount, 2) }}</span></div>
  @endif
  <div class="r g"><span>Grand Total</span><span>{{ $sym }}{{ number_format($invoice->total, 2) }}</span></div>
</div>

@if ($invoice->currency === 'INR')
<div class="words"><b>Amount in words:</b> {{ \App\Support\AmountInWords::convert((float) $invoice->total) }}</div>
@endif

<div class="blocks">
  <div class="bx"><b>Payment</b>
    <div class="kv"><span>Status</span><span>{{ strtoupper($invoice->status) }}</span></div>
    @if ($order)
      <div class="kv"><span>Method</span><span>{{ $order->manual_method ?: ucfirst($order->gateway ?? '—') }}</span></div>
      @if ($order->manual_reference || $order->gateway_payment_id)
      <div class="kv"><span>Reference</span><span>{{ $order->manual_reference ?: $order->gateway_payment_id }}</span></div>
      @endif
      @if ($order->paid_at)
      <div class="kv"><span>Received on</span><span>{{ $order->paid_at->format('d M Y') }}</span></div>
      @endif
    @endif
  </div>
  <div class="bx"><b>Bank &amp; UPI</b>
    @if ($hasBank || $company['bank_name'])
      @if ($company['bank_account_name'])<div class="kv"><span>Account name</span><span>{{ $company['bank_account_name'] }}</span></div>@endif
      @if ($company['bank_name'])<div class="kv"><span>Bank</span><span>{{ $company['bank_name'] }}{{ $company['bank_branch'] ? ', ' . $company['bank_branch'] : '' }}</span></div>@endif
      @if ($company['bank_account_no'])<div class="kv"><span>Account no.</span><span>{{ $company['bank_account_no'] }}</span></div>@endif
      @if ($company['bank_ifsc'])<div class="kv"><span>IFSC</span><span>{{ $company['bank_ifsc'] }}</span></div>@endif
      @if ($company['upi_id'])<div class="kv"><span>UPI</span><span>{{ $company['upi_id'] }}</span></div>@endif
    @else
      Bank details available on request — WhatsApp 90000 98877.
    @endif
  </div>
</div>

<div class="sig">
  <div class="line">For {{ $company['name'] }}<br><br><br>Authorised Signatory</div>
</div>

<div class="notes">
  Whether tax is payable under reverse charge: <b>No</b>. SAC {{ $invoice->sac_code }} — licensing services for the right to use computer software (SmartEPT).
  Subscription/licence fees are non-transferable; refunds per our policy at {{ url('/refunds') }}. Disputes subject to Hyderabad, Telangana jurisdiction.
  This document is electronically generated and does not require a physical signature.
</div>

<div class="foot">
  <span>SmartEPT — Employee Productivity Tracking & Intelligence · by {{ $company['name'] }}</span>
  <span>This is a computer-generated invoice.</span>
</div>
</body>
</html>
