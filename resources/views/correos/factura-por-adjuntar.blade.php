<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Falta la factura de un gasto recurrente</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<h1 style="font-size:22px;margin:20px 0 8px 0">Falta la factura de {{ $gasto }}</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">En <strong>{{ $condominio }}</strong>, el gasto recurrente <strong>{{ $gasto }}</strong> ({{ $proveedor }}) se creó el {{ $fecha }}@if ($monto !== null) por <strong>$ {{ $monto }}</strong>@else con monto variable@endif. Falta adjuntar la factura real.</p>
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Regístrala en <strong>Finanzas › Cuentas por pagar</strong> y vincúlala en <strong>Finanzas › Gastos recurrentes</strong>.</p>
</td></tr>
</table>
</body>
</html>
