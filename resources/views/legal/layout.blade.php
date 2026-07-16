<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title') — SmartEPT by Ametecs</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;background:#F4F6F9;color:#15171C;font-size:14.5px;line-height:1.75}
header{background:linear-gradient(160deg,#04252C 0%,#083A44 60%,#0B4A56 100%);padding:18px 0}
.wrap{max-width:820px;margin:0 auto;padding:0 22px}
.brand{display:flex;align-items:center;gap:11px;text-decoration:none}
.mk{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff}
.brand b{font-size:16px;color:#fff}.brand small{display:block;font-size:8.5px;letter-spacing:2px;color:#7FA8AF}
main{background:#fff;border:1px solid #E7E9EF;border-radius:14px;margin:26px auto;max-width:820px;padding:34px 38px}
h1{font-size:23px;color:#0B6373;margin-bottom:4px}
.upd{font-size:12px;color:#878C99;margin-bottom:20px}
h2{font-size:16px;color:#0E7C8F;margin:22px 0 8px}
p{margin-bottom:12px;color:#3A3E48}
ul{margin:0 0 12px 22px;color:#3A3E48}
li{margin-bottom:6px}
a{color:#0E7C8F;font-weight:600}
.note{background:#E3F4F7;border-left:4px solid #0E7C8F;border-radius:0 9px 9px 0;padding:12px 15px;margin:14px 0;color:#0B6373;font-size:13.5px}
footer{max-width:820px;margin:0 auto 34px;padding:0 22px;font-size:12px;color:#878C99;display:flex;flex-wrap:wrap;gap:8px 16px}
footer a{color:#0B6373;text-decoration:none}
@media(max-width:640px){main{padding:24px 20px;margin:16px}}
</style>
</head>
<body>
<header><div class="wrap"><a class="brand" href="/"><span class="mk">EPT</span><span><b>SmartEPT</b><small>BY AMETECS</small></span></a></div></header>
<main>
<h1>@yield('title')</h1>
<div class="upd">Last updated: 15 July 2026 · Ametecs India Private Limited</div>
@yield('content')
</main>
<footer>
  <span>© 2026 Ametecs India Private Limited · GSTIN 36AAHCT0971F1ZB</span>
  <a href="/privacy">Privacy</a><a href="/terms">Terms</a><a href="/refunds">Refunds</a><a href="/contact">Contact</a>
  <a href="/">smartept home</a>
</footer>
</body>
</html>
