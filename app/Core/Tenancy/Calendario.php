<?php

namespace App\Core\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fecha de "hoy" en la zona horaria del condominio activo (las vigencias de los
 * ocupantes y los vencimientos se cuentan en la hora local, no en UTC).
 */
final class Calendario
{
    private ?string $zona = null;

    private ?int $condominioId = null;

    public function __construct(private readonly TenantContext $tenant) {}

    public function hoy(): string
    {
        return CarbonImmutable::now($this->zonaHoraria())->toDateString();
    }

    public function zonaHoraria(): string
    {
        $id = $this->tenant->require();

        if ($this->condominioId !== $id) {
            $this->condominioId = $id;
            $this->zona = (string) (DB::table('condominios')->where('id', $id)->value('zona_horaria') ?: 'America/Guayaquil');
        }

        return (string) $this->zona;
    }
}
