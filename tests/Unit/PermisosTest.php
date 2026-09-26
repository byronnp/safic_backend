<?php

use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;

it('no da permisos de plataforma a roles de condominio', function (Rol $rol) {
    $dePlataforma = array_filter($rol->permisosPorDefecto(), fn (Permiso $p) => $p->esDePlataforma());

    expect($dePlataforma)->toBeEmpty();
})->with(array_filter(Rol::cases(), fn (Rol $r) => ! $r->esDePlataforma()));

it('marca los cargos de directiva', function () {
    expect(Rol::Presidente->esCargo())->toBeTrue()
        ->and(Rol::Tesorero->esCargo())->toBeTrue()
        ->and(Rol::Administrador->esCargo())->toBeFalse();
});
