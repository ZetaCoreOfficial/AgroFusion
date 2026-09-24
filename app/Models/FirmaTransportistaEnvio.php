<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmaTransportistaEnvio extends Model
{
    protected $table = 'firma_transportista_envio';
    protected $primaryKey = 'firmatransportistaid';

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
