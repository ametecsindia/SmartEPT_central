<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico?v=2" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=2">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — SmartEPT Central</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;
background:linear-gradient(160deg,#04252C 0%,#083A44 60%,#0B4A56 100%)}
.card{background:#fff;border-radius:18px;padding:36px 34px;width:100%;max-width:400px;box-shadow:0 30px 80px rgba(0,0,0,.4)}
.brand{display:flex;align-items:center;gap:11px;margin-bottom:6px}
.mk{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff}
.brand b{font-size:18px;color:#15171C}.brand small{display:block;font-size:9px;letter-spacing:2px;color:#878C99}
p.sub{font-size:13px;color:#565A66;margin:6px 0 18px}
label{display:block;font-size:12px;font-weight:700;color:#565A66;margin:12px 0 5px}
input{width:100%;padding:12px 13px;border:1.5px solid #DCDFE7;border-radius:9px;font-size:14.5px;font-family:inherit}
input:focus{outline:none;border-color:#0E7C8F}
button{width:100%;margin-top:20px;padding:13px;border:none;border-radius:9px;font-weight:700;font-size:15px;color:#fff;
background:linear-gradient(135deg,#0E7C8F,#1899AE);cursor:pointer}
.err{background:#FBE9ED;color:#D02748;font-size:12.5px;padding:9px 12px;border-radius:8px;margin-top:12px}
.foot{text-align:center;font-size:11px;color:#878C99;margin-top:18px}
</style>
</head>
<body>
<div class="card">
  <div class="brand" style="justify-content:center;margin-bottom:14px"><img src="/img/smartept-logo-h-light.png" alt="SmartEPT Central" style="width:220px;max-width:80%;height:auto;display:block"></div>
  <p class="sub">Sign in with your Ametecs admin account.</p>
  <form method="POST" action="/admin/login">
    @csrf
    <label>Email</label>
    <input type="email" name="email" value="{{ old('email') }}" required autofocus>
    <label>Password</label>
    <input type="password" name="password" required>
    @error('email')<div class="err">{{ $message }}</div>@enderror
    <button type="submit">Sign in →</button>
  </form>
  <div class="foot">Ametecs India Private Limited · Authorised personnel only</div>
</div>
</body>
</html>
