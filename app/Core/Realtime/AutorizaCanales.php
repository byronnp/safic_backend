<?php

namespace App\Core\Realtime;

use App\Core\Permissions\Permiso;
use App\Models\User;

/**
 * Quién puede escuchar cada canal privado. Todas las reglas parten de la membresía activa en el condominio del nombre del canal:
 * quien no pertenece a él no recibe ni siquiera el aviso de que algo cambió.
 */
final class AutorizaCanales
{
    /** Cada cuenta escucha solo su propio canal. */
    public function usuario(User $user, int $condominioId, int $usuarioId): bool
    {
        return $user->id === $usuarioId && $this->puedeEntrar($user, $condominioId);
    }

    public function garita(User $user, int $condominioId): bool
    {
        return $this->puedeEntrar($user, $condominioId) && $this->tienePermiso($user, $condominioId, Permiso::GaritaRegistrar);
    }

    /** Quien registra la asistencia o es propietario escucha el quórum de las asambleas. */
    public function asambleas(User $user, int $condominioId): bool
    {
        return $this->puedeEntrar($user, $condominioId)
            && ($this->tienePermiso($user, $condominioId, Permiso::AsambleasRegistrar) || $this->tienePermiso($user, $condominioId, Permiso::AsambleasPropias));
    }

    private function puedeEntrar(User $user, int $condominioId): bool
    {
        // Mismas condiciones que el header X-Condominio-Id: membresía activa y, para el contador, doble factor ya activado
        return $user->tieneMembresiaActivaEn($condominioId) && ! $user->debeActivarDobleFactor();
    }

    private function tienePermiso(User $user, int $condominioId, Permiso $permiso): bool
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($condominioId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $user->can($permiso->value);
        } finally {
            setPermissionsTeamId($anterior);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
