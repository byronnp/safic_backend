<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recordatorio de cuota</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
@if ($tipo === 'antes')
<h1 style="font-size:22px;margin:20px 0 8px 0">Tu cuota está por vencer</h1>
@elseif ($tipo === 'dia')
<h1 style="font-size:22px;margin:20px 0 8px 0">Tu cuota vence hoy</h1>
@else
<h1 style="font-size:22px;margin:20px 0 8px 0">Tienes cuotas pendientes</h1>
@endif
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">Hola, {{ $nombre }}. En <strong>{{ $condominio }}</strong>, la unidad <strong>{{ $unidad }}</strong> tiene {{ $cuotas }} {{ $cuotas === 1 ? 'cuota pendiente' : 'cuotas pendientes' }} por <strong>$ {{ $saldo }}</strong>@if ($tipo !== 'antes'), vencida{{ $cuotas === 1 ? '' : 's' }} desde el {{ $vence }}@else, la primera vence el {{ $vence }}@endif.</p>
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Puedes pagar por transferencia desde <strong>Mi cuenta › Pagar</strong>. Si ya pagaste, ignora este mensaje: se actualiza al aprobar tu comprobante.</p>
</td></tr>
</table>
</body>
</html>
