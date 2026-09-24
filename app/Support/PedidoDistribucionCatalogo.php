<?php

namespace App\Support;

use App\Models\PedidoDistribucion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PedidoDistribucionCatalogo
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_CONFIRMADO = 'confirmado';

    public const ESTADO_EN_TRANSITO = 'en_transito';

    public const ESTADO_RECIBIDO = 'recibido';

    public const ESTADO_RECHAZADO = 'rechazado';

    public const ESTADO_CANCELADO = 'cancelado';

    public const TIPO_SOLICITUD_CATALOGO = 'catalogo';

    public const TIPO_SOLICITUD_STOCK = 'stock';

    public const TIPO_SOLICITUD_CUSTOM = 'custom';

    /** Canal de origen (MAY-13): el minorista lo creó en la plataforma. */
    public const CANAL_SISTEMA = 'sistema';

    /** @var array<string, string> Canales externos: el mayorista registra el pedido a mano. */
    public const CANALES_EXTERNOS = [
        'whatsapp' => 'WhatsApp',
        'telefono' => 'Teléfono',
        'presencial' => 'Presencial',
        'otro' => 'Otro canal',
    ];

    public static function esPedidoExterno(PedidoDistribucion $pedido): bool
    {
        return array_key_exists((string) ($pedido->canal_origen ?? self::CANAL_SISTEMA), self::CANALES_EXTERNOS);
    }

    public static function etiquetaCanal(?string $canal): string
    {
        return self::CANALES_EXTERNOS[$canal ?? ''] ?? 'Plataforma (minorista)';
    }

    /**
     * El minorista edita/cancela solo mientras su solicitud está pendiente (MIN-04): una vez
     * confirmada el contenido comercial queda congelado; los cambios pasan por «volver a revisión».
     */
    public static function puedeEditarSolicitudMinorista(PedidoDistribucion $pedido): bool
    {
        return self::pendienteAprobacionMayorista($pedido);
    }

    public static function generarNumeroSolicitud(): string
    {
        $fecha = Carbon::now()->format('Ymd');
        $ultimo = PedidoDistribucion::query()
            ->where('numero_solicitud', 'like', "PDV-{$fecha}-%")
            ->orderByDesc('pedidodistribucionid')
            ->value('numero_solicitud');

        $secuencia = 1;
        if ($ultimo && preg_match('/-(\d+)$/', $ultimo, $m)) {
            $secuencia = ((int) $m[1]) + 1;
        }

        return sprintf('PDV-%s-%04d', $fecha, $secuencia);
    }

    public static function pendienteAprobacionMayorista(PedidoDistribucion $pedido): bool
    {
        return $pedido->estado === self::ESTADO_PENDIENTE
            && ! (bool) $pedido->envio_iniciado_mayorista;
    }

    public static function envioIniciadoPorMayorista(PedidoDistribucion $pedido): bool
    {
        return (bool) $pedido->envio_iniciado_mayorista;
    }

    public static function pendienteConfirmacionMinorista(PedidoDistribucion $pedido): bool
    {
        return self::envioIniciadoPorMayorista($pedido)
            && $pedido->fecha_confirmacion_minorista === null
            && in_array($pedido->estado, [self::ESTADO_CONFIRMADO, self::ESTADO_EN_TRANSITO], true);
    }

    public static function pendienteAprobacionPlanta(PedidoDistribucion $pedido): bool
    {
        return self::pendienteAprobacionMayorista($pedido);
    }

    /** @deprecated Use pendienteAprobacionMayorista() */
    public static function pendienteAprobacionMinorista(PedidoDistribucion $pedido): bool
    {
        return self::pendienteAprobacionMayorista($pedido);
    }

    public static function puedeAceptarMayorista(PedidoDistribucion $pedido): bool
    {
        return self::pendienteAprobacionMayorista($pedido);
    }

    public static function puedeAceptarPlanta(PedidoDistribucion $pedido): bool
    {
        return self::puedeAceptarMayorista($pedido);
    }

    public static function puedeMarcarEnviado(PedidoDistribucion $pedido): bool
    {
        return $pedido->estado === self::ESTADO_CONFIRMADO;
    }

    /** Pedido aceptado y aún sin ruta ni transportista asignado. */
    public static function puedeDesignarTransportista(PedidoDistribucion $pedido): bool
    {
        if ($pedido->estado !== self::ESTADO_CONFIRMADO || $pedido->rutadistribucionid !== null) {
            return false;
        }

        if ($pedido->requiere_coordinacion_planta && ! $pedido->coordinacion_planta_resuelta) {
            return false;
        }

        return $pedido->almacen_mayorista_origenid !== null;
    }

    /** @deprecated Use puedeDesignarTransportista() */
    public static function puedeDespacharDirecto(PedidoDistribucion $pedido): bool
    {
        return self::puedeDesignarTransportista($pedido);
    }

    public static function tieneTransportistaDesignado(PedidoDistribucion $pedido): bool
    {
        return $pedido->estado === self::ESTADO_CONFIRMADO
            && ($pedido->rutadistribucionid !== null || $pedido->transportista_usuarioid !== null);
    }

    /** Mientras no haya salido en ruta ni cerrado el pedido. */
    public static function puedeEditarFlujoAntesDeRuta(PedidoDistribucion $pedido): bool
    {
        return in_array($pedido->estado, [self::ESTADO_PENDIENTE, self::ESTADO_CONFIRMADO], true);
    }

    public static function puedeReabrirRevision(PedidoDistribucion $pedido): bool
    {
        return $pedido->estado === self::ESTADO_CONFIRMADO
            && self::puedeEditarFlujoAntesDeRuta($pedido);
    }

    public static function puedeConfirmarRecepcion(PedidoDistribucion $pedido): bool
    {
        return $pedido->estado === self::ESTADO_EN_TRANSITO;
    }

    /** Paso visible del wizard (1–5) según estado real del pedido. */
    public static function pasoActualFlujo(PedidoDistribucion $pedido): int
    {
        if (self::pendienteConfirmacionMinorista($pedido)) {
            return 3;
        }

        if (self::pendienteAprobacionMayorista($pedido)) {
            return 2;
        }

        if (self::puedeDesignarTransportista($pedido)) {
            return 3;
        }

        if ($pedido->estado === self::ESTADO_EN_TRANSITO) {
            return 4;
        }

        if ($pedido->estado === self::ESTADO_RECIBIDO) {
            return 5;
        }

        if (self::tieneTransportistaDesignado($pedido) && $pedido->estado === self::ESTADO_CONFIRMADO) {
            return 4;
        }

        return 3;
    }

    /**
     * Pasos del stepper en detalle de pedido (mayorista → PDV vs solicitud minorista).
     *
     * @return list<array{num: int, icon: string, label: string, hecho: bool, activo: bool, navegable: bool}>
     */
    public static function pasosFlujoUi(
        PedidoDistribucion $pedido,
        bool $esMinoristaDueño,
        bool $puedeEditarFlujo,
        bool $transportistaDesignado,
    ): array {
        $envioMayorista = self::envioIniciadoPorMayorista($pedido);
        $pendienteMayorista = self::pendienteAprobacionMayorista($pedido);
        $pendienteConfMinorista = self::pendienteConfirmacionMinorista($pedido);
        $estado = $pedido->estado;

        $paso3Label = $envioMayorista
            ? ($esMinoristaDueño ? 'Confirmar envío' : 'Confirmación minorista')
            : 'Designar transportista';
        $paso3Icon = $envioMayorista ? 'fa-check-circle' : 'fa-user-tie';

        if ($envioMayorista) {
            $paso1Hecho = false;
            $paso2Hecho = false;
        } else {
            $paso1Hecho = true;
            $paso2Hecho = in_array($estado, [self::ESTADO_CONFIRMADO, self::ESTADO_EN_TRANSITO, self::ESTADO_RECIBIDO], true);
        }

        $paso3Hecho = $envioMayorista
            ? ! $pendienteConfMinorista
            : (($transportistaDesignado && ! $pendienteConfMinorista)
                || in_array($estado, [self::ESTADO_EN_TRANSITO, self::ESTADO_RECIBIDO], true));

        $paso3Activo = $pendienteConfMinorista
            || (! $envioMayorista && $estado === self::ESTADO_CONFIRMADO && ! $transportistaDesignado);

        $paso4Activo = $transportistaDesignado
            && $estado === self::ESTADO_CONFIRMADO
            && ! $pendienteConfMinorista;

        return [
            [
                'num' => 1,
                'icon' => 'fa-paper-plane',
                'label' => $envioMayorista ? 'Envío mayorista' : 'Solicitud minorista',
                'hecho' => $paso1Hecho,
                'activo' => ! $envioMayorista && $pendienteMayorista,
                'navegable' => $puedeEditarFlujo && ! $envioMayorista,
            ],
            [
                'num' => 2,
                'icon' => 'fa-warehouse',
                'label' => $envioMayorista ? 'Programación mayorista' : 'Revisión mayorista',
                'hecho' => $paso2Hecho,
                'activo' => ! $envioMayorista && $pendienteMayorista,
                'navegable' => $puedeEditarFlujo && ! $envioMayorista,
            ],
            [
                'num' => 3,
                'icon' => $paso3Icon,
                'label' => $paso3Label,
                'hecho' => $paso3Hecho,
                'activo' => $paso3Activo,
                'navegable' => $puedeEditarFlujo || ($esMinoristaDueño && $pendienteConfMinorista),
            ],
            [
                'num' => 4,
                'icon' => 'fa-shipping-fast',
                'label' => 'En ruta',
                'hecho' => in_array($estado, [self::ESTADO_EN_TRANSITO, self::ESTADO_RECIBIDO], true),
                'activo' => $paso4Activo,
                'navegable' => $transportistaDesignado
                    && in_array($estado, [self::ESTADO_CONFIRMADO, self::ESTADO_EN_TRANSITO], true)
                    && ! $pendienteConfMinorista,
            ],
            [
                'num' => 5,
                'icon' => 'fa-dolly',
                'label' => 'Recepción PDV',
                'hecho' => $estado === self::ESTADO_RECIBIDO,
                'activo' => $estado === self::ESTADO_EN_TRANSITO,
                'navegable' => $estado === self::ESTADO_EN_TRANSITO,
            ],
        ];
    }

    /** Panel de capacidad de vehículo: solo quien designa transportista (mayorista). */
    public static function mostrarPanelCapacidadVehiculo(
        PedidoDistribucion $pedido,
        bool $puedeGestionarMayorista,
        bool $puedeDesignarTransportista,
        bool $transportistaDesignado,
    ): bool {
        if (! $puedeGestionarMayorista) {
            return false;
        }

        if (self::pendienteConfirmacionMinorista($pedido)) {
            return false;
        }

        return $puedeDesignarTransportista
            || ($transportistaDesignado && $pedido->estado === self::ESTADO_CONFIRMADO);
    }

    /** @return array<string, string> Etiquetas legibles para filtros (menos opciones técnicas). */
    public static function etiquetasFiltroEstado(): array
    {
        return [
            'revision' => 'En revisión',
            'preparacion' => 'Preparando envío',
            'camino' => 'En ruta',
            'recibido' => 'Recibido',
            'cerrado' => 'Cerrado',
        ];
    }

    /** @return list<string> */
    public static function estadosDeGrupoFiltro(string $grupo): array
    {
        return match ($grupo) {
            'revision' => [self::ESTADO_PENDIENTE],
            'preparacion' => [self::ESTADO_CONFIRMADO],
            'camino' => [self::ESTADO_EN_TRANSITO],
            'recibido' => [self::ESTADO_RECIBIDO],
            'cerrado' => [self::ESTADO_RECHAZADO, self::ESTADO_CANCELADO],
            default => [],
        };
    }

    public static function aplicarFiltroGrupoEstado(Builder $query, string $grupo): Builder
    {
        if ($grupo === 'camino') {
            return $query
                ->where('estado', self::ESTADO_EN_TRANSITO)
                ->whereHas('rutaDistribucion', fn (Builder $r) => $r
                    ->whereNotNull('simulacion_inicio_at')
                    ->where('estado', RutaDistribucionCatalogo::ESTADO_EN_RUTA));
        }

        if ($grupo === 'preparacion') {
            return $query->where(function (Builder $w) {
                $w->where('estado', self::ESTADO_CONFIRMADO)
                    ->orWhere(function (Builder $w2) {
                        $w2->where('estado', self::ESTADO_EN_TRANSITO)
                            ->where(function (Builder $w3) {
                                $w3->whereDoesntHave('rutaDistribucion')
                                    ->orWhereHas('rutaDistribucion', fn (Builder $r) => $r
                                        ->whereNull('simulacion_inicio_at')
                                        ->orWhere('estado', '!=', RutaDistribucionCatalogo::ESTADO_EN_RUTA));
                            });
                    });
            });
        }

        $estados = self::estadosDeGrupoFiltro($grupo);

        return $estados !== [] ? $query->whereIn('estado', $estados) : $query;
    }

    public static function puedeSolicitarProduccionPlanta(PedidoDistribucion $pedido): bool
    {
        return $pedido->estado === self::ESTADO_CONFIRMADO
            && $pedido->requiere_coordinacion_planta
            && ! $pedido->coordinacion_planta_resuelta;
    }

    public static function etiquetaEntregaDeseada(PedidoDistribucion $pedido): ?string
    {
        if ($pedido->fecha_entrega_deseada === null) {
            return null;
        }

        $fecha = $pedido->fecha_entrega_deseada->format('d/m/Y');
        $hora = $pedido->hora_entrega_deseada
            ? substr((string) $pedido->hora_entrega_deseada, 0, 5)
            : null;

        return $hora ? "{$fecha} · {$hora}" : $fecha;
    }

    public static function estaEnRutaTiempoReal(PedidoDistribucion $pedido): bool
    {
        if ($pedido->estado !== self::ESTADO_EN_TRANSITO || $pedido->rutadistribucionid === null) {
            return false;
        }

        $ruta = $pedido->relationLoaded('rutaDistribucion')
            ? $pedido->rutaDistribucion
            : $pedido->rutaDistribucion()->first();

        return $ruta !== null && SimulacionRutaCatalogo::simulacionActivaDistribucion($ruta);
    }

    /** @return array<string, string> */
    public static function etiquetasEstado(): array
    {
        return [
            self::ESTADO_PENDIENTE => 'En revisión',
            self::ESTADO_CONFIRMADO => 'Preparando envío',
            self::ESTADO_EN_TRANSITO => 'En ruta',
            self::ESTADO_RECIBIDO => 'Recibido',
            self::ESTADO_RECHAZADO => 'Rechazado',
            self::ESTADO_CANCELADO => 'Cancelado',
        ];
    }

    public static function etiquetaEstado(?string $estado): string
    {
        return self::etiquetasEstado()[$estado ?? ''] ?? ucfirst(str_replace('_', ' ', (string) $estado));
    }

    /** @return array<string, string> */
    public static function opcionesFiltroEstado(): array
    {
        return self::etiquetasEstado();
    }

    public static function badgeBootstrapClase(array $badge): string
    {
        return match ($badge['clase'] ?? '') {
            'revision', 'asignado' => 'warning',
            'preparacion' => 'info',
            'ruta' => 'primary',
            'recibido' => 'success',
            'rechazado' => 'danger',
            'cancelado', 'neutral' => 'secondary',
            default => 'secondary',
        };
    }

    public static function esperaConfirmacionTransportista(PedidoDistribucion $pedido): bool
    {
        if ($pedido->estado !== self::ESTADO_CONFIRMADO || ! self::tieneTransportistaDesignado($pedido)) {
            return false;
        }

        if (self::pendienteConfirmacionMinorista($pedido)) {
            return false;
        }

        $ruta = $pedido->relationLoaded('rutaDistribucion')
            ? $pedido->rutaDistribucion
            : null;

        if ($ruta !== null) {
            return $ruta->estado === \App\Support\RutaDistribucionCatalogo::ESTADO_PLANIFICADA
                && $ruta->simulacion_inicio_at === null;
        }

        return $pedido->rutadistribucionid !== null || $pedido->transportista_usuarioid !== null;
    }

    /** @return array{clase: string, etiqueta: string, icono: string} */
    public static function badgeEstado(PedidoDistribucion $pedido): array
    {
        if (self::pendienteConfirmacionMinorista($pedido)) {
            return [
                'clase' => 'revision',
                'etiqueta' => 'Esperando confirmación del minorista',
                'icono' => 'fa-user-clock',
            ];
        }

        if (self::esperaConfirmacionTransportista($pedido)) {
            return [
                'clase' => 'asignado',
                'etiqueta' => 'Esperando confirmación del transportista',
                'icono' => 'fa-truck-loading',
            ];
        }

        if ($pedido->estado === self::ESTADO_PENDIENTE && $pedido->espera_stock) {
            $det = $pedido->relationLoaded('detalles')
                ? $pedido->detalles->first()
                : null;
            $presNombre = $det?->presentacion?->nombre
                ?? (is_string($det?->producto_nombre) && str_contains($det->producto_nombre, '·')
                    ? trim(explode('·', $det->producto_nombre, 2)[1] ?? '')
                    : null);
            $sufijo = $presNombre ? ' — '.$presNombre : '';

            return [
                'clase' => 'revision',
                'etiqueta' => 'Esperando stock'.$sufijo,
                'icono' => 'fa-box-open',
            ];
        }

        return match ($pedido->estado) {
            self::ESTADO_PENDIENTE => ['clase' => 'revision', 'etiqueta' => 'En revisión', 'icono' => 'fa-hourglass-half'],
            self::ESTADO_CONFIRMADO => self::tieneTransportistaDesignado($pedido)
                ? ['clase' => 'preparacion', 'etiqueta' => 'Preparando salida', 'icono' => 'fa-truck-loading']
                : ['clase' => 'preparacion', 'etiqueta' => 'Preparando envío', 'icono' => 'fa-box-open'],
            self::ESTADO_EN_TRANSITO => self::estaEnRutaTiempoReal($pedido)
                ? ['clase' => 'ruta', 'etiqueta' => 'En ruta', 'icono' => 'fa-shipping-fast']
                : ['clase' => 'asignado', 'etiqueta' => 'Listo para salida', 'icono' => 'fa-truck-loading'],
            self::ESTADO_RECIBIDO => ['clase' => 'recibido', 'etiqueta' => 'Recibido', 'icono' => 'fa-check-circle'],
            self::ESTADO_RECHAZADO => ['clase' => 'rechazado', 'etiqueta' => 'Rechazado', 'icono' => 'fa-ban'],
            self::ESTADO_CANCELADO => ['clase' => 'cancelado', 'etiqueta' => 'Cancelado', 'icono' => 'fa-times-circle'],
            default => ['clase' => 'neutral', 'etiqueta' => self::etiquetaEstado($pedido->estado), 'icono' => 'fa-info-circle'],
        };
    }
}
