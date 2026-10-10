<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprobante no aprobado</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">Tu comprobante no fue aprobado</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">Hola, {{ $nombre }}. La administración de <strong>{{ $condominio }}</strong> no pudo aprobar tu pago de <strong>$ {{ $monto }}</strong> de la unidad <strong>{{ $unidad }}</strong>.</p>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0;background:#FFF1DC;padding:12px 14px;border-radius:8px"><strong>Motivo:</strong> {{ $motivo }}</p>
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Puedes volver a enviar el comprobante desde <strong>Mi cuenta › Pagar</strong>.</p>
</td></tr>
</table>
</body>
</html>
