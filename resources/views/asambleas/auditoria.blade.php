<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Auditoría de votación</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1b18; }
  h1 { font-size: 17px; margin: 0 0 4px 0; }
  h2 { font-size: 13px; margin: 16px 0 6px 0; }
  .meta { color: #5f5b52; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #e4e1d8; }
  th { background: #f5f4ef; font-size: 10px; }
  .num { text-align: right; }
  .ok { color: #14653a; font-weight: bold; }
  .mal { color: #9b1c12; font-weight: bold; }
  .hash { font-family: DejaVu Sans Mono, monospace; font-size: 8px; word-break: break-all; }
</style>
</head>
<body>
<h1>Auditoría de votación</h1>
<div class="meta">{{ $condominio['nombre'] }}@if ($condominio['ruc']) · RUC {{ $condominio['ruc'] }}@endif · {{ $asamblea['titulo'] }} · {{ $asamblea['fecha'] }}</div>

<h2>Punto {{ $punto['orden'] }}: {{ $punto['titulo'] }}</h2>
<div class="meta">
  Votación abierta el {{ $votacion['abierta'] }}@if ($votacion['cerrada']) y cerrada el {{ $votacion['cerrada'] }}@endif ·
  {{ $votacion['mayoria'] }} · base: {{ $votacion['base'] }} ({{ $votacion['base_alicuota'] }})
  @if ($votacion['secreta']) · <strong>Voto secreto</strong>@endif
</div>

<table>
  <thead><tr><th>Opción</th><th class="num">Votos</th><th class="num">Alícuota</th></tr></thead>
  <tbody>
    <tr><td>Sí</td><td class="num">{{ $totales['votos_si'] }}</td><td class="num">{{ $totales['alicuota_si'] }}</td></tr>
    <tr><td>No</td><td class="num">{{ $totales['votos_no'] }}</td><td class="num">{{ $totales['alicuota_no'] }}</td></tr>
    <tr><td>Abstención</td><td class="num">{{ $totales['votos_abstencion'] }}</td><td class="num">{{ $totales['alicuota_abstencion'] }}</td></tr>
  </tbody>
</table>
<p><strong>Resultado: {{ $votacion['resultado'] }}.</strong></p>

<h2>{{ $votacion['secreta'] ? 'Unidades que votaron' : 'Votos por unidad' }}</h2>
<table>
  <thead>
    <tr><th>Unidad</th><th class="num">Alícuota</th>@unless ($votacion['secreta'])<th>Opción</th><th>Canal</th>@endunless<th>Hora</th></tr>
  </thead>
  <tbody>
  @foreach ($votos as $v)
    <tr>
      <td>{{ $v['codigo'] }}</td><td class="num">{{ $v['alicuota'] }}</td>
      @unless ($votacion['secreta'])<td>{{ $v['opcion'] }}</td><td>{{ $v['canal'] }}</td>@endunless
      <td>{{ $v['hora'] }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

<h2>Integridad</h2>
<p class="{{ $integridad['valida'] ? 'ok' : 'mal' }}">
  {{ $integridad['valida'] ? 'La cadena de hashes es válida: ningún voto fue alterado.' : 'La cadena de hashes NO es válida: hay un voto alterado.' }}
</p>
<div class="meta">{{ $integridad['votos'] }} votos encadenados. Último hash:</div>
<div class="hash">{{ $integridad['ultimo_hash'] ?? '—' }}</div>
<div class="meta" style="margin-top:14px">Reporte emitido por SAFIC el {{ $emitida }}. Las horas son las del condominio.</div>
</body>
</html>
