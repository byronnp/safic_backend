<?php

use App\Core\Tenancy\TenantDatabase;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

function tenantDatabase(int $nivelTransaccion): array
{
    $connection = Mockery::mock(Connection::class);
    $connection->allows('getDriverName')->andReturn('pgsql');
    $connection->allows('transactionLevel')->andReturn($nivelTransaccion);

    $db = Mockery::mock(DatabaseManager::class);
    $db->allows('connection')->andReturn($connection);

    return [new TenantDatabase($db), $connection];
}

it('no fija el condominio fuera de una transacción', function () {
    [$database] = tenantDatabase(0);

    $database->apply(5);
})->throws(LogicException::class, 'transacción');

it('fuera de una transacción limpiar no hace nada', function () {
    [$database, $connection] = tenantDatabase(0);
    $connection->expects('select')->never();

    $database->apply(null);
});

it('fija el condominio solo para la transacción (SET LOCAL)', function () {
    [$database, $connection] = tenantDatabase(1);
    $connection->expects('select')->with('select set_config(?, ?, true)', ['app.condominio_id', '5'])->once();

    $database->apply(5);
});
