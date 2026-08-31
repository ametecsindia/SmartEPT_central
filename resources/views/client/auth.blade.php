<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico?v=2" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=2">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<meta charset="UTF-8"><meta name="robots" content="noindex,follow">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Client Portal — SmartEPT by Ametecs</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;
background:linear-gradient(160deg,#04252C 0%,#083A44 60%,#0B4A56 100%);padding:24px}
.wrap{display:grid;grid-template-columns:1.08fr 1fr;gap:0;width:100%;max-width:960px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 30px 90px rgba(0,0,0,.45)}
.summary{background:linear-gradient(165deg,#04252C 0%,#0B4A56 100%);color:#fff;padding:30px 30px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:11px;margin-bottom:16px}
.mk{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}
.brand b{font-size:18px;color:#fff}.brand small{display:block;font-size:9px;letter-spacing:2px;color:#7FA8AF}
.plan-name{font-size:21px;font-weight:800;margin-bottom:2px}
.plan-tag{font-size:12px;color:#9FC5CC;margin-bottom:14px}
.ctrl{margin-bottom:11px}
.ctrl>label{display:block;font-size:11px;color:#9FC5CC;font-weight:700;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.dev{display:flex;align-items:center;gap:8px}
.dev button{width:28px;height:28px;border-radius:7px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.08);color:#fff;font-size:16px;font-weight:700;cursor:pointer;line-height:1}
.dev input{width:64px;text-align:center;padding:6px;border-radius:7px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.2);color:#fff;font-size:14px;font-weight:700}
.seg{display:flex;background:rgba(0,0,0,.22);border-radius:8px;padding:3px;gap:2px}
.seg button{flex:1;border:none;background:none;color:#9FC5CC;font-size:11px;font-weight:700;padding:6px 6px;border-radius:6px;cursor:pointer;white-space:nowrap}
.seg button.on{background:#0E7C8F;color:#fff}
.seg button .off{display:block;font-size:9px;color:#7FE0EC;font-weight:700}
.seg button.on .off{color:#CFF3F8}
.inv{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:13px 15px;margin-top:4px}
.inv .ln{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:#CFE0E3;padding:4px 0;line-height:1.35}
.inv .ln.disc b{color:#7FE0EC}
.inv .ln.sub{border-top:1px solid rgba(255,255,255,.12);margin-top:4px;padding-top:8px}
.inv .ln.tot{border-top:1px solid rgba(255,255,255,.18);margin-top:6px;padding-top:9px;font-size:17px;font-weight:800;color:#fff}
.inv .ln b{color:#fff;white-space:nowrap}
.inv .eff{font-size:11px;color:#7FA8AF;margin-top:7px;text-align:right}
.trust{margin-top:14px;font-size:10.5px;color:#7FA8AF;line-height:1.6}
.card{padding:28px 28px;display:flex;flex-direction:column;max-height:94vh;overflow-y:auto}
p.sub{font-size:13px;color:#565A66;margin:0 0 12px}
.tabs{display:flex;gap:6px;margin-bottom:12px}
.tabs button{flex:1;padding:9px 4px;border-radius:9px;border:1.5px solid #DCDFE7;background:#fff;font-weight:700;font-size:12.5px;color:#565A66;cursor:pointer;font-family:inherit}
.tabs button.on{background:#E3F4F7;border-color:#0E7C8F;color:#0B6373}
label{display:block;font-size:12px;font-weight:700;color:#565A66;margin:9px 0 4px}
input,select{width:100%;padding:10px 12px;border:1.5px solid #DCDFE7;border-radius:9px;font-size:14px;font-family:inherit;background:#fff}
input:focus,select:focus{outline:none;border-color:#0E7C8F}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
button.go{width:100%;margin-top:14px;padding:13px;border:none;border-radius:9px;font-weight:700;font-size:14.5px;color:#fff;background:linear-gradient(135deg,#0E7C8F,#1899AE);cursor:pointer;font-family:inherit}
button.go:disabled{opacity:.55;cursor:not-allowed}
a.quote{display:block;text-align:center;margin-top:9px;padding:11px;border:1.5px solid #0E7C8F;border-radius:9px;color:#0B6373;font-weight:700;font-size:13px;text-decoration:none}
.msg{font-size:12.5px;padding:10px 12px;border-radius:8px;margin-top:12px;display:none;line-height:1.5}
.msg.err{background:#FBE9ED;color:#D02748;display:block}
.msg.ok{background:#E6F5EE;color:#08875D;display:block}
.foot{text-align:center;font-size:11px;color:#878C99;margin-top:12px;line-height:1.7}
.foot a{color:#0B6373;font-weight:700;text-decoration:none}
.trial-note{background:#E3F4F7;border-radius:9px;padding:9px 12px;font-size:11.5px;color:#0B6373;margin-top:10px;line-height:1.55}
.hint{font-size:11px;color:#878C99;margin-top:-2px;margin-bottom:2px}
.otp-wrap{display:none}
@media(max-width:820px){.wrap{grid-template-columns:1fr;max-width:440px}.summary{display:none}}
</style>
</head>
<body>
<div class="wrap">
  <aside class="summary">
    <div class="brand" style="flex-direction:column;align-items:flex-start;gap:8px"><img src="/img/smartept-logo-h-dark.png" alt="SmartEPT" style="width:210px;max-width:84%;height:auto;display:block"></div>
    <div class="plan-name" id="sumPlan">SmartEPT</div>
    <div class="plan-tag" id="sumTag">Every standard feature included — free for the first 7 days.</div>

    <div class="ctrl"><label>How you buy</label>
      <div class="seg" id="segKind">
        <button type="button" data-kind="subscription" class="on" id="kCloud">Rent · Cloud</button>
        <button type="button" data-kind="perpetual" id="kPerp">Own · Perpetual</button>
      </div>
    </div>
    <div class="ctrl"><label>Monitored users</label>
      <div class="dev"><button type="button" id="devMinus">&minus;</button><input id="devCount" type="number" min="1" value="25"><button type="button" id="devPlus">+</button></div>
    </div>
    <div class="ctrl" id="advPayCtrl"><label>Advance payment</label>
      <div class="seg">
        <button type="button" data-cyc="q" id="cQ">Quarterly<span class="off">0% off</span></button>
        <button type="button" data-cyc="h" id="cH">6 Months<span class="off">10% off</span></button>
        <button type="button" class="on" data-cyc="y" id="cY">12 Months<span class="off">25% off</span></button>
      </div>
    </div>
    <label style="display:flex;align-items:flex-start;gap:8px;font-size:11.5px;color:#BFD6DA;font-weight:600;margin-bottom:11px;cursor:pointer;text-transform:none;letter-spacing:0">
      <input type="checkbox" id="setupChk" style="width:auto;margin-top:2px;accent-color:#22B8CF">
      <span>Add <b>Remote Assisted Setup &amp; Onboarding</b> <span style="color:#7FA8AF">(one-time — installation assistance, configuration guidance, administrator orientation and go-live support; charged on your first paid order). Optional — you can self-install and add it later.</span></span>
    </label>
    <div class="ctrl" id="couponCtrl"><label>Coupon code</label>
      <div style="display:flex;gap:6px">
        <input id="couponInp" placeholder="e.g. DIWALI25" style="flex:1;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.2);color:#fff;font-size:12px;text-transform:uppercase">
        <button type="button" id="couponBtn" style="padding:7px 12px;border-radius:7px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:#fff;font-size:11.5px;font-weight:700;cursor:pointer">Apply</button>
      </div>
      <div id="couponMsg" style="font-size:10.5px;margin-top:5px;color:#7FE0EC"></div>
    </div>

    <div class="inv" id="inv">
      <div class="ln"><span id="ivSubLbl">Subtotal</span><b id="ivSub">—</b></div>
      <div class="ln disc" id="ivDiscRow" style="display:none"><span id="ivDiscLbl">Advance discount</span><b id="ivDisc">—</b></div>
      <div class="ln" id="ivSetupRow" style="display:none"><span>Setup &amp; onboarding (one-time)</span><b id="ivSetup">—</b></div>
      <div class="ln disc" id="ivCoupRow" style="display:none"><span id="ivCoupLbl">Coupon</span><b id="ivCoup">—</b></div>
      <div class="ln sub"><span>GST 18%</span><b id="ivGst">—</b></div>
      <div class="ln tot"><span id="ivTotLbl">Payable now</span><b id="ivTot">—</b></div>
      <div class="eff" id="ivEff"></div>
    </div>
    <div class="trust">SmartEPT Managed Cloud — we host it, every standard feature on. Managed hosting is included, and each subscribed user contributes 500&nbsp;MB to your organisation&rsquo;s pooled storage; additional storage is &#8377;3 per GB per month, with standard retention configurable up to a maximum of three months. First invoice includes one-time Remote Assisted Setup (if selected); renewals bill the subscription only. Prefer to own it? <b>Perpetual licences from &#8377;25,000</b> — just ask.</div>
  </aside>

  <div class="card">
  <p class="sub"><b>New to SmartEPT?</b> Start a 7-day free trial below — or <a href="/buy" style="color:#0B6373;font-weight:700">ready to buy? Get instant activation →</a> <b>Already a customer?</b> Sign in below.</p>
  <div class="tabs">
    <button class="on" data-mode="login" onclick="mode('login',this)">Sign in</button>
    <button data-mode="signup" onclick="mode('signup',this)">Start free trial</button>
    <button data-mode="forgot" onclick="mode('forgot',this)">Forgot password</button>
  </div>
  <form id="f-login" onsubmit="return doLogin(event)">
    <label>Email</label><input type="email" name="email" required autocomplete="username">
    <label>Password</label><input type="password" name="password" required autocomplete="current-password">
    <button class="go" type="submit">Sign in →</button>
  </form>
  <form id="f-signup" style="display:none" onsubmit="return doSignup(event)">
    {{-- Honeypot (Ejaz, 7-Aug): invisible to humans; bots that fill it get a fake success and no trial/OTP is created. --}}
    <div style="position:absolute;left:-5000px;top:-5000px;height:1px;overflow:hidden" aria-hidden="true"><input type="text" name="website_hp" tabindex="-1" autocomplete="off"></div>
    <label>Company name</label><input name="company_name" required maxlength="190">
    <div class="row">
      <div><label>Your name</label><input name="contact_name" required maxlength="190"></div>
      <div><label>Mobile</label><input name="phone" maxlength="20" placeholder="98480 12345"></div>
    </div>
    <label>Work email</label><input type="email" name="email" required maxlength="190">
    <label>Choose a password (min 8 characters)</label><input type="password" name="password" required minlength="8" autocomplete="new-password">
    <div class="row">
      <div><label>State <span style="color:#D02748">*</span></label><select name="state_code" id="stateSel" required><option value="">Select…</option></select></div>
      <div><label>GSTIN <span style="font-weight:400;color:#878C99">(optional)</span></label><input name="gstin" id="gstinInp" maxlength="15" placeholder="36AAHCT0971F1ZB" style="text-transform:uppercase"></div>
    </div>
    <div class="hint">Your state decides how GST appears on your invoice — CGST+SGST for Telangana, IGST for other states. GSTIN lets you claim input credit.</div>
    <input type="hidden" name="plan" id="planField">
    <input type="hidden" name="device_estimate" id="devField">
    <label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#565A66;font-weight:600;margin-top:10px;cursor:pointer">
      <input type="checkbox" name="terms_accepted" required style="width:auto;margin-top:2px;accent-color:#0E7C8F">
      <span>I agree to the <a href="/terms" target="_blank" style="color:#0B6373;font-weight:700">Terms</a> and <a href="/refunds" target="_blank" style="color:#0B6373;font-weight:700">Refund policy</a></span>
    </label>
    <div class="otp-wrap" id="signupOtp">
      <label>Enter the 6-digit code we emailed you</label>
      <input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••">
    </div>
    <button class="go" type="submit" id="signupBtn">Email me a verification code →</button>
    <a class="quote" id="quoteCta" href="#" target="_blank">Prefer a formal quotation? Request one →</a>
    <div class="trial-note"><b>7-day full-platform evaluation · every standard feature · up to 10 monitored employees.</b><br>No card needed. Biometric, AD/SSO, client-hosted and other infrastructure-dependent integrations require assisted setup. Trial data is scheduled for deletion within 14 days after trial expiry unless you activate a paid service.</div>
  </form>
  <form id="f-forgot" style="display:none" onsubmit="return doForgot(event)">
    <label>Account email</label><input type="email" name="email" required>
    <div class="otp-wrap" id="forgotOtp">
      <label>6-digit code from your email</label>
      <input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••">
      <label>New password (min 8 characters)</label>
      <input type="password" name="password" minlength="8" autocomplete="new-password">
    </div>
    <button class="go" type="submit" id="forgotBtn">Email me a reset code →</button>
  </form>
  <div class="msg" id="msg"></div>
  <div class="foot">Prefer a human? WhatsApp <a href="https://wa.me/919000098877?text=Hi%20Ametecs" target="_blank">90000 98877</a><br>
  Ametecs India Private Limited · sales@ametecsindia.com<br>
  <a href="/privacy">Privacy</a> · <a href="/terms">Terms</a> · <a href="/refunds">Refunds</a> · <a href="/contact">Contact</a><br>
  <span style="opacity:.8">SmartEPT · Developed by Ametecs India Private Limited · © 2026. All rights reserved.</span></div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
let signupStep = 1, forgotStep = 1;
let PLANS=[], GST=18, SETUP={base:5000,included:30,per:100}, ANN_DISC=0.25, HALF_DISC=0.10;
// Per-user/month cloud rate — mirrors backend PricingService::deviceRate exactly (config-driven, rounded).
function cloudRate(annual,cyc){ const base=annual/Math.max(0.1,1-ANN_DISC); if(cyc==='y') return annual; if(cyc==='h') return Math.round(base*(1-HALF_DISC)*100)/100; return Math.round(base*100)/100; }
let SEL='smartept', KIND='subscription', CYC='y', COUPON=null;
// Progressive lifetime pricing (11-Aug-2026) — mirrors backend PricingService::
// calculateLifetimeLicencePrice(): milestone price AT each max-users point,
// straight-line interpolation between milestones, flat minimum first band.
// Fallback values only — overwritten by the live admin-saved bands from /api/plans.
const PERP_CFG={min:1,miles:[{u:30,p:25000},{u:100,p:50000},{u:250,p:85000},{u:500,p:125000},{u:1000,p:200000},{u:2000,p:325000},{u:5000,p:500000}]};
function perpPriceFor(u){
  const m=PERP_CFG.miles; if(!m.length) return {custom:true,top:0};
  if(u<PERP_CFG.min) return {belowMin:true,min:PERP_CFG.min};
  if(u<=m[0].u) return {price:m[0].p};
  for(let i=1;i<m.length;i++){
    if(u<=m[i].u){const a=m[i-1],b=m[i];
      return {price:Math.round(a.p+(u-a.u)*(b.p-a.p)/(b.u-a.u))};}
  }
  return {custom:true,top:m[m.length-1].u};
}
const PERIOD = {q:{m:3,d:0,label:'Quarterly (3 months)'}, h:{m:6,d:0.10,label:'6-month advance'}, y:{m:12,d:0.25,label:'12-month advance'}};

const STATES=[['37','Andhra Pradesh'],['12','Arunachal Pradesh'],['18','Assam'],['10','Bihar'],['22','Chhattisgarh'],['30','Goa'],['24','Gujarat'],['06','Haryana'],['02','Himachal Pradesh'],['20','Jharkhand'],['29','Karnataka'],['32','Kerala'],['23','Madhya Pradesh'],['27','Maharashtra'],['14','Manipur'],['17','Meghalaya'],['15','Mizoram'],['13','Nagaland'],['21','Odisha'],['03','Punjab'],['08','Rajasthan'],['11','Sikkim'],['33','Tamil Nadu'],['36','Telangana'],['16','Tripura'],['09','Uttar Pradesh'],['05','Uttarakhand'],['19','West Bengal'],['07','Delhi'],['04','Chandigarh'],['01','Jammu & Kashmir'],['26','Dadra & Nagar Haveli and Daman & Diu'],['31','Lakshadweep'],['35','Andaman & Nicobar'],['38','Ladakh'],['34','Puducherry']];

function mode(m,btn){document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('on',b.dataset.mode===m));['login','signup','forgot'].forEach(x=>document.getElementById('f-'+x).style.display=x===m?'':'none');show('','');}
function show(kind,text){const el=document.getElementById('msg');el.className='msg'+(kind?' '+kind:'');el.textContent=text;el.style.display=kind?'block':'none';}
async function post(url,data){const res=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify(data)});let body={};try{body=await res.json();}catch(e){}if(!res.ok)throw new Error(body.error||(body.errors?Object.values(body.errors).flat().join(' '):body.message)||(res.status===419?'Your session expired — please refresh the page and try again.':res.status===429?'Too many tries — please wait a minute.':'Something went wrong.'));return body;}
const formData=f=>Object.fromEntries(new FormData(f).entries());
async function doLogin(e){e.preventDefault();try{const out=await post('/client/login',formData(e.target));location.href=out.redirect||'/client';}catch(err){show('err',err.message);}return false;}
async function doSignup(e){e.preventDefault();const f=e.target,data=formData(f),btn=document.getElementById('signupBtn');btn.disabled=true;try{if(signupStep===1){const out=await post('/client/signup/request-otp',data);signupStep=2;document.getElementById('signupOtp').style.display='block';btn.textContent='Verify code & start my trial →';show('ok',out.message);}else{if(!/^[0-9]{6}$/.test(data.otp||''))throw new Error('Please enter the 6-digit code.');const out=await post('/client/signup/verify',data);show('ok','Trial activated — opening your portal…');location.href=out.redirect||'/client';}}catch(err){show('err',err.message);}btn.disabled=false;return false;}
async function doForgot(e){e.preventDefault();const f=e.target,data=formData(f),btn=document.getElementById('forgotBtn');btn.disabled=true;try{if(forgotStep===1){const out=await post('/client/forgot/request-otp',{email:data.email});forgotStep=2;document.getElementById('forgotOtp').style.display='block';btn.textContent='Set new password →';show('ok',out.message);}else{if(!/^[0-9]{6}$/.test(data.otp||''))throw new Error('Please enter the 6-digit code.');if(!data.password||data.password.length<8)throw new Error('New password needs at least 8 characters.');const out=await post('/client/forgot/reset',data);show('ok',out.message+' Use the Sign in tab above.');forgotStep=1;document.getElementById('forgotOtp').style.display='none';btn.textContent='Email me a reset code →';}}catch(err){show('err',err.message);}btn.disabled=false;return false;}

const inr=n=>'₹'+Math.round(n).toLocaleString('en-IN');
function annualRate(plan,dev){const p=PLANS[0]||null;if(!p)return{r:0,p:null};let r=p.inr_annual;(p.volume_tiers||[]).forEach(t=>{if(dev>=t.min&&(t.max===null||dev<=t.max))r=t.rate;});return{r,p};}
function render(){
  const dev=Math.max(1,parseInt(document.getElementById('devCount').value||'1',10));
  document.getElementById('devField').value=dev;
  const {r:aRate,p}=annualRate(SEL,dev); if(!p)return;
  const wantSetup=document.getElementById('setupChk').checked;
  const setup=wantSetup?(SETUP.base+Math.max(0,dev-SETUP.included)*SETUP.per):0;
  const cr=document.getElementById('ivCoupRow');
  const dr=document.getElementById('ivDiscRow');
  document.getElementById('ivSetupRow').style.display=wantSetup?'':'none';
  document.getElementById('ivSetup').textContent=inr(setup);

  if(KIND==='perpetual'){
    dr.style.display='none';
    const pc=perpPriceFor(dev);
    if(pc.belowMin){
      document.getElementById('ivSubLbl').textContent='Perpetual licence';
      document.getElementById('ivSub').textContent='—';
      cr.style.display='none';
      document.getElementById('ivGst').textContent='—';
      document.getElementById('ivTotLbl').textContent='Below minimum';
      document.getElementById('ivTot').textContent='—';
      document.getElementById('ivEff').textContent='Minimum On-Premise licence capacity is '+pc.min.toLocaleString('en-IN')+' users.';
      return;
    }
    if(pc.custom){
      document.getElementById('ivSubLbl').textContent='Perpetual · '+(pc.top?pc.top.toLocaleString('en-IN')+'+ users':'custom');
      document.getElementById('ivSub').textContent='Custom';
      cr.style.display='none';
      document.getElementById('ivGst').textContent='—';
      document.getElementById('ivTotLbl').textContent='Custom quotation';
      document.getElementById('ivTot').textContent='—';
      document.getElementById('ivEff').textContent='For '+(pc.top?pc.top.toLocaleString('en-IN')+'+':'this many')+' users we prepare a custom quote.';
      return;
    }
    let taxable=pc.price+setup, coupDisc=0;
    if(COUPON){coupDisc=COUPON.type==='percent'?taxable*COUPON.value/100:Math.min(COUPON.value,taxable);
      cr.style.display='';document.getElementById('ivCoupLbl').textContent='Coupon '+COUPON.code+(COUPON.type==='percent'?' ('+COUPON.value+'% off)':'');
      document.getElementById('ivCoup').textContent='− '+inr(coupDisc);taxable-=coupDisc;}
    else cr.style.display='none';
    const gstAmt=taxable*GST/100, total=taxable+gstAmt;
    document.getElementById('ivSubLbl').textContent='Perpetual licence · '+dev.toLocaleString('en-IN')+' user'+(dev>1?'s':'');
    document.getElementById('ivSub').textContent=inr(pc.price);
    document.getElementById('ivGst').textContent=inr(gstAmt);
    document.getElementById('ivTotLbl').textContent='One-time total';
    document.getElementById('ivTot').textContent=inr(total);
    document.getElementById('ivEff').textContent='One-time · you own it · optional AMC 15–20%/yr on prevailing prices';
    return;
  }

  const qRate=Math.round(aRate/Math.max(0.1,1-ANN_DISC)*100)/100; // quarterly base (undiscounted)
  const rate=cloudRate(aRate,CYC);                                 // actual per-user/month (matches backend)
  const per=PERIOD[CYC];
  const refGross=dev*qRate*per.m;                                  // amount at quarterly base (for the "you save" line)
  const sub=Math.round(dev*rate*per.m*100)/100;                    // licence charge — identical to backend invoice
  const cycDisc=CYC==='y'?ANN_DISC:(CYC==='h'?HALF_DISC:0);
  const discAmt=Math.max(0,refGross-sub);
  let taxable=sub+setup, coupDisc=0;
  if(COUPON){coupDisc=COUPON.type==='percent'?taxable*COUPON.value/100:Math.min(COUPON.value,taxable);
    cr.style.display='';document.getElementById('ivCoupLbl').textContent='Coupon '+COUPON.code+(COUPON.type==='percent'?' ('+COUPON.value+'% off)':'');
    document.getElementById('ivCoup').textContent='− '+inr(coupDisc);taxable-=coupDisc;}
  else cr.style.display='none';
  const gstAmt=taxable*GST/100, total=taxable+gstAmt;
  const effPerMo=sub/dev/per.m;
  document.getElementById('ivSubLbl').textContent='Cloud · '+dev+' user'+(dev>1?'s':'')+' × '+per.m+' mo';
  document.getElementById('ivSub').textContent=inr(refGross);
  if(discAmt>0.5){dr.style.display='';document.getElementById('ivDiscLbl').textContent='Advance discount ('+Math.round(cycDisc*100)+'%)';document.getElementById('ivDisc').textContent='− '+inr(discAmt);}else dr.style.display='none';
  document.getElementById('ivGst').textContent=inr(gstAmt);
  document.getElementById('ivTotLbl').textContent='Payable now ('+per.m+'-month advance)';
  document.getElementById('ivTot').textContent=inr(total);
  document.getElementById('ivEff').textContent='≈ '+inr(effPerMo)+' /user/month · GST extra shown above';
}

(async function init(){
  const sel=document.getElementById('stateSel');
  STATES.slice().sort((a,b)=>a[1].localeCompare(b[1])).forEach(([c,n])=>{const o=document.createElement('option');o.value=c;o.textContent=n+' ('+c+')';sel.appendChild(o);});
  const params=new URLSearchParams(location.search);const plan=(params.get('plan')||'').toLowerCase();
  SEL='smartept';
  document.getElementById('planField').value=SEL;
  const buyParam=(params.get('buy')||'').toLowerCase();
  // Phase 1 (6-Aug-2026): buyers belong on the /buy page — this page is for the
  // free trial and sign-in. Old ?buy= links land on the real cart instead.
  // 31-Aug-2026: carry the user count through the hop as well — it used to be
  // dropped here, so anyone arriving via an old ?buy= link (or a bookmark) landed
  // on /buy with the hard-coded default of 25 instead of the size they picked.
  if(buyParam){
    const u=parseInt(params.get('users')||params.get('devices')||'0',10);
    location.replace('/buy?kind='+(buyParam==='perpetual'?'perpetual':'cloud')+(u>0?'&users='+u:''));
    return;
  }
  KIND = 'subscription';
  if(plan)mode('signup',document.querySelector('.tabs button[data-mode=signup]'));
  // controls
  const dc=document.getElementById('devCount');
  document.getElementById('devMinus').onclick=()=>{dc.value=Math.max(1,(parseInt(dc.value||'1',10)-5));render();upd();};
  document.getElementById('devPlus').onclick=()=>{dc.value=(parseInt(dc.value||'1',10)+5);render();upd();};
  dc.oninput=()=>{render();upd();};
  function segCyc(v){CYC=v;['q','h','y'].forEach(k=>document.getElementById('c'+k.toUpperCase()).classList.toggle('on',k===v));render();}
  document.getElementById('cQ').onclick=()=>segCyc('q');
  document.getElementById('cH').onclick=()=>segCyc('h');
  document.getElementById('cY').onclick=()=>segCyc('y');
  function segKind(v){KIND=v;document.getElementById('kCloud').classList.toggle('on',v==='subscription');document.getElementById('kPerp').classList.toggle('on',v==='perpetual');const ap=document.getElementById('advPayCtrl');if(ap)ap.style.display=(v==='perpetual'?'none':'');document.getElementById('sumPlan').textContent=(v==='perpetual'?'SmartEPT Perpetual':'SmartEPT Managed Cloud');document.getElementById('sumTag').textContent=(v==='perpetual'?'One-time, client-hosted licence — never expires. First 12 months of updates & support included; optional AMC from Year 2.':'Every standard feature included. Managed hosting + 500 MB pooled storage per user; additional storage ₹3/GB/month. Free for the first 7 days.');try{const u=new URL(location.href);u.searchParams.set('buy',v==='perpetual'?'perpetual':'cloud');history.replaceState({},'',u);}catch(e){}render();upd();}
  document.getElementById('kCloud').onclick=()=>segKind('subscription');
  document.getElementById('kPerp').onclick=()=>segKind('perpetual');
  document.getElementById('setupChk').onchange=render;
  // ---- Coupon apply (public coupon-check keeps signup, portal and admin on the same rules) ----
  const cMsg=document.getElementById('couponMsg');
  async function applyCoupon(code,quiet){
    code=(code||'').trim().toUpperCase();
    if(!code){COUPON=null;cMsg.textContent='';render();return;}
    try{
      const dev=Math.max(1,parseInt(document.getElementById('devCount').value||'1',10));
      const email=(document.querySelector('#f-signup [name=email]')?.value||'').trim();
      const r=await fetch('/api/v1/public/coupon-check',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify({code,devices:dev,email:email||null})});
      const j=await r.json();
      if(j.ok){COUPON={code:j.code,type:j.type,value:j.value};cMsg.style.color='#7FE0EC';cMsg.textContent='✓ '+j.code+' applied'+(j.description?' — '+j.description:'');document.getElementById('couponInp').value=j.code;}
      else{COUPON=null;cMsg.style.color='#FFB3C0';cMsg.textContent=quiet?'':'✗ Code not valid ('+(j.reason||'unknown')+')';}
    }catch(e){if(!quiet){cMsg.style.color='#FFB3C0';cMsg.textContent='Could not check the code — try again.';}}
    render();
  }
  document.getElementById('couponBtn').onclick=()=>applyCoupon(document.getElementById('couponInp').value,false);
  document.getElementById('couponInp').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();applyCoupon(e.target.value,false);}});
  // ---- Exclusive-offer catch (blueprint §6): email typed → quietly check for a personal coupon ----
  const emailInp=document.querySelector('#f-signup [name=email]');
  if(emailInp)emailInp.addEventListener('blur',async()=>{
    const email=emailInp.value.trim();
    if(!email||COUPON)return;
    try{
      const r=await fetch('/api/v1/public/exclusive-offer',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify({email})});
      const j=await r.json();
      if(j.ok){COUPON={code:j.code,type:j.type,value:j.value};document.getElementById('couponInp').value=j.code;
        cMsg.style.color='#7FE0EC';cMsg.textContent='🎁 An exclusive offer for you was waiting — '+j.code+' applied automatically!';render();}
    }catch(e){}
  });
  try{
    const r=await fetch('/api/v1/public/plans',{headers:{Accept:'application/json'},cache:'no-store'});const j=await r.json();
    PLANS=(j.plans||j.data||[]);GST=j.gst_rate||18;
    if(j.setup)SETUP={base:j.setup.base,included:j.setup.included,per:j.setup.per_extra};
    if(j.cycles){if(j.cycles.annual_discount!=null)ANN_DISC=+j.cycles.annual_discount;if(j.cycles.half_yearly_discount!=null)HALF_DISC=+j.cycles.half_yearly_discount;}
    {const oh=document.querySelector('#cH .off');if(oh)oh.textContent=Math.round(HALF_DISC*100)+'% off';const oy=document.querySelector('#cY .off');if(oy)oy.textContent=Math.round(ANN_DISC*100)+'% off';}
    if(PLANS[0]&&Array.isArray(PLANS[0].perpetual_bands)){const pb=PLANS[0].perpetual_bands.filter(b=>b.max!=null&&b.price!=null&&b.price>0).map(b=>({u:b.max,p:b.price,min:b.min})).sort((a,b)=>a.u-b.u);if(pb.length){PERP_CFG.min=Math.min(...pb.map(b=>b.min));PERP_CFG.miles=pb.map(b=>({u:b.u,p:b.p}));}}
    document.getElementById('sumPlan').textContent='SmartEPT';
    document.getElementById('sumTag').textContent='Every feature included — free for the first 7 days.';
    render();
  }catch(e){document.getElementById('inv').style.opacity=.5;}
  function upd(){const dev=document.getElementById('devCount').value;const t=KIND==='perpetual'?('Hi Ametecs, I would like a quotation for SmartEPT Perpetual — '+dev+' users (one-time licence).'):('Hi Ametecs, I would like a quotation for SmartEPT Cloud — '+dev+' users, '+PERIOD[CYC].label+'.');document.getElementById('quoteCta').href='https://wa.me/919000098877?text='+encodeURIComponent(t);}
  segKind(KIND);
  window.upd=upd; upd();
})();
</script>
</body>
</html>
