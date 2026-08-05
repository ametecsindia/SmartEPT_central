<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Landing Page Editor — SmartEPT</title>
<style>
:root{--teal:#0E7C8F;--teal-d:#0B6373;--cyan:#22B8CF;--ink:#15171C;--ink2:#565A66;--ink3:#8a94a0;--bg:#EEF2F3;--line:#E4E9EB}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px}
.top{background:linear-gradient(135deg,#08505E,#0E7C8F);color:#fff;display:flex;align-items:center;gap:16px;padding:0 18px;height:54px}
.top b{font-weight:800;font-size:15px}
.top a.back{color:#cdeef2;text-decoration:none;font-size:13px;font-weight:600}
.top .sp{flex:1}
.top .msg{font-size:12.5px;color:#bfeff5;font-weight:600}
.btn{border:0;border-radius:9px;padding:9px 15px;font-weight:800;font-size:13px;cursor:pointer}
.btn.pub{background:#22B8CF;color:#053}
.btn.ghost{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.25)}
.app{display:grid;grid-template-columns:290px 1fr 480px;height:calc(100vh - 54px)}
.col{overflow:auto}
.rail{background:#fff;border-right:1px solid var(--line);padding:12px}
.rail h4,.mid h4{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--ink3);font-weight:800;margin:4px 2px 10px}
.si{display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid var(--line);border-radius:10px;margin-bottom:7px;background:#fff;cursor:pointer}
.si.active{border-color:var(--teal);box-shadow:0 0 0 2px rgba(14,124,143,.12);background:#F4FBFC}
.si.layout{opacity:.7;cursor:default;background:#fafafa}
.si .nm{flex:1;font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.si .nm small{display:block;color:var(--ink3);font-weight:600;font-size:11px}
.si .ord{display:flex;flex-direction:column;gap:1px}
.si .ord button{border:1px solid var(--line);background:#fff;border-radius:5px;width:22px;height:16px;line-height:1;cursor:pointer;font-size:10px;color:var(--ink2)}
.eye{width:34px;height:20px;border-radius:999px;background:var(--teal);position:relative;flex:0 0 auto;cursor:pointer;border:0}
.eye i{position:absolute;top:2px;left:16px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.15s}
.eye.off{background:#cdd5da}.eye.off i{left:2px}
.mid{background:#F7F9FA;border-right:1px solid var(--line);padding:18px 20px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:14px}
label{display:block;font-size:12px;font-weight:700;color:var(--ink2);margin:10px 0 5px}
input[type=text],textarea{width:100%;border:1px solid #d7dee1;border-radius:9px;padding:9px 11px;font:inherit;font-size:13px}
textarea{min-height:340px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre;resize:vertical}
.hint{font-size:12px;color:var(--ink3);margin:6px 0 0}
.row{display:flex;gap:10px;align-items:center;justify-content:space-between}
.prev{background:#0d1b1f;padding:12px;display:flex;flex-direction:column}
.prev .bar{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.prev .bar h4{color:#8fb3ba;margin:0;flex:1;font-size:11px;text-transform:uppercase;letter-spacing:1px;font-weight:800}
.prev iframe{flex:1;width:100%;border:0;border-radius:10px;background:#fff}
.small{font-size:12px;padding:6px 11px;border-radius:8px;border:1px solid var(--teal);color:var(--teal-d);background:#fff;font-weight:700;cursor:pointer}
.banner{background:#FEF9E7;border:1px solid #E4C65B;color:#7a5b00;border-radius:8px;padding:8px 10px;font-size:12px;font-weight:600;margin-bottom:10px}
</style>
</head>
<body>
<div class="top">
  <b>SmartEPT · Landing Page Editor</b>
  <a class="back" href="/admin">&larr; Back to console</a>
  <a class="back" href="/cms-preview" target="_blank">Open preview ↗</a>
  <button class="btn ghost" id="tabSections" onclick="tab('sections')">Sections</button>
  <button class="btn ghost" id="tabMedia" onclick="tab('media')">Media</button>
  <button class="btn ghost" id="tabSeo" onclick="tab('seo')">SEO &amp; Tracking</button>
  <span class="sp"></span>
  <span class="msg" id="msg"></span>
  <button class="btn ghost" onclick="loadAll()">Reload</button>
  <button class="btn ghost" onclick="toggleGuide(1)">Guide</button>
  <button class="btn pub" onclick="publish()">Publish ▸ make live</button>
</div>

<div id="guideOv" style="display:none;position:fixed;inset:0;background:rgba(8,30,35,.55);z-index:50;align-items:center;justify-content:center">
 <div style="background:#fff;max-width:560px;width:92%;border-radius:14px;padding:22px 24px;max-height:86vh;overflow:auto">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px"><b style="font-size:16px">How to edit your landing page</b><button class="small" onclick="toggleGuide(0)">Close</button></div>
  <ol style="font-size:13.5px;color:#4a5560;line-height:1.7;padding-left:18px">
   <li><b>Pick a section</b> on the left - the preview jumps to it.</li>
   <li><b>Simple edit</b> changes wording &amp; image links directly. <b>HTML (advanced)</b> is there if you need it.</li>
   <li><b>Reorder</b> with the arrows, <b>show/hide</b> with the toggle.</li>
   <li><b>Media</b> tab: upload images/icons, then <b>Copy URL</b> and paste into an Image field.</li>
   <li><b>SEO &amp; Tracking</b> tab: title/description, social share image, Google Analytics/Ads, Meta Pixel, and the <b>/thank-you</b> conversion page.</li>
   <li>Nothing is live until <b>Publish</b> - every publish is backed up and reversible.</li>
  </ol>
 </div>
</div>

<div class="app" id="sectionsApp">
  <div class="col rail">
    <h4>Sections · order, show/hide</h4>
    <div id="list"></div>
  </div>

  <div class="col mid">
    <div class="banner">Editing here changes a <b>draft</b>. The public site updates only when you click <b>Publish</b>. Every publish is backed up &amp; versioned.</div>
    <h4>Edit section</h4>
    <div class="card" id="editCard">
      <p class="hint">Select a section on the left to edit it.</p>
    </div>
  </div>

  <div class="col prev">
    <div class="bar"><h4>Live draft preview</h4><button class="small" onclick="refreshPrev()">Refresh</button></div>
    <iframe id="pv" src="/cms-preview" onload="onPvLoad()"></iframe>
  </div>
</div>

<div id="seoPane" style="display:none;padding:18px 22px;overflow:auto;height:calc(100vh - 54px)">
 <div style="max-width:920px;margin:0 auto">
  <div class="row" style="margin-bottom:12px"><h4 style="margin:0;font-size:15px">SEO &amp; Tracking</h4><button class="small" style="background:var(--teal);color:#fff;border-color:var(--teal)" onclick="saveSeoAll()">Save</button></div>
  <div class="banner">Saved settings apply to the live site on your next <b>Publish</b>. The preview updates immediately.</div>
  <div class="card">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a94a0;font-weight:800;margin-bottom:8px">Google result</div>
    <div style="border:1px solid #e6ebed;border-radius:8px;padding:12px">
      <div id="gU" style="color:#0a7a12;font-size:12.5px"></div>
      <div id="gT" style="color:#1a0dab;font-size:17px;margin:2px 0"></div>
      <div id="gD" style="color:#4d5156;font-size:13px"></div>
    </div>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a94a0;font-weight:800;margin:14px 0 8px">Social share card (Facebook / LinkedIn / WhatsApp / X)</div>
    <div style="border:1px solid #e6ebed;border-radius:10px;overflow:hidden;max-width:460px">
      <div id="sImg" style="height:200px;background:#0d1b1f;background-position:center;background-size:cover;background-repeat:no-repeat;color:#7fb3ba;display:flex;align-items:center;justify-content:center;font-size:12px">og:image</div>
      <div style="padding:10px 12px;background:#f7f9fa"><div id="sU" style="font-size:11px;color:#8a94a0;text-transform:uppercase"></div><div id="sT" style="font-weight:700;font-size:15px;margin:2px 0"></div><div id="sD" style="font-size:12.5px;color:#4d5156"></div></div>
    </div>
  </div>
  <div class="card"><h3 style="margin:0 0 4px">Search</h3>
    <label>Page title</label><input type="text" id="seo_title" oninput="updateSeoPreview()">
    <label>Meta description</label><textarea id="seo_description" style="min-height:70px" oninput="updateSeoPreview()"></textarea>
    <div class="row"><div style="flex:1"><label>Canonical URL</label><input type="text" id="seo_canonical" placeholder="https://smartept.in/" oninput="updateSeoPreview()"></div><div style="width:190px"><label>Robots</label><input type="text" id="seo_robots" placeholder="index, follow"></div></div>
  </div>
  <div class="card"><h3 style="margin:0 0 4px">Social (Open Graph + Twitter/X)</h3>
    <label>Share image URL (og:image, 1200&times;630) &mdash; upload in the Media tab, then Copy URL</label><input type="text" id="seo_og_image" placeholder="/storage/landing/og-share.png" oninput="updateSeoPreview()">
    <div class="row"><div style="flex:1"><label>Site name</label><input type="text" id="seo_site_name" placeholder="SmartEPT"></div><div style="flex:1"><label>Twitter/X handle</label><input type="text" id="seo_twitter_handle" placeholder="@ametecs"></div></div>
    <label>Favicon URL (PNG 32&times;32, ICO or SVG) &mdash; upload in Media, then Copy URL</label><input type="text" id="seo_favicon" placeholder="/favicon.ico">
    <label>Site logo URL &mdash; replaces the header/footer logo (upload in Media, Copy URL)</label><input type="text" id="seo_logo" placeholder="/img/smartept-logo-h-dark.png">
  </div>
  <div class="card"><h3 style="margin:0 0 4px">Analytics &amp; Pixels</h3>
    <div class="row"><div style="flex:1"><label>Google Analytics (GA4) &mdash; Measurement ID</label><input type="text" id="track_ga4" placeholder="G-XXXXXXXXXX"></div><div style="flex:1"><label>Google Tag Manager &mdash; Container ID</label><input type="text" id="track_gtm" placeholder="GTM-XXXXXXX"></div></div>
    <div class="row"><div style="flex:1"><label>Meta / Facebook Pixel ID</label><input type="text" id="track_fb_pixel" placeholder="1234567890123456"></div><div style="flex:1"><label>Google Ads ID (remarketing/conversion)</label><input type="text" id="track_google_ads" placeholder="AW-XXXXXXXXX"></div></div>
    <p class="hint">Enter just the IDs &mdash; the correct install snippet is generated automatically.</p>
  </div>
  <div class="card"><h3 style="margin:0 0 4px">Conversion tracking &amp; Thank-you page</h3>
    <p class="hint">Shown at <b>/thank-you</b>. Put your Google Ads / Meta conversion code here &mdash; it fires only on this page. The site-wide GA/Pixel load here too, so <code>gtag()</code> / <code>fbq()</code> are available. Point your lead form or ad landing to <b>/thank-you</b> to record conversions.</p>
    <label>Thank-you headline</label><input type="text" id="thankyou_headline" placeholder="Thank you!">
    <label>Thank-you message</label><textarea id="thankyou_message" style="min-height:60px" placeholder="We have received your details and will contact you shortly."></textarea>
    <label>Conversion code (fires on /thank-you only)</label><textarea id="track_conversion_html" style="min-height:90px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px" placeholder="&lt;script&gt;gtag('event','conversion',{'send_to':'AW-XXXXXXXXX/AbC-label'});&lt;/script&gt;  or  &lt;script&gt;fbq('track','Lead');&lt;/script&gt;"></textarea>
    <a class="small" href="/thank-you" target="_blank" style="display:inline-block;margin-top:8px">Preview thank-you page &#8599;</a>
  </div>
  <div class="card"><h3 style="margin:0 0 4px">Advanced &mdash; custom code</h3>
    <label>Custom &lt;head&gt; code (site verification, extra remarketing tags, etc.)</label><textarea id="track_head_html" style="min-height:80px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px"></textarea>
    <label>Custom code before &lt;/body&gt;</label><textarea id="track_body_html" style="min-height:80px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px"></textarea>
    <p class="hint">Pasted as-is into the page. Only add code from sources you trust.</p>
  </div>
 </div>
</div>

<div id="mediaPane" style="display:none;padding:18px 22px;overflow:auto;height:calc(100vh - 54px)">
  <div style="max-width:1000px;margin:0 auto">
    <h4 style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a94a0;font-weight:800;margin:0 0 12px">Media Library — images &amp; icons</h4>
    <label style="display:flex;align-items:center;justify-content:center;flex-direction:column;border:2px dashed #b9ccd1;border-radius:12px;padding:22px;color:#0B6373;font-weight:800;cursor:pointer;background:#f6fafb;margin-bottom:16px">
      <input type="file" accept="image/*,.svg" multiple style="display:none" onchange="uploadMedia(this)"> &#8593; Click to upload (PNG &middot; JPG &middot; SVG &middot; WebP &middot; GIF)
    </label>
    <div id="mediaGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px"></div>
  </div>
</div>

@verbatim
<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const API = '/admin/api/landing';
let sections = [], curId = null;

function headers(){ return {'X-CSRF-TOKEN':CSRF,'Accept':'application/json','Content-Type':'application/json'}; }
function msg(t){ const m=document.getElementById('msg'); m.textContent=t; if(t) setTimeout(()=>{if(m.textContent===t)m.textContent='';},4000); }

async function loadAll(){
  const r = await fetch(API+'/sections',{headers:{'Accept':'application/json'}});
  if(!r.ok){ msg('Failed to load ('+r.status+')'); return; }
  sections = await r.json();
  renderList();
  if(curId===null){ const first = sections.find(s=>!s.is_layout); if(first) select(first.id); }
}

function renderList(){
  const el = document.getElementById('list'); el.innerHTML='';
  sections.forEach((s,idx)=>{
    const div=document.createElement('div');
    div.className='si'+(s.id===curId?' active':'')+(s.is_layout?' layout':'');
    const ord = s.is_layout ? '' :
      `<span class="ord"><button title="Move up" onclick="event.stopPropagation();move(${s.id},-1)">▲</button><button title="Move down" onclick="event.stopPropagation();move(${s.id},1)">▼</button></span>`;
    const eye = s.is_layout ? '' :
      `<button class="eye ${s.is_visible?'':'off'}" title="Show/hide" onclick="event.stopPropagation();toggle(${s.id})"><i></i></button>`;
    div.innerHTML = `${ord}<span class="nm">${escapeHtml(s.title||s.key)}<small>${s.key}${s.is_layout?' · layout (always shown)':''}</small></span>${eye}`;
    if(!s.is_layout) div.onclick=()=>select(s.id);
    el.appendChild(div);
  });
}

function select(id){
  curId=id; renderList();
  const s=sections.find(x=>x.id===id); if(!s) return;
  const c=document.getElementById('editCard');
  c.innerHTML = `
    <label>Section label (admin only)</label>
    <input type="text" id="fTitle" value="${escapeAttr(s.title||'')}">
    <label>Content (HTML) — advanced. Edit text between the tags; keep the tags intact.</label>
    <textarea id="fHtml" spellcheck="false"></textarea>
    <p class="hint">Friendly per-field editing (headline / tiles / images) comes next. For now this is the exact section HTML — your content, untouched.</p>
    <div class="row" style="margin-top:12px">
      <button class="small" style="background:var(--teal);color:#fff;border-color:var(--teal)" onclick="save()">Save draft</button>
      <span class="hint">Then Refresh the preview →</span>
    </div>`;
  document.getElementById('fHtml').value = s.html||''; scrollPreview();
}

async function save(){
  const s=sections.find(x=>x.id===curId); if(!s) return;
  const body={ title:document.getElementById('fTitle').value, html:document.getElementById('fHtml').value };
  const r=await fetch(API+'/sections/'+curId,{method:'PUT',headers:headers(),body:JSON.stringify(body)});
  if(!r.ok){ msg('Save failed ('+r.status+')'); return; }
  s.title=body.title; s.html=body.html; renderList(); msg('Draft saved ✓'); refreshPrev();
}

async function toggle(id){
  const s=sections.find(x=>x.id===id); if(!s) return;
  const r=await fetch(API+'/sections/'+id,{method:'PUT',headers:headers(),body:JSON.stringify({is_visible:!s.is_visible})});
  if(!r.ok){ msg('Failed ('+r.status+')'); return; }
  s.is_visible=!s.is_visible; renderList(); msg('Visibility updated ✓'); refreshPrev();
}

async function move(id,dir){
  const editable = sections.filter(s=>!s.is_layout);
  const i = editable.findIndex(s=>s.id===id); const j=i+dir;
  if(i<0||j<0||j>=editable.length) return;
  [editable[i],editable[j]]=[editable[j],editable[i]];
  const layout = sections.filter(s=>s.is_layout);
  sections = layout.concat(editable);
  renderList();
  const order = sections.map(s=>s.id);
  const r=await fetch(API+'/reorder',{method:'POST',headers:headers(),body:JSON.stringify({order})});
  if(!r.ok){ msg('Reorder failed ('+r.status+')'); return; }
  msg('Order saved ✓'); refreshPrev();
}

async function publish(){
  if(!confirm('Publish the current draft to the LIVE site? A backup + version are saved automatically.')) return;
  const note = prompt('Optional note for the version history:','') || '';
  const r=await fetch(API+'/publish',{method:'POST',headers:headers(),body:JSON.stringify({note})});
  const d=await r.json().catch(()=>({}));
  if(r.ok && d.ok){ msg('Published ✓ '+(d.bytes||'')+' bytes live'); }
  else { msg('Publish failed: '+(d.error||r.status)); }
}

function curKey(){ const s=sections.find(x=>x.id===curId); return s?s.key:null; }
function refreshPrev(){ const k=curKey(); const f=document.getElementById('pv'); f.src='/cms-preview?t='+Date.now()+(k?('#cms-'+k):''); }
function scrollPreview(){ const k=curKey(); if(!k) return; const f=document.getElementById('pv'); try{ const el=f.contentDocument&&f.contentDocument.getElementById('cms-'+k); if(el){ el.scrollIntoView({behavior:'smooth',block:'start'}); } else { f.contentWindow.location.hash='cms-'+k; } }catch(e){} }
function onPvLoad(){ scrollPreview(); }
function escapeHtml(s){ return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escapeAttr(s){ return (s||'').replace(/"/g,'&quot;'); }

function tab(n){
  document.getElementById('sectionsApp').style.display = n==='sections'?'grid':'none';
  document.getElementById('mediaPane').style.display = n==='media'?'block':'none';
  document.getElementById('seoPane').style.display = n==='seo'?'block':'none';
  [['tabSections','sections'],['tabMedia','media'],['tabSeo','seo']].forEach(function(p){ var el=document.getElementById(p[0]); if(el){ el.style.background=n===p[1]?'#22B8CF':''; el.style.color=n===p[1]?'#053':''; } });
  if(n==='media') loadMedia();
  if(n==='seo') loadSeo();
}
async function loadMedia(){
  const r=await fetch(API+'/media',{headers:{'Accept':'application/json'}});
  if(!r.ok){ msg('Media load failed ('+r.status+')'); return; }
  const items=await r.json(); const g=document.getElementById('mediaGrid'); g.innerHTML='';
  if(!items.length){ g.innerHTML='<p style="color:#8a94a0">No media yet - upload above.</p>'; return; }
  items.forEach(m=>{
    const d=document.createElement('div');
    d.style.cssText='border:1px solid #E4E9EB;border-radius:10px;overflow:hidden;background:#fff';
    d.innerHTML=`<div style="height:110px;background:#0d1b1f url('${m.url}') center/contain no-repeat"></div>
      <div style="padding:9px 10px">
        <div style="font-size:11px;color:#8a94a0">${m.kind}${m.width?(' - '+m.width+'x'+m.height):''}</div>
        <input type="text" value="${escapeAttr(m.alt||'')}" placeholder="alt text" onchange="saveAlt(${m.id},this.value)" style="width:100%;margin:6px 0;border:1px solid #d7dee1;border-radius:7px;padding:5px 7px;font-size:12px">
        <div style="display:flex;gap:6px">
          <button class="small" onclick="copyUrl('${m.url}')">Copy URL</button>
          <button class="small" style="border-color:#e0b4b4;color:#b23" onclick="delMedia(${m.id})">Delete</button>
        </div>
      </div>`;
    g.appendChild(d);
  });
}
async function uploadMedia(inp){
  const files=[...inp.files]; if(!files.length) return; msg('Uploading...');
  for(const file of files){ const fd=new FormData(); fd.append('file',file);
    const r=await fetch(API+'/media',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:fd});
    if(!r.ok){ const e=await r.json().catch(()=>({})); msg('Upload failed: '+(e.message||r.status)); }
  }
  inp.value=''; loadMedia(); msg('Uploaded');
}
function copyUrl(u){ const full=location.origin+u; navigator.clipboard.writeText(full).then(()=>msg('URL copied - paste into a section')); }
async function saveAlt(id,val){ await fetch(API+'/media/'+id,{method:'PUT',headers:headers(),body:JSON.stringify({alt:val})}); msg('Alt saved'); }
async function delMedia(id){ if(!confirm('Delete this media file?'))return; const r=await fetch(API+'/media/'+id,{method:'DELETE',headers:headers()}); if(r.ok){ loadMedia(); msg('Deleted'); } else msg('Delete failed'); }

const SEO_KEYS=['seo_title','seo_description','seo_canonical','seo_robots','seo_og_image','seo_site_name','seo_twitter_handle','seo_favicon','seo_logo','track_ga4','track_gtm','track_fb_pixel','track_google_ads','track_head_html','track_body_html','track_conversion_html','thankyou_headline','thankyou_message'];
async function loadSeo(){ const r=await fetch(API+'/seo',{headers:{'Accept':'application/json'}}); if(!r.ok){ msg('SEO load failed ('+r.status+')'); return; } const d=await r.json(); SEO_KEYS.forEach(k=>{ const el=document.getElementById(k); if(el) el.value=d[k]||''; }); updateSeoPreview(); }
async function saveSeoAll(){ const body={}; SEO_KEYS.forEach(k=>{ const el=document.getElementById(k); body[k]=el?el.value:''; }); const r=await fetch(API+'/seo',{method:'PUT',headers:headers(),body:JSON.stringify(body)}); if(r.ok){ msg('SEO saved - Publish to apply to the live site'); refreshPrev(); } else msg('Save failed'); }
function updateSeoPreview(){
  const g=id=>document.getElementById(id);
  const t=g('seo_title').value||'Your page title'; const d=g('seo_description').value||'Your meta description will show here.'; const u=g('seo_canonical').value||location.origin;
  g('gT').textContent=t; g('gU').textContent=u; g('gD').textContent=d;
  g('sT').textContent=t; g('sD').textContent=d; g('sU').textContent=u.replace(/^https?:\/\//,'');
  const img=g('seo_og_image').value; const si=g('sImg');
  if(img){ const full=img.startsWith('http')?img:(location.origin+'/'+img.replace(/^\//,'')); si.style.backgroundImage="url('"+full+"')"; si.textContent=''; }
  else { si.style.backgroundImage=''; si.textContent='og:image'; }
}
function toggleGuide(v){ const o=document.getElementById('guideOv'); if(o) o.style.display=v?'flex':'none'; }
function select(id){ curId=id; renderList(); const s=sections.find(x=>x.id===id); if(!s) return; renderEditor(s); scrollPreview(); }
let editMode='simple', simpleTpl=null, simpleMap=[], simpleImgs=[];
function renderEditor(s){
  const c=document.getElementById('editCard');
  c.innerHTML=`
    <label>Section label (admin only)</label>
    <input type="text" id="fTitle" value="${escapeAttr(s.title||'')}">
    <div style="display:flex;gap:6px;margin:12px 0 8px">
      <button id="mSimple" onclick="setMode('simple')" style="padding:6px 12px;border:1px solid #d7dee1;background:#fff;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer">Simple edit</button>
      <button id="mHtml" onclick="setMode('html')" style="padding:6px 12px;border:1px solid #d7dee1;background:#fff;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer">HTML (advanced)</button>
    </div>
    <div id="simpleWrap"></div>
    <textarea id="fHtml" spellcheck="false" style="display:none"></textarea>
    <div class="row" style="margin-top:12px"><button class="small" style="background:#0E7C8F;color:#fff;border-color:#0E7C8F" onclick="save()">Save draft</button><span class="hint">Draft only - Publish to go live</span></div>`;
  document.getElementById('fHtml').value=s.html||'';
  setMode(editMode);
}
function setMode(m){
  const s=sections.find(x=>x.id===curId); if(!s) return;
  if(m==='html'){ if(editMode==='simple') syncSimpleToHtml(s); document.getElementById('fHtml').value=s.html||''; document.getElementById('fHtml').style.display='block'; document.getElementById('simpleWrap').style.display='none'; }
  else { if(editMode==='html') s.html=document.getElementById('fHtml').value; renderSimple(s); document.getElementById('simpleWrap').style.display='block'; document.getElementById('fHtml').style.display='none'; }
  editMode=m;
  const a=document.getElementById('mSimple'), b=document.getElementById('mHtml');
  a.style.background=m==='simple'?'#0E7C8F':'#fff'; a.style.color=m==='simple'?'#fff':'#15171C';
  b.style.background=m==='html'?'#0E7C8F':'#fff'; b.style.color=m==='html'?'#fff':'#15171C';
}
function renderSimple(s){
  simpleTpl=document.createElement('template'); simpleTpl.innerHTML=s.html||''; simpleMap=[]; simpleImgs=[];
  const walker=document.createTreeWalker(simpleTpl.content, NodeFilter.SHOW_TEXT, null); let n; const texts=[];
  while(n=walker.nextNode()){ const p=n.parentNode, tag=p&&p.nodeName; if(tag==='SCRIPT'||tag==='STYLE') continue; const raw=n.nodeValue, t=raw.trim(); if(t==='') continue; simpleMap.push({node:n,pre:raw.match(/^\s*/)[0],post:raw.match(/\s*$/)[0]}); texts.push(t); }
  const rows=simpleMap.map((m,idx)=>`<label style="margin-top:8px">Text ${idx+1}</label><input type="text" data-txt="${idx}" value="${escapeAttr(texts[idx])}">`).join('');
  const imgs=simpleTpl.content.querySelectorAll('img'); let imgRows='';
  imgs.forEach((img,idx)=>{ simpleImgs.push(img); imgRows+=`<label style="margin-top:8px">Image ${idx+1} URL <span class="hint">(Copy URL from the Media tab)</span></label><input type="text" data-img="${idx}" value="${escapeAttr(img.getAttribute('src')||'')}">`; });
  document.getElementById('simpleWrap').innerHTML=(rows||imgRows)?(rows+imgRows):'<p class="hint">No plain text found here - use HTML (advanced).</p>';
}
function syncSimpleToHtml(s){ if(!simpleTpl) return;
  document.querySelectorAll('#simpleWrap [data-txt]').forEach(inp=>{ const m=simpleMap[+inp.dataset.txt]; if(m) m.node.nodeValue=m.pre+inp.value+m.post; });
  document.querySelectorAll('#simpleWrap [data-img]').forEach(inp=>{ const img=simpleImgs[+inp.dataset.img]; if(img) img.setAttribute('src',inp.value); });
  s.html=simpleTpl.innerHTML;
}
async function save(){ const s=sections.find(x=>x.id===curId); if(!s) return;
  if(editMode==='simple') syncSimpleToHtml(s); else s.html=document.getElementById('fHtml').value;
  const body={title:document.getElementById('fTitle').value,html:s.html};
  const r=await fetch(API+'/sections/'+curId,{method:'PUT',headers:headers(),body:JSON.stringify(body)});
  if(!r.ok){ msg('Save failed ('+r.status+')'); return; }
  s.title=body.title; renderList(); msg('Draft saved'); refreshPrev();
}
loadAll();

</script>
@endverbatim
</body>
</html>
