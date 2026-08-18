<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Orden extends Model
{
    use HasFactory, SoftDeletes;

    // --- CONSTANTES DE ESTADO ---
    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_EN_PROCESO = 'en proceso';
    const ESTADO_SERVIDA   = 'servida';
    const ESTADO_PAGADA    = 'pagada';

    // Cuenta cerrada SIN cobrar (el cliente se fue sin pagar, error de
    // captura, cortesía...). Al no estar en getEstadosActivos(), la mesa
    // deja de aparecer en Caja y en la comanda automáticamente.
    const ESTADO_CANCELADA = 'cancelada';

    // --- CONSTANTES DE ORIGEN (NUEVAS) ---
    const ORIGEN_LOCAL = 'local';
    const ORIGEN_WEB   = 'web';

    /**
     * Retorna los estados que se consideran "activos" o "en servicio".
     * Útil para consultas en el controlador.
     */
    public static function getEstadosActivos(): array
    {
        return [
            self::ESTADO_PENDIENTE,
            self::ESTADO_EN_PROCESO,
            self::ESTADO_SERVIDA,
        ];
    }

    protected $table = 'ordenes';

    protected $fillable = [
        'numero_orden',
        'mesa_id',
        'mesero_id',
        'capitan_id',
        'estado',
        // --- NUEVOS CAMPOS WEB ---
        'origen',           
        'nombre_cliente',   
        'telefono_cliente', 
        // -------------------------
        'total',
        'propina',
        'metodo_pago',
        'abierta_el',
        'cerrada_el',
        'cuenta_dividida',
        'numero_cuenta_division',
        'total_cuentas_division',
        'personas',
        'descuento_porcentaje',
        // Auditoría de cancelación de la cuenta completa
        'cancelada_motivo',
        'cancelada_por',
        'cancelada_en',
        'monto_cancelado',
    ];

    /**
     * Usuario de caja que canceló la cuenta. Se guarda para que siempre
     * quede claro quién autorizó una cuenta que no se cobró.
     */
    public function canceladaPor()
    {
        return $this->belongsTo(User::class, 'cancelada_por');
    }

    protected $casts = [
        'total' => 'decimal:2',
        'propina' => 'decimal:2',
        'cuenta_dividida' => 'boolean',
        'numero_cuenta_division' => 'integer',
        'abierta_el' => 'datetime',
        'cerrada_el' => 'datetime',
        'personas' => 'integer',
        'descuento_porcentaje' => 'decimal:2',
    ];

    // --- RELACIONES ---

    public function mesa()
    {
        return $this->belongsTo(Mesa::class);
    }

    public function mesero()
    {
        return $this->belongsTo(User::class, 'mesero_id');
    }

    public function capitan()
    {
        return $this->belongsTo(User::class, 'capitan_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleOrden::class, 'orden_id');
    }

    public function promocionesAplicadas()
    {
        return $this->hasMany(OrdenPromocion::class, 'orden_id');
    }

    public function getTotalDescuentosPromocionesAttribute()
    {
        return $this->promocionesAplicadas->sum('monto_descuento');
    }

    // --- ACCESORES ---

    public function getTotalConImpuestosAttribute()
    {
        return $this->total + ($this->propina ?? 0);
    }

    public function transacciones()
    {
        return $this->hasMany(Transaccion::class, 'orden_id');
    }

    public function getMontoPorPersonaAttribute()
    {
        if ($this->cuenta_dividida && $this->numero_cuenta_division > 1) {
            return $this->total / $this->numero_cuenta_division;
        }

        return $this->total;
    }
}