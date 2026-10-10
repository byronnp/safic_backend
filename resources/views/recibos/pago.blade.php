<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Recibo N.º {{ $numero }}</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1C1B18; }
  h1 { font-size: 20px; margin: 0; }
  .marca { font-weight: bold; font-size: 14px; color: #12302F; }
  .cabecera { width: 100%; border-bottom: 2px solid #12302F; padding-bottom: 10px; margin-bottom: 14px; }
  .cabecera td { vertical-align: top; }
  .derecha { text-align: right; }
  .caja { border: 1px solid #E4E1D8; border-radius: 6px; padding: 8px 10px; margin-bottom: 12px; }
  .etiqueta { color: #6B675D; font-size: 10px; text-transform: uppercase; }
  table.detalle { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  table.detalle th { text-align: left; background: #F5F4EF; padding: 6px 8px; font-size: 10px; text-transform: uppercase; color: #6B675D; }
  table.detalle td { padding: 6px 8px; border-bottom: 1px solid #F0EDE5; }
  .num { text-align: right; }
  .total td { font-weight: bold; font-size: 14px; border-top: 2px solid #12302F; border-bottom: none; }
  .pie { color: #6B675D; font-size: 10px; margin-top: 18px; }
</style>
</head>
<body>
<table class="cabecera">
  <tr>
    <td>
      <div class="marca">{{ $condominio['nombre'] }}</div>
      @if($condominio['ruc'])<div>RUC {{ $condominio['ruc'] }}</div>@endif
      @if($condominio['direccion'])<div>{{ $condominio['direccion'] }}</div>@endif
    </td>
    <td class="derecha">
      <h1>Recibo N.º {{ $numero }}</h1>
      <div>{{ $fecha }}</div>
    </td>
  </tr>
</table>

<table style="width:100%"><tr>
<td style="width:50%;padding-right:6px"><div class="caja"><div class="etiqueta">Unidad</div><strong>{{ $unidad }}</strong></div></td>
<td style="width:50%;padding-left:6px"><div class="caja"><div class="etiqueta">Pagado por</div><strong>{{ $pagador['nombre'] }}</strong>@if($pagador['cedula'])<div>C.I. {{ $pagador['cedula'] }}</div>@endif</div></td>
</tr></table>

<table class="detalle">
  <thead><tr><th>Concepto</th><th class="num">Valor aplicado</th></tr></thead>
  <tbody>
  @forelse($aplicaciones as $a)
    <tr><td>{{ $a['concepto'] }} · {{ $a['mes'] }}</td><td class="num">$ {{ $a['monto'] }}</td></tr>
  @empty
    <tr><td colspan="2">El valor quedó como saldo a favor de la unidad.</td></tr>
  @endforelse
  @if($sobrante !== '0.00')
    <tr><td>Saldo a favor generado</td><td class="num">$ {{ $sobrante }}</td></tr>
  @endif
    <tr class="total"><td>Total recibido</td><td class="num">$ {{ $total }}</td></tr>
  </tbody>
</table>

<div class="caja">
  <div class="etiqueta">Forma de pago</div>
  {{ $forma }}@if($comprobante) · Comprobante {{ $comprobante }}@endif
</div>

<div class="pie">Este recibo corresponde a un pago registrado en SAFIC y no reemplaza una factura electrónica.</div>
</body>
</html>
