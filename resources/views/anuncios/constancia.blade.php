<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Constancia de lectura</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1b18; }
  h1 { font-size: 17px; margin: 0 0 4px 0; }
  h2 { font-size: 13px; margin: 16px 0 6px 0; }
  .meta { color: #5f5b52; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #e4e1d8; }
  th { background: #f5f4ef; font-size: 10px; }
  .total { margin: 8px 0; }
</style>
</head>
<body>
<h1>Constancia de lectura</h1>
<div class="meta">{{ $condominio['nombre'] }}@if ($condominio['ruc']) · RUC {{ $condominio['ruc'] }}@endif @if ($condominio['direccion']) · {{ $condominio['direccion'] }}@endif</div>

<h2>{{ $anuncio['titulo'] }}</h2>
<div class="meta">Publicado el {{ $anuncio['publicado'] }} · Para: {{ $anuncio['destino'] }}</div>
<div class="total">
  <strong>{{ $totales['unidades'] }}</strong> unidades recibieron el anuncio ·
  <strong>{{ $totales['leyeron'] }}</strong> lo leyeron
  @if ($anuncio['pide_confirmacion']) · <strong>{{ $totales['confirmaron'] }}</strong> confirmaron su lectura @endif
</div>

<table>
  <thead><tr><th>Unidad</th><th>Estado</th><th>Fecha y hora</th><th>Registrado por</th></tr></thead>
  <tbody>
  @foreach ($unidades as $u)
    <tr><td>{{ $u['codigo'] }}</td><td>{{ $u['estado'] }}</td><td>{{ $u['cuando'] ?? '—' }}</td><td>{{ $u['quien'] ?? '—' }}</td></tr>
  @endforeach
  </tbody>
</table>
<div class="meta" style="margin-top:14px">Constancia emitida por SAFIC el {{ $emitida }}. Las horas son las del condominio.</div>
</body>
</html>
