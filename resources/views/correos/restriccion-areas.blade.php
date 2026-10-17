<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aviso de restricción de áreas comunes</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">Aviso de restricción de áreas comunes</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">Hola, {{ $nombre }}. La unidad <strong>{{ $unidad }}</strong> de <strong>{{ $condominio }}</strong> tiene {{ $cuotas }} {{ $cuotas === 1 ? 'cuota vencida' : 'cuotas vencidas' }} de {{ $meses }} {{ $meses === 1 ? 'mes' : 'meses' }}, por <strong>$ {{ $saldo }}</strong>.</p>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0;background:#FFF1DC;padding:12px 14px;border-radius:8px">Desde el <strong>{{ $restringeDesde }}</strong> no podrán reservarse ni usarse las áreas comunes (piscina, salón, canchas…) con esta unidad. <strong>Las visitas, los paquetes y la seguridad no se restringen.</strong></p>
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Para evitarlo, paga las cuotas vencidas o acuerda un convenio de pago con la administración. La restricción se levanta apenas se registra el pago o el convenio.</p>
</td></tr>
</table>
</body>
</html>
