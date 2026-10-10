<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Estado de cuenta {{ $unidad['codigo'] }}</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1C1B18; }
  h1 { font-size: 18px; margin: 0; }
  .marca { font-weight: bold; font-size: 14px; color: #12302F; }
  .cabecera { width: 100%; border-bottom: 2px solid #12302F; padding-bottom: 10px; margin-bottom: 12px; }
  .cabecera td { vertical-align: top; }
  .derecha { text-align: right; }
  .resumen { width: 100%; margin-bottom: 12px; }
  .resumen td { border: 1px solid #E4E1D8; padding: 6px 8px; width: 25%; }
  .etiqueta { color: #6B675D; font-size: 9px; text-transform: uppercase; }
  table.mov { width: 100%; border-collapse: collapse; }
  table.mov th { text-align: left; background: #F5F4EF; padding: 5px 7px; font-size: 9px; text-transform: uppercase; color: #6B675D; }
  table.mov td { padding: 5px 7px; border-bottom: 1px solid #F0EDE5; }
  .num { text-align: right; }
  .pie { color: #6B675D; font-size: 9px; margin-top: 14px; }
</style>
</head>
<body>
<table class="cabecera">
  <tr>
    <td>
      <div class="marca">{{ $condominio['nombre'] }}</div>
      @if($condominio['ruc'])<div>RUC {{ $condominio['ruc'] }}</div>@endif
    </td>
    <td class="derecha">
      <h1>Estado de cuenta · {{ $unidad['codigo'] }}</h1>
      <div>{{ $unidad['ubicacion'] }}</div>
      <div>Emitido el {{ $fecha }}</div>
    </td>
  </tr>
</table>

@if($responsable)<p>Responsable de pago: <strong>{{ $responsable['nombre'] }}</strong> ({{ $responsable['relacion'] }})</p>@endif

<table class="resumen"><tr>
<td><div class="etiqueta">Saldo pendiente</div><strong>$ {{ $saldo_pendiente }}</strong></td>
<td><div class="etiqueta">Saldo a favor</div><strong>$ {{ $saldo_favor }}</strong></td>
<td><div class="etiqueta">Días de atraso</div><strong>{{ $dias_atraso }}</strong></td>
<td><div class="etiqueta">Pagado en {{ $pagado_en_el_anio['anio'] }}</div><strong>$ {{ $pagado_en_el_anio['monto'] }}</strong> ({{ $pagado_en_el_anio['pagos'] }} pagos)</td>
</tr></table>

<table class="mov">
  <thead><tr><th>Fecha</th><th>Concepto</th><th class="num">Cargo</th><th class="num">Abono</th><th class="num">Saldo</th></tr></thead>
  <tbody>
  @foreach($movimientos as $m)
    <tr>
      <td>{{ $m['fecha_texto'] }}</td>
      <td>{{ $m['concepto'] }}</td>
      <td class="num">{{ $m['cargo'] ? '$ '.$m['cargo'] : '' }}</td>
      <td class="num">{{ $m['abono'] ? '$ '.$m['abono'] : '' }}</td>
      <td class="num">$ {{ $m['saldo'] }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
<div class="pie">El saldo se calcula de los cargos y los pagos; un saldo negativo es a favor de la unidad. Este documento no es una factura electrónica.</div>
</body>
</html>
