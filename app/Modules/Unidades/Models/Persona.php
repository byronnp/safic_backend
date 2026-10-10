<?php

namespace App\Modules\Unidades\Models;

use App\Core\Privacy\DatosPersonales;
use App\Core\Tenancy\BelongsToCondominio;
use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Persona del condominio (propietario, inquilino, residente o contacto). Es distinta
 * del usuario que inicia sesión: no toda persona tiene cuenta.
 *
 * @property int $id
 * @property int $condominio_id
 * @property int|null $user_id Cuenta con la que entra al sistema
 * @property string $tipo_documento
 * @property string $documento
 * @property string $documento_hash
 * @property string $nombres
 * @property string $apellidos
 * @property string|null $telefono
 * @property string|null $email
 */
class Persona extends Model implements AuditableContract
{
    /** @use HasFactory<PersonaFactory> */
    use Auditable, BelongsToCondominio, HasFactory, SoftDeletes;

    public const TIPOS_DOCUMENTO = ['cedula', 'ruc', 'pasaporte'];

    protected $table = 'personas';

    /** Datos personales: la auditoría registra que cambiaron, no sus valores. */
    protected array $auditExclude = ['documento', 'documento_hash', 'telefono', 'email'];

    protected $fillable = ['tipo_documento', 'documento', 'nombres', 'apellidos', 'telefono', 'email'];

    protected $hidden = ['documento_hash'];

    protected function casts(): array
    {
        // Cifrado en reposo (LOPDP); la búsqueda exacta usa documento_hash
        return ['documento' => 'encrypted', 'telefono' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::saving(function (Persona $persona): void {
            if ($persona->isDirty(['tipo_documento', 'documento'])) {
                $persona->documento = DatosPersonales::normalizarDocumento($persona->documento);
                $persona->documento_hash = DatosPersonales::hashDocumento($persona->tipo_documento, $persona->documento);
            }
        });
    }

    /** @return HasMany<Ocupante, $this> */
    public function ocupaciones(): HasMany
    {
        return $this->hasMany(Ocupante::class);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }

    /**
     * Por nombre (contiene) y, si $porDocumento, también por documento exacto en
     * cualquier tipo. Sin residentes.ver_datos no se busca por documento: permitiría
     * confirmar si una cédula está registrada aunque la respuesta salga enmascarada.
     *
     * @param  Builder<Persona>  $query
     */
    public function scopeBuscar(Builder $query, string $texto, bool $porDocumento = true): void
    {
        $texto = trim($texto);
        $hashes = $porDocumento
            ? array_map(fn (string $tipo) => DatosPersonales::hashDocumento($tipo, $texto), self::TIPOS_DOCUMENTO)
            : [];
        $patron = '%'.addcslashes($texto, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->whereIn('documento_hash', $hashes)
            ->orWhereRaw("(nombres || ' ' || apellidos) ilike ?", [$patron])
            ->orWhereRaw("(apellidos || ' ' || nombres) ilike ?", [$patron]));
    }

    protected static function newFactory(): PersonaFactory
    {
        return PersonaFactory::new();
    }
}
