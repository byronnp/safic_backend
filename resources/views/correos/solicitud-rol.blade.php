<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Solicitud de rol</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC · soporte</div>
<h1 style="font-size:20px;margin:20px 0 8px 0">Solicitud de un rol nuevo</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0"><strong>{{ $condominio }}</strong> pide un rol que hoy no existe. Lo solicita {{ $solicitante }}.</p>
<p style="font-size:14px;margin:0 0 4px 0;color:#5F5B52">Nombre sugerido</p>
<p style="font-size:16px;font-weight:700;margin:0 0 16px 0">{{ $nombre }}</p>
<p style="font-size:14px;margin:0 0 4px 0;color:#5F5B52">¿Qué debe poder hacer?</p>
<p style="font-size:15px;line-height:1.55;margin:0">{{ $descripcion }}</p>
</td></tr>
</table>
</body>
</html>
