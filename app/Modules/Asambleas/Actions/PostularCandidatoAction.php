<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Finanzas\Actions\PersonaEnMoraAction;
use App\Modules\Unidades\Actions\PropietariosVigentesAction;
use App\Modules\Usuarios\Models\CargoDirectiva;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: postular a una persona como candidata a un cargo de la directiva en un punto de elección, o retirar su candidatura.
 * Solo propietarios sin mora y con correo (necesitan cuenta para ejercer el cargo). Se puede cambiar la lista de un cargo hasta que se
 * abre su votación. Un cargo con un solo candidato también se vota (puede ganar el voto en blanco).
 */
final class PostularCandidatoAction
{
    public function __construct(private readonly PropietariosVigentesAction $propietarios, private readonly PersonaEnMoraAction $enMora) {}

    public function postular(User $user, int $puntoId, int $personaId, string $cargo): Candidato
    {
        return DB::transaction(function () use ($user, $puntoId, $personaId, $cargo): Candidato {
            $punto = $this->punto($puntoId);
            if (! in_array($cargo, array_map(fn (Rol $r): string => $r->value, CargoDirectiva::cargos()), true)) {
                throw new ApiException('CARGO_NO_VALIDO', 'Ese cargo no existe en la directiva.', 422);
            }
            $this->exigirSinVotacion($punto, $cargo);

            $persona = $this->propietarios->execute([$personaId])[0]
                ?? throw new ApiException('NO_ES_PROPIETARIO', 'Solo los propietarios pueden ser candidatos a la directiva.', 422);
            if ($this->enMora->execute($personaId)) {
                throw new ApiException('PERSONA_EN_MORA', 'Esa persona está en mora con el condominio: no puede ser candidata a la directiva (Decreto 462).', 422);
            }
            if ($persona['email'] === null || $persona['email'] === '') {
                throw new ApiException('PERSONA_SIN_CORREO', 'Esa persona no tiene correo registrado: lo necesita para ejercer el cargo. Agrégalo en su ficha.', 422);
            }
            if (Candidato::query()->where('punto_id', $punto->id)->where('cargo', $cargo)->where('persona_id', $personaId)->exists()) {
                throw new ApiException('YA_ES_CANDIDATO', 'Esa persona ya es candidata a ese cargo.', 409);
            }

            return Candidato::create(['asamblea_id' => $punto->asamblea_id, 'punto_id' => $punto->id, 'persona_id' => $personaId, 'cargo' => $cargo, 'postulado_por' => $user->id]);
        });
    }

    public function retirar(int $candidatoId): void
    {
        DB::transaction(function () use ($candidatoId): void {
            $c = Candidato::query()->lockForUpdate()->findOrFail($candidatoId);
            $punto = $this->punto($c->punto_id);
            $this->exigirSinVotacion($punto, $c->cargo);
            $c->delete();
        });
    }

    private function punto(int $puntoId): AsambleaPunto
    {
        $punto = AsambleaPunto::query()->lockForUpdate()->findOrFail($puntoId);
        if ($punto->tipo !== AsambleaPunto::ELECCION) {
            throw new ApiException('PUNTO_NO_ES_ELECCION', 'Solo los puntos de elección de directiva tienen candidatos.', 422);
        }
        $asamblea = Asamblea::query()->findOrFail($punto->asamblea_id);
        if (! in_array($asamblea->estado, [Asamblea::BORRADOR, Asamblea::CONVOCADA, Asamblea::EN_REGISTRO, Asamblea::INSTALADA, Asamblea::EN_VOTACION], true) || $punto->eleccion_cerrada_en !== null) {
            throw new ApiException('ELECCION_CERRADA', 'La elección ya se cerró o la asamblea terminó: no se cambian los candidatos.', 409);
        }

        return $punto;
    }

    private function exigirSinVotacion(AsambleaPunto $punto, string $cargo): void
    {
        if (Votacion::query()->where('punto_id', $punto->id)->where('cargo', $cargo)->exists()) {
            throw new ApiException('CARGO_YA_VOTADO', 'La votación de ese cargo ya se abrió: la lista de candidatos no cambia.', 409);
        }
    }
}
