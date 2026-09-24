<?php

namespace App\Services;

use App\Models\Actividad;
use App\Models\AsignacionEtapaPlanta;
use App\Models\EnvioAsignacionMultiple;
use App\Models\Lote;
use App\Models\Pedido;
use App\Models\PedidoDistribucion;
use App\Models\Usuario;
use App\Models\UsuarioNotificacion;
use App\Models\RutaDistribucion;
use App\Support\EnvioAsignacionEstadoCatalogo;
use App\Support\EnvioPedidoService;
use App\Support\PedidoCatalogo;
use App\Support\PedidoDistribucionCatalogo;
use App\Support\RutaDistribucionCatalogo;
use App\Support\UsuarioRol;

class NotificacionUsuarioService
{
    public function notificar(
        Usuario|int $destinatario,
        string $tipo,
        string $titulo,
        ?string $mensaje = null,
        ?string $enlace = null,
        ?string $referenciaTipo = null,
        ?int $referenciaId = null,
    ): UsuarioNotificacion {
        $usuarioid = $destinatario instanceof Usuario ? (int) $destinatario->usuarioid : (int) $destinatario;

        return UsuarioNotificacion::create([
            'usuarioid' => $usuarioid,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'enlace' => $this->enlaceInterno($enlace),
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
            'creado_en' => now(),
        ]);
    }

    public function loteAsignado(Lote $lote, ?int $anteriorUsuarioid = null): void
    {
        if (! $lote->usuarioid) {
            return;
        }

        if ($anteriorUsuarioid && (int) $anteriorUsuarioid === (int) $lote->usuarioid) {
            return;
        }

        $this->notificar(
            (int) $lote->usuarioid,
            'lote_asignado',
            'Nuevo lote asignado',
            "Se te asignó el lote «{$lote->nombre}».",
            $this->enlaceLoteParaAgricultor($lote),
            'lote',
            (int) $lote->loteid,
        );
    }

    public function actividadAsignada(Actividad $actividad): void
    {
        if (! $actividad->usuarioid) {
            return;
        }

        $actividad->loadMissing(['lote', 'tipoActividad']);
        $loteNombre = $actividad->lote?->nombre ?? 'lote';
        $this->notificar(
            (int) $actividad->usuarioid,
            'actividad_asignada',
            'Nueva actividad asignada',
            "Actividad «{$actividad->descripcion}» en {$loteNombre}.",
            $this->enlaceActividadParaAgricultor($actividad),
            'actividad',
            (int) $actividad->actividadid,
        );
    }

    /** Reconstruye el destino correcto a partir de la referencia guardada (evita enlaces obsoletos). */
    public function resolverEnlace(UsuarioNotificacion $notificacion, ?Usuario $usuario = null): ?string
    {
        if ($notificacion->referencia_tipo === 'lote' && $notificacion->referencia_id) {
            $lote = Lote::query()->find($notificacion->referencia_id);
            if ($lote) {
                return $this->enlaceLoteParaAgricultor($lote, $usuario);
            }
        }

        if ($notificacion->referencia_tipo === 'actividad' && $notificacion->referencia_id) {
            $actividad = Actividad::with(['lote', 'tipoActividad'])->find($notificacion->referencia_id);
            if ($actividad) {
                return $this->enlaceActividadParaAgricultor($actividad, $usuario);
            }
        }

        return $notificacion->enlace ? $this->enlaceInterno($notificacion->enlace) : null;
    }

    private function enlaceLoteParaAgricultor(Lote $lote, ?Usuario $usuario = null): string
    {
        $trazabilidad = app(\App\Support\LoteTrazabilidadService::class);
        $fase = $trazabilidad->resolverFaseActual($lote);

        if (
            in_array($fase, ['preparacion', 'siembra'], true)
            && ! $trazabilidad->siembraCompletada($lote)
            && ($usuario === null || (int) $lote->usuarioid === (int) $usuario->usuarioid)
        ) {
            return route('lotes.siembra.completar', $lote, false);
        }

        return route('lotes.trazabilidad', $lote, false);
    }

    private function enlaceActividadParaAgricultor(Actividad $actividad, ?Usuario $usuario = null): string
    {
        $actividad->loadMissing(['lote', 'tipoActividad']);
        $tipo = mb_strtolower(trim($actividad->tipoActividad->nombre ?? ''));
        $esSiembra = str_contains($tipo, 'siembra');
        $pendiente = $actividad->fechafin === null;
        $esResponsable = $usuario === null || (int) $actividad->usuarioid === (int) $usuario->usuarioid;

        if ($esSiembra && $pendiente && $esResponsable && $actividad->lote) {
            return route('lotes.siembra.completar', $actividad->lote, false);
        }

        if ($actividad->lote) {
            return route('lotes.trazabilidad', $actividad->lote, false);
        }

        return route('actividades.index', [], false);
    }

    public function llegadaDestinoReportada(EnvioAsignacionMultiple $asignacion, Usuario $transportista): void
    {
        $codigo = $asignacion->externo_envio_id ?? '#'.$asignacion->envioasignacionmultipleid;
        $nombre = trim(($transportista->nombre ?? '').' '.($transportista->apellido ?? '')) ?: ($transportista->nombreusuario ?? 'Transportista');
        $planta = $asignacion->pedido?->nombre_planta;

        $mensaje = $planta
            ? "{$nombre} reportó la llegada del envío {$codigo} a {$planta}."
            : "{$nombre} reportó la llegada del envío {$codigo} a destino.";

        $admins = Usuario::query()
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereIn('role', UsuarioRol::nombresRolAdmin())
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', UsuarioRol::nombresRolAdmin()));
            })
            ->get();

        foreach ($admins as $admin) {
            $this->notificar(
                $admin,
                'envio_llegada_destino',
                'Envío recibido en planta',
                $mensaje,
                route('logistica.asignaciones.listado', ['q' => $codigo], false),
                'envio_asignacion',
                (int) $asignacion->envioasignacionmultipleid,
            );
        }
    }

    public function envioListoParaRecoger(EnvioAsignacionMultiple $asignacion): void
    {
        if (! $asignacion->transportista_usuarioid) {
            return;
        }

        $asignacion->loadMissing(['pedido.detalles']);
        $codigo = $asignacion->externo_envio_id
            ?? $asignacion->pedido?->numero_solicitud
            ?? '#'.$asignacion->envioasignacionmultipleid;
        $producto = $asignacion->pedido?->detalles?->first()?->cultivo_personalizado ?? 'Producto agrícola';
        $destino = $asignacion->pedido
            ? (EnvioPedidoService::etiquetaPlantaDestinoLista($asignacion->pedido) ?? 'planta de procesamiento')
            : 'planta de procesamiento';

        $this->notificar(
            (int) $asignacion->transportista_usuarioid,
            'envio_listo_recoger',
            'Envío listo para recoger',
            "El pedido {$codigo} ({$producto}) fue aceptado. Pulse «Empezar ruta» cuando esté listo para salir hacia {$destino}.",
            route('logistica.asignaciones.show', $asignacion, false),
            'envio_asignacion',
            (int) $asignacion->envioasignacionmultipleid,
        );
    }

    public function solicitudPedidoMinoristaMayorista(PedidoDistribucion $pedido): void
    {
        if (! PedidoDistribucionCatalogo::pendienteAprobacionMayorista($pedido)) {
            return;
        }

        $pedido->loadMissing(['detalles.insumo', 'puntoVenta.minorista', 'almacenMayoristaOrigen']);
        $almacen = $pedido->almacenMayoristaOrigen;

        if ($almacen === null
            || ! \Illuminate\Support\Facades\Schema::hasColumn('almacen', 'responsable_usuarioid')
            || ! $almacen->responsable_usuarioid) {
            return;
        }

        $det = $pedido->detalles->first();
        $minorista = $pedido->puntoVenta?->minorista;
        $nombreMinorista = $minorista
            ? trim(($minorista->nombre ?? '').' '.($minorista->apellido ?? ''))
            : 'Un minorista';
        $producto = $det?->producto_nombre ?? $det?->insumo?->nombre ?? 'producto';
        $pdv = $pedido->puntoVenta?->nombre ?? 'punto de venta';
        $codigo = $pedido->numero_solicitud ?? '#'.$pedido->pedidodistribucionid;

        $this->notificar(
            (int) $almacen->responsable_usuarioid,
            'pedido_distribucion_pendiente',
            'Nueva solicitud de minorista',
            "{$nombreMinorista} solicitó {$producto} para «{$pdv}» ({$codigo}).",
            route('punto-venta.pedidos.show', ['pedido' => $pedido, 'ctx' => 'mayorista'], false),
            'pedido_distribucion',
            (int) $pedido->pedidodistribucionid,
        );
    }

    public function envioMayoristaPendienteConfirmacionMinorista(PedidoDistribucion $pedido): void
    {
        if (! PedidoDistribucionCatalogo::pendienteConfirmacionMinorista($pedido)) {
            return;
        }

        $pedido->loadMissing(['detalles.insumo', 'puntoVenta.minorista', 'transportista']);
        $minoristaId = (int) $pedido->puntoVenta?->usuarioid;
        if ($minoristaId <= 0) {
            return;
        }

        $productos = $pedido->detalles->map(fn ($d) => $d->producto_nombre ?? $d->insumo?->nombre ?? 'producto')->take(2)->implode(', ');
        $extra = $pedido->detalles->count() > 2 ? ' (+'.($pedido->detalles->count() - 2).' más)' : '';
        $codigo = $pedido->numero_solicitud ?? '#'.$pedido->pedidodistribucionid;
        $pdv = $pedido->puntoVenta?->nombre ?? 'su punto de venta';

        $this->notificar(
            $minoristaId,
            'pedido_distribucion_confirmar',
            'Confirme el envío entrante',
            "El mayorista programó un envío ({$codigo}) hacia «{$pdv}»: {$productos}{$extra}. Confirme para habilitar la salida del transportista.",
            route('punto-venta.pedidos.show', ['pedido' => $pedido, 'ctx' => 'pdv'], false),
            'pedido_distribucion',
            (int) $pedido->pedidodistribucionid,
        );
    }

    public function envioMayoristaConfirmadoPorMinorista(PedidoDistribucion $pedido): void
    {
        $transportistaId = (int) ($pedido->transportista_usuarioid ?? $pedido->rutaDistribucion?->transportista_usuarioid);
        if ($transportistaId <= 0) {
            return;
        }

        $codigo = $pedido->numero_solicitud ?? '#'.$pedido->pedidodistribucionid;
        $pdv = $pedido->puntoVenta?->nombre ?? 'punto de venta';

        $this->notificar(
            $transportistaId,
            'envio_listo_recoger',
            'Envío confirmado por el minorista',
            "El minorista confirmó el envío {$codigo} hacia «{$pdv}». Complete el cierre operativo y pulse «Empezar ruta» cuando esté listo.",
            route('punto-venta.pedidos.show', ['pedido' => $pedido, 'ctx' => 'mayorista'], false),
            'pedido_distribucion',
            (int) $pedido->pedidodistribucionid,
        );
    }

    public function pedidoPendienteAgricola(Pedido $pedido): void
    {
        if (! PedidoCatalogo::pendienteAprobacionAgricola($pedido)) {
            return;
        }

        $pedido->loadMissing('detalles');
        $producto = $pedido->detalles->first()?->cultivo_personalizado ?? 'Producto agrícola';
        $totalKg = number_format((float) $pedido->detalles->sum('cantidad'), 2);

        $jefes = Usuario::query()
            ->where('activo', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'jefe_agricultor'))
            ->get();

        foreach ($jefes as $jefe) {
            $pedido->loadMissing('envioAsignacion');
            $enlace = $pedido->envioAsignacion
                ? route('logistica.asignaciones.show', $pedido->envioAsignacion, false)
                : route('logistica.asignaciones.listado', [], false);

            $this->notificar(
                $jefe,
                'pedido_pendiente_agricola',
                'Envío pendiente de aceptar',
                "La solicitud {$pedido->numero_solicitud} ({$producto}, {$totalKg} kg) espera aprobación de producción agrícola.",
                $enlace,
                'pedido',
                (int) $pedido->pedidoid,
            );
        }
    }

    public function etapaPlantaAsignada(AsignacionEtapaPlanta $asignacion): void
    {
        $operador = Usuario::query()->find($asignacion->operador_usuarioid);
        if (! $operador || ! UsuarioRol::esOperarioPlanta($operador)) {
            return;
        }

        $asignacion->loadMissing(['proceso', 'maquina', 'loteProduccion']);
        $proceso = $asignacion->proceso?->nombre ?? 'proceso';
        $maquina = $asignacion->maquina?->nombre ?? 'maquinaria';
        $lote = $asignacion->loteProduccion?->codigo_lote ?? 'lote';

        $this->notificar(
            (int) $operador->usuarioid,
            'etapa_planta_asignada',
            'Nueva tarea de transformación',
            "Se le asignó «{$proceso}» en {$maquina} — lote {$lote}.",
            route('tareas-planta.show', $asignacion, false),
            'asignacion_etapa_planta',
            (int) $asignacion->asignacionetapaplantaid,
        );
    }

    public function simulacionCompletadaAgricola(EnvioAsignacionMultiple $asignacion): void
    {
        $asignacion->loadMissing(['pedido', 'transportista']);
        $codigo = $asignacion->externo_envio_id ?? '#'.$asignacion->envioasignacionmultipleid;
        $chofer = trim(($asignacion->transportista?->nombre ?? '').' '.($asignacion->transportista?->apellido ?? ''));
        $planta = $asignacion->pedido
            ? (EnvioPedidoService::etiquetaPlantaDestinoLista($asignacion->pedido) ?? 'planta')
            : 'planta';
        $mensaje = "El envío {$codigo} llegó a {$planta} (simulación completada). Chofer: {$chofer}.";

        $destinatarios = Usuario::query()
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereIn('role', UsuarioRol::nombresRolAdmin())
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', [...UsuarioRol::nombresRolAdmin(), 'jefe_planta', 'jefe_agricultor']));
            })
            ->get();

        foreach ($destinatarios as $usuario) {
            $this->notificar(
                $usuario,
                'simulacion_envio_completada',
                'Envío recibido en planta',
                $mensaje,
                route('logistica.asignaciones.show', $asignacion, false),
                'envio_asignacion',
                (int) $asignacion->envioasignacionmultipleid,
            );
        }
    }

    public function simulacionCompletadaDistribucion(RutaDistribucion $ruta): void
    {
        if (RutaDistribucionCatalogo::esTrasladoPlantaMayorista($ruta)) {
            $this->trasladoPlantaCompletado($ruta);

            return;
        }

        $ruta->loadMissing(['transportista', 'almacenOrigen']);
        $chofer = trim(($ruta->transportista?->nombre ?? '').' '.($ruta->transportista?->apellido ?? ''));
        $mensaje = "La ruta {$ruta->codigo} completó todas las entregas (simulación). Chofer: {$chofer}.";

        $destinatarios = Usuario::query()
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereIn('role', UsuarioRol::nombresRolAdmin())
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', [...UsuarioRol::nombresRolAdmin(), 'jefe_planta']));
            })
            ->get();

        foreach ($destinatarios as $usuario) {
            $this->notificar(
                $usuario,
                'simulacion_ruta_completada',
                'Ruta de distribución completada',
                $mensaje,
                \App\Support\RutaDistribucionNavegacion::urlVer($ruta, $usuario),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function trasladoPlantaPendienteAprobacion(RutaDistribucion $ruta): void
    {
        if (! RutaDistribucionCatalogo::pendienteAprobacionPlanta($ruta)) {
            return;
        }

        $ruta->loadMissing(['almacenPlantaOrigen', 'almacenMayoristaDestino', 'detallesTraslado']);
        $destino = $ruta->almacenMayoristaDestino?->nombre ?? 'almacén mayorista';
        $items = $ruta->detallesTraslado->count();
        $mensaje = "Traslado {$ruta->codigo} hacia «{$destino}» con {$items} producto(s) requiere aprobación del jefe de planta antes de salir.";

        foreach ($this->destinatariosPlantaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_pendiente',
                'Traslado pendiente de aprobación',
                $mensaje,
                route('logistica.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function trasladoPlantaAceptado(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['almacenMayoristaDestino', 'creadoPor', 'almacenPlantaOrigen', 'aprobadoPor']);
        $destino = $ruta->almacenMayoristaDestino?->nombre ?? 'almacén mayorista';
        $aprobador = trim(($ruta->aprobadoPor?->nombre ?? '').' '.($ruta->aprobadoPor?->apellido ?? ''));
        $mensaje = "El traslado {$ruta->codigo} fue aprobado por planta"
            .($aprobador !== '' ? " ({$aprobador})" : '')
            .". Destino: {$destino}. El transportista puede marcar en ruta cuando salga.";

        foreach ($this->destinatariosPlantaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_aceptado',
                'Traslado aprobado por planta',
                $mensaje,
                route('logistica.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }

        foreach ($this->destinatariosMayoristaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_en_camino',
                'Traslado aprobado desde planta',
                "La planta aprobó el envío {$ruta->codigo} hacia su almacén. Recibirá una alerta cuando el vehículo complete la entrega.",
                route('almacen-mayorista.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function trasladoPlantaRechazado(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['almacenMayoristaDestino', 'almacenPlantaOrigen']);
        $motivo = trim((string) ($ruta->motivo_rechazo_mayorista ?? ''));
        $mensaje = "El traslado {$ruta->codigo} fue rechazado por el jefe de planta."
            .($motivo !== '' ? " Motivo: {$motivo}" : '');

        foreach ($this->destinatariosPlantaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_rechazado',
                'Traslado rechazado por planta',
                $mensaje,
                route('logistica.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function trasladoPlantaListoParaRecoger(RutaDistribucion $ruta): void
    {
        if (! $ruta->transportista_usuarioid) {
            return;
        }

        $ruta->loadMissing(['almacenPlantaOrigen', 'almacenMayoristaDestino']);
        $origen = $ruta->almacenPlantaOrigen?->nombre ?? 'planta';
        $destino = $ruta->almacenMayoristaDestino?->nombre ?? 'almacén mayorista';

        $this->notificar(
            (int) $ruta->transportista_usuarioid,
            'traslado_planta_listo_recoger',
            'Traslado listo para salir',
            "El traslado {$ruta->codigo} fue aprobado por planta. Acepte la solicitud e inicie el cierre operativo cuando salga de {$origen} hacia {$destino}.",
            route('logistica.traslados-planta.cierre.panel', $ruta, false),
            'ruta_distribucion',
            (int) $ruta->rutadistribucionid,
        );
    }

    public function trasladoPlantaCompletado(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['transportista', 'almacenPlantaOrigen', 'almacenMayoristaDestino', 'detallesTraslado']);
        $chofer = trim(($ruta->transportista?->nombre ?? '').' '.($ruta->transportista?->apellido ?? ''));
        $destino = $ruta->almacenMayoristaDestino?->nombre ?? 'almacén mayorista';
        $mensaje = "El traslado {$ruta->codigo} llegó a {$destino} (simulación completada). Chofer: {$chofer}.";

        foreach ($this->destinatariosMayoristaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_completado',
                'Traslado recibido en almacén',
                $mensaje,
                route('almacen-mayorista.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }

        foreach ($this->destinatariosPlantaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_completado',
                'Traslado entregado al mayorista',
                $mensaje,
                route('logistica.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function trasladoPlantaPendienteFirmaMayorista(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['almacenMayoristaDestino', 'transportista']);
        $chofer = trim(($ruta->transportista?->nombre ?? '').' '.($ruta->transportista?->apellido ?? ''));
        $mensaje = "El traslado {$ruta->codigo} está listo para su firma de recepción en almacén."
            .($chofer !== '' ? " Chofer: {$chofer}." : '');

        foreach ($this->destinatariosMayoristaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_firma_mayorista',
                'Pendiente firma de recepción',
                $mensaje,
                route('almacen-mayorista.traslados-planta.cierre.panel', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function trasladoPlantaRecibidoEnMayorista(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['almacenMayoristaDestino', 'transportista']);
        $destino = $ruta->almacenMayoristaDestino?->nombre ?? 'almacén mayorista';
        $chofer = trim(($ruta->transportista?->nombre ?? '').' '.($ruta->transportista?->apellido ?? ''));
        $mensaje = "El traslado {$ruta->codigo} fue recibido en «{$destino}»."
            .($chofer !== '' ? " Chofer: {$chofer}." : '');

        foreach ($this->destinatariosMayoristaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_recibido_mayorista',
                'Recepción completada',
                $mensaje,
                route('almacen-mayorista.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }

        foreach ($this->destinatariosPlantaTraslado($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'traslado_planta_recibido_mayorista',
                'Traslado recibido en mayorista',
                $mensaje,
                route('logistica.traslados-planta.show', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function distribucionPdvPendienteFirmaMinorista(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['pedidos.puntoVenta', 'transportista']);
        $codigo = $ruta->codigo ?? ('DIST-'.$ruta->rutadistribucionid);

        foreach ($this->destinatariosMinoristaDistribucion($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'distribucion_pdv_pendiente_firma',
                'Firma de recepción pendiente',
                "La entrega {$codigo} está lista para su firma en punto de venta.",
                route('punto-venta.rutas.cierre.panel', $ruta, false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    public function distribucionPdvRecibidaEnTienda(RutaDistribucion $ruta): void
    {
        $ruta->loadMissing(['pedidos.puntoVenta', 'transportista']);
        $codigo = $ruta->codigo ?? ('DIST-'.$ruta->rutadistribucionid);
        $pdv = $ruta->pedidos->first()?->puntoVenta?->nombre ?? 'punto de venta';
        $mensaje = "La entrega {$codigo} fue recibida en «{$pdv}».";

        foreach ($this->destinatariosMinoristaDistribucion($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'distribucion_pdv_recibida',
                'Pedido recibido en tienda',
                $mensaje,
                route('punto-venta.pedidos.index', ['ctx' => 'pdv'], false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }

        foreach ($this->destinatariosMayoristaDistribucion($ruta) as $usuario) {
            $this->notificar(
                $usuario,
                'distribucion_pdv_recibida',
                'Entrega completada en PDV',
                $mensaje,
                route('punto-venta.pedidos.index', ['ctx' => 'mayorista'], false),
                'ruta_distribucion',
                (int) $ruta->rutadistribucionid,
            );
        }
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function destinatariosMinoristaDistribucion(RutaDistribucion $ruta): \Illuminate\Support\Collection
    {
        $ruta->loadMissing('pedidos.puntoVenta.minorista');
        $ids = $ruta->pedidos
            ->map(fn ($p) => (int) ($p->puntoVenta?->usuarioid ?? 0))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Usuario::query()->whereIn('usuarioid', $ids)->get();
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function destinatariosMayoristaDistribucion(RutaDistribucion $ruta): \Illuminate\Support\Collection
    {
        $ruta->loadMissing('almacenOrigen');
        $almacen = $ruta->almacenOrigen;

        if ($almacen === null
            || ! \Illuminate\Support\Facades\Schema::hasColumn('almacen', 'responsable_usuarioid')
            || ! $almacen->responsable_usuarioid) {
            return collect();
        }

        $responsable = Usuario::query()
            ->where('usuarioid', $almacen->responsable_usuarioid)
            ->where('activo', true)
            ->first();

        return $responsable ? collect([$responsable]) : collect();
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function destinatariosMayoristaTraslado(RutaDistribucion $ruta): \Illuminate\Support\Collection
    {
        $ruta->loadMissing('almacenMayoristaDestino');
        $almacen = $ruta->almacenMayoristaDestino;

        if ($almacen !== null
            && \Illuminate\Support\Facades\Schema::hasColumn('almacen', 'responsable_usuarioid')
            && $almacen->responsable_usuarioid) {
            $responsable = Usuario::query()
                ->where('usuarioid', $almacen->responsable_usuarioid)
                ->where('activo', true)
                ->first();

            if ($responsable !== null) {
                return collect([$responsable]);
            }
        }

        return Usuario::query()
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereIn('role', ['mayorista', 'jefe_mayorista'])
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['mayorista', 'jefe_mayorista']));
            })
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function destinatariosPlantaTraslado(RutaDistribucion $ruta): \Illuminate\Support\Collection
    {
        $destinatarios = collect();

        if ($ruta->creado_por_usuarioid) {
            $ruta->loadMissing('creadoPor');
            if ($ruta->creadoPor?->activo) {
                $destinatarios->push($ruta->creadoPor);
            }
        }

        $supervisores = Usuario::query()
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereIn('role', UsuarioRol::nombresRolAdmin())
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', [...UsuarioRol::nombresRolAdmin(), 'jefe_planta']));
            })
            ->get();

        return $destinatarios->merge($supervisores)->unique('usuarioid')->values();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, UsuarioNotificacion> */
    public function noLeidasPara(int $usuarioid, int $limite = 10, ?Usuario $usuario = null)
    {
        return $this->queryNoLeidas($usuarioid, $usuario)
            ->orderByDesc('creado_en')
            ->get()
            ->reject(fn (UsuarioNotificacion $n) => $this->esObsoleta($n))
            ->take($limite)
            ->values();
    }

    public function contarNoLeidas(int $usuarioid, ?Usuario $usuario = null): int
    {
        return $this->queryNoLeidas($usuarioid, $usuario)
            ->get()
            ->reject(fn (UsuarioNotificacion $n) => $this->esObsoleta($n))
            ->count();
    }

    public function esNotificacionOperarioPlanta(UsuarioNotificacion $notificacion): bool
    {
        return $notificacion->tipo === 'etapa_planta_asignada';
    }

    public function puedeRecibirNotificacionOperarioPlanta(?Usuario $usuario): bool
    {
        return UsuarioRol::esOperarioPlanta($usuario);
    }

    private function queryNoLeidas(int $usuarioid, ?Usuario $usuario = null)
    {
        $usuario ??= Usuario::query()->find($usuarioid);

        $query = UsuarioNotificacion::query()
            ->where('usuarioid', $usuarioid)
            ->whereNull('leida_at');

        if (! $this->puedeRecibirNotificacionOperarioPlanta($usuario)) {
            $query->where('tipo', '!=', 'etapa_planta_asignada');
        }

        return $query;
    }

    private function esObsoleta(UsuarioNotificacion $notificacion): bool
    {
        if ($notificacion->tipo !== 'envio_listo_recoger') {
            return false;
        }

        if ($notificacion->referencia_tipo !== 'envio_asignacion' || ! $notificacion->referencia_id) {
            return false;
        }

        $envio = EnvioAsignacionMultiple::query()->find($notificacion->referencia_id);
        if ($envio === null) {
            return true;
        }

        if (EnvioAsignacionEstadoCatalogo::llegoADestino($envio)) {
            return true;
        }

        if ($envio->simulacion_inicio_at !== null) {
            return true;
        }

        $estado = strtolower(trim((string) ($envio->estado ?? '')));

        return ! in_array($estado, ['asignado', 'asignada', 'pendiente', 'creada'], true);
    }

    public function marcarLeida(UsuarioNotificacion $notificacion, int $usuarioid): void
    {
        if ((int) $notificacion->usuarioid !== $usuarioid) {
            return;
        }

        if ($notificacion->leida_at === null) {
            $notificacion->update(['leida_at' => now()]);
        }
    }

    /** Marca como leídas todas las notificaciones no leídas visibles para el usuario. */
    public function marcarTodasLeidas(int $usuarioid, ?Usuario $usuario = null): int
    {
        $ahora = now();

        return $this->queryNoLeidas($usuarioid, $usuario)
            ->update(['leida_at' => $ahora]);
    }

    /** Elimina alertas de tarea de transformación cuando la asignación ya fue completada. */
    public function descartarEtapaPlantaAsignada(int $asignacionId): void
    {
        UsuarioNotificacion::query()
            ->where('tipo', 'etapa_planta_asignada')
            ->where('referencia_tipo', 'asignacion_etapa_planta')
            ->where('referencia_id', $asignacionId)
            ->delete();
    }

    /** Marca como leídas las alertas de actividad asignada cuando ya fue completada. */
    public function descartarActividadAsignada(int $actividadId): void
    {
        UsuarioNotificacion::query()
            ->where('tipo', 'actividad_asignada')
            ->where('referencia_tipo', 'actividad')
            ->where('referencia_id', $actividadId)
            ->whereNull('leida_at')
            ->update(['leida_at' => now()]);
    }

    private function enlaceInterno(?string $enlace): ?string
    {
        if ($enlace === null || $enlace === '') {
            return null;
        }

        if (str_starts_with($enlace, '/')) {
            return $enlace;
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base !== '' && str_starts_with($enlace, $base)) {
            $relativo = substr($enlace, strlen($base));

            return $relativo === '' ? '/' : $relativo;
        }

        return $enlace;
    }
}
