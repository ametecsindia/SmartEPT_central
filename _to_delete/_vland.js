
/* ===== mobile nav ===== */
(function(){
  var links=document.getElementById('navLinks'), h=document.getElementById('navToggle');
  if(!links||!h) return;
  function shut(){ links.classList.remove('openm'); h.setAttribute('aria-expanded','false'); }
  h.addEventListener('click', function(e){ e.stopPropagation(); var o=links.classList.toggle('openm'); h.setAttribute('aria-expanded', o?'true':'false'); });
  Array.prototype.forEach.call(links.querySelectorAll('a'), function(a){ a.addEventListener('click', shut); });
  document.addEventListener('click', function(e){ if(links.classList.contains('openm') && !links.contains(e.target) && !h.contains(e.target)) shut(); });
  window.addEventListener('resize', function(){ if(window.innerWidth>960) shut(); });
})();
/* ===== pricing (v2: single product, priced by user scale) ===== */
const NPX_BANDS = [
  {cap:30,   label:'Up to 30',    cloud:45, perp:25000},
  {cap:100,  label:'Up to 100',   cloud:28, perp:50000},
  {cap:250,  label:'Up to 250',   cloud:20, perp:85000},
  {cap:500,  label:'Up to 500',   cloud:15, perp:125000},
  {cap:1000, label:'Up to 1,000', cloud:12, perp:200000},
  {cap:2000, label:'Up to 2,000', cloud:9,  perp:325000},
  {cap:5000, label:'Up to 5,000', cloud:6,  perp:500000},
  {cap:null, label:'5,000+',      custom:true}
];
const NPX_FEATURES = ['Attendance & shift adherence','Productive vs idle time','App & website usage','Screenshots (policy-driven)','Webcam presence photos','Live status & live screen','Meetings & scheduling','Productivity scoring','AI-tool usage insights','Reports, exports & payroll pack','Restrictions & USB controls','Biometric gate-to-PC','Multi-office & departments','Role-based access & SSO','Unlimited manager accounts','Full API access','Screen recording','Employee data archive & backup'];
let npxSel=0, npxCycle='annual';
let npxAnnualDisc=0.25, npxHalfDisc=0.10, npxSetupBase=5000, npxSetupIncl=30, npxSetupPer=100, npxAmc='15\u201320';
const npxINR = n => '\u20B9'+Number(n).toLocaleString('en-IN');
function npxRate(annual,c){ const base=annual/Math.max(0.1,1-npxAnnualDisc); if(c==='annual') return annual; if(c==='half_yearly') return Math.round(base*(1-npxHalfDisc)*100)/100; return Math.round(base*100)/100; }
function npxSetup(cap){ return npxSetupBase+Math.max(0,cap-npxSetupIncl)*npxSetupPer; }
function npxRender(){
  const b=NPX_BANDS[npxSel];
  const camt=document.getElementById('npxCloudAmt'), crun=document.getElementById('npxCloudRun'), cset=document.getElementById('npxCloudSetup');
  const pamt=document.getElementById('npxPerpAmt'), pper=document.getElementById('npxPerpPer'), pset=document.getElementById('npxPerpSetup'), pamc=document.getElementById('npxPerpAmc');
  const cyclbl={quarterly:'quarterly',half_yearly:'half-yearly',annual:'annually'}[npxCycle];
  if(b.custom){
    camt.textContent='\u2014'; crun.textContent='Custom quotation for 5,000+ users'; cset.innerHTML='Talk to sales for volume setup.';
    pamt.textContent='Custom'; pper.textContent='quotation \u00B7 5,000+ users'; pset.innerHTML='Volume setup quoted with the licence.'; pamc.innerHTML='+ Optional AMC '+npxAmc+'%/year on prevailing prices.';
  } else {
    const r=npxRate(b.cloud,npxCycle);
    camt.textContent=(r%1===0)?r:r.toFixed(2);
    crun.textContent='for '+b.label.toLowerCase()+' users \u00B7 billed '+cyclbl;
    cset.innerHTML='+ <b>'+npxINR(npxSetup(b.cap))+'</b> one-time setup (self-service)';
    pamt.textContent=Number(b.perp).toLocaleString('en-IN');
    pper.textContent='one-time \u00B7 '+b.label.toLowerCase()+' users';
    pset.innerHTML='+ <b>'+npxINR(npxSetup(b.cap))+'</b> one-time setup (self-service)';
    pamc.innerHTML='+ Optional AMC <b>'+npxAmc+'%/year</b> (updates & support), on prevailing prices.';
  }
  document.querySelectorAll('#npxChips .npx-chip').forEach((c,i)=>c.classList.toggle('on',i===npxSel));
  document.querySelectorAll('#npxCyc button').forEach(x=>x.classList.toggle('on',x.dataset.c===npxCycle));
}
(function npxInit(){
  const chips=document.getElementById('npxChips'); if(!chips) return;
  NPX_BANDS.forEach((b,i)=>{ const el=document.createElement('button'); el.className='npx-chip'+(i===0?' on':''); el.textContent=b.custom?'5,000+':b.label.replace('Up to ',''); el.onclick=()=>{npxSel=i;npxRender();}; chips.appendChild(el); });
  document.querySelectorAll('#npxCyc button').forEach(btn=>btn.onclick=()=>{npxCycle=btn.dataset.c;npxRender();});
  const fg=document.getElementById('npxFeatures');
  NPX_FEATURES.forEach(f=>{ const d=document.createElement('div'); d.className='npx-fi'; d.innerHTML='<b>\u2713</b> <span>'+f+'</span>'; fg.appendChild(d); });
  npxRender();
})();
/* ===== live pricing: wire calculator + hero to admin bands (Cloud + Lifetime) ===== */
function npxMerge(p){
  const vts=(p.volume_tiers||[]).slice().sort((a,b)=>a.min-b.min);
  const pbs=(p.perpetual_bands||[]).slice().sort((a,b)=>a.min-b.min);
  if(!vts.length && !pbs.length) return [];
  const bounds=[...new Set([].concat(vts,pbs).map(x=>x.max))].filter(x=>x!=null).sort((a,b)=>a-b);
  const cloudFor=cap=>{const t=vts.find(t=>(t.max==null||cap<=t.max)&&cap>=t.min);return t?t.rate:(p.inr_annual||0);};
  const perpFor=cap=>{const b=pbs.find(b=>(b.max==null||cap<=b.max)&&cap>=b.min);return b?b.price:null;};
  const bands=bounds.map(cap=>({cap:cap,label:'Up to '+Number(cap).toLocaleString('en-IN'),cloud:cloudFor(cap),perp:perpFor(cap)}));
  if(vts.some(t=>t.max==null)||pbs.some(b=>b.max==null)){const top=bounds.length?bounds[bounds.length-1]:0;bands.push({cap:null,label:(top?Number(top).toLocaleString('en-IN'):'5,000')+'+',custom:true});}
  return bands;
}
function npxUpdateHero(entry){
  if(!entry||entry.custom||entry.perp==null) return;
  const pe=document.querySelector('.ps-price'); if(pe) pe.textContent='₹'+Number(entry.perp).toLocaleString('en-IN');
}
(function npxLive(){
  const chips=document.getElementById('npxChips'); if(!chips || location.protocol==='file:') return;
  fetch('/api/v1/public/plans',{headers:{Accept:'application/json'}}).then(r=>r.ok?r.json():null).then(d=>{
    if(!d||!d.plans) return;
    const p=d.plans.find(x=>x.code==='smartept')||d.plans[0]; if(!p) return;
    const nb=npxMerge(p); if(!nb.length) return;
    NPX_BANDS.length=0; nb.forEach(x=>NPX_BANDS.push(x));
    if(d.cycles){ if(d.cycles.annual_discount!=null) npxAnnualDisc=+d.cycles.annual_discount; if(d.cycles.half_yearly_discount!=null) npxHalfDisc=+d.cycles.half_yearly_discount; }
    if(d.setup){ if(d.setup.base) npxSetupBase=+d.setup.base; if(d.setup.included) npxSetupIncl=+d.setup.included; if(d.setup.per_extra) npxSetupPer=+d.setup.per_extra; }
    if(d.amc_pct) npxAmc=(''+d.amc_pct).replace(/\.0+$/,'');
    npxSel=0; chips.innerHTML='';
    NPX_BANDS.forEach((b,i)=>{ const el=document.createElement('button'); el.className='npx-chip'+(i===0?' on':''); el.textContent=b.custom?(b.label||'5,000+'):b.label.replace('Up to ',''); el.onclick=()=>{npxSel=i;npxRender();}; chips.appendChild(el); });
    npxRender(); npxUpdateHero(NPX_BANDS[0]);
  }).catch(()=>{});
})();
/* ===== FAQ ===== */
function fq(btn){
  const item = btn.parentElement, a = item.querySelector('.faq-a'), open = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(i=>{i.classList.remove('open');i.querySelector('.faq-a').style.maxHeight=null;});
  if(!open){ item.classList.add('open'); a.style.maxHeight = a.scrollHeight+'px'; }
}
/* ===== WhatsApp lead ===== */
function sendWA(e){
  e.preventDefault();
  const name=document.getElementById('lfName').value, co=document.getElementById('lfCo').value,
        ph=document.getElementById('lfPhone').value, dev=document.getElementById('lfDev').value,
        plan=document.getElementById('lfPlan').value;
  const msg = `Hi Ametecs, I'm ${name} from ${co}. We have ${dev} devices and we're interested in SmartEPT — ${plan}. Please send a quote.`;
  /* Real lead capture (blueprint §5): the enquiry also lands in /admin → Leads
     with an instant email to sales — fire-and-forget, never blocks WhatsApp. */
  if (location.protocol !== 'file:') {
    try { fetch('/api/v1/public/leads', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({name:name, company:co, phone:ph,
        devices_interested: parseInt(dev,10)||null, message:'Interested in: '+plan+' ('+dev+' devices)', source:'website'})}).catch(()=>{}); } catch(err){}
  }
  window.open((window.WA_BASE||'https://wa.me/919000098877')+'?text='+encodeURIComponent(msg),'_blank');
  return false;
}
/* Live rates: landing now uses the fixed v2 bands above (edit here or wire to /api/v1/public/plans later). */

/* ===== CMS-EDITABLE CONTENT from SmartEPT Central (blueprint §5) =====
   Super Admin edits hero text, announcement bar, contact details and
   testimonials in /admin → Landing CMS. Empty values keep this page's
   built-in copy — offline/file:// preview is untouched. */
(function cmsContent(){
  if (location.protocol === 'file:') return;
  fetch('/api/v1/public/content', {headers:{Accept:'application/json'}})
    .then(r => r.ok ? r.json() : null)
    .then(c => {
      if (!c) return;
      const heroH1 = document.querySelector('.hero-in h1');
      const heroLead = document.querySelector('.hero-in .lead');
      if (c.landing_hero_title && heroH1) heroH1.textContent = c.landing_hero_title;
      if (c.landing_hero_subtitle && heroLead) heroLead.textContent = c.landing_hero_subtitle;
      if (c.landing_contact_phone) { const el = document.getElementById('ctaPhone'); if (el) el.textContent = c.landing_contact_phone; }
      if (c.landing_contact_email) { const el = document.getElementById('ctaEmail'); if (el) el.textContent = c.landing_contact_email; }
      if (c.whatsapp_number && /^[0-9]{10,14}$/.test(c.whatsapp_number)) window.WA_BASE = 'https://wa.me/' + c.whatsapp_number;
      if (c.landing_announcement) {
        const bar = document.createElement('div');
        bar.style.cssText = 'background:linear-gradient(135deg,#0E7C8F,#1899AE);color:#fff;text-align:center;padding:9px 16px;font:600 13.5px Inter,\'Segoe UI\',sans-serif;letter-spacing:.2px';
        bar.textContent = c.landing_announcement;
        document.body.insertBefore(bar, document.body.firstChild);
      }
      if (Array.isArray(c.landing_testimonials) && c.landing_testimonials.length) {
        const cta = document.getElementById('contact');
        if (cta) {
          const sec = document.createElement('section');
          sec.style.cssText = 'padding:64px 0;background:#FFFFFF';
          sec.innerHTML = '<div class="wrap"><h2 style="text-align:center;margin-bottom:28px">What our customers say</h2>'
            + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px">'
            + c.landing_testimonials.slice(0,4).map(function(t){
                return '<div style="background:#fff;border:1px solid #E7E9EF;border-radius:14px;padding:20px 22px">'
                  + '<p style="font-size:14px;line-height:1.7;color:#3A4150">&ldquo;' + String(t.quote||'').replace(/</g,'&lt;') + '&rdquo;</p>'
                  + '<p style="margin-top:12px;font-weight:800;color:#0B6373;font-size:13px">' + String(t.name||'').replace(/</g,'&lt;')
                  + '</p><p style="font-size:12px;color:#878C99">' + String(t.role||'').replace(/</g,'&lt;') + '</p></div>';
              }).join('')
            + '</div></div>';
          cta.parentNode.insertBefore(sec, cta);
        }
      }
    })
    .catch(() => {});
})();
render();
