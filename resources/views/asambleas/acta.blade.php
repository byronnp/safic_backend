<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Acta de asamblea</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1b18; line-height: 1.45; }
  h1 { font-size: 18px; margin: 0 0 2px 0; }
  h2 { font-size: 13px; margin: 18px 0 6px 0; border-bottom: 1px solid #cfcabd; padding-bottom: 3px; }
  h3 { font-size: 12px; margin: 12px 0 4px 0; }
  .meta { color: #5f5b52; margin-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; margin: 4px 0 8px 0; }
  th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e4e1d8; vertical-align: top; }
  th { background: #f5f4ef; font-size: 10px; }
  .num { text-align: right; }
  .ok { color: #14653a; font-weight: bold; }
  .mal { color: #9b1c12; font-weight: bold; }
  .nota { color: #5f5b52; font-size: 10px; }
  .firmas { margin-top: 36px; width: 100%; }
  .firmas td { width: 50%; text-align: center; border: none; padding-top: 34px; }
  .linea { border-top: 1px solid #1c1b18; padding-top: 4px; }
  .hash { font-family: DejaVu Sans Mono, monospace; font-size: 8px; word-break: break-all; }
</style>
</head>
<body>
@php
  $a = $c['asamblea']; $i = $c['instalacion']; $q = $c['padron'];
  $forma = ['presencial' => 'Presencial', 'virtual' => 'Virtual', 'poder' => 'Por poder'];
  $nombre = fn (string $d) => str_replace('T', ' ', $d);
@endphp

<h1>Acta N.º {{ str_pad((string) $acta['numero'], 3, '0', STR_PAD_LEFT) }}</h1>
<div class="meta">
  {{ $c['condominio']['nombre'] }}@if ($c['condominio']['ruc']) · RUC {{ $c['condominio']['ruc'] }}@endif @if ($c['condominio']['direccion']) · {{ $c['condominio']['direccion'] }}@endif
</div>

<h2>Asamblea {{ $a['tipo'] === 'ordinaria' ? 'ordinaria' : 'extraordinaria' }} de copropietarios: {{ $a['titulo'] }}</h2>
<p>
  Fecha: <strong>{{ $nombre($a['fecha_hora']) }}</strong> · Modalidad: {{ ['presencial' => 'presencial', 'mixta' => 'mixta', 'virtual' => 'virtual'][$a['modalidad']] }}
  @if ($a['lugar']) · Lugar: {{ $a['lugar'] }}@endif
</p>
<p>
  Convocada por escrito el {{ $nombre($c['convocatoria']['convocada_en'] ?? '') }}; la convocatoria llegó a {{ $c['convocatoria']['unidades'] }} unidades y
  {{ $c['convocatoria']['confirmaron'] }} confirmaron su recepción.
</p>

<h2>Instalación y quórum</h2>
<p>
  Se instaló el {{ $nombre($i['instalada_en']) }} en <strong>{{ $i['convocatoria'] === 1 ? 'primera' : 'segunda' }} convocatoria</strong>
  @if ($i['instalada_por']) por {{ $i['instalada_por'] }}@if ($i['en_subrogacion']) <strong>en subrogación del presidente</strong>@endif @endif.
  Quórum al instalar: <strong>{{ $i['quorum_alicuota_presente'] }}</strong> de {{ $i['quorum_alicuota_total'] }} de alícuotas ({{ $i['quorum_pct'] }} %).
  @if ($i['convocatoria'] === 2) En segunda convocatoria se instala con los presentes (Art. 39). @endif
</p>
<p class="nota">
  Padrón congelado al instalar: {{ $q['unidades'] }} unidades con propietario y alícuota.
  @if (count($q['en_mora']) > 0) En mora (sin derecho a voto): {{ implode(', ', $q['en_mora']) }}.@else Ninguna unidad estaba en mora.@endif
</p>

<h2>Asistencia</h2>
<table>
  <thead><tr><th>Unidad</th><th class="num">Alícuota</th><th>Asistente</th><th>Forma</th><th>Ingreso</th><th>Salida</th></tr></thead>
  <tbody>
  @foreach ($c['asistencia'] as $x)
    <tr>
      <td>{{ $x['codigo'] }}@if ($x['en_mora']) <span class="mal">(mora)</span>@endif</td><td class="num">{{ $x['alicuota'] }}</td>
      <td>{{ $x['asistente'] }}</td><td>{{ $forma[$x['forma']] }}</td><td>{{ $nombre($x['ingreso_en']) }}</td><td>{{ $x['salida_en'] ? $nombre($x['salida_en']) : '—' }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

@if (count($c['poderes']) > 0)
<h2>Poderes</h2>
<p class="nota">Poderes y cartas validados por la administración (Art. 40); los documentos se anexan a este acta.</p>
<table>
  <thead><tr><th>Unidad</th><th>Propietario que otorga</th><th>Representante</th><th>Documento</th></tr></thead>
  <tbody>
  @foreach ($c['poderes'] as $p)
    <tr><td>{{ $p['codigo'] }}</td><td>{{ $p['otorgante'] ?? '—' }}</td><td>{{ $p['representante'] }}</td><td>{{ $p['tipo'] === 'notarial' ? 'Poder notarial' : 'Carta' }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

<h2>Orden del día y resultados</h2>
@foreach ($c['puntos'] as $p)
  <h3>{{ $p['orden'] }}. {{ $p['titulo'] }}</h3>
  @if ($p['descripcion'])<p class="nota">{{ $p['descripcion'] }}</p>@endif
  @if ($p['estado'] === 'tratado')
    <p>Punto informativo: se trató sin votación.</p>
  @elseif ($p['estado'] === 'no_tratado')
    <p class="mal">Sin tratar: no se votó este punto.</p>
  @else
    @foreach ($p['votaciones'] as $v)
      <p class="nota">
        @if ($v['cargo_etiqueta']) Cargo: <strong>{{ $v['cargo_etiqueta'] }}</strong> · @endif
        {{ $v['mayoria'] === 'calificada' ? 'Mayoría calificada ('.$v['umbral_pct'].' %)' : 'Mayoría simple' }}
        sobre {{ $v['base'] === 'total' ? 'el total de alícuotas' : 'las alícuotas presentes con derecho a voto' }}
        @if ($v['secreta']) · voto secreto @endif
      </p>
      @if ($v['eleccion'])
        <table>
          <thead><tr><th>Candidato</th><th class="num">Votos</th><th class="num">Alícuota</th></tr></thead>
          <tbody>
          @foreach ($v['eleccion']['candidatos'] as $cand)
            <tr><td>{{ $cand['nombre'] }}@if ($v['eleccion']['ganador_candidato_id'] === $cand['candidato_id']) <strong>(ganador)</strong>@endif</td><td class="num">{{ $cand['votos'] }}</td><td class="num">{{ $cand['alicuota'] }}</td></tr>
          @endforeach
            <tr><td>En blanco</td><td class="num">{{ $v['eleccion']['blanco']['votos'] }}</td><td class="num">{{ $v['eleccion']['blanco']['alicuota'] }}</td></tr>
          </tbody>
        </table>
        <p class="{{ $v['resultado'] === 'aprobado' ? 'ok' : 'mal' }}">{{ $v['resultado'] === 'aprobado' ? 'Hubo ganador.' : 'Nadie alcanzó la mayoría: el cargo queda vacante.' }}</p>
      @else
        <table>
          <thead><tr><th>Opción</th><th class="num">Votos</th><th class="num">Alícuota</th></tr></thead>
          <tbody>
            <tr><td>Sí</td><td class="num">{{ $v['cifras']['votos_si'] }}</td><td class="num">{{ $v['cifras']['alicuota_si'] }}</td></tr>
            <tr><td>No</td><td class="num">{{ $v['cifras']['votos_no'] }}</td><td class="num">{{ $v['cifras']['alicuota_no'] }}</td></tr>
            <tr><td>Abstención</td><td class="num">{{ $v['cifras']['votos_abstencion'] }}</td><td class="num">{{ $v['cifras']['alicuota_abstencion'] }}</td></tr>
          </tbody>
        </table>
        <p class="{{ $v['resultado'] === 'aprobado' ? 'ok' : 'mal' }}">Resultado: {{ $v['resultado'] === 'aprobado' ? 'APROBADO' : 'RECHAZADO' }} (base {{ $v['cifras']['base_alicuota'] }}).</p>
      @endif
      <p class="nota">Cadena de votos: {{ $v['integridad']['valida'] ? 'íntegra' : 'ALTERADA' }} · {{ $v['integridad']['votos'] }} votos · último hash <span class="hash">{{ $v['integridad']['ultimo_hash'] ?? '—' }}</span></p>
    @endforeach
    @if (! empty($p['nombramientos']))
      <p><strong>Nombramientos:</strong>
      @foreach ($p['nombramientos'] as $n)
        {{ $n['etiqueta'] }}: {{ $n['nombre'] ?? 'sin ganador' }} ({{ ['nombrado' => 'nombrado', 'vacante' => 'vacante', 'no_nombrado' => 'no nombrado'][$n['estado']] }})@if (! $loop->last); @endif
      @endforeach
      </p>
    @endif
  @endif
@endforeach

<h2>Resoluciones</h2>
@if (count($resoluciones) === 0)
  <p>La asamblea no adoptó resoluciones.</p>
@else
  <ol>
  @foreach ($resoluciones as $r)
    <li>{{ $r }}</li>
  @endforeach
  </ol>
@endif

<p>Se cerró la asamblea el {{ $nombre($c['cierre']['cerrada_en']) }}.</p>

<table class="firmas">
  <tr>
    <td>
      <div class="linea">
        {{ $firmas['presidente']['nombre'] ?? ($c['directiva']['presidente'] ?? 'Presidente') }}<br>
        Presidente @if ($firmas['presidente']['subrogacion']) <strong>(vicepresidente en subrogación)</strong>@endif<br>
        <span class="nota">{{ $firmas['presidente']['cuando'] ? 'Firmó el '.$firmas['presidente']['cuando'] : 'Pendiente de firma' }}</span>
      </div>
    </td>
    <td>
      <div class="linea">
        {{ $firmas['secretario']['nombre'] ?? ($c['directiva']['secretario'] ?? 'Secretario') }}<br>
        Secretario<br>
        <span class="nota">{{ $firmas['secretario']['cuando'] ? 'Firmó el '.$firmas['secretario']['cuando'] : 'Pendiente de firma' }}</span>
      </div>
    </td>
  </tr>
</table>

<div class="nota" style="margin-top:18px">
  Acta emitida por SAFIC el {{ $emitida }} (horas del condominio). Huella del contenido: <span class="hash">{{ $acta['hash'] }}</span>.
  {{ $acta['firmada'] ? 'Acta firmada y publicada.' : 'Acta pendiente de firmas.' }}
</div>
</body>
</html>
