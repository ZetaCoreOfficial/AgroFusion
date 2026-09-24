<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmaRecepcionEnvio extends Model
{
    protected $table = 'firma_recepcion_envio';
    protected $primaryKey = 'firmarecepcionid';

    protected $fillable = [
        'envioasignacionmultipleid',
        'rutadistribucionid',
        'imagenfirma',
        'nombrefirmante',
        'fechafirma',
        'firmante_usuarioid',
    ];

    protected $casts = [
        'fechafirma' => 'datetime',
        'firmante_usuarioid' => 'integer',
    ];

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(RutaDistribucion::class, 'rutadistribucionid', 'rutadistribucionid');
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(EnvioAsignacionMultiple::class, 'envioasignacionmultipleid', 'envioasignacionmultipleid');
    }
}
