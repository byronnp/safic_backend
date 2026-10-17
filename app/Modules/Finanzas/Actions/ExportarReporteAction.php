<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Models\Exportacion;
use App\Modules\Finanzas\Services\ReportesFinancieros;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Caso de uso: exportar un reporte a Excel. Cada archivo lleva un código único (EXP-7Q2K) dentro
 * del archivo y en la bitácora de exportaciones (quién, qué, cuándo). Cada persona puede exportar
 * hasta 30 archivos por día (429 LIMITE_EXPORTACIONES). El Excel lleva los montos como números.
 */
final class ExportarReporteAction
{
    public const LIMITE_DIARIO = 30;

    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ReportesFinancieros $reportes,
        private readonly Calendario $calendario,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{ruta: string, nombre: string, codigo: string}
     */
    public function execute(User $user, string $reporte, string $periodo): array
    {
        $this->exigirCupo($user);

        $datos = $this->reportes->generar($reporte, $periodo);
        $codigo = $this->registrar($user, $datos, $periodo);

        $nombre = sprintf('%s-%s-%s.xlsx', str_replace('_', '-', $reporte), $periodo, $codigo);

        return ['ruta' => $this->escribir($datos, $codigo, $user), 'nombre' => $nombre, 'codigo' => $codigo];
    }

    private function exigirCupo(User $user): void
    {
        $inicio = CarbonImmutable::parse($this->calendario->hoy(), $this->calendario->zonaHoraria())->startOfDay()->utc();
        $hechas = Exportacion::query()->where('user_id', $user->id)->where('created_at', '>=', $inicio)->count();

        if ($hechas >= self::LIMITE_DIARIO) {
            throw new ApiException('LIMITE_EXPORTACIONES', 'Llegaste al límite de '.self::LIMITE_DIARIO.' exportaciones por día. Inténtalo mañana o pide apoyo al administrador.', 429);
        }
    }

    /**
     * @param  array{reporte: string, titulo: string, filas: list<array<string, mixed>>}  $datos
     */
    private function registrar(User $user, array $datos, string $periodo): string
    {
        $rol = $user->getRoleNames()->first();

        for ($intento = 0; $intento < 5; $intento++) {
            $codigo = 'EXP-'.implode('', array_map(fn () => self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)], range(1, 4)));
            try {
                DB::transaction(fn () => Exportacion::create([
                    'codigo' => $codigo, 'reporte' => $datos['reporte'], 'titulo' => $datos['titulo'], 'parametros' => ['periodo' => $periodo],
                    'formato' => 'xlsx', 'filas' => count($datos['filas']), 'user_id' => $user->id, 'user_nombre' => $user->name, 'user_rol' => is_string($rol) ? $rol : null,
                ]));

                return $codigo;
            } catch (UniqueConstraintViolationException) {
                continue; // el código ya existía: se prueba otro
            }
        }

        throw new ApiException('ERROR_CODIGO', 'No se pudo generar el código de la exportación. Inténtalo de nuevo.', 500);
    }

    /**
     * @param  array{titulo: string, columnas: list<array{clave: string, etiqueta: string, tipo: string}>, filas: list<array<string, mixed>>}  $datos
     */
    private function escribir(array $datos, string $codigo, User $user): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        $negrita = new Style(fontBold: true);
        $condominio = (string) DB::table('condominios')->where('id', $this->tenant->require())->value('nombre');
        $generado = CarbonImmutable::now($this->calendario->zonaHoraria())->format('d/m/Y H:i');

        $w = new Writer;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValuesWithStyle([$condominio], $negrita));
        $w->addRow(Row::fromValuesWithStyle([$datos['titulo']], $negrita));
        $w->addRow(Row::fromValues(['Código', $codigo, 'Generado', $generado, 'Por', $user->name]));
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValuesWithStyle(array_column($datos['columnas'], 'etiqueta'), $negrita));
        foreach ($datos['filas'] as $fila) {
            $w->addRow(Row::fromValues(array_map(fn (array $c) => $this->celda($fila[$c['clave']] ?? null, $c['tipo']), $datos['columnas'])));
        }
        $w->close();

        return $ruta;
    }

    /** El dinero va como número en el Excel (para sumar y ordenar); lo demás, tal cual. */
    private function celda(mixed $valor, string $tipo): string|int|float|null
    {
        return match ($tipo) {
            'dinero' => is_numeric($valor) ? round((float) $valor, 2) : null,
            'entero', 'porcentaje' => is_numeric($valor) ? (int) $valor : null,
            default => $valor === null ? null : (string) $valor,
        };
    }
}
