<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daños reportados en {{ $area }}</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">Daños reportados en {{ $area }}</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 12px 0">En <strong>{{ $condominio }}</strong>, al recibir <strong>{{ $area }}</strong> de la unidad <strong>{{ $unidad }}</strong> ({{ $cuando }}), el guardia reportó:</p>
<p style="font-size:15px;line-height:1.55;margin:0 0 12px 0;padding:12px 14px;background:#F7F6F2;border-radius:10px">{{ $danos }}</p>
@if ($fotos > 0)
<p style="font-size:14px;line-height:1.5;margin:0 0 12px 0">El guardia adjuntó {{ $fotos }} {{ $fotos === 1 ? 'foto' : 'fotos' }}: las ves en SAFIC, en el detalle de la reserva.</p>
@endif
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Revisa si corresponde descontar de la garantía o cobrar el daño a la cuenta de la unidad.</p>
</td></tr>
</table>
</body>
</html>
