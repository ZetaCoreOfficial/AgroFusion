<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlmacenMovimiento extends Model
{
    use HasFactory;

    protected $table = 'almacen_movimiento';
    protected $primaryKey = 'almacen_movimientoid';

    protected $fillable = [
        'almacenid',
        'insumoid',
        'tipo_movimiento_almacenid',
        'usuarioid',
        'fecha',
        'cantidad',
        'referencia',
        'destino_motivo',
        'observaciones',
        'detallepedidodistribucionid',
    ];

    protected $casts = [
        'almacenid' => 'integer',
        'insumoid' => 'integer',
        'tipo_movimiento_almacenid' => 'integer',
        'usuarioid' => 'integer',
        'fecha' => 'date',
        'cantidad' => 'float',
    ];

    public function almacen()
    {
        return $this->belongsTo(Almacen::class, 'almacenid', 'almacenid');
    }

    public function insumo()
    {
        return $this->belongsTo(Insumo::class, 'insumoid', 'insumoid');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoMovimientoAlmacen::class, 'tipo_movimiento_almacenid', 'tipo_movimiento_almacenid');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuarioid', 'usuarioid');
    }
}
