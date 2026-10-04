<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tu acceso a SAFIC</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">Hola, {{ $nombre }}</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">Te registraron como administrador de <strong>{{ $condominio }}</strong> en SAFIC, el sistema de administración financiera del condominio.</p>
<p style="font-size:15px;line-height:1.55;margin:0 0 24px 0">Para entrar, crea tu contraseña con este enlace:</p>
<p style="margin:0 0 24px 0"><a href="{{ $enlace }}" style="display:inline-block;background:#0E5E5B;color:#FFFFFF;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px">Crear mi contraseña</a></p>
<p style="font-size:13px;line-height:1.5;color:#5F5B52;margin:0">El enlace vence en {{ $diasVigencia }} días y solo se puede usar una vez. Si no esperabas este correo, ignóralo.</p>
</td></tr>
</table>
</body>
</html>
