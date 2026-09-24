<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Actividad extends Model
{
    use HasFactory;

    protected $table = 'actividad';
    protected $primaryKey = 'actividadid';
    public $timestamps = false;

    protected $fillable = [
        'loteid',
        'usuarioid',
        'usuarioid_ejecutor',
        'descripcion',
        'fechainicio',
        'fecha_planificada',
        'fechafin',
        'tipoactividadid',
        'prioridadid',
        'observaciones',
        'evidencia_foto_path',
        'detalle_json',
        'orden_secuencia',
    ];

    protected $casts = [
        'actividadid'     => 'integer',
        'loteid'          => 'integer',
        'usuarioid'       => 'integer',
        'usuarioid_ejecutor' => 'integer',
        'tipoactividadid' => 'integer',
        'prioridadid'     => 'integer',
        'orden_secuencia' => 'integer',
        'fechainicio'     => 'datetime',
        'fecha_planificada' => 'date',
        'fechafin'        => 'datetime',
    ];

    protected $hidden = [
        'lote',
        'usuario',
        'tipoActividad',
        'prioridad',
    ];

    public function lote()       { return $this->belongsTo(Lote::class,'loteid','loteid'); }
    public function usuario()    { return $this->belongsTo(Usuario::class,'usuarioid','usuarioid'); }
    public function ejecutor()   { return $this->belongsTo(Usuario::class,'usuarioid_ejecutor','usuarioid'); }
    public function tipoActividad(){return $this->belongsTo(TipoActividad::class,'tipoactividadid','tipoactividadid');}
    public function prioridad()  { return $this->belongsTo(Prioridad::class,'prioridadid','prioridadid'); }
}