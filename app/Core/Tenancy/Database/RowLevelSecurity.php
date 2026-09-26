<?php

namespace App\Core\Tenancy\Database;

use App\Core\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Activa Row Level Security en una tabla con condominio_id.
 * Se llama al final del up() de cada migración de tabla de condominio:
 *
 *     RowLevelSecurity::enable('bloques');
 *
 * FORCE hace que también aplique al dueño de la tabla (safic_owner).
 */
final class RowLevelSecurity
{
    public static function enable(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        self::assertValidName($table);
        $condition = sprintf(
            "condominio_id = NULLIF(current_setting('%s', true), '')::bigint",
            TenantDatabase::SETTING,
        );

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY {$table}_por_condominio ON {$table} USING ({$condition}) WITH CHECK ({$condition})");
    }

    public static function disable(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        self::assertValidName($table);
        DB::statement("DROP POLICY IF EXISTS {$table}_por_condominio ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private static function assertValidName(string $table): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $table)) {
            throw new InvalidArgumentException("Nombre de tabla no válido: {$table}");
        }
    }
}
