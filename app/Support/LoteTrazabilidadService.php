<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Almacen;
use App\Models\CertificacionLote;
use App\Models\Cultivo;
use App\Models\EstadoLoteTipo;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\Produccion;
use App\Models\ProduccionAlmacenamiento;
use App\Models\Usuario;
use App\Services\PlanificacionCosechaService;
use App\Support\EstadoLoteCatalogo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LoteTrazabilidadService
{
    /** @var array<string, array{label: string, orden: int, color: string, icon: string}> */
    public const FASES = [
        'preparacion' => ['label' => 'Preparación', 'orden' => 1, 'color' => '#6c757d', 'icon' => 'tools'],
        'siembra' => ['label' => 'Siembra', 'orden' => 2, 'color' => '#17a2b8', 'icon' => 'seedling'],
        'en_crecimiento' => ['label' => 'En crecimiento', 'orden' => 3, 'color' => '#28a745', 'icon' => 'leaf'],
        'cosecha' => ['label' => 'Cosecha', 'orden' => 4, 'color' => '#e83e8c', 'icon' => 'tractor'],
        'certificacion' => ['label' => 'Certificación', 'orden' => 5, 'color' => '#6f42c1', 'icon' => 'certificate'],
        'envio_almacen' => ['label' => 'Envío al almacén', 'orden' => 6, 'color' => '#6f42c1', 'icon' => 'warehouse'],
    ];

    /** Fases que ocurren una sola vez por lote (muestran check, no contador). */
    private const FASES_UNICAS = ['preparacion', 'siembra', 'cosecha', 'certificacion', 'envio_almacen'];

    /** Fases internas de eventos (historial) que se agrupan en «En crecimiento» en el pipeline. */
    private const FASES_EVENTO_EXTRA = [
        'regado' => ['label' => 'Regado', 'color' => '#28a745', 'icon' => 'tint'],
        'fumigacion' => ['label' => 'Fumigación', 'color' => '#fd7e14', 'icon' => 'spray-can'],
        'fertilizacion' => ['label' => 'Fertilización', 'color' => '#20c997', 'icon' => 'flask'],
    ];

    /** @var array<string, string> */
    private const ESTADO_A_FASE = [
        'planificado' => 'preparacion',
        'disponible' => 'preparacion',
        'en preparación' => 'preparacion',
        'en preparacion' => 'preparacion',
        'sembrado' => 'siembra',
        'en crecimiento' => 'en_crecimiento',
        'en producción' => 'en_crecimiento',
        'en produccion' => 'en_crecimiento',
        'listo para cosecha' => 'cosecha',
        'cosechado' => 'cosecha',
        'certificado' => 'certificacion',
        'no conforme' => 'certificacion',
        'finalizado' => 'envio_almacen',
        'en descanso' => 'preparacion',
    ];

    public function fasesMeta(): array
    {
        return self::FASES;
    }

    /** @return array<string, array{label: string, color: string, icon?: string}> */
    public function fasesMetaEvento(): array
    {
        $base = collect(self::FASES)->map(fn ($m) => [
            'label' => $m['label'],
            'color' => $m['color'],
            'icon' => $m['icon'],
        ])->all();

        foreach (self::FASES_EVENTO_EXTRA as $key => $meta) {
            $base[$key] = $meta;
        }

        return $base;
    }

    public function faseFromEstado(?string $estadoNombre): string
    {
        $key = strtolower(trim($estadoNombre ?? 'disponible'));

        return self::ESTADO_A_FASE[$key] ?? 'preparacion';
    }

    public function resolverFaseActual(Lote $lote): string
    {
        $lote->loadMissing([
            'estadoTipo',
            'actividades.tipoActividad',
            'producciones.almacenamientos',
        ]);

        if ($this->milestoneEnvioAlmacen($lote)) {
            return 'envio_almacen';
        }

        if ($this->milestoneCosecha($lote)) {
            $cert = app(CertificacionCampoService::class);
            if ($cert->estaCertificado($lote)) {
                return 'envio_almacen';
            }

            return 'certificacion';
        }
        if ($this->milestoneFumigacion($lote) || $this->milestoneRegado($lote)) {
            return 'en_crecimiento';
        }
        if ($this->milestoneSiembra($lote)) {
            return 'en_crecimiento';
        }
        if ($this->milestonePreparacion($lote)) {
            return 'siembra';
        }

        return 'preparacion';
    }

    /**
     * Valida si el tipo de actividad puede registrarse en el lote (fase actual + duplicados).
     */
    public function mensajeActividadNoPermitida(Lote $lote, ?string $tipoNombre): ?string
    {
        if ($tipoNombre === null || trim($tipoNombre) === '') {
            return null;
        }

        $lote->loadMissing(['actividades.tipoActividad', 'estadoTipo']);
        $nombre = mb_strtolower(trim($tipoNombre));
        $faseActual = $this->resolverFaseActual($lote);
        $faseTipo = $this->faseDeTipoActividad($nombre);

        if ($faseTipo !== null && ! $this->tipoActividadPermitidoEnFase($tipoNombre, $faseActual)) {
            $labelActual = self::FASES[$faseActual]['label'] ?? ucfirst($faseActual);

            return "El lote está en fase «{$labelActual}». No puede registrar «{$tipoNombre}» porque pertenece a una fase anterior o distinta.";
        }

        // Riego, fertilización y control de plagas pueden repetirse en «en crecimiento».
        if (str_contains($nombre, 'siembra')) {
            if ($this->actividadExistenteConKeywords($lote, ['siembra'], true)) {
                return 'Este lote ya tiene una actividad de siembra (pendiente o completada). Solo puede realizarse una vez.';
            }
            if ($this->milestoneSiembra($lote)) {
                return 'Este lote ya superó la fase de siembra.';
            }
        }

        if ((str_contains($nombre, 'labranza') || str_contains($nombre, 'prepar'))
            && $this->milestonePreparacion($lote)) {
            return 'La preparación del lote ya fue registrada. Solo puede realizarse una vez.';
        }

        return null;
    }

    /** @deprecated Use mensajeActividadNoPermitida() */
    public function mensajeActividadDuplicada(Lote $lote, ?string $tipoNombre): ?string
    {
        return $this->mensajeActividadNoPermitida($lote, $tipoNombre);
    }

    public function tipoActividadPermitidoEnFase(?string $tipoNombre, string $faseActual): bool
    {
        $faseTipo = $this->faseDeTipoActividad(mb_strtolower(trim($tipoNombre ?? '')));

        if ($faseTipo === null) {
            return true;
        }

        if ($faseTipo === $faseActual) {
            return true;
        }

        return $this->siguienteFase($faseActual) === $faseTipo;
    }

    private function faseDeTipoActividad(string $nombre): ?string
    {
        if ($nombre === '') {
            return null;
        }

        return match (true) {
            str_contains($nombre, 'labranza') || str_contains($nombre, 'prepar') => 'preparacion',
            str_contains($nombre, 'siembra') => 'siembra',
            str_contains($nombre, 'riego') || str_contains($nombre, 'regad'),
            str_contains($nombre, 'fumig') || str_contains($nombre, 'plaga') || str_contains($nombre, 'fitosanit'),
            str_contains($nombre, 'fertiliz') => 'en_crecimiento',
            str_contains($nombre, 'cosecha') => 'cosecha',
            default => null,
        };
    }

    private function milestonePreparacion(Lote $lote): bool
    {
        return $this->actividadCompletadaConKeywords($lote, ['labranza', 'prepar']);
    }

    public function trazabilidadCompleta(Lote $lote): bool
    {
        $lote->loadMissing(['producciones.almacenamientos']);

        return $this->milestoneEnvioAlmacen($lote);
    }

    public function siguienteFase(string $faseActual): ?string
    {
        $ordenActual = self::FASES[$faseActual]['orden'] ?? 1;
        foreach (self::FASES as $key => $meta) {
            if ($meta['orden'] === $ordenActual + 1) {
                return $key;
            }
        }

        return null;
    }

    private function returnUrlTrazabilidad(Lote $lote): string
    {
        return route('lotes.trazabilidad', $lote, absolute: false);
    }

    /**
     * URL para registrar actividades de la fase «En crecimiento» (sin saltar a cosecha).
     */
    public function urlAsignarActividadEnCrecimiento(Lote $lote): string
    {
        $return = $this->returnUrlTrazabilidad($lote);
        $params = [
            'loteid' => $lote->loteid,
            'return' => $return,
        ];

        $tipoSugerido = $this->siguienteTipoActividadCrecimiento($lote);
        if ($tipoSugerido !== null) {
            $params['tipo'] = $tipoSugerido;
        }

        return route('actividades.create', $params);
    }

    /**
     * URL directa para registrar la fase indicada (usada en el botón «siguiente» del pipeline).
     */
    public function urlAccionFase(Lote $lote, string $faseKey): ?string
    {
        $return = $this->returnUrlTrazabilidad($lote);

        return match ($faseKey) {
            'siembra' => null,
            'en_crecimiento' => $this->urlAsignarActividadEnCrecimiento($lote),
            'cosecha' => route('producciones.create', [
                'loteid' => $lote->loteid,
                'return' => $return,
            ]),
            'certificacion' => null,
            'envio_almacen' => null,
            default => null,
        };
    }

    public function produccionPendienteAlmacen(Lote $lote): ?Produccion
    {
        $lote->loadMissing(['producciones.almacenamientos', 'producciones.unidadMedida', 'producciones.almacenDestino.tipoAlmacen']);

        return $lote->producciones
            ->sortByDesc(fn (Produccion $p) => $p->fechacosecha ?? $p->produccionid)
            ->first(fn (Produccion $p) => $p->almacenamientos->isEmpty());
    }

    public function puedeCertificarCampo(Lote $lote, ?Usuario $user = null): bool
    {
        if ($user && ! UsuarioRol::gestionaCampo($user)) {
            return false;
        }

        if ($user && UsuarioRol::esJefeAgricultor($user) && ! UsuarioRol::esAdminGlobal($user)
            && ! LoteAcceso::puedeGestionar($user, $lote)) {
            return false;
        }

        $lote->loadMissing(['producciones', 'actividades.tipoActividad']);
        $cert = app(CertificacionCampoService::class);

        return $this->resolverFaseActual($lote) === 'certificacion'
            && ! $cert->fueEvaluado($lote)
            && $lote->producciones->isNotEmpty()
            && $this->actividadesPendientes($lote, false)->isEmpty()
            && $this->actividadesCrecimientoCompletas($lote);
    }

    public function puedeEnviarAlmacenCampo(Lote $lote, ?Usuario $user = null): bool
    {
        if ($user && ! UsuarioRol::gestionaCampo($user)) {
            return false;
        }

        $cert = app(CertificacionCampoService::class);

        return $cert->estaCertificado($lote)
            && $this->produccionPendienteAlmacen($lote) !== null;
    }

    public function siguienteTipoActividadCrecimiento(Lote $lote): ?string
    {
        $lote->loadMissing('actividades.tipoActividad');

        if (! $this->milestoneRegado($lote)) {
            return 'Riego';
        }
        if (! $this->milestoneFumigacion($lote)) {
            return 'Control de plagas';
        }
        if (! $this->milestoneFertilizacion($lote)) {
            return 'Fertilización';
        }

        return null;
    }

    public function actividadesCrecimientoCompletas(Lote $lote): bool
    {
        $lote->loadMissing('actividades.tipoActividad');

        return $this->milestoneRegado($lote)
            && $this->milestoneFumigacion($lote)
            && $this->milestoneFertilizacion($lote);
    }

    /** @return Collection<int, Actividad> */
    public function actividadesPendientes(Lote $lote, bool $soloFaseActual = true): Collection
    {
        return app(ActividadSecuenciaService::class)->pendientesOrdenadas($lote, $soloFaseActual);
    }

    public function esActividadCrecimientoEsencial(Actividad $actividad): bool
    {
        $nombre = mb_strtolower(trim($actividad->tipoActividad->nombre ?? ''));

        return str_contains($nombre, 'riego')
            || str_contains($nombre, 'regad')
            || str_contains($nombre, 'fumig')
            || str_contains($nombre, 'plaga')
            || str_contains($nombre, 'fitosanit')
            || str_contains($nombre, 'fertiliz');
    }

    /** @return Collection<int, Actividad> */
    public function actividadesPendientesEsencialesCrecimiento(Lote $lote): Collection
    {
        $lote->loadMissing('actividades.tipoActividad');

        return $lote->actividades
            ->whereNull('fechafin')
            ->filter(fn (Actividad $actividad) => $this->esActividadCrecimientoEsencial($actividad))
            ->sortByDesc('fechainicio')
            ->values();
    }

    public function puedeIrACosecha(Lote $lote): bool
    {
        return $this->puedeRegistrarCosecha($lote)
            && $this->actividadesPendientesEsencialesCrecimiento($lote)->isEmpty();
    }

    public function puedeUsuarioRegistrarCosecha(Lote $lote, ?Usuario $user): bool
    {
        if (! $user || ! $this->puedeRegistrarCosecha($lote)) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return LoteAcceso::puedeGestionar($user, $lote);
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            return $this->operarioAsignadoACosecha($lote, $user);
        }

        return false;
    }

    public function puedeUsuarioIrACosecha(Lote $lote, ?Usuario $user): bool
    {
        return $this->puedeIrACosecha($lote) && $this->puedeUsuarioRegistrarCosecha($lote, $user);
    }

    private function operarioAsignadoACosecha(Lote $lote, Usuario $user): bool
    {
        $lote->loadMissing(['actividades.tipoActividad']);

        return $lote->actividades->contains(function (Actividad $actividad) use ($user) {
            $tipo = mb_strtolower(trim($actividad->tipoActividad->nombre ?? ''));

            return str_contains($tipo, 'cosecha')
                && (int) $actividad->usuarioid === (int) $user->usuarioid
                && $actividad->fechafin === null;
        });
    }

    /**
     * @return array{
     *     meta_label: ?string,
     *     meta_progreso: ?int,
     *     hitos: array<int, array{label: string, ok: bool}>,
     *     actividades_abiertas: array<int, array{titulo: string, responsable: ?string}>
     * }
     */
    public function panelPendientesLegible(Lote $lote, string $faseActual): array
    {
        $lote->loadMissing(['actividades.tipoActividad', 'actividades.usuario', 'actividades.prioridad']);
        $pend = $this->pasosHaciaCompleto($lote, $faseActual);
        $siguienteKey = $pend['siguiente_fase'] ?? null;

        $hitos = [];
        if ($faseActual === 'en_crecimiento') {
            $hitos = [
                ['label' => 'Riego completado', 'ok' => $this->milestoneRegado($lote)],
                ['label' => 'Control de plagas completado', 'ok' => $this->milestoneFumigacion($lote)],
                ['label' => 'Fertilización completada', 'ok' => $this->milestoneFertilizacion($lote)],
            ];
        }

        $actividadesAbiertas = $this->actividadesPendientes($lote)
            ->map(function (Actividad $a) {
                $secuencia = app(ActividadSecuenciaService::class);

                return [
                    'actividadid' => (int) $a->actividadid,
                    'titulo' => $a->descripcion ?: ($a->tipoActividad->nombre ?? 'Actividad'),
                    'responsable' => trim(($a->usuario->nombre ?? '').' '.($a->usuario->apellido ?? '')) ?: null,
                    'prioridad' => $a->prioridad?->nombre,
                    'prioridad_badge' => PrioridadCatalogo::badgeClase($a->prioridad?->nombre),
                    'es_siembra' => str_contains(mb_strtolower(trim($a->tipoActividad->nombre ?? '')), 'siembra'),
                    'orden_secuencia' => (int) ($a->orden_secuencia ?? 0),
                    'en_turno' => $secuencia->esSiguienteEnCola($a, false),
                ];
            })
            ->values()
            ->all();

        return [
            'meta_label' => $pend['siguiente_label'] ?? null,
            'meta_progreso' => $pend['progreso_siguiente'] ?? null,
            'fases_despues' => array_slice($pend['fases_restantes'] ?? [], 1),
            'acciones' => $pend['acciones'] ?? [],
            'hitos' => $hitos,
            'actividades_abiertas' => $actividadesAbiertas,
            'completo' => (bool) ($pend['completo'] ?? false),
            'resumen_corto' => $pend['resumen'] ?? '',
        ];
    }

    /** @return list<string> */
    public function actividadesCrecimientoPendientes(Lote $lote): array
    {
        $lote->loadMissing('actividades.tipoActividad');
        $pendientes = [];

        if (! $this->milestoneRegado($lote)) {
            $pendientes[] = 'riego';
        }
        if (! $this->milestoneFumigacion($lote)) {
            $pendientes[] = 'control de plagas';
        }
        if (! $this->milestoneFertilizacion($lote)) {
            $pendientes[] = 'fertilización';
        }

        return $pendientes;
    }

    public function puedeRegistrarCosecha(Lote $lote): bool
    {
        $lote->loadMissing(['estadoTipo', 'actividades.tipoActividad']);
        $slug = EstadoLoteCatalogo::slugFromNombre($lote->estadoTipo->nombre ?? '');

        if ($slug === 'listo_para_cosecha') {
            return true;
        }

        return $slug === 'en_crecimiento' && $this->actividadesCrecimientoCompletas($lote);
    }

    private function milestoneSiembra(Lote $lote): bool
    {
        if ($lote->fechasiembra) {
            return true;
        }

        $estado = mb_strtolower(trim($lote->estadoTipo->nombre ?? ''));

        return $estado === 'sembrado'
            || $this->actividadCompletadaConKeywords($lote, ['siembra']);
    }

    public function siembraCompletada(Lote $lote): bool
    {
        $lote->loadMissing(['estadoTipo', 'actividades.tipoActividad']);

        return $this->milestoneSiembra($lote);
    }

    public function actividadSiembraPendiente(Lote $lote): ?Actividad
    {
        $lote->loadMissing('actividades.tipoActividad');

        return $lote->actividades
            ->whereNull('fechafin')
            ->first(function (Actividad $actividad) {
                $nombre = mb_strtolower(trim($actividad->tipoActividad->nombre ?? ''));

                return str_contains($nombre, 'siembra');
            });
    }

    private function milestoneRegado(Lote $lote): bool
    {
        return $this->actividadCompletadaConKeywords($lote, ['riego', 'regad']);
    }

    private function milestoneFumigacion(Lote $lote): bool
    {
        return $this->actividadCompletadaConKeywords($lote, ['fumig', 'plaga', 'fitosanit']);
    }

    private function milestoneFertilizacion(Lote $lote): bool
    {
        return $this->actividadCompletadaConKeywords($lote, ['fertiliz']);
    }

    private function milestoneCosecha(Lote $lote): bool
    {
        return $lote->producciones->isNotEmpty();
    }

    private function milestoneEnvioAlmacen(Lote $lote): bool
    {
        return $lote->producciones->flatMap->almacenamientos->isNotEmpty();
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function actividadCompletadaConKeywords(Lote $lote, array $keywords): bool
    {
        return $this->actividadExistenteConKeywords($lote, $keywords, false);
    }

    private function actividadExistenteConKeywords(Lote $lote, array $keywords, bool $incluirPendientes): bool
    {
        return $lote->actividades
            ->when(! $incluirPendientes, fn (Collection $items) => $items->whereNotNull('fechafin'))
            ->contains(function ($actividad) use ($keywords) {
                $nombre = mb_strtolower(trim($actividad->tipoActividad->nombre ?? ''));

                foreach ($keywords as $keyword) {
                    if (str_contains($nombre, mb_strtolower($keyword))) {
                        return true;
                    }
                }

                return false;
            });
    }

    public function progresoFase(string $faseKey): int
    {
        $orden = self::FASES[$faseKey]['orden'] ?? 1;
        $total = count(self::FASES);

        return (int) round(($orden / $total) * 100);
    }

    /**
     * Pasos y fases que faltan para alcanzar comercialización (100 %).
     *
     * @return array{
     *     completo: bool,
     *     siguiente_fase: ?string,
     *     siguiente_label: ?string,
     *     progreso_siguiente: ?int,
     *     fases_restantes: array<int, string>,
     *     acciones: array<int, string>,
     *     resumen: string
     * }
     */
    public function pasosHaciaCompleto(Lote $lote, string $faseActual): array
    {
        $lote->loadMissing([
            'estadoTipo',
            'actividades.tipoActividad',
            'loteInsumos',
            'producciones.almacenamientos',
        ]);

        $ordenActual = self::FASES[$faseActual]['orden'] ?? 1;

        if ($this->trazabilidadCompleta($lote)) {
            return [
                'completo' => true,
                'siguiente_fase' => null,
                'siguiente_label' => null,
                'progreso_siguiente' => 100,
                'fases_restantes' => [],
                'acciones' => [],
                'resumen' => 'Trazabilidad completa (100 %)',
            ];
        }

        if ($faseActual === 'envio_almacen') {
            $prod = $this->produccionPendienteAlmacen($lote);
            $prod?->loadMissing('almacenDestino.tipoAlmacen');
            $acciones = $this->accionesSugeridas($lote, $faseActual, null);

            if ($prod?->almacenDestino) {
                array_unshift(
                    $acciones,
                    'Destino previsto: '.$prod->almacenDestino->nombre.' (elegido en la cosecha)'
                );
            }

            return [
                'completo' => false,
                'siguiente_fase' => 'envio_almacen',
                'siguiente_label' => self::FASES['envio_almacen']['label'],
                'progreso_siguiente' => 100,
                'fases_restantes' => [],
                'acciones' => $acciones,
                'resumen' => 'Confirme el envío de la cosecha al almacén agrícola',
            ];
        }

        $certService = app(CertificacionCampoService::class);
        if ($faseActual === 'certificacion' && ! $certService->fueEvaluado($lote)) {
            $acciones = $this->accionesSugeridas($lote, $faseActual, null);

            return [
                'completo' => false,
                'siguiente_fase' => 'certificacion',
                'siguiente_label' => self::FASES['certificacion']['label'],
                'progreso_siguiente' => $this->progresoFase('certificacion'),
                'fases_restantes' => [self::FASES['envio_almacen']['label']],
                'acciones' => $acciones,
                'resumen' => 'Evalúe el lote como Certificado o No conforme',
            ];
        }

        $fasesRestantes = [];
        $siguienteKey = null;
        foreach (self::FASES as $key => $meta) {
            if ($meta['orden'] > $ordenActual) {
                $fasesRestantes[] = $meta['label'];
                $siguienteKey ??= $key;
            }
        }

        $acciones = $this->accionesSugeridas($lote, $faseActual, $siguienteKey);
        $siguienteLabel = $siguienteKey ? (self::FASES[$siguienteKey]['label'] ?? $siguienteKey) : null;
        $progresoSiguiente = $siguienteKey ? $this->progresoFase($siguienteKey) : 100;

        $resumen = $siguienteLabel
            ? sprintf(
                'Siguiente meta: %s (%d %%)',
                $siguienteLabel,
                $progresoSiguiente
            )
            : 'En avance hacia envío al almacén';

        if (count($fasesRestantes) > 1) {
            $resumen .= ' · Después: '.implode(' → ', array_slice($fasesRestantes, 1));
        }

        return [
            'completo' => false,
            'siguiente_fase' => $siguienteKey,
            'siguiente_label' => $siguienteLabel,
            'progreso_siguiente' => $progresoSiguiente,
            'fases_restantes' => $fasesRestantes,
            'acciones' => $acciones,
            'resumen' => $resumen,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function accionesSugeridas(Lote $lote, string $faseActual, ?string $siguienteFase): array
    {
        $lote->loadMissing([
            'actividades',
            'loteInsumos',
            'producciones.almacenamientos',
            'producciones.ventas',
            'certificaciones',
        ]);

        $pasos = [];

        switch ($faseActual) {
            case 'preparacion':
                $pasos[] = 'Asignar quién va a sembrar el lote';
                $pasos[] = 'Programar labranza o preparación del suelo si aplica';
                break;

            case 'siembra':
                $siembraPendiente = $this->actividadSiembraPendiente($lote);
                if ($siembraPendiente) {
                    $pasos[] = 'Completar la siembra asignada (con foto de evidencia)';
                } else {
                    $pasos[] = 'Asignar quién va a sembrar el lote';
                }
                break;

            case 'en_crecimiento':
                if (! $this->milestoneRegado($lote)) {
                    $pasos[] = 'Asignar y completar el riego del lote';
                }
                if (! $this->milestoneFumigacion($lote)) {
                    $pasos[] = 'Asignar y completar el control de plagas';
                }
                if (! $this->milestoneFertilizacion($lote)) {
                    $pasos[] = 'Asignar y completar la fertilización';
                }
                foreach ($this->actividadesPendientes($lote) as $actividad) {
                    $titulo = $actividad->descripcion ?: ($actividad->tipoActividad->nombre ?? 'Actividad');
                    $pasos[] = 'Completar actividad pendiente: «'.$titulo.'»';
                }
                break;

            case 'cosecha':
                if ($lote->producciones->isEmpty()) {
                    $pasos[] = 'Registrar la cosecha (kg, fecha y evidencia)';
                } else {
                    $pasos[] = 'Cosecha registrada — continúe con la certificación del lote';
                }
                break;

            case 'certificacion':
                $cert = app(CertificacionCampoService::class);
                if ($cert->esNoConforme($lote)) {
                    $pasos[] = 'Lote No conforme: no puede enviarse al almacén';
                    if ($cert->ultima($lote)?->observaciones) {
                        $pasos[] = 'Motivo: '.$cert->ultima($lote)->observaciones;
                    }
                } else {
                    $pasos[] = 'Evaluar el lote como Certificado o No conforme (formulario de abajo)';
                    $pasos[] = 'Si hay daños, plagas o calidad deficiente, marque No conforme';
                }
                break;

            case 'envio_almacen':
                $prod = $this->produccionPendienteAlmacen($lote);
                if ($prod?->almacenDestino) {
                    $pasos[] = 'Confirmar envío a «'.$prod->almacenDestino->nombre.'» (elegido en cosecha)';
                } else {
                    $pasos[] = 'Seleccionar el almacén agrícola de destino';
                }
                if ($lote->producciones->flatMap->almacenamientos->isEmpty()) {
                    $pasos[] = 'Registrar el ingreso con el botón «Enviar al almacén»';
                }
                break;
        }

        if ($siguienteFase && isset(self::FASES[$siguienteFase]) && $faseActual !== 'en_crecimiento') {
            array_unshift(
                $pasos,
                'Alcanzar la fase «'.self::FASES[$siguienteFase]['label'].'» ('.$this->progresoFase($siguienteFase).' %)'
            );
        }

        return array_values(array_unique(array_slice($pasos, 0, 6)));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function buildEventos(Lote $lote): Collection
    {
        $lote->loadMissing([
            'cultivo',
            'usuario',
            'insumoSemilla',
            'historialEstados.estadoTipo',
            'historialEstados.usuario',
            'loteInsumos.insumo',
            'loteInsumos.usuario',
            'actividades.tipoActividad',
            'actividades.usuario',
            'actividades.ejecutor',
            'producciones.unidadMedida',
            'producciones.catalogoTamanoConteo.tipoEmpaque',
            'producciones.destino',
            'producciones.almacenamientos.almacen',
            'producciones.almacenamientos.unidadMedida',
            'producciones.ventas',
            'certificaciones.usuario',
        ]);

        $eventos = collect();

        foreach ($lote->historialEstados as $historial) {
            if ($this->historialEstadoOmitirEnTrazabilidad($historial, $lote)) {
                continue;
            }

            $estadoNombre = $historial->estadoTipo->nombre ?? '';

            $usuarioHist = $historial->usuario;
            $nombreHist = trim(($usuarioHist->nombre ?? '').' '.($usuarioHist->apellido ?? '')) ?: null;
            $presentacion = $this->presentarEventoEstado($historial, $lote, $nombreHist);

            $eventos->push($this->evento(
                $historial->fecha_cambio,
                'estado',
                $this->faseFromEstado($estadoNombre),
                $presentacion['titulo'],
                $presentacion['descripcion'],
                $nombreHist,
                'exchange-alt',
                'info'
            ));
        }

        $this->agregarEventoSiembraHistorial($eventos, $lote);

        foreach ($lote->loteInsumos as $insumo) {
            if ($this->loteInsumoYaRepresentadoEnActividad($insumo, $lote)) {
                continue;
            }

            $faseInsumo = $this->faseEventoDesdeInsumo($insumo);
            $imagenInsumo = $insumo->insumo
                ? InsumoImagenCatalogo::urlPara($insumo->insumo)
                : null;
            $unidad = $insumo->insumo?->unidadMedida?->abreviatura
                ?? $insumo->insumo?->unidadMedida?->nombre
                ?? 'ud';
            $eventos->push($this->evento(
                $insumo->fechauo,
                'insumo',
                $faseInsumo,
                'Aplicación: '.($insumo->insumo->nombre ?? 'Insumo'),
                'Cantidad: '.number_format((float) $insumo->cantidadusada, 2, '.', '').' '.$unidad
                    .($insumo->observaciones ? ' — '.$insumo->observaciones : ''),
                trim(($insumo->usuario->nombre ?? '').' '.($insumo->usuario->apellido ?? '')) ?: null,
                'flask',
                'warning',
                null,
                null,
                self::FASES[$faseInsumo]['label'] ?? null,
                $imagenInsumo,
                null,
                null,
                $imagenInsumo ? 'insumo' : null,
            ));
        }

        foreach ($lote->actividades as $actividad) {
            $tipoNombre = strtolower(trim($actividad->tipoActividad->nombre ?? ''));

            // La siembra tiene pantalla y flujo propios; no aparece como «actividad» en el historial.
            if (str_contains($tipoNombre, 'siembra')) {
                continue;
            }

            if ($this->debeOmitirActividadAutomaticaEnHistorial($actividad, $lote)) {
                continue;
            }

            // La cosecha registrada en Producción ya tiene evento propio con foto y cantidades.
            if (str_contains($tipoNombre, 'cosecha') && $lote->producciones->isNotEmpty()) {
                continue;
            }

            $fasePipeline = $this->resolverFasePipelineActividad($actividad, $lote);
            $nombreTipo = $actividad->tipoActividad->nombre ?? 'Actividad';
            $descripcionAct = $this->descripcionHistorialActividad($actividad);
            $evidenciaHistorial = $this->evidenciaHistorialActividad($actividad, $tipoNombre, $lote);
            $fechaEvento = $actividad->fechafin ?? $actividad->fechainicio;
            $eventos->push($this->evento(
                $fechaEvento,
                'actividad',
                $fasePipeline,
                $nombreTipo,
                $descripcionAct,
                app(ActividadSecuenciaService::class)->nombreEjecutor($actividad)
                    ?? ($actividad->usuario->nombre ?? null),
                'tasks',
                'primary',
                $actividad->fechafin !== null,
                (int) $actividad->actividadid,
                null,
                $evidenciaHistorial['url'],
                null,
                $evidenciaHistorial['icono'],
                $evidenciaHistorial['tipo'],
                $evidenciaHistorial['foto_url'],
            ));
        }

        foreach ($lote->producciones as $produccion) {
            $evidenciaCosecha = EvidenciaFoto::urlDesdeImagenUrl($produccion->imagenurl ?? null);
            $eventos->push($this->evento(
                $this->fechaEventoCosecha($lote, $produccion),
                'cosecha',
                'cosecha',
                'Cosechado',
                $this->descripcionCosechaHistorial($produccion, $lote),
                $this->nombreUsuarioCosecha($lote),
                'tractor',
                'success',
                true,
                null,
                null,
                $evidenciaCosecha,
                (int) $produccion->produccionid,
                null,
                $evidenciaCosecha ? 'foto' : null,
            ));

            foreach ($produccion->almacenamientos as $alm) {
                $alm->loadMissing('almacen');
                $eventoAlmacen = $this->evento(
                    $alm->fechaentrada ?? $produccion->fechacosecha,
                    'almacenamiento',
                    'envio_almacen',
                    'Ingreso a almacén',
                    ($alm->almacen->nombre ?? 'Almacén').' — '
                        .number_format((float) $alm->cantidad, 2).' '
                        .($alm->unidadMedida->abreviatura ?? 'kg'),
                    null,
                    'warehouse',
                    'secondary'
                );
                if ($alm->almacen) {
                    $eventoAlmacen['almacenid'] = (int) $alm->almacenid;
                    $eventoAlmacen['almacen_url'] = AlmacenAmbito::urlVerAlmacen($alm->almacen);
                }
                $eventos->push($eventoAlmacen);
            }

            foreach ($produccion->ventas as $venta) {
                $eventos->push($this->evento(
                    $venta->fechaventa ?? $produccion->fechacosecha,
                    'venta',
                    'envio_almacen',
                    'Venta registrada',
                    ($venta->cliente ?? 'Cliente').' — '
                        .number_format((float) ($venta->cantidad ?? 0), 2).' u. × Bs. '
                        .number_format((float) ($venta->preciounitario ?? 0), 2),
                    null,
                    'shopping-cart',
                    'info'
                ));
            }
        }

        foreach ($lote->certificaciones as $cert) {
            $titulo = $cert->esNoConforme() ? 'No conforme — lote de campo' : 'Certificación de lote';
            $detalle = $cert->codigo_certificado
                ? 'Código: '.$cert->codigo_certificado
                : ($cert->observaciones ?? 'Evaluación registrada');
            if ($cert->observaciones && $cert->esNoConforme()) {
                $detalle .= ' — '.$cert->observaciones;
            }

            $eventos->push($this->evento(
                $cert->fecha_certificacion,
                'certificacion',
                'certificacion',
                $titulo,
                $detalle,
                trim(($cert->usuario->nombre ?? '').' '.($cert->usuario->apellido ?? '')) ?: null,
                'certificate',
                $cert->esNoConforme() ? 'danger' : 'success'
            ));
        }

        return $this->deduplicarEventos($eventos)
            ->filter(fn ($e) => $e['fecha'] !== null)
            ->pipe(fn (Collection $c) => $this->ordenarEventosHistorialCronologico($c));
    }

    /** @var array<string, int> */
    private const ETAPAS_HISTORIAL_ORDEN = [
        'planificacion' => 1,
        'siembra' => 2,
        'actividad' => 3,
        'insumo' => 4,
        'cosecha' => 5,
        'certificacion' => 6,
        'almacen' => 7,
    ];

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return Collection<int, array<string, mixed>>
     */
    private function ordenarEventosHistorialCronologico(Collection $eventos): Collection
    {
        return $eventos
            ->sortBy(fn (array $e) => $this->claveOrdenHistorial($e))
            ->values();
    }

    /**
     * Clave única para ordenar: etapa del ciclo (asc) y luego fecha/hora (asc).
     * sortBy con un solo extractor es compatible con todas las versiones de Laravel.
     */
    private function claveOrdenHistorial(array $evento): string
    {
        $etapa = $this->etapaOrdenHistorial($evento);
        $ts = str_pad((string) $this->parseFechaApp($evento['fecha'])->timestamp, 12, '0', STR_PAD_LEFT);
        $desempate = str_pad((string) ($evento['actividadid'] ?? $evento['produccionid'] ?? 0), 8, '0', STR_PAD_LEFT);

        return sprintf('%02d-%s-%s', $etapa, $ts, $desempate);
    }

    /**
     * Etapa fija del ciclo: planificación → siembra → actividades → cosecha → certificación → almacén.
     */
    private function etapaOrdenHistorial(array $evento): int
    {
        $etapa = $this->clasificarEtapaHistorial($evento);

        return self::ETAPAS_HISTORIAL_ORDEN[$etapa] ?? 3;
    }

    private function clasificarEtapaHistorial(array $evento): string
    {
        $tipo = (string) ($evento['tipo'] ?? '');
        $fase = (string) ($evento['fase'] ?? '');
        $titulo = $this->normalizarTextoHistorial((string) ($evento['titulo'] ?? ''));

        if ($tipo === 'siembra') {
            return 'siembra';
        }

        if ($tipo === 'insumo') {
            return 'insumo';
        }

        if ($tipo === 'cosecha') {
            return 'cosecha';
        }

        if ($tipo === 'certificacion') {
            return 'certificacion';
        }

        if (in_array($tipo, ['almacenamiento', 'venta'], true)) {
            return 'almacen';
        }

        if ($tipo === 'estado' || ($tipo === 'actividad' && $fase === 'preparacion')) {
            if (
                $fase === 'preparacion'
                || str_contains($titulo, 'planificacion')
            ) {
                return 'planificacion';
            }
        }

        if (in_array($tipo, ['actividad'], true)) {
            return 'actividad';
        }

        if ($tipo === 'estado') {
            return 'actividad';
        }

        return 'actividad';
    }

    private function normalizarTextoHistorial(string $texto): string
    {
        $t = mb_strtolower(trim($texto));

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $t
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardGlobal(Request $request): array
    {
        $filtros = $this->parseFiltros($request);

        $query = Lote::query()
            ->with([
                'cultivo',
                'estadoTipo',
                'usuario',
                'producciones.ventas',
                'producciones.almacenamientos',
                'certificaciones',
            ]);

        if ($filtros['cultivoid']) {
            $query->where('cultivoid', $filtros['cultivoid']);
        }
        if ($filtros['estadolotetipoid']) {
            $query->where('estadolotetipoid', $filtros['estadolotetipoid']);
        }
        if ($filtros['usuarioid']) {
            $query->where('usuarioid', $filtros['usuarioid']);
        }
        if ($filtros['q']) {
            $q = '%'.$filtros['q'].'%';
            $query->where(function ($sub) use ($q) {
                $sub->where('nombre', 'like', $q)
                    ->orWhere('codigo_trazabilidad', 'like', $q)
                    ->orWhere('ubicacion', 'like', $q);
            });
        }

        $lotes = $query->orderByDesc('fechamodificacion')->orderBy('nombre')->get();

        $filas = collect();
        $todosEventos = collect();
        $porFase = array_fill_keys(array_keys(self::FASES), 0);

        foreach ($lotes as $lote) {
            $faseActual = $this->resolverFaseActual($lote);
            if ($filtros['fase'] && $filtros['fase'] !== $faseActual) {
                continue;
            }

            $eventos = $this->buildEventos($lote);
            $eventosFiltrados = $this->filtrarEventos($eventos, $filtros);
            $ultimo = $eventosFiltrados->first();

            $porFase[$faseActual] = ($porFase[$faseActual] ?? 0) + 1;
            $todosEventos = $todosEventos->merge($eventosFiltrados);

            $progreso = $this->progresoFase($faseActual);
            $pendiente = $this->pasosHaciaCompleto($lote, $faseActual);

            $filas->push([
                'lote' => $lote,
                'fase_actual' => $faseActual,
                'fase_label' => self::FASES[$faseActual]['label'],
                'fase_color' => self::FASES[$faseActual]['color'],
                'progreso' => $progreso,
                'pendiente' => $pendiente,
                'total_eventos' => $eventos->count(),
                'kg_producidos' => $lote->producciones->sum('cantidad'),
                'ultimo_evento' => $ultimo,
            ]);
        }

        $porTipo = $todosEventos->groupBy('tipo')->map->count();

        return [
            'filtros' => $filtros,
            'fases' => self::FASES,
            'stats' => [
                'total_lotes' => $filas->count(),
                'total_eventos' => $todosEventos->count(),
                'kg_total' => round($filas->sum('kg_producidos'), 2),
                'lotes_en_cultivo' => $filas->whereIn('fase_actual', ['siembra', 'en_crecimiento'])->count(),
                'lotes_cosechados' => $filas->whereIn('fase_actual', ['cosecha', 'envio_almacen'])->count(),
            ],
            'chart_por_fase' => [
                'labels' => collect($porFase)->map(fn ($c, $k) => self::FASES[$k]['label'])->values()->all(),
                'data' => array_values($porFase),
                'colors' => collect($porFase)->keys()->map(fn ($k) => self::FASES[$k]['color'])->values()->all(),
            ],
            'chart_por_tipo' => [
                'labels' => $porTipo->keys()->map(fn ($t) => ucfirst($t))->values()->all(),
                'data' => $porTipo->values()->all(),
            ],
            'chart_linea' => $this->chartLineaMensual($todosEventos),
            'filas' => $filas,
            'cultivos' => Cultivo::orderBy('nombre')->get(['cultivoid', 'nombre']),
            'estados' => EstadoLoteTipo::orderBy('nombre')->get(['estadolotetipoid', 'nombre']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardLote(Lote $lote, Request $request): array
    {
        $base = $this->buildLoteDetalleBase($lote);
        $filtros = $this->parseFiltros($request);
        $eventos = $this->buildEventos($lote);
        $eventosFiltrados = $this->numerarYOrdenarEventosHistorial(
            $this->filtrarEventos($eventos, $filtros)
        );
        $faseActual = $this->resolverFaseActual($lote);

        $porFase = $eventos->groupBy('fase')->map->count();
        $porTipo = $eventos->groupBy('tipo')->map->count();
        $porTipoFiltrado = $eventosFiltrados->groupBy('tipo')->map->count();

        $user = $request->user();
        $fasesPipeline = collect(self::FASES)->map(function ($meta, $key) use ($faseActual, $porFase, $porTipo, $lote, $user) {
            $ordenActual = self::FASES[$faseActual]['orden'] ?? 1;
            $ordenSiguiente = $ordenActual + 1;
            $completo = $this->trazabilidadCompleta($lote);

            $estado = match (true) {
                $completo => 'done',
                $meta['orden'] < $ordenActual => 'done',
                $key === $faseActual => 'active',
                $meta['orden'] === $ordenSiguiente => 'next',
                default => 'pending',
            };

            $url = null;
            if ($estado === 'next' || ($estado === 'active' && ! $completo)) {
                $candidata = $this->urlAccionFase($lote, $key);
                if ($key === 'cosecha' && $faseActual === 'en_crecimiento' && ! $this->puedeUsuarioIrACosecha($lote, $user)) {
                    $url = null;
                } elseif ($key === 'envio_almacen' && ! app(CertificacionCampoService::class)->estaCertificado($lote)) {
                    $url = null;
                } else {
                    $url = $candidata;
                }
            }

            $esFaseUnica = in_array($key, self::FASES_UNICAS, true);
            $eventosCount = match ($key) {
                'en_crecimiento' => ($porFase->get('regado', 0) + $porFase->get('fumigacion', 0) + $porFase->get('fertilizacion', 0)),
                'cosecha' => (int) $porTipo->get('cosecha', 0),
                'certificacion' => (int) $porTipo->get('certificacion', 0),
                'envio_almacen' => (int) $porTipo->get('almacenamiento', 0),
                default => (int) $porFase->get($key, 0),
            };

            if ($esFaseUnica && $key !== 'en_crecimiento') {
                $eventosCount = 0;
            }

            $certService = app(CertificacionCampoService::class);
            $completada = match ($key) {
                'preparacion', 'siembra' => $estado === 'done',
                'cosecha' => (int) $porTipo->get('cosecha', 0) > 0,
                'certificacion' => $certService->fueEvaluado($lote),
                'envio_almacen' => (int) $porTipo->get('almacenamiento', 0) > 0,
                default => false,
            };

            return [
                'key' => $key,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'icon' => $meta['icon'],
                'eventos' => $eventosCount,
                'fase_unica' => $esFaseUnica,
                'completada' => $completada,
                'mostrar_contador' => $key === 'en_crecimiento' && $eventosCount > 0,
                'estado' => $estado,
                'url' => $url,
            ];
        })->values();

        $siguienteFase = $this->siguienteFase($faseActual);
        $urlSiguienteFase = $siguienteFase ? $this->urlAccionFase($lote, $siguienteFase) : null;
        $puedeCertificarCampo = $this->puedeCertificarCampo($lote, $user);
        $puedeEnviarAlmacen = $this->puedeEnviarAlmacenCampo($lote, $user);
        $produccionPendienteAlmacen = $this->produccionPendienteAlmacen($lote);
        if ($puedeCertificarCampo || $puedeEnviarAlmacen) {
            $urlSiguienteFase = null;
        } elseif ($siguienteFase === 'cosecha' && ! $this->puedeUsuarioIrACosecha($lote, $user)) {
            $urlSiguienteFase = null;
        }
        $certCampo = app(CertificacionCampoService::class);
        $siembraPendiente = $this->actividadSiembraPendiente($lote);
        if ($siembraPendiente) {
            $siembraPendiente->loadMissing('usuario');
        }
        $actividadesMarcablesIds = $this->idsActividadesMarcables($lote, $user);
        $puedeCompletarSiembraDirecta = $siguienteFase === 'siembra'
            && ! $this->siembraCompletada($lote)
            && $siembraPendiente === null
            && $user
            && UsuarioRol::gestionaCampo($user);
        $puedeCompletarSiembra = $puedeCompletarSiembraDirecta
            || ($siembraPendiente !== null
                && in_array((int) $siembraPendiente->actividadid, $actividadesMarcablesIds, true));
        $sugerenciaSiembra = $lote->cultivo
            ? CultivoSiembraCatalogo::sugerenciaParaLote($lote->cultivo, (float) $lote->superficie)
            : null;
        $resumenSiembraCompletar = $this->resumenSiembraCompletar($lote);
        $urlAsignarActividad = ($faseActual === 'en_crecimiento' && $user && UsuarioRol::gestionaCampo($user))
            ? $this->urlAsignarActividadEnCrecimiento($lote)
            : null;
        $siguienteActividadCrecimiento = $faseActual === 'en_crecimiento'
            ? $this->siguienteTipoActividadCrecimiento($lote)
            : null;

        return array_merge($base, [
            'filtros' => $filtros,
            'fases' => self::FASES,
            'fases_evento' => $this->fasesMetaEvento(),
            'fase_actual' => $faseActual,
            'fase_actual_label' => self::FASES[$faseActual]['label'],
            'progreso' => $this->progresoFase($faseActual),
            'pendiente' => $this->pasosHaciaCompleto($lote, $faseActual),
            'trazabilidad' => $eventosFiltrados,
            'trazabilidad_json' => $eventosFiltrados->values()->all(),
            'fases_pipeline' => $fasesPipeline,
            'siguiente_fase' => $siguienteFase,
            'siguiente_fase_label' => $siguienteFase ? (self::FASES[$siguienteFase]['label'] ?? $siguienteFase) : null,
            'url_siguiente_fase' => $urlSiguienteFase,
            'puede_certificar_campo' => $puedeCertificarCampo,
            'puede_enviar_almacen' => $puedeEnviarAlmacen,
            'produccion_pendiente_almacen' => $produccionPendienteAlmacen,
            'certificacion_campo' => $certCampo->ultima($lote),
            'puede_asignar_siembra' => $siguienteFase === 'siembra'
                && ! $this->siembraCompletada($lote)
                && $siembraPendiente === null
                && ! $puedeCompletarSiembraDirecta,
            'puede_completar_siembra_directa' => $puedeCompletarSiembraDirecta,
            'url_completar_siembra' => $puedeCompletarSiembraDirecta
                ? route('lotes.siembra.completar', [
                    'lote' => $lote,
                    'return' => $this->returnUrlTrazabilidad($lote),
                ])
                : null,
            'actividad_siembra_pendiente' => $siembraPendiente ? [
                'actividadid' => (int) $siembraPendiente->actividadid,
                'titulo' => $siembraPendiente->descripcion ?: ($siembraPendiente->tipoActividad->nombre ?? 'Siembra'),
                'responsable' => trim(($siembraPendiente->usuario->nombre ?? '').' '.($siembraPendiente->usuario->apellido ?? '')) ?: null,
                'fechainicio' => $siembraPendiente->fechainicio
                    ? $this->formatearFechaApp($siembraPendiente->fechainicio)
                    : null,
            ] : null,
            'puede_completar_siembra' => $puedeCompletarSiembra,
            'sugerencia_siembra' => $sugerenciaSiembra,
            'resumen_siembra_completar' => $resumenSiembraCompletar,
            'url_asignar_actividad' => $urlAsignarActividad,
            'siguiente_actividad_crecimiento' => $siguienteActividadCrecimiento,
            'puede_ir_a_cosecha' => $faseActual === 'en_crecimiento' && $siguienteFase === 'cosecha'
                ? $this->puedeUsuarioIrACosecha($lote, $user)
                : true,
            'actividades_pendientes_count' => $faseActual === 'en_crecimiento'
                ? $this->actividadesPendientesEsencialesCrecimiento($lote)->count()
                : $this->actividadesPendientes($lote)->count(),
            'panel_pendientes' => $this->panelPendientesLegible($lote, $faseActual),
            'chart_por_fase' => [
                'labels' => $porFase->keys()->map(fn ($k) => self::FASES[$k]['label'] ?? $k)->values()->all(),
                'data' => $porFase->values()->all(),
                'colors' => $porFase->keys()->map(fn ($k) => self::FASES[$k]['color'] ?? '#999')->values()->all(),
            ],
            'chart_por_tipo' => [
                'labels' => $porTipoFiltrado->keys()->map(fn ($t) => ucfirst($t))->values()->all(),
                'data' => $porTipoFiltrado->values()->all(),
            ],
            'chart_linea' => $this->chartLineaMensual($eventosFiltrados),
            'actividades_marcables_ids' => $actividadesMarcablesIds,
        ]);
    }

    /** @return list<int> */
    private function idsActividadesMarcables(Lote $lote, ?Usuario $user): array
    {
        if (! $user) {
            return [];
        }

        return Actividad::query()
            ->where('loteid', $lote->loteid)
            ->whereNull('fechafin')
            ->with('tipoActividad')
            ->get()
            ->filter(fn (Actividad $actividad) => ActividadPermisos::puedeMarcarCompletada($user, $actividad))
            ->map(fn (Actividad $actividad) => (int) $actividad->actividadid)
            ->values()
            ->all();
    }

    /**
     * @return array{lote: Lote, estadisticas: array<string, mixed>, estadoClass: string}
     */
    public function buildLoteDetalleBase(Lote $lote): array
    {
        $lote->load(['usuario', 'cultivo', 'estadoTipo']);

        $estadoColors = [
            'disponible' => 'bg-secondary',
            'en preparación' => 'bg-info',
            'sembrado' => 'bg-primary',
            'en producción' => 'bg-success',
            'cosechado' => 'bg-warning text-dark',
            'en descanso' => 'bg-dark',
        ];
        $estadoNombre = strtolower($lote->estadoTipo->nombre ?? 'disponible');
        $estadoClass = $estadoColors[$estadoNombre] ?? 'bg-secondary';

        $diasDesdeSiembra = null;
        if ($lote->fechasiembra) {
            $fechaSiembra = Carbon::parse($lote->fechasiembra);
            $diasDesdeSiembra = $fechaSiembra->isFuture() ? 0 : (int) $fechaSiembra->diffInDays(now());
        }

        $lote->loadCount(['loteInsumos', 'actividades', 'producciones']);
        $lote->load(['actividades', 'producciones']);

        $estadisticas = [
            'total_insumos' => $lote->lote_insumos_count,
            'total_actividades' => $lote->actividades_count,
            'actividades_completadas' => $lote->actividades->whereNotNull('fechafin')->count(),
            'actividades_pendientes' => $lote->actividades->whereNull('fechafin')->count(),
            'total_aplicaciones' => $lote->lote_insumos_count,
            'total_cosechas' => $lote->producciones_count,
            'produccion_total' => $lote->producciones->sum('cantidad'),
            'dias_desde_siembra' => $diasDesdeSiembra,
        ];

        return compact('lote', 'estadisticas', 'estadoClass');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFiltros(Request $request): array
    {
        return [
            'fase' => $request->input('fase'),
            'tipo' => $request->input('tipo'),
            'cultivoid' => $request->integer('cultivoid') ?: null,
            'estadolotetipoid' => $request->integer('estadolotetipoid') ?: null,
            'usuarioid' => $request->integer('usuarioid') ?: null,
            'q' => trim((string) $request->input('q', '')),
            'desde' => $request->input('desde'),
            'hasta' => $request->input('hasta'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return Collection<int, array<string, mixed>>
     */
    private function filtrarEventos(Collection $eventos, array $filtros): Collection
    {
        return $eventos->filter(function ($e) use ($filtros) {
            if ($filtros['fase']) {
                $faseEvento = $e['fase'] ?? '';
                $match = $faseEvento === $filtros['fase']
                    || ($filtros['fase'] === 'en_crecimiento' && in_array($faseEvento, ['regado', 'fumigacion'], true));
                if (! $match) {
                    return false;
                }
            }
            if ($filtros['tipo'] && ($e['tipo'] ?? '') !== $filtros['tipo']) {
                return false;
            }
            $fecha = $e['fecha'] ? $this->parseFechaApp($e['fecha']) : null;
            if ($filtros['desde'] && $fecha && $fecha->lt($this->parseFechaApp($filtros['desde'])->startOfDay())) {
                return false;
            }
            if ($filtros['hasta'] && $fecha && $fecha->gt($this->parseFechaApp($filtros['hasta'])->endOfDay())) {
                return false;
            }

            return true;
        })->values();
    }

    private function formatearObservacionHistorial(?string $observaciones, ?string $usuario, ?string $rol): string
    {
        $lineas = [];

        if ($observaciones !== null && trim($observaciones) !== '' && trim($observaciones) !== 'Sin observaciones') {
            $texto = preg_replace('/^\[[^\]]+\]\s*/', '', trim($observaciones)) ?? trim($observaciones);
            $partes = preg_split('/\s*·\s*/', $texto) ?: [];

            foreach ($partes as $parte) {
                $parte = trim($parte);
                if ($parte === '' || strcasecmp($parte, 'historial') === 0) {
                    continue;
                }
                if (preg_match('/^Realizado por:/i', $parte)) {
                    continue;
                }
                if (preg_match('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $parte)) {
                    continue;
                }
                if (preg_match('/\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}/', $parte)) {
                    continue;
                }
                $lineas[] = $parte;
            }
        }

        if ($usuario) {
            $lineas[] = 'Registrado por '.$usuario.($rol ? " ({$rol})" : '');
        }

        if ($lineas === []) {
            return 'Cambio de estado registrado en el lote.';
        }

        return implode("\n", array_values(array_unique($lineas)));
    }

    private function resolverFasePipelineActividad(Actividad $actividad, Lote $lote): string
    {
        $tipoNombre = strtolower(trim($actividad->tipoActividad->nombre ?? ''));

        if (str_contains($tipoNombre, 'labranza')) {
            return 'preparacion';
        }
        if (str_contains($tipoNombre, 'siembra')) {
            return 'siembra';
        }
        if (str_contains($tipoNombre, 'cosecha')) {
            return 'cosecha';
        }

        if ($this->loteSiembraRegistrada($lote)) {
            return 'en_crecimiento';
        }

        if ($this->esActividadPostSiembra($tipoNombre) && $actividad->fechafin !== null) {
            return 'en_crecimiento';
        }

        return 'siembra';
    }

    private function esActividadPostSiembra(string $tipoNombre): bool
    {
        return str_contains($tipoNombre, 'riego')
            || str_contains($tipoNombre, 'regad')
            || str_contains($tipoNombre, 'fertiliz')
            || str_contains($tipoNombre, 'fumig')
            || str_contains($tipoNombre, 'plaga')
            || str_contains($tipoNombre, 'fitosanit');
    }

    private function loteSiembraRegistrada(Lote $lote): bool
    {
        if ($this->siembraCompletada($lote)) {
            return true;
        }

        if (
            $this->milestoneRegado($lote)
            || $this->milestoneFumigacion($lote)
            || $this->milestoneFertilizacion($lote)
            || $this->milestoneCosecha($lote)
        ) {
            return true;
        }

        if ($this->fechaHistorialSiembra($lote) !== null) {
            return true;
        }

        if ((float) ($lote->cantidad_semilla_planificada ?? 0) > 0 && filled($lote->insumosemillaid)) {
            return true;
        }

        return $lote->actividades->contains(function (Actividad $a) {
            $nombre = mb_strtolower(trim($a->tipoActividad->nombre ?? ''));

            return str_contains($nombre, 'siembra')
                && ($a->fechafin !== null || filled($a->evidencia_foto_path));
        });
    }

    private function fechaHistorialSiembra(Lote $lote): ?Carbon
    {
        foreach ($lote->historialEstados as $historial) {
            $slug = EstadoLoteCatalogo::slugFromNombre($historial->estadoTipo->nombre ?? '');
            if ($slug === 'sembrado') {
                return $this->parseFechaApp($historial->fecha_cambio);
            }

            if (preg_match('/Actividad\s*«\s*Siembra\s*»\s+completada/i', (string) ($historial->observaciones ?? ''))) {
                return $this->parseFechaApp($historial->fecha_cambio);
            }
        }

        return null;
    }

    private function fechaInferidaSiembraDesdeActividades(Lote $lote): ?Carbon
    {
        $primeraPostPlanificacion = $lote->actividades
            ->filter(function (Actividad $a) {
                if ($a->fechafin === null) {
                    return false;
                }
                $nombre = mb_strtolower(trim($a->tipoActividad->nombre ?? ''));

                return $this->esActividadPostSiembra($nombre)
                    || str_contains($nombre, 'cosecha');
            })
            ->sortBy(fn (Actividad $a) => $this->parseFechaApp($a->fechafin)->timestamp)
            ->first();

        if ($primeraPostPlanificacion === null) {
            return null;
        }

        return $this->parseFechaApp($primeraPostPlanificacion->fechafin)->subDay();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Actividad>
     */
    private function actividadesSiembraDelLote(Lote $lote): Collection
    {
        return $lote->actividades
            ->filter(fn (Actividad $a) => str_contains(mb_strtolower(trim($a->tipoActividad->nombre ?? '')), 'siembra'))
            ->sortByDesc(function (Actividad $a) {
                $puntos = 0;
                if (filled($a->evidencia_foto_path)) {
                    $puntos += 20;
                }
                if ($a->fechafin !== null) {
                    $puntos += 10;
                }

                return $puntos * 1_000_000_000 + $this->parseFechaApp($a->fechafin ?? $a->fechainicio ?? now())->timestamp;
            })
            ->values();
    }

    private function resolverActividadSiembraParaHistorial(Lote $lote): ?Actividad
    {
        return $this->actividadesSiembraDelLote($lote)->first();
    }

    private function parseFechaApp(mixed $fecha): Carbon
    {
        return Carbon::parse($fecha)->timezone(config('app.timezone'));
    }

    private function formatearFechaApp(mixed $fecha): string
    {
        if ($fecha === null || $fecha === '') {
            return '—';
        }

        return $this->parseFechaApp($fecha)->format('d/m/Y H:i');
    }

    private function evento(
        mixed $fecha,
        string $tipo,
        string $fase,
        string $titulo,
        string $descripcion,
        ?string $usuario,
        string $icono,
        string $color,
        ?bool $completada = null,
        ?int $actividadid = null,
        ?string $faseLabelOverride = null,
        ?string $evidenciaUrl = null,
        ?int $produccionid = null,
        ?string $evidenciaIcono = null,
        ?string $evidenciaTipo = null,
        ?string $evidenciaFotoUrl = null,
    ): array {
        $row = [
            'fecha' => $fecha,
            'fecha_iso' => $fecha ? $this->parseFechaApp($fecha)->toIso8601String() : null,
            'fecha_fmt' => $this->formatearFechaApp($fecha),
            'tipo' => $tipo,
            'fase' => $fase,
            'fase_label' => $faseLabelOverride
                ?? self::FASES_EVENTO_EXTRA[$fase]['label']
                ?? self::FASES[$fase]['label']
                ?? ucfirst($fase),
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'usuario' => $usuario,
            'icono' => $icono,
            'color' => $color,
        ];
        if ($completada !== null) {
            $row['completada'] = $completada;
        }
        if ($actividadid !== null) {
            $row['actividadid'] = $actividadid;
        }
        if ($evidenciaUrl !== null) {
            $row['evidencia_url'] = $evidenciaUrl;
        }
        if ($produccionid !== null) {
            $row['produccionid'] = $produccionid;
        }
        if ($evidenciaIcono !== null) {
            $row['evidencia_icono'] = $evidenciaIcono;
        }
        if ($evidenciaTipo !== null) {
            $row['evidencia_tipo'] = $evidenciaTipo;
        }
        if ($evidenciaFotoUrl !== null) {
            $row['evidencia_foto_url'] = $evidenciaFotoUrl;
        }

        return $row;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return Collection<int, array<string, mixed>>
     */
    private function numerarYOrdenarEventosHistorial(Collection $eventos): Collection
    {
        $cronologico = $this->ordenarEventosHistorialCronologico($eventos);

        return $cronologico
            ->map(function (array $evento, int $idx) {
                $evento['paso'] = $idx + 1;

                return $evento;
            })
            ->reverse()
            ->values();
    }

    private function fechaEventoCosecha(Lote $lote, Produccion $produccion): mixed
    {
        $historial = $lote->historialEstados
            ->filter(fn ($h) => EstadoLoteCatalogo::slugFromNombre($h->estadoTipo->nombre ?? '') === 'cosechado')
            ->sortByDesc(fn ($h) => $this->parseFechaApp($h->fecha_cambio)->timestamp)
            ->first();

        if ($historial?->fecha_cambio) {
            return $historial->fecha_cambio;
        }

        $fecha = $produccion->fechacosecha;
        if ($fecha === null) {
            return now();
        }

        $parsed = $this->parseFechaApp($fecha);

        if ($parsed->format('H:i:s') === '00:00:00') {
            $ultimaActividad = $lote->actividades
                ->filter(fn (Actividad $a) => $a->fechafin !== null)
                ->sortByDesc(fn (Actividad $a) => $this->parseFechaApp($a->fechafin)->timestamp)
                ->first();

            if ($ultimaActividad?->fechafin) {
                $base = $this->parseFechaApp($ultimaActividad->fechafin);

                return $base->copy()->addMinute();
            }

            return $parsed->endOfDay();
        }

        return $parsed;
    }

    private function historialEstadoOmitirEnTrazabilidad($historial, Lote $lote): bool
    {
        $estadoNombre = $historial->estadoTipo->nombre ?? '';
        $slug = EstadoLoteCatalogo::slugFromNombre($estadoNombre);

        $estadosVisibles = ['planificado', 'disponible'];
        if ($slug !== null && $slug !== '' && ! in_array($slug, $estadosVisibles, true)) {
            return true;
        }

        if ($slug === 'sembrado') {
            return true;
        }

        if ($slug === 'listo_para_cosecha') {
            return true;
        }

        if ($slug === 'en_crecimiento' && $lote->actividades->contains(fn (Actividad $a) => $a->fechafin !== null)) {
            return true;
        }

        if ($slug === 'cosechado' && $lote->producciones->isNotEmpty()) {
            return true;
        }

        if ($slug === 'certificado' && $lote->certificaciones->isNotEmpty()) {
            return true;
        }

        $obs = trim((string) ($historial->observaciones ?? ''));
        if ($obs === '') {
            return false;
        }

        if (preg_match('/^Actividad\s*«.+»\s+completada\.?$/u', $obs)) {
            return true;
        }

        if (preg_match('/Actividad\s*«\s*Siembra\s*»\s+completada/i', $obs)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{titulo: string, descripcion: string}
     */
    private function presentarEventoEstado($historial, Lote $lote, ?string $registradoPor): array
    {
        $estadoNombre = trim((string) ($historial->estadoTipo->nombre ?? ''));
        $slug = EstadoLoteCatalogo::slugFromNombre($estadoNombre);
        $obs = trim((string) ($historial->observaciones ?? ''));

        $encargado = trim(($lote->usuario->nombre ?? '').' '.($lote->usuario->apellido ?? ''));

        $titulo = match ($slug) {
            'planificado', 'disponible' => 'En planificación',
            'en_preparacion' => 'En preparación',
            'sembrado' => 'Sembrado',
            'en_crecimiento', 'en_produccion' => 'En crecimiento',
            'listo_para_cosecha' => 'Listo para cosecha',
            'certificado' => 'Certificado',
            'cosechado' => 'Cosechado',
            'no_conforme' => 'No conforme',
            default => $estadoNombre !== '' ? 'En '.mb_strtolower($estadoNombre) : 'Actualización del lote',
        };

        if (str_contains(mb_strtolower($obs), 'registro inicial')) {
            $descripcion = $encargado !== ''
                ? 'Encargado del lote: '.$encargado
                : 'Alta del lote en el sistema.';
        } elseif (preg_match('/^Motivo:\s*(.+)$/s', $obs, $m)) {
            $descripcion = 'Motivo: '.trim($m[1]);
        } elseif ($obs !== '') {
            $descripcion = $this->formatearObservacionHistorial($obs, null, null);
            if ($descripcion === 'Cambio de estado registrado en el lote.') {
                $descripcion = '';
            }
        } else {
            $descripcion = '';
        }

        if ($descripcion === '' && $encargado !== '' && ! str_contains(mb_strtolower($obs), 'registro inicial')) {
            $descripcion = 'Encargado del lote: '.$encargado;
        }

        return [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
        ];
    }

    private function nombreCultivoSiembrado(Lote $lote, ?Actividad $siembra = null): string
    {
        $lote->loadMissing(['cultivo', 'insumoSemilla']);

        if ($lote->cultivo?->nombre) {
            return trim($lote->cultivo->nombre);
        }

        if ($lote->insumoSemilla?->nombre) {
            return trim($lote->insumoSemilla->nombre);
        }

        if ($siembra) {
            $desc = trim((string) ($siembra->descripcion ?? ''));
            if ($desc !== '' && ! str_contains(mb_strtolower($desc), 'siembra')) {
                return $desc;
            }
        }

        return 'cultivo';
    }

    private function descripcionCosechaHistorial(Produccion $produccion, Lote $lote): string
    {
        $produccion->loadMissing(['unidadMedida', 'catalogoTamanoConteo.tipoEmpaque']);
        $lote->loadMissing('catalogoTamanoConteo.tipoEmpaque');

        $unidad = trim((string) ($produccion->unidadMedida->abreviatura ?? 'kg'));
        $partes = [number_format((float) $produccion->cantidad, 0, '.', '.').' '.$unidad];

        if ($produccion->cantidad_empaques !== null && (int) $produccion->cantidad_empaques > 0) {
            $calibre = $produccion->catalogoTamanoConteo ?? $lote->catalogoTamanoConteo;
            $empaqueLabel = \App\Services\CosechaPresentacionService::etiquetaEmpaquePlural(
                \App\Services\CosechaPresentacionService::tipoEmpaqueParaCosechaEnCampo($calibre?->tipoEmpaque?->nombre)
            );
            $partes[] = number_format((int) $produccion->cantidad_empaques, 0, ',', '.').' '.$empaqueLabel;
        }

        if ($produccion->cantidad_unidades !== null && (int) $produccion->cantidad_unidades > 0) {
            $partes[] = number_format((int) $produccion->cantidad_unidades, 0, ',', '.').' unidades';
        }

        return implode('/', $partes);
    }

    private function nombreUsuarioCosecha(Lote $lote): ?string
    {
        $historial = $lote->historialEstados
            ->filter(fn ($h) => EstadoLoteCatalogo::slugFromNombre($h->estadoTipo->nombre ?? '') === 'cosechado')
            ->sortByDesc(fn ($h) => $this->parseFechaApp($h->fecha_cambio)->timestamp)
            ->first();

        if ($historial?->usuario) {
            $nombre = trim(($historial->usuario->nombre ?? '').' '.($historial->usuario->apellido ?? ''));

            return $nombre !== '' ? $nombre : null;
        }

        if ($lote->usuario) {
            $nombre = trim(($lote->usuario->nombre ?? '').' '.($lote->usuario->apellido ?? ''));

            return $nombre !== '' ? $nombre : null;
        }

        return null;
    }

    private function descripcionSiembraHistorial(Actividad $siembra, Lote $lote, string $cultivoNombre): string
    {
        $material = $this->materialSiembraHistorial($siembra, $lote);
        if ($material !== '') {
            return $material;
        }

        $detalle = $this->descripcionHistorialActividad($siembra);
        if ($detalle === '') {
            return '';
        }

        $normalizado = mb_strtolower(trim($detalle));
        $cultivo = mb_strtolower(trim($cultivoNombre));

        if ($normalizado === 'siembra' || $normalizado === $cultivo) {
            return '';
        }

        if (str_starts_with($normalizado, 'cultivo:')) {
            return '';
        }

        if (str_starts_with($normalizado, 'insumo aplicado:')) {
            return $detalle;
        }

        if (str_contains($normalizado, 'siembra') && $normalizado === $cultivo) {
            return '';
        }

        return $detalle;
    }

    private function materialSiembraHistorial(Actividad $siembra, Lote $lote): string
    {
        $detalle = json_decode((string) ($siembra->detalle_json ?? ''), true);
        if (is_array($detalle) && ($detalle['modo'] ?? '') === 'insumos') {
            foreach ($detalle['insumos'] ?? [] as $fila) {
                if (! is_array($fila)) {
                    continue;
                }
                $nombre = trim((string) ($fila['nombre'] ?? ''));
                $cant = (float) ($fila['cantidad'] ?? 0);
                $unidad = trim((string) ($fila['unidad'] ?? 'ud'));
                if ($nombre !== '' && $cant > 0) {
                    return 'Material de siembra: '.number_format($cant, 2, '.', '').' '.$unidad.' de '.$nombre;
                }
            }
        }

        $lote->loadMissing(['insumoSemilla.unidadMedida', 'cultivo', 'loteInsumos.insumo.unidadMedida']);
        $insumoRegistro = $lote->loteInsumos->first(
            fn ($li) => (int) ($li->actividadid ?? 0) === (int) $siembra->actividadid && (float) ($li->cantidadusada ?? 0) > 0
        );
        if ($insumoRegistro) {
            $nombre = trim((string) ($insumoRegistro->insumo->nombre ?? 'semilla'));
            $unidad = trim((string) ($insumoRegistro->insumo->unidadMedida->abreviatura ?? 'kg'));

            return 'Material de siembra: '.number_format((float) $insumoRegistro->cantidadusada, 2, '.', '').' '.$unidad.' de '.$nombre;
        }

        $sugerencia = CultivoSiembraCatalogo::sugerenciaDesdeLote($lote);
        if (! $sugerencia && $lote->cultivo) {
            $fallback = CultivoSiembraCatalogo::sugerenciaParaLote($lote->cultivo, (float) $lote->superficie);
            $sugerencia = array_merge($fallback, [
                'insumo_nombre' => $lote->insumoSemilla?->nombre ?? $lote->cultivo->nombre,
            ]);
        }

        if ($sugerencia && ($sugerencia['tiene_dosis'] ?? false) && (float) ($sugerencia['sugerido'] ?? 0) > 0) {
            $nombre = trim((string) ($sugerencia['insumo_nombre'] ?? 'semilla'));
            $unidad = trim((string) ($sugerencia['unidad'] ?? 'kg'));

            return 'Material de siembra: '.number_format((float) $sugerencia['sugerido'], 2, '.', '').' '.$unidad.' de '.$nombre;
        }

        return '';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return Collection<int, array<string, mixed>>
     */
    private function deduplicarEventos(Collection $eventos): Collection
    {
        $tieneEventoSiembra = $eventos->contains(fn (array $e) => ($e['tipo'] ?? '') === 'siembra');
        $vistos = [];
        $siembraIncluida = false;
        $cosechaIncluida = false;

        return $eventos->filter(function (array $evento) use (&$vistos, &$siembraIncluida, &$cosechaIncluida, $tieneEventoSiembra) {
            $tipo = (string) ($evento['tipo'] ?? '');
            $titulo = mb_strtolower(trim((string) ($evento['titulo'] ?? '')));
            $fecha = $evento['fecha'] ?? null;
            $fechaKey = $fecha ? $this->parseFechaApp($fecha)->format('Y-m-d H:i') : '';

            if ($tipo === 'siembra') {
                if ($siembraIncluida) {
                    return false;
                }
                $siembraIncluida = true;
            }

            if ($tipo === 'cosecha') {
                if ($cosechaIncluida) {
                    return false;
                }
                $cosechaIncluida = true;
            }

            $clave = $tipo.'|'.$titulo.'|'.$fechaKey;
            if (isset($vistos[$clave])) {
                return false;
            }

            if ($tipo === 'estado' && $tieneEventoSiembra && str_contains($titulo, 'en crecimiento')) {
                $desc = mb_strtolower((string) ($evento['descripcion'] ?? ''));
                if (str_contains($desc, 'siembra')) {
                    return false;
                }
            }

            $vistos[$clave] = true;

            return true;
        })->values();
    }

    private function loteInsumoYaRepresentadoEnActividad($insumo, Lote $lote): bool
    {
        if (! empty($insumo->actividadid)) {
            return true;
        }

        $marcadorAuto = \App\Services\OperacionAgricolaAutomaticaService::MARK.' insumo|'.(int) $insumo->loteinsumoid;
        if ($lote->actividades->contains(fn (Actividad $a) => str_contains((string) ($a->observaciones ?? ''), $marcadorAuto))) {
            return true;
        }

        $obs = trim((string) ($insumo->observaciones ?? ''));

        return $obs !== '' && preg_match('/^Actividad\s*#\d+/i', $obs) === 1;
    }

    private function faseEventoDesdeInsumo($insumo): string
    {
        $nombreInsumo = mb_strtolower(trim($insumo->insumo->nombre ?? ''));
        $tipoInsumo = mb_strtolower(trim($insumo->insumo?->tipo?->nombre ?? ''));

        if (
            str_contains($nombreInsumo, 'fumig')
            || str_contains($nombreInsumo, 'fitosanit')
            || str_contains($nombreInsumo, 'plaga')
            || str_contains($nombreInsumo, 'insectic')
            || str_contains($nombreInsumo, 'herbic')
            || str_contains($tipoInsumo, 'pestic')
        ) {
            return 'en_crecimiento';
        }

        if (
            str_contains($nombreInsumo, 'fertiliz')
            || str_contains($nombreInsumo, 'urea')
            || str_contains($nombreInsumo, 'abono')
            || str_contains($nombreInsumo, 'npk')
            || str_contains($tipoInsumo, 'fertiliz')
        ) {
            return 'en_crecimiento';
        }

        return 'en_crecimiento';
    }

    private function descripcionHistorialActividad(Actividad $actividad): string
    {
        $detalle = json_decode((string) ($actividad->detalle_json ?? ''), true);
        $detalle = is_array($detalle) ? $detalle : [];
        $modo = (string) ($detalle['modo'] ?? '');

        if ($modo === 'riego') {
            $label = trim((string) ($detalle['riego']['label'] ?? ''));

            return $label !== '' ? 'Tipo de riego: '.$label : '';
        }

        if ($modo === 'insumos') {
            $partes = [];
            foreach ($detalle['insumos'] ?? [] as $fila) {
                if (! is_array($fila)) {
                    continue;
                }
                $nombre = trim((string) ($fila['nombre'] ?? ''));
                $cant = (float) ($fila['cantidad'] ?? 0);
                $unidad = trim((string) ($fila['unidad'] ?? 'ud'));
                if ($nombre !== '' && $cant > 0) {
                    $partes[] = $nombre.': '.number_format($cant, 2, '.', '').' '.$unidad;
                }
            }
            if ($partes !== []) {
                return 'Insumo aplicado: '.implode(' · ', $partes);
            }
        }

        return trim((string) ($actividad->descripcion ?? ''));
    }

    /**
     * @return array{url: ?string, foto_url: ?string, icono: ?string, tipo: ?string}
     */
    private function evidenciaHistorialActividad(Actividad $actividad, string $tipoNombre, Lote $lote): array
    {
        $vacio = ['url' => null, 'foto_url' => null, 'icono' => null, 'tipo' => null];
        if ($actividad->fechafin === null) {
            return $vacio;
        }

        $esRiego = str_contains($tipoNombre, 'riego') || str_contains($tipoNombre, 'regad');
        $esAplicacionInsumo = $this->esActividadConInsumoAplicado($tipoNombre, $actividad);

        $foto = EvidenciaFoto::urlDesdePath($actividad->evidencia_foto_path ?? null);
        if ($foto === null && $esAplicacionInsumo) {
            $foto = $this->evidenciaFotoHermanaActividad($actividad, $lote, $tipoNombre);
        }

        $insumo = null;
        if ($esAplicacionInsumo) {
            $insumo = $this->imagenInsumoDesdeDetalleActividad($actividad)
                ?? $this->imagenInsumoDesdeLoteInsumoPorActividad($actividad, $lote)
                ?? $this->imagenInsumoDesdeActividadAutomatica($actividad, $lote)
                ?? $this->imagenInsumoDesdeActividadHermana($actividad, $lote, $tipoNombre)
                ?? $this->imagenInsumoDesdeLoteInsumoRelacionado($actividad, $lote, $tipoNombre);
        }

        if ($insumo !== null && $foto !== null) {
            return ['url' => $insumo, 'foto_url' => $foto, 'icono' => null, 'tipo' => 'insumo_foto'];
        }
        if ($insumo !== null) {
            return ['url' => $insumo, 'foto_url' => null, 'icono' => null, 'tipo' => 'insumo'];
        }
        if ($foto !== null) {
            return ['url' => $foto, 'foto_url' => null, 'icono' => null, 'tipo' => 'foto'];
        }
        if ($esRiego) {
            return ['url' => null, 'foto_url' => null, 'icono' => 'tint', 'tipo' => 'icono'];
        }

        return $vacio;
    }

    private function esActividadConInsumoAplicado(string $tipoNombre, Actividad $actividad): bool
    {
        if ($this->esActividadAutomaticaInsumo($actividad)) {
            return true;
        }

        $detalle = json_decode((string) ($actividad->detalle_json ?? ''), true);

        return is_array($detalle) && ($detalle['modo'] ?? '') === 'insumos'
            || str_contains($tipoNombre, 'fertiliz')
            || str_contains($tipoNombre, 'fumig')
            || str_contains($tipoNombre, 'plaga')
            || str_contains($tipoNombre, 'fitosanit');
    }

    private function esActividadAutomaticaInsumo(Actividad $actividad): bool
    {
        return str_contains(
            (string) ($actividad->observaciones ?? ''),
            \App\Services\OperacionAgricolaAutomaticaService::MARK.' insumo|'
        );
    }

    private function debeOmitirActividadAutomaticaEnHistorial(Actividad $actividad, Lote $lote): bool
    {
        if (! $this->esActividadAutomaticaInsumo($actividad)) {
            return false;
        }

        $tipoId = (int) $actividad->tipoactividadid;

        return $lote->actividades->contains(function (Actividad $otra) use ($actividad, $tipoId) {
            if ((int) $otra->actividadid === (int) $actividad->actividadid) {
                return false;
            }
            if ($this->esActividadAutomaticaInsumo($otra)) {
                return false;
            }
            if ((int) $otra->tipoactividadid !== $tipoId) {
                return false;
            }

            return $otra->fechafin !== null;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $eventos
     */
    private function agregarEventoSiembraHistorial(Collection $eventos, Lote $lote): void
    {
        if (! $this->loteSiembraRegistrada($lote)) {
            return;
        }

        $siembra = $this->resolverActividadSiembraParaHistorial($lote);
        $fecha = $siembra?->fechafin
            ?? $siembra?->fechainicio
            ?? $lote->fechasiembra
            ?? $this->fechaHistorialSiembra($lote)
            ?? $this->fechaInferidaSiembraDesdeActividades($lote);

        if ($fecha === null) {
            return;
        }

        $cultivoSiembra = $this->nombreCultivoSiembrado($lote, $siembra);
        $descripcionSiembra = $siembra
            ? $this->descripcionSiembraHistorial($siembra, $lote, $cultivoSiembra)
            : $this->descripcionSiembraDesdeLote($lote);
        $evidenciaSiembra = $this->evidenciaFotoSiembraHistorial($lote, $siembra);

        $eventos->push($this->evento(
            $fecha,
            'siembra',
            'siembra',
            'Siembra de '.$cultivoSiembra,
            $descripcionSiembra,
            $siembra
                ? (app(ActividadSecuenciaService::class)->nombreEjecutor($siembra)
                    ?? trim(($siembra->usuario->nombre ?? '').' '.($siembra->usuario->apellido ?? ''))
                    ?: null)
                : ($lote->usuario
                    ? trim(($lote->usuario->nombre ?? '').' '.($lote->usuario->apellido ?? ''))
                    : null),
            'seedling',
            'success',
            $siembra?->fechafin !== null || $lote->fechasiembra !== null,
            $siembra ? (int) $siembra->actividadid : null,
            null,
            $evidenciaSiembra,
            null,
            null,
            $evidenciaSiembra ? 'foto' : null,
        ));
    }

    private function descripcionSiembraDesdeLote(Lote $lote): string
    {
        $lote->loadMissing(['insumoSemilla.unidadMedida', 'cultivo', 'loteInsumos.insumo.unidadMedida']);
        $partes = [];

        if ((float) ($lote->cantidad_semilla_planificada ?? 0) > 0) {
            $unidad = trim((string) ($lote->insumoSemilla?->unidadMedida?->abreviatura ?? 'kg'));
            $nombre = trim((string) ($lote->insumoSemilla?->nombre ?? 'semilla'));
            $partes[] = 'Material de siembra: '.number_format((float) $lote->cantidad_semilla_planificada, 2, '.', '').' '.$unidad.' de '.$nombre;
        }

        $insumoSiembra = $lote->loteInsumos->first(function ($li) {
            $nombre = mb_strtolower(trim($li->insumo->nombre ?? ''));
            $tipo = mb_strtolower(trim($li->insumo?->tipo?->nombre ?? ''));

            return str_contains($nombre, 'semilla')
                || str_contains($tipo, 'semilla')
                || str_contains($tipo, 'siembra');
        });
        if ($insumoSiembra && $partes === []) {
            $unidad = trim((string) ($insumoSiembra->insumo->unidadMedida->abreviatura ?? 'kg'));
            $partes[] = 'Material de siembra: '.number_format((float) $insumoSiembra->cantidadusada, 2, '.', '').' '.$unidad
                .' de '.trim((string) ($insumoSiembra->insumo->nombre ?? 'semilla'));
        }

        if ((float) ($lote->superficie ?? 0) > 0) {
            $partes[] = 'Superficie sembrada: '.number_format((float) $lote->superficie, 2, '.', '').' ha';
        }

        $sugerencia = CultivoSiembraCatalogo::sugerenciaDesdeLote($lote);
        if ($partes === [] && $sugerencia && ($sugerencia['tiene_dosis'] ?? false) && (float) ($sugerencia['sugerido'] ?? 0) > 0) {
            $partes[] = 'Material de siembra: '.number_format((float) $sugerencia['sugerido'], 2, '.', '').' '
                .trim((string) ($sugerencia['unidad'] ?? 'kg')).' de '
                .trim((string) ($sugerencia['insumo_nombre'] ?? 'semilla'));
        }

        return implode("\n", $partes);
    }

    private function evidenciaFotoSiembraHistorial(Lote $lote, ?Actividad $siembra): ?string
    {
        if ($siembra !== null) {
            $foto = EvidenciaFoto::urlDesdePath($siembra->evidencia_foto_path ?? null);
            if ($foto !== null) {
                return $foto;
            }
        }

        foreach ($this->actividadesSiembraDelLote($lote) as $actividadSiembra) {
            $foto = EvidenciaFoto::urlDesdePath($actividadSiembra->evidencia_foto_path ?? null);
            if ($foto !== null) {
                return $foto;
            }
        }

        return EvidenciaFoto::urlDesdeImagenUrl($lote->imagenurl ?? null);
    }

    private function evidenciaFotoHermanaActividad(Actividad $actividad, Lote $lote, string $tipoNombre): ?string
    {
        $fechaRef = $this->parseFechaApp($actividad->fechafin ?? $actividad->fechainicio);
        $tipoNorm = mb_strtolower(trim($tipoNombre));

        $hermana = $lote->actividades
            ->filter(function (Actividad $otra) use ($actividad, $tipoNorm) {
                if ((int) $otra->actividadid === (int) $actividad->actividadid) {
                    return false;
                }
                if ($otra->fechafin === null || ! filled($otra->evidencia_foto_path)) {
                    return false;
                }

                return mb_strtolower(trim($otra->tipoActividad->nombre ?? '')) === $tipoNorm;
            })
            ->sortBy(fn (Actividad $otra) => abs(
                $fechaRef->diffInSeconds($this->parseFechaApp($otra->fechafin ?? $otra->fechainicio))
            ))
            ->first();

        return $hermana
            ? EvidenciaFoto::urlDesdePath($hermana->evidencia_foto_path)
            : null;
    }

    private function imagenInsumoDesdeActividadHermana(Actividad $actividad, Lote $lote, string $tipoNombre): ?string
    {
        $tipoNorm = mb_strtolower(trim($tipoNombre));

        $hermanaAuto = $lote->actividades->first(function (Actividad $otra) use ($actividad, $tipoNorm) {
            if ((int) $otra->actividadid === (int) $actividad->actividadid) {
                return false;
            }
            if (! $this->esActividadAutomaticaInsumo($otra)) {
                return false;
            }

            return mb_strtolower(trim($otra->tipoActividad->nombre ?? '')) === $tipoNorm;
        });

        if ($hermanaAuto) {
            return $this->imagenInsumoDesdeActividadAutomatica($hermanaAuto, $lote);
        }

        $fechaRef = $this->parseFechaApp($actividad->fechafin ?? $actividad->fechainicio);
        $hermanaManual = $lote->actividades
            ->filter(function (Actividad $otra) use ($actividad, $tipoNorm) {
                if ((int) $otra->actividadid === (int) $actividad->actividadid) {
                    return false;
                }
                if ($this->esActividadAutomaticaInsumo($otra)) {
                    return false;
                }
                if (mb_strtolower(trim($otra->tipoActividad->nombre ?? '')) !== $tipoNorm) {
                    return false;
                }

                return $this->imagenInsumoDesdeDetalleActividad($otra) !== null;
            })
            ->sortBy(fn (Actividad $otra) => abs(
                $fechaRef->diffInSeconds($this->parseFechaApp($otra->fechafin ?? $otra->fechainicio))
            ))
            ->first();

        return $hermanaManual
            ? $this->imagenInsumoDesdeDetalleActividad($hermanaManual)
            : null;
    }

    private function imagenInsumoDesdeLoteInsumoPorActividad(Actividad $actividad, Lote $lote): ?string
    {
        $actividadId = (int) $actividad->actividadid;

        $loteInsumo = $lote->loteInsumos->first(function ($li) use ($actividadId) {
            if ((int) $li->actividadid === $actividadId) {
                return true;
            }

            $obs = (string) ($li->observaciones ?? '');

            return preg_match('/Actividad\s*#'.$actividadId.'\b/i', $obs) === 1;
        });

        if ($loteInsumo?->insumo) {
            return InsumoImagenCatalogo::urlPara($loteInsumo->insumo);
        }

        $marcadorAuto = \App\Services\OperacionAgricolaAutomaticaService::MARK.' insumo|';
        $hermanaAuto = $lote->actividades->first(function (Actividad $otra) use ($actividad, $marcadorAuto) {
            if ((int) $otra->actividadid === (int) $actividad->actividadid) {
                return false;
            }
            if (! $this->esActividadAutomaticaInsumo($otra)) {
                return false;
            }

            return mb_strtolower(trim($otra->tipoActividad->nombre ?? ''))
                === mb_strtolower(trim($actividad->tipoActividad->nombre ?? ''));
        });

        if ($hermanaAuto && preg_match('/'.preg_quote($marcadorAuto, '/').'(\d+)/', (string) ($hermanaAuto->observaciones ?? ''), $coincidencia)) {
            $loteInsumoAuto = $lote->loteInsumos->firstWhere('loteinsumoid', (int) $coincidencia[1]);
            if ($loteInsumoAuto?->insumo) {
                return InsumoImagenCatalogo::urlPara($loteInsumoAuto->insumo);
            }
        }

        return null;
    }

    private function imagenInsumoDesdeLoteInsumoRelacionado(Actividad $actividad, Lote $lote, string $tipoNombre): ?string
    {
        $fechaRef = $this->parseFechaApp($actividad->fechafin ?? $actividad->fechainicio);
        $tipoNorm = mb_strtolower(trim($tipoNombre));

        $loteInsumo = $this->buscarLoteInsumoPorTipoActividad($lote, $tipoNorm, $fechaRef, true)
            ?? $this->buscarLoteInsumoPorTipoActividad($lote, $tipoNorm, $fechaRef, false);

        if ($loteInsumo?->insumo) {
            return InsumoImagenCatalogo::urlPara($loteInsumo->insumo);
        }

        return null;
    }

    private function buscarLoteInsumoPorTipoActividad(Lote $lote, string $tipoNorm, Carbon $fechaRef, bool $exigirVentanaHoras): ?object
    {
        return $lote->loteInsumos
            ->filter(function ($li) use ($tipoNorm, $fechaRef, $exigirVentanaHoras) {
                if (! $this->loteInsumoCoincideTipoActividad($li, $tipoNorm)) {
                    return false;
                }
                if (! $exigirVentanaHoras) {
                    return true;
                }
                if ($li->fechauo === null) {
                    return true;
                }

                $fechaUso = $this->parseFechaApp($li->fechauo);

                return $fechaRef->isSameDay($fechaUso)
                    || abs($fechaRef->diffInHours($fechaUso)) <= 168;
            })
            ->sortBy(fn ($li) => $li->fechauo === null
                ? PHP_INT_MAX
                : abs($fechaRef->diffInSeconds($this->parseFechaApp($li->fechauo))))
            ->first();
    }

    private function loteInsumoCoincideTipoActividad(object $li, string $tipoNorm): bool
    {
        $nombre = mb_strtolower(trim($li->insumo->nombre ?? ''));
        $tipoIns = mb_strtolower(trim($li->insumo?->tipo?->nombre ?? ''));

        return match (true) {
            str_contains($tipoNorm, 'fertiliz') => str_contains($nombre, 'fertil')
                || str_contains($nombre, 'npk')
                || str_contains($nombre, 'abono')
                || str_contains($nombre, 'urea')
                || str_contains($nombre, 'guano')
                || str_contains($tipoIns, 'fertil'),
            str_contains($tipoNorm, 'plaga'),
            str_contains($tipoNorm, 'fumig'),
            str_contains($tipoNorm, 'fitosanit') => str_contains($nombre, 'fung')
                || str_contains($nombre, 'herb')
                || str_contains($nombre, 'insect')
                || str_contains($nombre, 'plaga')
                || str_contains($tipoIns, 'pestic'),
            default => false,
        };
    }

    private function imagenInsumoDesdeActividadAutomatica(Actividad $actividad, Lote $lote): ?string
    {
        $obs = (string) ($actividad->observaciones ?? '');
        if (! preg_match('/\[AUTO-OP\] insumo\|(\d+)/', $obs, $coincidencia)) {
            return null;
        }

        $loteInsumo = $lote->loteInsumos->firstWhere('loteinsumoid', (int) $coincidencia[1]);
        if ($loteInsumo?->insumo) {
            return InsumoImagenCatalogo::urlPara($loteInsumo->insumo);
        }

        return null;
    }

    private function imagenInsumoDesdeDetalleActividad(Actividad $actividad): ?string
    {
        $detalle = json_decode((string) ($actividad->detalle_json ?? ''), true);
        if (! is_array($detalle) || ($detalle['modo'] ?? '') !== 'insumos') {
            return null;
        }

        foreach ($detalle['insumos'] ?? [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $insumoModel = isset($fila['insumoid'])
                ? Insumo::query()->find((int) $fila['insumoid'])
                : null;
            if ($insumoModel) {
                return InsumoImagenCatalogo::urlPara($insumoModel);
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return array{labels: array<int, string>, data: array<int, int>}
     */
    private function chartLineaMensual(Collection $eventos): array
    {
        $porMes = $eventos
            ->filter(fn ($e) => $e['fecha'])
            ->groupBy(fn ($e) => Carbon::parse($e['fecha'])->format('Y-m'))
            ->map->count()
            ->sortKeys();

        if ($porMes->isEmpty()) {
            $mes = now()->format('Y-m');

            return [
                'labels' => [Carbon::parse($mes.'-01')->translatedFormat('M Y')],
                'data' => [0],
            ];
        }

        return [
            'labels' => $porMes->keys()->map(fn ($ym) => Carbon::parse($ym.'-01')->translatedFormat('M Y'))->values()->all(),
            'data' => $porMes->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function datosFormularioEnvioAlmacen(Lote $lote, ?Usuario $user = null): array
    {
        return app(AlmacenEnvioSelectorService::class)->datosCampoDesdeLote($lote, $user);
    }

    /** Resumen para completar actividades de campo (plagas, fertilización, riego). */
    public function resumenActividadCompletar(Actividad $actividad): array
    {
        $actividad->loadMissing(['lote.cultivo', 'lote.insumoSemilla', 'tipoActividad', 'prioridad']);
        $lote = $actividad->lote;
        $detalle = json_decode((string) ($actividad->detalle_json ?? ''), true);
        $detalle = is_array($detalle) ? $detalle : [];

        $insumos = [];
        if (($detalle['modo'] ?? '') === 'insumos') {
            foreach ($detalle['insumos'] ?? [] as $fila) {
                if (! is_array($fila)) {
                    continue;
                }
                $insumoModel = isset($fila['insumoid']) ? Insumo::query()->with('tipo')->find((int) $fila['insumoid']) : null;
                $insumos[] = [
                    'nombre' => (string) ($fila['nombre'] ?? $insumoModel?->nombre ?? 'Insumo'),
                    'cantidad' => (float) ($fila['cantidad'] ?? 0),
                    'unidad' => (string) ($fila['unidad'] ?? 'ud'),
                    'imagen' => $insumoModel ? InsumoImagenCatalogo::urlPara($insumoModel) : null,
                ];
            }
        }

        $lat = $lote && $lote->latitud !== null ? (float) $lote->latitud : null;
        $lng = $lote && $lote->longitud !== null ? (float) $lote->longitud : null;

        return [
            'tipo' => $actividad->tipoActividad?->nombre ?? 'Actividad',
            'titulo' => $actividad->descripcion ?: ($actividad->tipoActividad?->nombre ?? 'Actividad'),
            'prioridad' => $actividad->prioridad?->nombre,
            'prioridad_badge' => PrioridadCatalogo::badgeClase($actividad->prioridad?->nombre),
            'observaciones' => trim((string) ($actividad->observaciones ?? '')) ?: null,
            'riego' => ($detalle['modo'] ?? '') === 'riego'
                ? ActividadDetalleCatalogo::textoResumenDesdeDetalle($actividad->tipoActividad?->nombre, $detalle)
                : null,
            'insumos' => $insumos,
            'lote' => $lote ? [
                'nombre' => $lote->nombre,
                'ubicacion' => $lote->ubicacion_visible ?? $lote->ubicacion,
                'superficie' => $lote->superficie_etiqueta ?? ((float) $lote->superficie.' ha'),
                'codigo' => $lote->codigo_trazabilidad,
                'cultivo' => $lote->cultivo_etiqueta ?? $lote->cultivo?->nombre,
            ] : null,
            'mapa' => [
                'lat' => $lat,
                'lng' => $lng,
                'superficie_ha' => $lote ? (float) $lote->superficie : null,
                'ubicacion' => $lote?->ubicacion_visible ?? $lote?->ubicacion,
                'tiene_coordenadas' => $lat !== null && $lng !== null && abs($lat) > 0.0001 && abs($lng) > 0.0001,
            ],
        ];
    }

    /** Resumen ampliado para completar siembra: mapa, insumo total y proyección de cosecha. */
    public function resumenSiembraCompletar(Lote $lote): array
    {
        $lote->loadMissing(['insumoSemilla.unidadMedida', 'cultivo', 'catalogoTamanoConteo.tipoEmpaque']);

        $insumo = CultivoSiembraCatalogo::sugerenciaDesdeLote($lote);
        if (! $insumo && $lote->cultivo) {
            $fallback = CultivoSiembraCatalogo::sugerenciaParaLote($lote->cultivo, (float) $lote->superficie);
            $insumo = array_merge($fallback, [
                'insumo_nombre' => $lote->cultivo_etiqueta ?? $lote->cultivo->nombre,
            ]);
        }

        $proyeccionRaw = app(PlanificacionCosechaService::class)->estimacionDesdeLote($lote);
        $proyeccion = null;
        if ($proyeccionRaw && ($proyeccionRaw['ok'] ?? false)) {
            $proyeccion = [
                'kg_cosecha_estimados' => (float) ($proyeccionRaw['kg_cosecha_estimados'] ?? 0),
                'unidades_estimadas' => (int) ($proyeccionRaw['unidades_estimadas'] ?? 0),
                'empaques_estimados' => (int) ($proyeccionRaw['empaques_estimados'] ?? 0),
                'empaque_label' => $proyeccionRaw['empaque_label'] ?? 'Cajas',
                'unidades_por_caja' => $proyeccionRaw['unidades_por_caja'] ?? null,
                'calibre_nombre' => $proyeccionRaw['calibre_nombre'] ?? null,
                'hectareas' => (float) ($proyeccionRaw['hectareas'] ?? $lote->superficie),
                'resumen' => $proyeccionRaw['resumen'] ?? null,
            ];
        }

        $cantidadTotal = null;
        $cantidadUnidad = null;
        if ($insumo && ($insumo['tiene_dosis'] ?? false)) {
            $cantidadTotal = (float) ($insumo['sugerido'] ?? 0);
            $cantidadUnidad = $insumo['etiqueta_unidad'] ?? $insumo['unidad'] ?? 'kg';
        } elseif ($proyeccionRaw && ($proyeccionRaw['semilla_cantidad'] ?? null) !== null) {
            $cantidadTotal = (float) $proyeccionRaw['semilla_cantidad'];
            $cantidadUnidad = $proyeccionRaw['semilla_unidad'] ?? 'kg';
        }

        $lat = $lote->latitud !== null ? (float) $lote->latitud : null;
        $lng = $lote->longitud !== null ? (float) $lote->longitud : null;

        return [
            'insumo' => $insumo ? array_merge($insumo, [
                'cantidad_total' => $cantidadTotal,
                'cantidad_unidad' => $cantidadUnidad,
            ]) : null,
            'proyeccion' => $proyeccion,
            'mapa' => [
                'lat' => $lat,
                'lng' => $lng,
                'superficie_ha' => (float) $lote->superficie,
                'ubicacion' => $lote->ubicacion_visible,
                'tiene_coordenadas' => $lat !== null && $lng !== null && abs($lat) > 0.0001 && abs($lng) > 0.0001,
            ],
        ];
    }
}
