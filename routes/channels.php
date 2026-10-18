<?php

use App\Core\Realtime\AutorizaCanales;
use Illuminate\Support\Facades\Broadcast;

// Canales privados de tiempo real (Reverb). El nombre siempre empieza por el condominio y la autorización parte de la
// membresía activa en él. Los avisos no llevan datos: ver App\Core\Realtime\ActualizacionEnVivo.

Broadcast::channel('condominio.{condominioId}.usuario.{usuarioId}', fn ($user, int $condominioId, int $usuarioId): bool => app(AutorizaCanales::class)->usuario($user, $condominioId, $usuarioId));

Broadcast::channel('condominio.{condominioId}.garita', fn ($user, int $condominioId): bool => app(AutorizaCanales::class)->garita($user, $condominioId));
