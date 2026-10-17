<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo }}</title>
</head>
<body style="margin:0;padding:24px;background:#F5F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C1B18">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E1D8;border-radius:12px">
<tr><td style="padding:28px 32px">
<div style="font-weight:800;font-size:18px;color:#12302F">SAFIC</div>
<p style="font-size:13px;color:#5F5B52;margin:16px 0 0 0">{{ $condominio }}</p>
<h1 style="font-size:22px;margin:4px 0 12px 0">{{ $titulo }}</h1>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0">Hola {{ $nombre }}, la administración publicó un anuncio:</p>
<p style="font-size:15px;line-height:1.55;margin:0 0 16px 0;padding:12px 14px;background:#F7F6F2;border-radius:10px">{{ $extracto }}</p>
@if ($requiereConfirmacion)
<p style="font-size:14px;line-height:1.5;margin:0;font-weight:700">Este anuncio pide que confirmes su lectura. Abre SAFIC › Avisos y toca «Confirmar lectura».</p>
@else
<p style="font-size:14px;line-height:1.5;color:#5F5B52;margin:0">Lo encuentras completo en SAFIC › Avisos.</p>
@endif
</td></tr>
</table>
</body>
</html>
