# Estado de cuenta del Banco del Pichincha (referencia de formato)

Fuente: «Estado de cuenta - Marzo 2026.pdf» (cuenta de ahorros de ejemplo, ciclo 1, 5 páginas, PDF con texto seleccionable, sin cifrar).
El original tiene datos personales y no se guarda en el repositorio; aquí solo queda su estructura.

## Encabezado (primera página)
Nombre del titular, `C.I./RUC`, `CUENTA: <10 dígitos>`, `CICLO n`.

## Detalle de movimientos (tabla, repetida en cada página)
Columnas: `FECHA` · `OFIC.` · `N.DOC.` · `DESCRIPCION` · `DEBITO` · `CREDITO` · `SALDO`.

- `FECHA` viene como `dd-Mmm` en español abreviado (`30-Mar`, `06-Apr` en el ejemplo) y **sin año**: el año sale del ciclo (`FECHA ESTE CORTE`).
- Orden: del más reciente al más antiguo dentro del ciclo.
- `DEBITO` y `CREDITO`: una de las dos es `0.00` en cada fila; punto decimal, sin separador de miles.
- `SALDO`: saldo después del movimiento. Sirve para validar la lectura (saldo anterior ± movimientos).
- `N.DOC.` no es único (varias filas comparten el mismo, p. ej. una retención y sus comisiones).
- Descripciones vistas: `TRANSFERENCIA INTERNET`, `TRANSFERENCIA INTERBANCARIA ENVIADA`, `CONSUMO VISA …`, `RETIRO ATM …`, y cargos del banco: `**CM RET …` (comisión), `IVA RET…`, `COSTO IVA CASH`, `IVA COBRADO …`.
- La descripción **no trae el nombre ni la referencia del ordenante** en las transferencias recibidas: para conciliar pagos de residentes se depende de monto, fecha y N.DOC.

## Resumen y conciliación (última página)
`FECHA ÚLTIMO CORTE (FACTURA)` y `FECHA ESTE CORTE (FACTURA)` en `dd-mm-aaaa`; `SALDO ANTERIOR`; `DEPÓSITO / CRÉDITOS (n)`; `CHEQUES / DÉBITOS (n)`; `INTERÉS PERIODO`; `SALDO ACTUAL`; `SALDO PROMEDIO EFECTIVO`; `VALOR IVA COBRADO`.
Cuadre: saldo anterior + créditos − débitos = saldo actual.

## Implicaciones para SAFIC
- `Core/Banking/LectorEstadoCuenta` hoy lee Excel y CSV; este banco entrega PDF, así que **no se importa directo**.
- Opciones: pedir al banco el extracto en Excel/CSV (cash management), o leer el PDF (requiere una librería de PDF nueva, a justificar en el PR).
