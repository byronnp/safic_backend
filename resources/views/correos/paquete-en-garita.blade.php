<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tienes un paquete en garita</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">@if ($recordatorio) Tu paquete sigue en garita @else Tienes un paquete en garita @endif</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 12px 0">Hola {{ $nombre }}, en <strong>{{ $condominio }}</strong> hay un paquete de <strong>{{ $empresa }}</strong> para la unidad <strong>{{ $unidad }}</strong>, recibido el {{ $recibido }}.</p>
@if ($recordatorio)
<p style="font-size:15px;line-height:1.55;margin:0 0 12px 0">Lleva <strong>{{ $dias }} días</strong> esperando. Pasa a retirarlo cuando puedas.</p>
@endif
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Puede retirarlo cualquier ocupante de la unidad o una persona que tú autorices. Al entregarlo se pide una firma.</p>
</td></tr>
</table>
</body>
</html>
