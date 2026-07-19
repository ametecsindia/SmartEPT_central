<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico?v=2" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=2">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Pay {{ $order->number }} — SmartEPT by Ametecs</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;min-height:100vh;background:#F4F6F9;color:#15171C;
display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
.card{background:#fff;border-radius:18px;max-width:560px;width:100%;box-shadow:0 18px 50px rgba(21,23,28,.12);overflow:hidden}
.head{background:linear-gradient(160deg,#04252C,#0B4A56);color:#fff;padding:24px 28px}
.head .mk{display:inline-flex;width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);align-items:center;justify-content:center;font-weight:800;font-size:12px;margin-bottom:10px}
.head b{font-size:17px;display:block}
.head span{font-size:12px;color:#9FC5CC}
.body{padding:26px 28px}
.ln{display:flex;justify-content:space-between;gap:14px;padding:9px 0;border-bottom:1px solid #F0F1F4;font-size:13.5px;color:#565A66}
.ln b{color:#15171C;white-space:nowrap}
.tot{display:flex;justify-content:space-between;padding:14px 0 4px;font-size:18px;font-weight:800}
.paybtn{display:block;width:100%;margin-top:16px;padding:14px;border:none;border-radius:10px;font-weight:700;font-size:15px;
color:#fff;background:linear-gradient(135deg,#0E7C8F,#1899AE);cursor:pointer;text-align:center;text-decoration:none;font-family:inherit}
.alt{display:block;width:100%;margin-top:10px;padding:13px;border:1.5px solid #DCDFE7;border-radius:10px;font-weight:700;
font-size:14px;color:#0B6373;background:#fff;cursor:pointer;text-align:center;text-decoration:none;font-family:inherit}
.paid{background:#E6F5EE;color:#08875D;border-radius:12px;padding:18px;text-align:center;font-weight:700;margin-top:14px}
.note{font-size:12px;color:#878C99;margin-top:16px;text-align:center;line-height:1.6}
</style>
</head>
<body>
<div class="card">
  <div class="head"><div class="mk">EPT</div>
    <b>SmartEPT — {{ $order->quote_number ? 'Quotation ' . $order->quote_number : 'Order ' . $order->number }}</b>
    <span>{{ $order->tenant->company_name }} · {{ $order->description }}@if($order->status === 'quote') · awaiting management payment @endif</span>
  </div>
  <div class="body">
    @foreach ($order->line_items as $line)
      <div class="ln"><span>{{ $line['description'] }}</span><b>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($line['amount'], 2) }}</b></div>
    @endforeach
    <div class="ln"><span>GST</span><b>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->tax_amount, 2) }}</b></div>
    <div class="tot"><span>Total</span><span>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->total, 2) }}</span></div>
    @if ($received > 0.01 && $balance > 0.01)
      {{-- Credit client: the same link stays alive showing received vs balance --}}
      <div class="ln"><span>Received so far</span><b style="color:#08875D">− {{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($received, 2) }}</b></div>
      <div class="tot" style="font-size:16px"><span>Balance payable{{ $order->credit_due_date ? ' by ' . $order->credit_due_date->format('d M Y') : '' }}</span><span>{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($balance, 2) }}</span></div>
    @endif

    @if ($order->status === 'paid' || $balance <= 0.01 || request('paid'))
      <div class="paid">✓ Payment received — your licence is active.<br>The GST invoice has been emailed by our team.</div>
    @else
      @if ($razorpayEnabled)
        <button class="paybtn" id="rzpBtn">Pay {{ $received > 0.01 ? 'balance ' : '' }}{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($balance, 2) }} — UPI / Card / NetBanking</button>
      @endif
      @if ($stripeEnabled)
        <a class="alt" href="/pay/{{ $order->number }}/{{ $token }}/stripe">Pay by international card (Stripe)</a>
      @endif
      @if (! $razorpayEnabled && ! $stripeEnabled)
        <div class="note"><b>Online payment is being enabled.</b><br>Meanwhile pay by NEFT/UPI and share the UTR on WhatsApp 90000 98877 — we activate within the hour.</div>
      @endif
    @endif
    <div class="note">Ametecs India Private Limited · GST 36AAHCT0971F1ZB<br>Questions? WhatsApp 90000 98877 · sales@ametecsindia.com</div>
  </div>
</div>

@if ($razorpayEnabled && $order->status !== 'paid')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
document.getElementById('rzpBtn').onclick = async function () {
  const res = await fetch('/pay/{{ $order->number }}/{{ $token }}/razorpay-order', {
    method: 'POST',
    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json'},
  });
  const data = await res.json();
  if (!data.ok) { alert('Could not start payment: ' + (data.error || 'unknown error')); return; }
  const rzp = new Razorpay({
    key: data.key_id,
    order_id: data.razorpay_order_id,
    name: 'SmartEPT by Ametecs',
    description: '{{ $order->number }}',
    prefill: {email: '{{ $order->tenant->email }}', contact: '{{ $order->tenant->phone }}'},
    theme: {color: '#0E7C8F'},
    handler: async function (resp) {
      const cb = await fetch('/pay/{{ $order->number }}/{{ $token }}/razorpay-callback', {
        method: 'POST',
        headers: {'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json'},
        body: JSON.stringify(resp),
      });
      const out = await cb.json();
      if (out.ok) location.href = out.redirect; else alert('Verification failed — contact support.');
    },
  });
  rzp.open();
};
</script>
@endif
</body>
</html>
