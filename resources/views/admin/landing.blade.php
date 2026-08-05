<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Landing Page Editor — SmartEPT</title>
<style>
:root{--teal:#0E7C8F;--teal-d:#0B6373;--cyan:#22B8CF;--ink:#15171C;--ink2:#565A66;--ink3:#8a94a0;--bg:#EEF2F3;--line:#E4E9EB;--green:#0FA47A}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px}
.top{background:linear-gradient(135deg,#08505E,#0E7C8F);color:#fff;display:flex;align-items:center;gap:12px;padding:0 16px;height:54px;flex-wrap:nowrap;overflow-x:auto}
.top b{font-weight:800;font-size:15px;white-space:nowrap}
.top a.back{color:#cdeef2;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap}
.top .sp{flex:1}
.top .msg{font-size:12.5px;color:#bfeff5;font-weight:600;white-space:nowrap}
.btn{border:0;border-radius:9px;padding:9px 14px;font-weight:800;font-size:13px;cursor:pointer;white-space:nowrap}
.btn.pub{background:#22B8CF;color:#053}
.btn.ghost{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.25)}
.app{display:grid;grid-template-columns:280px 1fr 460px;height:calc(100vh - 54px)}
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
.card h3{margin:0 0 4px;font-size:15px}
label{display:block;font-size:12px;font-weight:700;color:var(--ink2);margin:10px 0 5px}
input[type=text],input:not([type]),textarea{width:100%;border:1px solid #d7dee1;border-radius:9px;padding:9px 11px;font:inherit;font-size:13px}
textarea{min-height:60px;resize:vertical}
textarea.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre;min-height:320px}
.row{display:flex;gap:10px;align-items:center}
.pf{display:flex;gap:6px;align-items:center}
.pf input{flex:1}
.hint{font-size:12px;color:var(--ink3);margin:6px 0 0}
.small{padding:6px 11px;border-radius:8px;border:1px solid var(--teal);color:var(--teal-d);background:#fff;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap}
.banner{background:#FEF9E7;border:1px solid #E4C65B;color:#7a5b00;border-radius:8px;padding:8px 10px;font-size:12px;font-weight:600;margin-bottom:10px}
.iconbtn{width:44px;height:44px;border:1px solid var(--line);border-radius:10px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
.iconbtn svg{width:24px;height:24px}
.prev{background:#0d1b1f;padding:12px;display:flex;flex-direction:column}
.prev .bar{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.prev .bar h4{color:#8fb3ba;margin:0;flex:1;font-size:11px;text-transform:uppercase;letter-spacing:1px;font-weight:800}
.prev iframe{flex:1;width:100%;border:0;border-radius:10px;background:#fff}
.pane{display:none;padding:18px 22px;overflow:auto;height:calc(100vh - 54px)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}
.m-tile{border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff}
.m-tile .ph{height:110px;background:#0d1b1f;background-position:center;background-size:contain;background-repeat:no-repeat}
.seo-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;max-width:920px;margin:0 auto 14px}
/* overlays */
.ov{display:none;position:fixed;inset:0;background:rgba(8,30,35,.55);z-index:60;align-items:center;justify-content:center}
.ov .box{background:#fff;max-width:720px;width:94%;border-radius:14px;padding:18px 20px;max-height:86vh;overflow:auto}
.ov .box .hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.pick-tile{border:1px solid var(--line);border-radius:10px;overflow:hidden;cursor:pointer;background:#fff}
.pick-tile:hover{border-color:var(--teal);box-shadow:0 0 0 2px rgba(14,124,143,.12)}
.pick-tile .pth{height:90px;background:#0d1b1f;background-position:center;background-size:contain;background-repeat:no-repeat}
</style>
</head>
<body>
<div class="top">
  <b>SmartEPT · Landing Editor</b>
  <a class="back" href="/admin">&larr; Console</a>
  <a class="back" href="/cms-preview" target="_blank">Preview ↗</a>
  <button class="btn ghost" id="tabSections" onclick="tab('sections')">Sections</button>
  <button class="btn ghost" id="tabMedia" onclick="tab('media')">Media</button>
  <button class="btn ghost" id="tabSeo" onclick="tab('seo')">SEO &amp; Tracking</button>
  <span class="sp"></span>
  <span class="msg" id="msg"></span>
  <button class="btn ghost" onclick="loadAll()">Reload</button>
  <button class="btn ghost" onclick="show('guideOv',1)">Guide</button>
  <button class="btn pub" onclick="publish()">Publish ▸ make live</button>
</div>

<!-- Guide -->
<div class="ov" id="guideOv"><div class="box">
  <div class="hd"><b style="font-size:16px">How to edit your landing page</b><button class="small" onclick="show('guideOv',0)">Close</button></div>
  <ol style="font-size:13.5px;color:#4a5560;line-height:1.7;padding-left:18px">
   <li><b>Pick a section</b> (left) — the preview jumps to it.</li>
   <li><b>Simple edit</b>: change text, swap images/backgrounds (Pick from Media) and icons (Change). <b>HTML</b> mode for advanced.</li>
   <li><b>Reorder</b> with arrows, <b>show/hide</b> with the toggle.</li>
   <li><b>Media</b>: upload, Scan existing, Extract embedded images; each shows which section uses it.</li>
   <li><b>SEO &amp; Tracking</b>: title/description/keywords, social share image, GA4/GTM/Pixel/Ads, favicon, logo, and the /thank-you conversion page.</li>
   <li>Nothing is live until <b>Publish</b> — every publish is backed up &amp; reversible.</li>
  </ol>
</div></div>

<!-- Media picker -->
<div class="ov" id="pickerOv"><div class="box">
  <div class="hd"><b style="font-size:15px">Choose an image</b><button class="small" onclick="show('pickerOv',0)">Cancel</button></div>
  <div class="grid" id="pickerGrid"></div>
</div></div>

<!-- Icon picker -->
<div class="ov" id="iconOv"><div class="box">
  <div class="hd"><b style="font-size:15px">Choose an icon</b><button class="small" onclick="show('iconOv',0)">Cancel</button></div>
  <div id="iconGrid" style="display:flex;flex-wrap:wrap;gap:10px"></div>
</div></div>

<!-- SECTIONS -->
<div class="app" id="sectionsApp">
  <div class="col rail"><h4>Sections · order, show/hide</h4><div id="list"></div></div>
  <div class="col mid">
    <div class="banner">Edits here are a <b>draft</b>. The public site changes only when you click <b>Publish</b>.</div>
    <h4>Edit: <span id="editingName" style="color:var(--teal-d)"></span></h4>
    <div class="card" id="editCard"><p class="hint">Select a section on the left.</p></div>
  </div>
  <div class="col prev">
    <div class="bar"><h4>Live draft preview</h4><button class="small" onclick="refreshPrev()">Refresh</button></div>
    <iframe id="pv" src="/cms-preview" onload="scrollPreview()"></iframe>
  </div>
</div>

<!-- MEDIA -->
<div class="pane" id="mediaPane"><div style="max-width:1000px;margin:0 auto">
  <h4 style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a94a0;font-weight:800;margin:0 0 12px">Media Library — images &amp; icons</h4>
  <label style="display:flex;align-items:center;justify-content:center;border:2px dashed #b9ccd1;border-radius:12px;padding:22px;color:#0B6373;font-weight:800;cursor:pointer;background:#f6fafb;margin-bottom:12px">
    <input type="file" accept="image/*,.svg" multiple style="display:none" onchange="uploadMedia(this)"> &#8593; Click to upload (PNG · JPG · SVG · WebP · GIF)
  </label>
  <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
    <button class="small" onclick="scanMedia()">Scan page for existing images</button>
    <button class="small" onclick="extractMedia()">Extract embedded images to files</button>
    <span class="hint">Pulls images already on the page into the library; shows which section each is used in.</span>
  </div>
  <div class="grid" id="mediaGrid"></div>
</div></div>

<!-- SEO -->
<div class="pane" id="seoPane"><div style="max-width:920px;margin:0 auto">
  <div class="row" style="justify-content:space-between;margin-bottom:12px"><h4 style="margin:0;font-size:15px">SEO &amp; Tracking</h4><button class="small" style="background:var(--teal);color:#fff;border-color:var(--teal)" onclick="saveSeoAll()">Save</button></div>
  <div class="banner">Saved settings apply to the live site on your next <b>Publish</b>. Preview updates immediately.</div>
  <div class="seo-card">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a94a0;font-weight:800;margin-bottom:8px">Google result</div>
    <div style="border:1px solid #e6ebed;border-radius:8px;padding:12px"><div id="gU" style="color:#0a7a12;font-size:12.5px"></div><div id="gT" style="color:#1a0dab;font-size:17px;margin:2px 0"></div><div id="gD" style="color:#4d5156;font-size:13px"></div></div>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a94a0;font-weight:800;margin:14px 0 8px">Social share card</div>
    <div style="border:1px solid #e6ebed;border-radius:10px;overflow:hidden;max-width:460px"><div id="sImg" style="height:200px;background:#0d1b1f;background-position:center;background-size:cover;background-repeat:no-repeat;color:#7fb3ba;display:flex;align-items:center;justify-content:center;font-size:12px">og:image</div><div style="padding:10px 12px;background:#f7f9fa"><div id="sU" style="font-size:11px;color:#8a94a0;text-transform:uppercase"></div><div id="sT" style="font-weight:700;font-size:15px;margin:2px 0"></div><div id="sD" style="font-size:12.5px;color:#4d5156"></div></div></div>
  </div>
  <div class="seo-card"><h3>Search</h3>
    <label>Page title</label><input type="text" id="seo_title" oninput="updateSeoPreview()">
    <label>Meta description</label><textarea id="seo_description" oninput="updateSeoPreview()"></textarea>
    <label>Keywords (comma-separated) <span class="hint">- minor SEO weight; title &amp; description matter most</span></label><textarea id="seo_keywords"></textarea>
    <div class="row"><div style="flex:1"><label>Canonical URL</label><input type="text" id="seo_canonical" placeholder="https://smartept.in/" oninput="updateSeoPreview()"></div><div style="width:190px"><label>Robots</label><input type="text" id="seo_robots" placeholder="index, follow"></div></div>
  </div>
  <div class="seo-card"><h3>Social + branding</h3>
    <label>Share image (og:image, 1200×630)</label><div class="pf"><input type="text" id="seo_og_image" oninput="updateSeoPreview()"><button class="small" onclick="pickSeo('seo_og_image')">Pick</button></div>
    <div class="row"><div style="flex:1"><label>Site name</label><input type="text" id="seo_site_name" placeholder="SmartEPT"></div><div style="flex:1"><label>Twitter/X handle</label><input type="text" id="seo_twitter_handle" placeholder="@ametecs"></div></div>
    <label>Favicon</label><div class="pf"><input type="text" id="seo_favicon" placeholder="/favicon.ico"><button class="small" onclick="pickSeo('seo_favicon')">Pick</button></div>
    <label>Site logo (replaces header/footer logo)</label><div class="pf"><input type="text" id="seo_logo" placeholder="/img/smartept-logo-h-dark.png"><button class="small" onclick="pickSeo('seo_logo')">Pick</button></div>
  </div>
  <div class="seo-card"><h3>Analytics &amp; Pixels</h3>
    <div class="row"><div style="flex:1"><label>Google Analytics (GA4)</label><input type="text" id="track_ga4" placeholder="G-XXXXXXXXXX"></div><div style="flex:1"><label>Google Tag Manager</label><input type="text" id="track_gtm" placeholder="GTM-XXXXXXX"></div></div>
    <div class="row"><div style="flex:1"><label>Meta / Facebook Pixel</label><input type="text" id="track_fb_pixel" placeholder="1234567890123456"></div><div style="flex:1"><label>Google Ads ID</label><input type="text" id="track_google_ads" placeholder="AW-XXXXXXXXX"></div></div>
    <p class="hint">Enter just the IDs — install snippets are generated automatically.</p>
  </div>
  <div class="seo-card"><h3>Conversion &amp; Thank-you page</h3>
    <p class="hint">Shown at <b>/thank-you</b>; your conversion code fires there (GA/Pixel already loaded). Point your lead form / ad landing to /thank-you.</p>
    <label>Thank-you headline</label><input type="text" id="thankyou_headline" placeholder="Thank you!">
    <label>Thank-you message</label><textarea id="thankyou_message"></textarea>
    <label>Conversion code (fires on /thank-you only)</label><textarea id="track_conversion_html" class="code" style="min-height:90px"></textarea>
    <a class="small" href="/thank-you" target="_blank" style="display:inline-block;margin-top:8px">Preview /thank-you ↗</a>
  </div>
  <div class="seo-card"><h3>Advanced — custom code</h3>
    <label>Custom &lt;head&gt; code</label><textarea id="track_head_html" class="code" style="min-height:70px"></textarea>
    <label>Custom code before &lt;/body&gt;</label><textarea id="track_body_html" class="code" style="min-height:70px"></textarea>
  </div>
</div></div>

@verbatim
<script>
const CSRF=document.querySelector('meta[name=csrf-token]').content;
const API='/admin/api/landing';
let sections=[], curId=null, editMode='simple';
let simpleTpl=null, sTexts=[], sImgs=[], sBgs=[], sIcons=[];
let pickCb=null, iconCb=null, mediaItems=[];
const SEO_KEYS=['seo_title','seo_description','seo_keywords','seo_canonical','seo_robots','seo_og_image','seo_site_name','seo_twitter_handle','seo_favicon','seo_logo','track_ga4','track_gtm','track_fb_pixel','track_google_ads','track_head_html','track_body_html','track_conversion_html','thankyou_headline','thankyou_message'];
const ICONS=[
 {n:'Clock',s:'<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>'},
 {n:'Check',s:'<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>'},
 {n:'Users',s:'<circle cx="9" cy="8" r="3"/><path d="M2.5 20v-1.4A4.6 4.6 0 0 1 7 14h4a4.6 4.6 0 0 1 4.5 4.6V20"/><path d="M16.5 6.2a3 3 0 0 1 0 5.6"/><path d="M18 14.2a4.6 4.6 0 0 1 3.5 4.4V20"/>'},
 {n:'Shield',s:'<path d="M12 3l7 3v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V6z"/>'},
 {n:'File',s:'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/>'},
 {n:'Chart',s:'<path d="M4 20V4"/><path d="M4 20h16"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="5" width="3" height="12"/>'},
 {n:'Globe',s:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>'},
 {n:'Mug',s:'<path d="M4 8h12v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8Z"/><path d="M16 9h2.5a2.5 2.5 0 0 1 0 5H16"/>'},
 {n:'Bell',s:'<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'},
 {n:'Lock',s:'<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'},
 {n:'Calendar',s:'<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>'},
 {n:'Camera',s:'<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>'},
 {n:'Eye',s:'<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>'},
 {n:'Monitor',s:'<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'},
 {n:'Gauge',s:'<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 14l3-3"/>'},
 {n:'Rupee',s:'<path d="M7 5h10M7 9h10M14 5c0 4-3 6-8 6l7 8"/>'}
];

function headers(){ return {'X-CSRF-TOKEN':CSRF,'Accept':'application/json','Content-Type':'application/json'}; }
function msg(t){ const m=document.getElementById('msg'); m.textContent=t; if(t) setTimeout(()=>{ if(m.textContent===t) m.textContent=''; },4000); }
function escHtml(s){ return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escAttr(s){ return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }
function show(id,v){ document.getElementById(id).style.display=v?'flex':'none'; }
async function getJson(p){ const r=await fetch(API+p,{headers:{'Accept':'application/json'}}); return r.ok?r.json():[]; }

function tab(n){
  document.getElementById('sectionsApp').style.display=n==='sections'?'grid':'none';
  document.getElementById('mediaPane').style.display=n==='media'?'block':'none';
  document.getElementById('seoPane').style.display=n==='seo'?'block':'none';
  [['tabSections','sections'],['tabMedia','media'],['tabSeo','seo']].forEach(p=>{ const el=document.getElementById(p[0]); el.style.background=n===p[1]?'#22B8CF':''; el.style.color=n===p[1]?'#053':''; });
  if(n==='media') loadMedia();
  if(n==='seo') loadSeo();
}

/* ---------- sections ---------- */
async function loadAll(){ sections=await getJson('/sections'); renderList(); if(curId==null){ const f=sections.find(s=>!s.is_layout); if(f) select(f.id); } }
function renderList(){
  const el=document.getElementById('list'); el.innerHTML='';
  sections.forEach(s=>{
    const d=document.createElement('div'); d.className='si'+(s.id===curId?' active':'')+(s.is_layout?' layout':'');
    const ord=s.is_layout?'':`<span class="ord"><button onclick="event.stopPropagation();move(${s.id},-1)">▲</button><button onclick="event.stopPropagation();move(${s.id},1)">▼</button></span>`;
    const eye=s.is_layout?'':`<button class="eye ${s.is_visible?'':'off'}" onclick="event.stopPropagation();toggle(${s.id})"><i></i></button>`;
    d.innerHTML=`${ord}<span class="nm">${escHtml(s.title||s.key)}<small>${s.key}${s.is_layout?' · layout':''}</small></span>${eye}`;
    if(!s.is_layout) d.onclick=()=>select(s.id);
    el.appendChild(d);
  });
}
function select(id){ curId=id; renderList(); const s=sections.find(x=>x.id===id); if(!s)return; document.getElementById('editingName').textContent=s.title||s.key; renderEditor(s); scrollPreview(); }
function curKey(){ const s=sections.find(x=>x.id===curId); return s?s.key:null; }

function renderEditor(s){
  const c=document.getElementById('editCard');
  c.innerHTML=`<label>Section label (admin only)</label><input type="text" id="fTitle" value="${escAttr(s.title||'')}">
    <div style="display:flex;gap:6px;margin:12px 0 8px">
      <button id="mSimple" class="small" onclick="setMode('simple')">Simple edit</button>
      <button id="mHtml" class="small" onclick="setMode('html')">HTML (advanced)</button>
    </div>
    <div id="simpleWrap"></div>
    <textarea id="fHtml" class="code" style="display:none"></textarea>
    <div class="row" style="margin-top:12px"><button class="small" style="background:var(--teal);color:#fff;border-color:var(--teal)" onclick="save()">Save draft</button><span class="hint">Draft only — Publish to go live</span></div>`;
  document.getElementById('fHtml').value=s.html||'';
  setMode(editMode);
}
function setMode(m){
  const s=sections.find(x=>x.id===curId); if(!s) return;
  if(m==='html'){ if(editMode==='simple') syncSimpleToHtml(s); document.getElementById('fHtml').value=s.html||''; document.getElementById('fHtml').style.display='block'; document.getElementById('simpleWrap').style.display='none'; }
  else { if(editMode==='html') s.html=document.getElementById('fHtml').value; renderSimple(s); document.getElementById('simpleWrap').style.display='block'; document.getElementById('fHtml').style.display='none'; }
  editMode=m;
  const a=document.getElementById('mSimple'), b=document.getElementById('mHtml');
  a.style.background=m==='simple'?'#0E7C8F':'#fff'; a.style.color=m==='simple'?'#fff':'#0B6373';
  b.style.background=m==='html'?'#0E7C8F':'#fff'; b.style.color=m==='html'?'#fff':'#0B6373';
}
function renderSimple(s){
  simpleTpl=document.createElement('template'); simpleTpl.innerHTML=s.html||''; sTexts=[];sImgs=[];sBgs=[];sIcons=[];
  const w=document.createTreeWalker(simpleTpl.content,NodeFilter.SHOW_TEXT,null); let n;
  while(n=w.nextNode()){ const p=n.parentNode,tg=p&&p.nodeName; if(tg==='SCRIPT'||tg==='STYLE')continue; const raw=n.nodeValue,tr=raw.trim(); if(tr==='')continue; sTexts.push({node:n,pre:raw.match(/^\s*/)[0],post:raw.match(/\s*$/)[0],val:tr}); }
  simpleTpl.content.querySelectorAll('img').forEach(img=>sImgs.push(img));
  simpleTpl.content.querySelectorAll('[style*="background-image"]').forEach(el=>{ const m=(el.getAttribute('style')||'').match(/background-image\s*:\s*url\((['"]?)([^)'"]+)\1\)/i); if(m && !m[2].startsWith('data:')) sBgs.push({el:el,url:m[2]}); });
  simpleTpl.content.querySelectorAll('svg').forEach(svg=>{ const vb=(svg.getAttribute('viewBox')||'').replace(/\s+/g,' ').trim(); if(vb==='0 0 24 24') sIcons.push(svg); });
  let h='';
  sTexts.forEach((t,i)=>{ h+=`<label>Text ${i+1}</label><input data-t="${i}" value="${escAttr(t.val)}">`; });
  sImgs.forEach((img,i)=>{ h+=`<label>Image ${i+1}</label><div class="pf"><input data-img="${i}" value="${escAttr(img.getAttribute('src')||'')}"><button class="small" onclick="pickFor('img',${i})">Pick</button></div>`; });
  sBgs.forEach((b,i)=>{ h+=`<label>Background image ${i+1}</label><div class="pf"><input data-bg="${i}" value="${escAttr(b.url)}"><button class="small" onclick="pickFor('bg',${i})">Pick</button></div>`; });
  if(sIcons.length){ h+=`<label>Icons (${sIcons.length}) — click to change</label><div id="iconRow" style="display:flex;flex-wrap:wrap;gap:8px">`; sIcons.forEach((svg,i)=>{ h+=`<button class="iconbtn" onclick="changeIcon(${i})"><svg viewBox="0 0 24 24" fill="none" stroke="#0B6373" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${svg.innerHTML}</svg></button>`; }); h+=`</div>`; }
  document.getElementById('simpleWrap').innerHTML = h || '<p class="hint">No simple fields here — use HTML (advanced).</p>';
}
function syncSimpleToHtml(s){
  if(!simpleTpl) return;
  document.querySelectorAll('#simpleWrap [data-t]').forEach(inp=>{ const t=sTexts[+inp.dataset.t]; if(t) t.node.nodeValue=t.pre+inp.value+t.post; });
  document.querySelectorAll('#simpleWrap [data-img]').forEach(inp=>{ const img=sImgs[+inp.dataset.img]; if(img) img.setAttribute('src',inp.value); });
  document.querySelectorAll('#simpleWrap [data-bg]').forEach(inp=>{ const b=sBgs[+inp.dataset.bg]; if(b){ const st=b.el.getAttribute('style')||''; b.el.setAttribute('style', st.replace(/background-image\s*:\s*url\([^)]*\)/i, "background-image:url('"+inp.value+"')")); } });
  s.html=simpleTpl.innerHTML;
}
function pickFor(kind,i){ openPicker(url=>{ const inp=document.querySelector('#simpleWrap ['+(kind==='img'?'data-img':'data-bg')+'="'+i+'"]'); if(inp) inp.value=url; }); }
function changeIcon(i){ openIconPicker(inner=>{ if(sIcons[i]) sIcons[i].innerHTML=inner; const btns=document.querySelectorAll('#iconRow .iconbtn svg'); if(btns[i]) btns[i].innerHTML=inner; msg('Icon changed — Save to keep'); }); }
async function save(){ const s=sections.find(x=>x.id===curId); if(!s)return; if(editMode==='simple') syncSimpleToHtml(s); else s.html=document.getElementById('fHtml').value; const body={title:document.getElementById('fTitle').value,html:s.html}; const r=await fetch(API+'/sections/'+curId,{method:'PUT',headers:headers(),body:JSON.stringify(body)}); if(!r.ok){ msg('Save failed ('+r.status+')'); return; } s.title=body.title; renderList(); msg('Draft saved'); refreshPrev(); }
async function toggle(id){ const s=sections.find(x=>x.id===id); if(!s)return; const r=await fetch(API+'/sections/'+id,{method:'PUT',headers:headers(),body:JSON.stringify({is_visible:!s.is_visible})}); if(!r.ok){ msg('Failed'); return; } s.is_visible=!s.is_visible; renderList(); msg('Visibility updated'); refreshPrev(); }
async function move(id,dir){ const ed=sections.filter(s=>!s.is_layout); const i=ed.findIndex(s=>s.id===id), j=i+dir; if(i<0||j<0||j>=ed.length)return; [ed[i],ed[j]]=[ed[j],ed[i]]; sections=sections.filter(s=>s.is_layout).concat(ed); renderList(); const r=await fetch(API+'/reorder',{method:'POST',headers:headers(),body:JSON.stringify({order:sections.map(s=>s.id)})}); if(!r.ok){ msg('Reorder failed'); return; } msg('Order saved'); refreshPrev(); }
async function publish(){ if(!confirm('Publish the current draft to the LIVE site? A backup + version are saved.'))return; const note=prompt('Optional note for version history:','')||''; const r=await fetch(API+'/publish',{method:'POST',headers:headers(),body:JSON.stringify({note})}); const d=await r.json().catch(()=>({})); if(r.ok&&d.ok) msg('Published ✓ '+(d.bytes||'')+' bytes live'); else msg('Publish failed: '+(d.error||r.status)); }
function refreshPrev(){ const k=curKey(); document.getElementById('pv').src='/cms-preview?t='+Date.now()+(k?('#cms-'+k):''); }
function scrollPreview(){ const k=curKey(); if(!k)return; const f=document.getElementById('pv'); try{ const el=f.contentDocument&&f.contentDocument.getElementById('cms-'+k); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); else f.contentWindow.location.hash='cms-'+k; }catch(e){} }

/* ---------- pickers ---------- */
async function openPicker(cb){ pickCb=cb; show('pickerOv',1); const g=document.getElementById('pickerGrid'); g.innerHTML='Loading…'; const items=await getJson('/media'); g.innerHTML=''; if(!items.length){ g.innerHTML='<p class="hint">No media yet — upload in the Media tab.</p>'; return; } items.forEach(m=>{ const d=document.createElement('div'); d.className='pick-tile'; d.innerHTML=`<div class="pth" style="background-image:url('${m.url}')"></div><div style="font-size:10.5px;color:#8a94a0;padding:4px 6px">${escHtml(m.kind)}</div>`; d.onclick=()=>{ show('pickerOv',0); if(pickCb) pickCb(m.url); }; g.appendChild(d); }); }
function openIconPicker(cb){ iconCb=cb; show('iconOv',1); const g=document.getElementById('iconGrid'); g.innerHTML=''; ICONS.forEach(ic=>{ const b=document.createElement('button'); b.className='iconbtn'; b.title=ic.n; b.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="#0B6373" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${ic.s}</svg>`; b.onclick=()=>{ show('iconOv',0); if(iconCb) iconCb(ic.s); }; g.appendChild(b); }); }

/* ---------- media ---------- */
async function loadMedia(){ const items=await getJson('/media'); mediaItems=items; const g=document.getElementById('mediaGrid'); g.innerHTML=''; if(!items.length){ g.innerHTML='<p class="hint">No media yet — upload, or click “Scan page for existing images”.</p>'; return; } items.forEach(m=>{ const d=document.createElement('div'); d.className='m-tile'; d.innerHTML=`<div class="ph" style="background-image:url('${m.url}')"></div><div style="padding:9px 10px"><div style="font-size:11px;color:#8a94a0">${escHtml(m.kind)}${m.width?(' · '+m.width+'×'+m.height):''}</div><div style="font-size:11px;color:#0B6373;margin-top:2px;font-weight:700">${(m.used_in&&m.used_in.length)?('Used in: '+m.used_in.map(escHtml).join(', ')):'Not used on the page'}</div><input type="text" value="${escAttr(m.alt||'')}" placeholder="alt text" onchange="saveAlt(${m.id},this.value)" style="margin:6px 0"><div style="display:flex;gap:6px"><button class="small" onclick="copyUrl('${m.url}')">Copy URL</button><button class="small" style="border-color:#e0b4b4;color:#b23" onclick="delMedia(${m.id})">Delete</button></div></div>`; g.appendChild(d); }); }
async function uploadMedia(inp){ const files=[...inp.files]; if(!files.length)return; msg('Uploading…'); for(const file of files){ const fd=new FormData(); fd.append('file',file); const r=await fetch(API+'/media',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:fd}); if(!r.ok){ const e=await r.json().catch(()=>({})); msg('Upload failed: '+(e.message||r.status)); } } inp.value=''; loadMedia(); msg('Uploaded'); }
async function scanMedia(){ msg('Scanning…'); const r=await fetch(API+'/media/scan',{method:'POST',headers:headers()}); const d=await r.json().catch(()=>({})); if(r.ok){ msg('Found '+(d.added||0)+' image(s)'); loadMedia(); } else msg('Scan failed'); }
async function extractMedia(){ if(!confirm('Extract embedded (base64) images into files? Updates sections; Publish to apply live.'))return; msg('Extracting…'); const r=await fetch(API+'/media/extract',{method:'POST',headers:headers()}); const d=await r.json().catch(()=>({})); if(r.ok){ msg('Extracted '+(d.extracted||0)+' image(s)'); loadMedia(); } else msg('Extract failed'); }
function copyUrl(u){ const full=(u&&u.indexOf('http')===0)?u:(location.origin+u); const done=()=>msg('URL copied'); if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(full).then(done).catch(()=>fallbackCopy(full,done)); } else { fallbackCopy(full,done); } }
function fallbackCopy(text,done){ const ta=document.createElement('textarea'); ta.value=text; ta.style.position='fixed'; ta.style.top='-1000px'; ta.style.opacity='0'; document.body.appendChild(ta); ta.focus(); ta.select(); let ok=false; try{ ok=document.execCommand('copy'); }catch(e){} document.body.removeChild(ta); if(ok){ done(); } else { window.prompt('Copy this URL (Ctrl+C):', text); } }
async function saveAlt(id,v){ await fetch(API+'/media/'+id,{method:'PUT',headers:headers(),body:JSON.stringify({alt:v})}); msg('Alt saved'); }
async function delMedia(id){ const m=mediaItems.find(x=>x.id===id); const used=(m&&m.used_in&&m.used_in.length)?m.used_in:null; let ok; if(used){ ok=confirm('This image is used in: '+used.join(', ')+'.\n\nDelete anyway? Those sections will show a broken image until you replace it (open the section, Simple edit, then Pick a new image).'); } else { ok=confirm('Delete this media file?'); } if(!ok) return; const r=await fetch(API+'/media/'+id,{method:'DELETE',headers:headers()}); if(r.ok){ loadMedia(); msg('Deleted'); } else msg('Delete failed'); }

/* ---------- seo ---------- */
async function loadSeo(){ const r=await fetch(API+'/seo',{headers:{'Accept':'application/json'}}); if(!r.ok){ msg('SEO load failed'); return; } const d=await r.json(); SEO_KEYS.forEach(k=>{ const el=document.getElementById(k); if(el) el.value=d[k]||''; }); updateSeoPreview(); }
async function saveSeoAll(){ const body={}; SEO_KEYS.forEach(k=>{ const el=document.getElementById(k); body[k]=el?el.value:''; }); const r=await fetch(API+'/seo',{method:'PUT',headers:headers(),body:JSON.stringify(body)}); if(r.ok){ msg('SEO saved — Publish to apply'); refreshPrev(); } else msg('Save failed'); }
function pickSeo(id){ openPicker(url=>{ const el=document.getElementById(id); if(el){ el.value=url; if(id==='seo_og_image') updateSeoPreview(); } }); }
function updateSeoPreview(){ const g=id=>document.getElementById(id); const t=g('seo_title').value||'Your page title'; const d=g('seo_description').value||'Your meta description will show here.'; const u=g('seo_canonical').value||location.origin; g('gT').textContent=t; g('gU').textContent=u; g('gD').textContent=d; g('sT').textContent=t; g('sD').textContent=d; g('sU').textContent=u.replace(/^https?:\/\//,''); const img=g('seo_og_image').value; const si=g('sImg'); if(img){ const full=img.startsWith('http')?img:(location.origin+'/'+img.replace(/^\//,'')); si.style.backgroundImage="url('"+full+"')"; si.textContent=''; } else { si.style.backgroundImage=''; si.textContent='og:image'; } }

tab('sections');
loadAll();
</script>
@endverbatim
</body>
</html>
