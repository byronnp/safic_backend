<?php

use Illuminate\Support\Facades\Schedule;

// Tareas programadas de SAFIC (php artisan schedule:work en el contenedor "scheduler").
// Se irán agregando por módulo, p. ej.:
// Schedule::job(new GenerarCuotasDelMes)->dailyAt('02:00')->withoutOverlapping()->onOneServer();

Schedule::command('finanzas:activar-cambios-de-cuenta')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('finanzas:recordatorios-de-mora')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
Schedule::command('finanzas:actualizar-morosidad')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('finanzas:generar-recurrentes')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
