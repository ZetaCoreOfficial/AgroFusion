<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoDistribucion extends Model
{
    protected $table = 'pedido_distribucion';

    protected $primaryKey = 'pedidodistribucionid';

    public $timestamps = false;

    protected $fillable = [
        'numero_solicitud',
        'puntoventaid',
        'almacen_planta_origenid',
        'almacen_mayorista_origenid',
        'rutadistribucionid',
        'transportista_usuarioid',
        'vehiculoid',
        'estado',
        'tipo_solicitud',
        'espera_stock',
        'requiere_coordinacion_planta',
        'coordinacion_planta_resuelta',
        'fechapedido',
        'fecha_entrega_deseada',
        'hora_entrega_deseada',
        'observaciones',
        'fecha_aceptacion',
        'aceptado_por_usuarioid',
        'fecha_envio',
        'fecha_recepcion',
        'creado_por_usuarioid',
        'envio_iniciado_mayorista',
        'fecha_confirmacion_minorista',
        'canal_origen',
        'registrado_manual_por_usuarioid',
    ];

    protected $casts = [
        'pedidodistribucionid' => 'integer',
        'puntoventaid' => 'integer',
        'almacen_planta_origenid' => 'integer',
        'almacen_mayorista_origenid' => 'integer',
        'rutadistribucionid' => 'integer',
        'transportista_usuarioid' => 'integer',
        'vehiculoid' => 'integer',
        'fechapedido' => 'datetime',
        'fecha_entrega_deseada' => 'date',
        'espera_stock' => 'boolean',
        'requiere_coordinacion_planta' => 'boolean',
        'coordinacion_planta_resuelta' => 'boolean',
        'fecha_aceptacion' => 'datetime',
        'fecha_envio' => 'datetime',
        'fecha_recepcion' => 'datetime',
        'aceptado_por_usuarioid' => 'integer',
        'creado_por_usuarioid' => 'integer',
        'envio_iniciado_mayorista' => 'boolean',
        'fecha_confirmacion_minorista' => 'datetime',
        'registrado_manual_por_usuarioid' => 'integer',
    ];

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class, 'puntoventaid', 'puntoventaid');
    }

    public function almacenPlantaOrigen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_planta_origenid', 'almacenid');
    }

    public function almacenMayoristaOrigen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_mayorista_origenid', 'almacenid');
    }

    public function aceptadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aceptado_por_usuarioid', 'usuarioid');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por_usuarioid', 'usuarioid');
    }

    public function registradoManualPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_manual_por_usuarioid', 'usuarioid');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePedidoDistribucion::class, 'pedidodistribucionid', 'pedidodistribucionid');
    }

    public function solicitudesProduccionPlanta(): HasMany
    {
        return $this->hasMany(SolicitudProduccionPlanta::class, 'pedidodistribucionid', 'pedidodistribucionid');
    }

    public function rutaDistribucion(): BelongsTo
    {
        return $this->belongsTo(RutaDistribucion::class, 'rutadistribucionid', 'rutadistribucionid');
    }

    public function transportista(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'transportista_usuarioid', 'usuarioid');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculoid', 'vehiculoid');
    }

    public function getRouteKeyName(): string
    {
        return 'pedidodistribucionid';
    }
}
