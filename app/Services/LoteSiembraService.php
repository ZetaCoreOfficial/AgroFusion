<?php



namespace App\Services;



use App\Models\Actividad;
use App\Models\Lote;
use App\Models\Prioridad;
use App\Models\TipoActividad;
use App\Models\Usuario;

use App\Support\ActividadSecuenciaService;
use App\Support\LoteTrazabilidadService;
use App\Support\UsuarioRol;

use Illuminate\Http\UploadedFile;

use Illuminate\Support\Facades\DB;

use Illuminate\Validation\ValidationException;



class LoteSiembraService

{

    public function __construct(

        private readonly LoteTrazabilidadService $trazabilidad,

        private readonly NotificacionUsuarioService $notificaciones,

        private readonly ActividadSecuenciaService $secuencia,

    ) {}



    public function asignar(Lote $lote, int $responsableId, ?int $asignadoPorId = null): Actividad

    {

        $tipo = $this->tipoSiembra();

        $faseActual = $this->trazabilidad->resolverFaseActual($lote);



        if ($this->trazabilidad->siembraCompletada($lote)) {

            throw ValidationException::withMessages([

                'siembra' => 'Este lote ya fue sembrado.',

            ]);

        }



        if ($this->trazabilidad->actividadSiembraPendiente($lote) !== null) {

            throw ValidationException::withMessages([

                'siembra' => 'Ya hay una siembra asignada pendiente de realizar.',

            ]);

        }



        if (! $this->trazabilidad->tipoActividadPermitidoEnFase($tipo->nombre, $faseActual)) {

            throw ValidationException::withMessages([

                'siembra' => 'El lote no está en fase de siembra.',

            ]);

        }



        $bloqueo = $this->trazabilidad->mensajeActividadNoPermitida($lote, $tipo->nombre);

        if ($bloqueo !== null) {

            throw ValidationException::withMessages(['siembra' => $bloqueo]);

        }



        $ahora = now();



        $actividad = DB::transaction(function () use ($lote, $tipo, $responsableId, $ahora) {

            $prioridadId = Prioridad::query()->orderBy('prioridadid')->value('prioridadid');

            if (! $prioridadId) {
                $prioridadId = Prioridad::firstOrCreate(['nombre' => 'Media'], ['nombre' => 'Media'])->prioridadid;
            }

            $actividad = Actividad::create([

                'loteid' => $lote->loteid,

                'usuarioid' => $responsableId,

                'descripcion' => $tipo->nombre ?? 'Siembra',

                'fechainicio' => $ahora,

                'fechafin' => null,

                'tipoactividadid' => $tipo->tipoactividadid,

                'prioridadid' => $prioridadId,

                'observaciones' => 'Siembra asignada desde trazabilidad del lote.',

            ]);

            $this->secuencia->asignarOrden($actividad);

            return $actividad;

        });



        if ($asignadoPorId !== null && (int) $asignadoPorId !== $responsableId) {

            $this->notificaciones->actividadAsignada($actividad);

        }



        return $actividad;

    }



    /**
     * Registra la siembra como completada con foto (actividad pendiente o hito directo del agricultor).
     */
    public function completarConEvidencia(Lote $lote, Usuario $usuario, UploadedFile $foto, ?string $observaciones = null): Actividad
    {
        $tipo = $this->tipoSiembra();
        $faseActual = $this->trazabilidad->resolverFaseActual($lote);

        if ($this->trazabilidad->siembraCompletada($lote)) {
            throw ValidationException::withMessages([
                'siembra' => 'Este lote ya fue sembrado.',
            ]);
        }

        if (! $this->trazabilidad->tipoActividadPermitidoEnFase($tipo->nombre, $faseActual)) {
            throw ValidationException::withMessages([
                'siembra' => 'El lote no está en fase de siembra.',
            ]);
        }

        $pendiente = $this->trazabilidad->actividadSiembraPendiente($lote);
        if ($pendiente) {
            $bloqueoOrden = $this->secuencia->mensajeBloqueoOrden($pendiente);
            if ($bloqueoOrden !== null) {
                throw ValidationException::withMessages(['siembra' => $bloqueoOrden]);
            }

            $esAsignado = (int) $pendiente->usuarioid === (int) $usuario->usuarioid;
            if (! $esAsignado && ! UsuarioRol::esAdminGlobal($usuario)) {
                throw ValidationException::withMessages([
                    'siembra' => 'La siembra está asignada a otro agricultor. El jefe solo supervisa; no la completa.',
                ]);
            }
        }

        $evidenciaPath = \App\Support\EvidenciaFoto::guardar($foto, 'actividades_evidencia');
        $ahora = now();

        return DB::transaction(function () use ($lote, $usuario, $tipo, $pendiente, $evidenciaPath, $ahora, $observaciones) {
            if ($pendiente) {
                $pendiente->evidencia_foto_path = $evidenciaPath;
                $pendiente->fechafin = $ahora;
                $pendiente->usuarioid_ejecutor = (int) $usuario->usuarioid;
                if ($observaciones) {
                    $pendiente->observaciones = trim(($pendiente->observaciones ?? '').' '.$observaciones);
                }
                $pendiente->save();
                $actividad = $pendiente;
            } else {
                $prioridadId = Prioridad::query()->orderBy('prioridadid')->value('prioridadid')
                    ?? Prioridad::firstOrCreate(['nombre' => 'Media'], ['nombre' => 'Media'])->prioridadid;

                $actividad = Actividad::create([
                    'loteid' => $lote->loteid,
                    'usuarioid' => $usuario->usuarioid,
                    'usuarioid_ejecutor' => $usuario->usuarioid,
                    'descripcion' => $tipo->nombre ?? 'Siembra',
                    'fechainicio' => $ahora,
                    'fechafin' => $ahora,
                    'tipoactividadid' => $tipo->tipoactividadid,
                    'prioridadid' => $prioridadId,
                    'observaciones' => $observaciones ?: 'Siembra completada con evidencia fotográfica.',
                    'evidencia_foto_path' => $evidenciaPath,
                ]);
                $this->secuencia->asignarOrden($actividad);
            }

            app(\App\Support\LoteEstadoPorActividad::class)->aplicarDesdeActividad($actividad);

            $lote->refresh();
            if (! $lote->fechasiembra) {
                $lote->fechasiembra = $ahora->toDateString();
                $lote->fechamodificacion = $ahora;
                $lote->save();
            }

            $this->notificaciones->descartarActividadAsignada((int) $actividad->actividadid);

            return $actividad;
        });
    }



    private function tipoSiembra(): TipoActividad

    {

        $existente = TipoActividad::query()

            ->whereRaw('LOWER(TRIM(nombre)) LIKE ?', ['%siembra%'])

            ->orderBy('tipoactividadid')

            ->first();

        if ($existente) {

            return $existente;

        }

        return TipoActividad::updateOrCreate(

            ['nombre' => 'Siembra'],

            ['descripcion' => 'Siembra']

        );

    }

}

