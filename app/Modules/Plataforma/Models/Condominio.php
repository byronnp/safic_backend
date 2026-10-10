<?php

namespace App\Modules\Plataforma\Models;

use App\Core\Audit\RegistraBitacora;
use App\Models\User;
use Database\Factories\CondominioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Tabla de nivel plataforma (no usa BelongsToCondominio ni RLS): es el propio
 * catálogo de condominios que administra el super admin.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property int $total_unidades
 * @property string $estado
 * @property array<string, mixed>|null $marca
 * @property int|null $plan_id
 * @property string|null $valor_unidad
 * @property string|null $tipo
 * @property string|null $ruc
 * @property string|null $razon_social
 * @property string|null $provincia_codigo
 * @property string|null $canton_codigo
 * @property string|null $parroquia_codigo
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $email_contacto
 * @property string|null $latitud
 * @property string|null $longitud
 * @property Carbon|null $prueba_hasta
 * @property Carbon $created_at
 * @property-read Plan|null $plan
 * @property-read Provincia|null $provincia
 * @property-read Canton|null $canton
 * @property-read Parroquia|null $parroquia
 */
class Condominio extends Model
{
    use RegistraBitacora;

    /** @var list<string> */
    protected array $bitacoraExcluir = ['remember_token'];

    public function bitacoraEntidad(): string
    {
        return 'condominio';
    }

    public function bitacoraCondominioId(): ?int
    {
        return $this->id;
    }

    public function bitacoraEtiqueta(): string
    {
        return $this->nombre;
    }

    /** @use HasFactory<CondominioFactory> */
    use HasFactory;

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_PRUEBA = 'prueba';

    public const ESTADO_SOLO_LECTURA = 'solo_lectura';

    public const ESTADO_SUSPENDIDO = 'suspendido';

    protected $table = 'condominios';

    protected $fillable = [
        'codigo', 'nombre', 'tipo', 'ruc', 'razon_social', 'total_unidades',
        'moneda', 'pais', 'zona_horaria', 'estado', 'marca',
        'plan_id', 'valor_unidad', 'provincia_codigo', 'canton_codigo', 'parroquia_codigo',
        'direccion', 'telefono', 'email_contacto', 'latitud', 'longitud', 'prueba_hasta',
    ];

    public const TIPOS = ['conjunto', 'edificio', 'urbanizacion', 'mixto'];

    protected function casts(): array
    {
        return [
            'total_unidades' => 'integer',
            'marca' => 'array',
            'valor_unidad' => 'decimal:2',
            'latitud' => 'decimal:6',
            'longitud' => 'decimal:6',
            'prueba_hasta' => 'date',
        ];
    }

    protected static function newFactory(): CondominioFactory
    {
        return CondominioFactory::new();
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Provincia, $this>
     */
    public function provincia(): BelongsTo
    {
        return $this->belongsTo(Provincia::class, 'provincia_codigo', 'codigo');
    }

    /**
     * @return BelongsTo<Canton, $this>
     */
    public function canton(): BelongsTo
    {
        return $this->belongsTo(Canton::class, 'canton_codigo', 'codigo');
    }

    /**
     * @return BelongsTo<Parroquia, $this>
     */
    public function parroquia(): BelongsTo
    {
        return $this->belongsTo(Parroquia::class, 'parroquia_codigo', 'codigo');
    }

    /**
     * @return BelongsToMany<User, $this, Membresia, 'pivot'>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'condominio_user')
            ->using(Membresia::class)
            ->withPivot(['es_principal', 'activo', 'acceso_hasta'])
            ->withTimestamps();
    }
}
