<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Subscriptions\LimiteUnidades;
use App\Modules\Finanzas\Actions\ObtenerMetodoCobroAction;
use App\Modules\Unidades\Http\Requests\GuardarUnidadRequest;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Unidades\Services\LectorExcelUnidades;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Caso de uso: importar unidades desde un Excel.
 *
 * Sin $confirmar solo valida (vista previa). Con $confirmar crea todo o nada:
 * si una fila tiene error no se crea ninguna. Cada fila se valida con las mismas
 * reglas que el formulario de Nueva unidad y respeta el total contratado.
 */
final class ImportarUnidadesAction
{
    public function __construct(
        private readonly ObtenerMetodoCobroAction $metodoCobro,
        private readonly LectorExcelUnidades $lector,
        private readonly LimiteUnidades $limite,
    ) {}

    /**
     * @return array{
     *     confirmado: bool,
     *     total_filas: int,
     *     validas: int,
     *     con_errores: int,
     *     errores: list<array{fila: int, campo: string, mensaje: string}>,
     *     bloques_nuevos: list<string>,
     *     cupo: array{total: int, registradas: int, nuevas: int, alcanza: bool},
     *     creadas: int
     * }
     */
    public function execute(string $ruta, bool $confirmar): array
    {
        $metodo = $this->metodoCobro->execute();
        $lectura = $this->lector->leer($ruta, $metodo);

        $peticion = GuardarUnidadRequest::create('/', 'POST');
        $reglas = $peticion->rules();
        $mensajes = $peticion->messages();

        $bloques = Bloque::query()->get()->keyBy(fn (Bloque $b) => mb_strtolower($b->nombre));
        $codigosVistos = [];
        $errores = [];
        $validas = [];
        $bloquesNuevos = [];

        foreach ($lectura['filas'] as $numero => $fila) {
            $datos = $this->preparar($fila);
            $nombreBloque = $fila['bloque'] ?? null;
            $erroresFila = [];

            if ($nombreBloque !== null && mb_strlen($nombreBloque) > 60) {
                $erroresFila['bloque'] = 'El nombre del bloque tiene máximo 60 caracteres.';
            }

            if ($nombreBloque !== null && $bloques->has(mb_strtolower($nombreBloque))) {
                $datos['bloque_id'] = $bloques->get(mb_strtolower($nombreBloque))->id;
            }

            $validador = Validator::make($datos, $reglas, $mensajes);
            foreach ($validador->errors()->messages() as $campo => $mensajesCampo) {
                $erroresFila[$campo] = $mensajesCampo[0];
            }

            $codigo = $datos['codigo'] ?? null;
            if ($codigo !== null && ! isset($erroresFila['codigo'])) {
                if (isset($codigosVistos[$codigo])) {
                    $erroresFila['codigo'] = "El código se repite en la fila {$codigosVistos[$codigo]}.";
                }
                $codigosVistos[$codigo] ??= $numero;
            }

            if ($erroresFila !== []) {
                foreach ($erroresFila as $campo => $mensaje) {
                    $errores[] = ['fila' => $numero, 'campo' => $campo, 'mensaje' => $mensaje];
                }

                continue;
            }

            if ($nombreBloque !== null && ! isset($datos['bloque_id']) && ! in_array($nombreBloque, $bloquesNuevos, true)) {
                $bloquesNuevos[] = $nombreBloque;
            }

            $validas[$numero] = ['datos' => $datos, 'bloque' => $nombreBloque];
        }

        $nuevasConCupo = count(array_filter($validas, fn (array $v) => LimiteUnidades::cuenta((string) $v['datos']['tipo'])));
        $total = $this->limite->total();
        $registradas = $this->limite->registradas();

        $resultado = [
            'confirmado' => false,
            'total_filas' => count($lectura['filas']),
            'validas' => count($validas),
            'con_errores' => count(array_unique(array_column($errores, 'fila'))),
            'errores' => $errores,
            'bloques_nuevos' => $bloquesNuevos,
            'cupo' => [
                'total' => $total,
                'registradas' => $registradas,
                'nuevas' => $nuevasConCupo,
                'alcanza' => $registradas + $nuevasConCupo <= $total,
            ],
            'creadas' => 0,
        ];

        if (! $confirmar) {
            return $resultado;
        }

        if ($errores !== []) {
            throw new ApiException('IMPORTACION_CON_ERRORES', 'Corrige los errores del archivo; no se creó ninguna unidad.', 422, ['errores' => $errores]);
        }

        if ($validas === []) {
            throw new ApiException('IMPORTACION_VACIA', 'El archivo no tiene unidades para importar.', 422);
        }

        DB::transaction(function () use ($validas, $nuevasConCupo): void {
            if ($nuevasConCupo > 0) {
                $this->limite->asegurarCupo($nuevasConCupo);
            }

            $bloques = Bloque::query()->get()->keyBy(fn (Bloque $b) => mb_strtolower($b->nombre));
            $orden = (int) Bloque::query()->max('orden');

            foreach ($validas as $unidad) {
                $datos = $unidad['datos'];
                $nombre = $unidad['bloque'];

                if ($nombre !== null && ! isset($datos['bloque_id'])) {
                    $bloque = $bloques->get(mb_strtolower($nombre))
                        ?? Bloque::create(['nombre' => $nombre, 'orden' => ++$orden]);
                    $bloques->put(mb_strtolower($nombre), $bloque);
                    $datos['bloque_id'] = $bloque->id;
                }

                Unidad::create($datos);
            }
        });

        return [...$resultado, 'confirmado' => true, 'creadas' => count($validas)];
    }

    /**
     * Deja la fila como la espera GuardarUnidadRequest: sin celdas vacías, con
     * el código en mayúsculas y los textos fijos en minúscula.
     *
     * @param  array<string, string|null>  $fila
     * @return array<string, string>
     */
    private function preparar(array $fila): array
    {
        unset($fila['bloque']);
        $datos = array_filter($fila, fn (?string $v) => $v !== null);

        foreach (['tipo', 'responsable_pago'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = mb_strtolower($datos[$campo]);
            }
        }

        if (isset($datos['codigo'])) {
            $datos['codigo'] = mb_strtoupper($datos['codigo']);
        }

        return $datos;
    }
}
