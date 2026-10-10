<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambio de cuenta de proveedor</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">Cambio de cuenta bancaria de un proveedor</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">{{ $solicitante }} pidió cambiar la cuenta para pagos de <strong>{{ $proveedor }}</strong> en <strong>{{ $condominio }}</strong> (nuevo banco: {{ $banco }}).</p>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0;background:#FFF1DC;padding:12px 14px;border-radius:8px">El cambio se activa el <strong>{{ $activaEn }}</strong>. Mientras tanto se paga a la cuenta anterior. Si no lo reconoces, detenlo desde <strong>Finanzas › Proveedores</strong>.</p>
</td></tr>
</table>
</body>
</html>
