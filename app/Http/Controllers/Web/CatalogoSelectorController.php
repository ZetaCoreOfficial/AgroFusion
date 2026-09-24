<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ActorAbastecimiento;
use App\Models\Almacen;
use App\Models\Cultivo;
use App\Models\Insumo;
use App\Models\InsumoPresentacion;
use App\Models\Lote;
use App\Models\MaquinaPlanta;
use App\Models\ProcesoPlanta;
use App\Models\Pedido;
use App\Models\PlantillaTransformacion;
use App\Models\Produccion;
use App\Models\PuntoVenta;
use App\Models\RutaMultiEntrega;
use App\Models\Venta;
use App\Models\PerfilTransportista;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Support\AlmacenAmbito;
use App\Services\ActividadInsumoService;
use App\Services\CatalogoProductoPlantaPdvService;
use App\Services\DisponibilidadMayoristaPdvService;
use App\Services\InventarioPresentacionService;
use App\Services\ProductoPlantaInventarioService;
use App\Services\PuntoVentaAlmacenService;
use App\Services\TransporteCapacidadService;
use App\Services\VehiculoDimensionesService;
use InvalidArgumentException;
use App\Support\ActividadDetalleCatalogo;
use App\Support\BusquedaTexto;
use App\Support\CultivoSiembraCatalogo;
use App\Support\InsumoCatalogo;
use App\Support\PedidoCatalogo;
use App\Support\ProcesoPlantaCatalogo;
use App\Support\PuntoVentaAccess;
use App\Services\VehiculoFlotaEstadoService;
use App\Support\EstadoVehiculoCatalogo;
use App\Support\LicenciaConduccionCatalogo;
use App\Support\TransportistaFlotaCatalogo;
use App\Support\VehiculoTransporteCatalogo;
use Illuminate\Support\Facades\Schema;
use App\Support\UbicacionGpsParser;
use App\Support\UsuarioRol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogoSelectorController extends Controller
{
    public function usuarios(Request $request): JsonResponse
    {
        if ($request->boolean('operarios_planta')) {
            $query = UsuarioRol::queryOperariosPlanta();
        } else {
            $query = Usuario::query()->where('activo', true);
        }
        $esTransportista = $request->filled('roles') && str_contains((string) $request->roles, 'transportista');
        $incluirSelfActor = $request->boolean('incluir_self_actor')
            && $request->user()
            && UsuarioRol::esAdminGlobal($request->user());

        if ($incluirSelfActor) {
            $actorId = (int) $request->user()->usuarioid;
            $query->where(function (Builder $q) use ($actorId) {
                $q->where('usuarioid', $actorId)
                    ->orWhere(function (Builder $inner) {
                        $inner->whereIn('role', ['agricultor'])
                            ->orWhereHas('roles', fn (Builder $r) => $r->where('name', 'agricultor'));
                    });
            });
        } elseif ($request->filled('roles')) {
            $roles = array_filter(array_map('trim', explode(',', (string) $request->roles)));
            if ($roles !== []) {
                $query->where(function (Builder $q) use ($roles) {
                    $q->whereIn('role', $roles)
                        ->orWhereHas('roles', fn (Builder $r) => $r->whereIn('name', $roles));
                });
            }
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // El administrador supervisa el sistema; no es responsable operativo de parcelas.
        if (! $incluirSelfActor && ! $request->boolean('incluir_admin')) {
            $query->whereNotIn('role', UsuarioRol::nombresRolAdmin());
        }

        if ($request->boolean('solo_empleados_equipo') && $request->filled('supervisor_usuarioid')) {
            $query->where('supervisor_usuarioid', (int) $request->supervisor_usuarioid);
        } elseif ($request->filled('supervisor_usuarioid')) {
            $query->where('supervisor_usuarioid', (int) $request->supervisor_usuarioid);
        } elseif (
            UsuarioRol::esJefeAgricultor($request->user())
            && ! UsuarioRol::esAdminGlobal($request->user())
            && $request->filled('roles')
            && str_contains((string) $request->roles, 'agricultor')
            && ! $request->boolean('solo_empleados_equipo')
        ) {
            $jefeId = (int) $request->user()->usuarioid;
            $query->where(function ($q) use ($jefeId) {
                $q->where('supervisor_usuarioid', $jefeId)
                    ->orWhere('usuarioid', $jefeId);
            });
        }

        if ($request->boolean('excluir_jefes_agricolas')) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'jefe_agricultor'));
        }

        if ($esTransportista) {
            $query->with('perfilTransportista.vehiculo');

            // Pool por ámbito (TRA-04, MAY-17): sin perfil no hay ámbito implícito «agrícola»; se excluyen
            // conductores inactivos, no disponibles, sin rol Spatie o con un viaje en curso.
            if ($request->filled('ambito_flota') && in_array($request->string('ambito_flota')->toString(), TransportistaFlotaCatalogo::valores(), true)) {
                \App\Support\TransportistaPool::scopePool($query, $request->string('ambito_flota')->toString());
            }

            if ($request->string('con_vehiculo')->toString() === '1') {
                $query->whereHas('perfilTransportista', fn ($p) => $p->whereNotNull('vehiculoid'));
            } elseif ($request->string('con_vehiculo')->toString() === '0') {
                $query->where(function (Builder $q) {
                    $q->whereDoesntHave('perfilTransportista')
                        ->orWhereHas('perfilTransportista', fn ($p) => $p->whereNull('vehiculoid'));
                });
            }
        }

        $this->aplicarBusquedaTransportista($query, (string) $request->q, $esTransportista);

        return $this->respuestaPaginada($request, $query->orderBy('nombre')->orderBy('apellido'), function (Usuario $u) use ($esTransportista) {
            $placa = $esTransportista ? ($u->perfilTransportista?->vehiculo?->placa) : null;
            $vehiculoId = $esTransportista ? ($u->perfilTransportista?->vehiculoid) : null;
            $metaPartes = array_filter([
                $u->email,
                $placa ? 'Placa: '.$placa : null,
            ]);

            return [
                'id' => $u->usuarioid,
                'label' => trim($u->nombre.' '.($u->apellido ?? '')),
                'meta' => $metaPartes !== [] ? implode(' · ', $metaPartes) : ucfirst((string) ($u->role ?? '')),
                'extra' => [
                    'placa' => $placa ?? '',
                    'email' => $u->email ?? '',
                    'vehiculoid' => $vehiculoId,
                ],
            ];
        });
    }

    public function vehiculos(Request $request): JsonResponse
    {
        $query = Vehiculo::query()
            ->with(['tipoVehiculo', 'estadoVehiculo'])
            ->where('activo', true);

        $excluirEstados = array_filter([
            EstadoVehiculoCatalogo::idMantenimiento(),
            EstadoVehiculoCatalogo::idPorNombre(EstadoVehiculoCatalogo::BAJA),
        ]);
        if ($excluirEstados !== []) {
            $query->where(function (Builder $w) use ($excluirEstados) {
                $w->whereNotIn('estadovehiculoid', $excluirEstados)
                    ->orWhereNull('estadovehiculoid');
            });
        }

        $mapaRuta = app(VehiculoFlotaEstadoService::class)->mapaEnRuta();
        $placasRuta = array_keys($mapaRuta['placas']);
        $idsRuta = array_keys($mapaRuta['ids']);
        if ($placasRuta !== []) {
            $query->whereNotIn(DB::raw('UPPER(placa)'), $placasRuta);
        }
        if ($idsRuta !== []) {
            $query->whereNotIn('vehiculoid', $idsRuta);
        }

        if ($request->filled('ambito_flota') && in_array($request->string('ambito_flota')->toString(), TransportistaFlotaCatalogo::valores(), true)) {
            $query->where('ambito_flota', $request->string('ambito_flota')->toString());
        }

        $vehiculoSugeridoId = null;

        if ($request->boolean('solo_transportista') && $request->filled('transportista_usuarioid')) {
            $transportistaId = (int) $request->transportista_usuarioid;
            $perfil = PerfilTransportista::query()
                ->where('usuarioid', $transportistaId)
                ->first();
            $ambito = $perfil?->ambito_flota ?? TransportistaFlotaCatalogo::AGRICOLA;
            $vehiculoSugeridoId = $perfil?->vehiculoid;

            if (Schema::hasColumn('vehiculo', 'ambito_flota')) {
                $query->where('ambito_flota', $ambito);
            } else {
                $vehiculoIds = PerfilTransportista::query()
                    ->where('usuarioid', $transportistaId)
                    ->whereNotNull('vehiculoid')
                    ->pluck('vehiculoid');
                $query->whereIn('vehiculoid', $vehiculoIds->isNotEmpty() ? $vehiculoIds : [-1]);
            }

            $transportista = Usuario::query()->find($transportistaId);
            if ($transportista) {
                $licencias = app(TransporteCapacidadService::class)->licenciasTransportista($transportista);
                $codigos = LicenciaConduccionCatalogo::codigosAutorizadosMultiples($licencias);
                if ($codigos === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('tipoVehiculo', function (Builder $t) use ($codigos) {
                        $t->whereIn('licencia_requerida', $codigos)
                            ->orWhereNull('licencia_requerida');
                    });
                }
            }
        }

        if ($vehiculoSugeridoId) {
            $query->orderByRaw('CASE WHEN vehiculoid = ? THEN 0 ELSE 1 END', [$vehiculoSugeridoId]);
        }

        $q = trim((string) $request->q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('placa', 'like', $like)
                    ->orWhere('marca', 'like', $like)
                    ->orWhere('modelo', 'like', $like)
                    ->orWhere('color', 'like', $like);
            });
        }

        $capacidadSvc = app(TransporteCapacidadService::class);

        return $this->respuestaPaginada($request, $query->orderBy('placa'), function (Vehiculo $v) use ($capacidadSvc, $vehiculoSugeridoId) {
            $nombre = trim(collect([$v->marca, $v->modelo])->filter()->implode(' '));

            $categoria = Schema::hasColumn('vehiculo', 'ambito_flota')
                ? TransportistaFlotaCatalogo::categoriaCorta($v->ambito_flota)
                : null;

            $capTexto = $capacidadSvc->etiquetaCapacidad($v);
            $tiposTransporte = $v->tiposTransporteEfectivos();
            $codigoPrincipal = $tiposTransporte->first()?->codigo;
            $metaTransporte = VehiculoTransporteCatalogo::metaUi($codigoPrincipal);
            $iconoTransporte = match ($metaTransporte['nombre']) {
                'Refrigerado' => 'fa-snowflake',
                'Multitemperatura' => 'fa-layer-group',
                'Isotérmico' => 'fa-thermometer-half',
                default => 'fa-box',
            };

            return [
                'id' => $v->vehiculoid,
                'label' => $v->placa,
                'sugerido' => $vehiculoSugeridoId && (int) $v->vehiculoid === (int) $vehiculoSugeridoId,
                'meta' => trim(collect([
                    $nombre !== '' ? $nombre : null,
                    $v->tipoVehiculo?->nombre ?? 'Vehículo',
                    $capTexto,
                    $metaTransporte['nombre'],
                    $categoria ? 'Flota '.$categoria : null,
                ])->filter()->implode(' · ')),
                'meta_lineas' => array_values(array_filter([
                    [
                        'icon' => 'fa-truck',
                        'text' => trim(collect([$nombre !== '' ? $nombre : null, $v->tipoVehiculo?->nombre])->filter()->implode(' · ')),
                    ],
                    ['icon' => 'fa-weight-hanging', 'text' => $capTexto],
                    ['icon' => $iconoTransporte, 'text' => $metaTransporte['nombre']],
                    $categoria ? ['icon' => 'fa-warehouse', 'text' => 'Flota '.$categoria] : null,
                ])),
                'extra' => array_merge($capacidadSvc->capacidadEfectiva($v), [
                    'tipos_transporte' => $tiposTransporte->pluck('codigo')->filter()->values()->all(),
                    'transporte_principal' => $metaTransporte['nombre'],
                    'transporte_codigo' => $codigoPrincipal,
                ]),
            ];
        });
    }

    public function vehiculoPreviewCarga(Request $request, Vehiculo $vehiculo): JsonResponse
    {
        $request->validate([
            'peso_kg' => 'nullable|numeric|min:0',
            'volumen_m3' => 'nullable|numeric|min:0',
        ]);

        $capSvc = app(TransporteCapacidadService::class);
        $dimSvc = app(VehiculoDimensionesService::class);
        $vehiculo->loadMissing('tipoVehiculo');

        $pesoKg = max(0, (float) $request->input('peso_kg', 0));
        $volumenM3 = $request->filled('volumen_m3')
            ? max(0, (float) $request->input('volumen_m3'))
            : null;
        if (($volumenM3 === null || $volumenM3 <= 0) && $pesoKg > 0) {
            $volumenM3 = $capSvc->volumenDesdePeso($pesoKg);
        }

        $cap = $capSvc->capacidadEfectiva($vehiculo);
        $dims = $dimSvc->dimensionesEfectivas($vehiculo);
        $m3Util = $dims['m3_util'] > 0 ? (float) $dims['m3_util'] : (float) $cap['m3'];

        $pctKg = $cap['kg'] > 0 ? round(($pesoKg / $cap['kg']) * 100, 1) : null;
        $pctM3 = ($m3Util > 0 && $volumenM3 !== null && $volumenM3 > 0)
            ? round(($volumenM3 / $m3Util) * 100, 1)
            : null;
        $pctUso = max($pctKg ?? 0, $pctM3 ?? 0);

        $limitePor = null;
        if ($pctKg !== null && $pctM3 !== null) {
            $limitePor = $pctKg >= $pctM3 ? 'peso' : 'volumen';
        } elseif ($pctM3 !== null) {
            $limitePor = 'volumen';
        } elseif ($pctKg !== null) {
            $limitePor = 'peso';
        }

        $ok = true;
        $mensaje = '';
        try {
            if ($pesoKg > 0) {
                $capSvc->validarCarga($vehiculo, $pesoKg, $volumenM3);
            }
        } catch (InvalidArgumentException $e) {
            $ok = false;
            $mensaje = $e->getMessage();
        }

        $tipoCodigo = strtoupper($vehiculo->tipoVehiculo?->codigo ?? 'CAMIONETA');

        return response()->json([
            'ok' => $ok,
            'mensaje' => $mensaje,
            'limite_por' => $limitePor,
            'vehiculo' => [
                'placa' => $vehiculo->placa,
                'tipo_codigo' => $tipoCodigo,
                'tipo_nombre' => $vehiculo->tipoVehiculo?->nombre ?? 'Vehículo',
            ],
            'dimensiones' => $dims,
            'capacidad_kg' => $cap['kg'],
            'capacidad_m3' => $cap['m3'],
            'm3_util' => $m3Util,
            'carga_peso_kg' => round($pesoKg, 2),
            'carga_volumen_m3' => $volumenM3 !== null ? round($volumenM3, 3) : null,
            'porcentaje_peso' => $pctKg,
            'porcentaje_volumen' => $pctM3,
            'porcentaje_uso' => $pctUso > 0 ? $pctUso : $pctKg,
            'recomendacion' => $pesoKg <= 0
                ? 'Indique productos y cantidades del envío para estimar la ocupación.'
                : ($ok
                    ? 'La carga cabe en este vehículo según peso y volumen registrados.'
                    : 'Reduzca la cantidad, divida en otro envío o elija un vehículo con mayor capacidad.'),
        ]);
    }

    public function rutasMulti(Request $request): JsonResponse
    {
        $query = RutaMultiEntrega::query()
            ->with(['transportista'])
            ->withCount('paradas');

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado')->toString());
        } else {
            $query->whereIn('estado', ['planificada', 'en_ruta', 'completada']);
        }

        if ($request->filled('transportista_usuarioid')) {
            $query->where('transportista_usuarioid', (int) $request->transportista_usuarioid);
        }

        if ($request->filled('q')) {
            $like = '%'.$request->string('q')->trim().'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('nombre', 'like', $like)
                    ->orWhereHas('transportista', function (Builder $t) use ($like) {
                        $t->where('nombre', 'like', $like)
                            ->orWhere('apellido', 'like', $like)
                            ->orWhere('nombreusuario', 'like', $like);
                    });
            });
        }

        return $this->respuestaPaginada($request, $query->orderByDesc('created_at'), function (RutaMultiEntrega $r) {
            $chofer = trim(($r->transportista?->nombre ?? '').' '.($r->transportista?->apellido ?? ''));

            return [
                'id' => $r->rutamultientregaid,
                'label' => $r->nombre,
                'meta' => implode(' · ', array_filter([
                    ucfirst(str_replace('_', ' ', (string) ($r->estado ?? ''))),
                    $chofer !== '' ? $chofer : null,
                    ($r->paradas_count ?? 0).' parada(s)',
                ])),
            ];
        });
    }

    public function cultivos(Request $request): JsonResponse
    {
        $query = Cultivo::query();
        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'detalle']);

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (Cultivo $c) {
            return [
                'id' => $c->cultivoid,
                'label' => $c->nombre,
                'meta' => $c->detalleVisible(),
            ];
        });
    }

    public function lotes(Request $request): JsonResponse
    {
        $query = Lote::query()->with(['cultivo', 'usuario']);

        if (UsuarioRol::debeAcotarPorAsignacion($request->user())) {
            \App\Support\LoteAcceso::aplicarScopeVisibles($query, $request->user());
        } elseif (
            UsuarioRol::esJefeAgricultor($request->user())
            && ! UsuarioRol::esAdminGlobal($request->user())
        ) {
            $query->whereIn('usuarioid', UsuarioRol::idsUsuariosBajoJefeAgricultor($request->user()));
        }

        if ($request->filled('usuarioid')) {
            $query->where('usuarioid', (int) $request->usuarioid);
        }

        if ($request->filled('cultivoid')) {
            $query->where('cultivoid', (int) $request->cultivoid);
        }

        if ($request->filled('loteids')) {
            $ids = collect(explode(',', (string) $request->loteids))
                ->map(fn ($id) => (int) trim($id))
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('loteid', $ids);
            }
        }

        if ($request->boolean('solo_cosecha')) {
            $query->with(['estadoTipo', 'actividades.tipoActividad']);
            $ids = \App\Support\EstadoLoteCatalogo::idsPorSlugs(['listo_para_cosecha', 'en_crecimiento']);
            if ($ids !== []) {
                $query->whereIn('estadolotetipoid', $ids);
            } else {
                $query->whereHas('estadoTipo', function ($q) {
                    $q->whereRaw('LOWER(TRIM(nombre)) IN (?, ?)', ['listo para cosecha', 'en crecimiento']);
                });
            }
        } elseif ($request->boolean('solo_produccion')) {
            $ids = \App\Support\EstadoLoteCatalogo::idsPorSlugs(['listo_para_cosecha']);
            if ($ids !== []) {
                $query->whereIn('estadolotetipoid', $ids);
            } else {
                $query->whereHas('estadoTipo', function ($q) {
                    $q->whereRaw('LOWER(TRIM(nombre)) = ?', ['listo para cosecha']);
                });
            }
        }

        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'codigo_trazabilidad', 'ubicacion']);

        if ($request->boolean('solo_cosecha')) {
            $trazabilidad = app(\App\Support\LoteTrazabilidadService::class);
            $planificacion = app(\App\Services\PlanificacionCosechaService::class);
            $perPage = min(50, max(5, (int) $request->input('per_page', 20)));
            $page = max(1, (int) $request->input('page', 1));
            $filtrados = $query->orderBy('nombre')->get()
                ->filter(fn (Lote $l) => $trazabilidad->puedeRegistrarCosecha($l))
                ->values();
            $total = $filtrados->count();
            $items = $filtrados->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $items->map(function (Lote $l) use ($planificacion) {
                    $meta = [];
                    if ($l->cultivo?->nombre) {
                        $meta[] = $l->cultivo->nombre;
                    }
                    if ($l->codigo_trazabilidad) {
                        $meta[] = $l->codigo_trazabilidad;
                    }

                    $responsable = $l->usuario
                        ? trim($l->usuario->nombre.' '.($l->usuario->apellido ?? ''))
                        : '';

                    return [
                        'id' => $l->loteid,
                        'label' => $l->nombre,
                        'meta' => $meta !== [] ? implode(' · ', $meta) : ($l->ubicacion ?: null),
                        'extra' => [
                            'responsable' => $responsable,
                            'usuarioid' => $l->usuarioid,
                            'cultivo' => $l->cultivo?->nombre ?? 'Sin cultivo',
                            'superficie' => $l->superficie,
                            'estimacion_cosecha' => $planificacion->estimacionUiDesdeLote($l),
                        ],
                    ];
                })->values(),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (Lote $l) {
            $l->loadMissing('insumoSemilla', 'cultivo');
            $cultivoEtiqueta = $l->cultivo_etiqueta;

            $meta = [];
            if ($cultivoEtiqueta) {
                $meta[] = $cultivoEtiqueta;
            }
            if ($l->codigo_trazabilidad) {
                $meta[] = $l->codigo_trazabilidad;
            }

            $responsable = $l->usuario
                ? trim($l->usuario->nombre.' '.($l->usuario->apellido ?? ''))
                : '';

            return [
                'id' => $l->loteid,
                'label' => $l->nombre,
                'meta' => $meta !== [] ? implode(' · ', $meta) : ($l->ubicacion ?: null),
                'extra' => [
                    'responsable' => $responsable,
                    'usuarioid' => $l->usuarioid,
                    'cultivo' => $cultivoEtiqueta ?? 'Sin cultivo',
                    'superficie' => $l->superficie,
                ],
            ];
        });
    }

    public function insumos(Request $request): JsonResponse
    {
        AlmacenAmbito::asegurarAmbitosEnRegistros();
        $inventarioPresentacion = app(InventarioPresentacionService::class);

        $esPlanta = $request->boolean('ambito_planta');

        if ($esPlanta) {
            try {
                app(ProductoPlantaInventarioService::class)->sincronizarDesdeAlmacenajes(
                    $request->filled('almacenid') ? (int) $request->almacenid : null
                );
            } catch (\Throwable $e) {
                report($e);
            }
            $query = Insumo::query()->with(['tipo', 'unidadMedida', 'almacen']);
        } elseif ($request->boolean('ambito_mayorista')) {
            $query = InsumoCatalogo::aplicarFiltroProductoTerminado(
                Insumo::query()->with(['tipo', 'unidadMedida', 'almacen'])
            );
        } else {
            $query = InsumoCatalogo::aplicarFiltroOperativo(
                Insumo::query()->with(['tipo', 'unidadMedida', 'almacen'])
            );
        }

        if ($request->filled('tipo_slug')) {
            $slug = trim((string) $request->tipo_slug);
            $tipoIds = InsumoCatalogo::tiposOrdenados()
                ->filter(fn ($t) => InsumoCatalogo::slugFromNombreTipo($t->nombre) === $slug)
                ->pluck('tipoinsumoid')
                ->all();

            $query->whereIn('tipoinsumoid', $tipoIds === [] ? [-1] : $tipoIds);

            if ($slug === 'material_siembra') {
                InsumoCatalogo::aplicarFiltroMaterialSiembraInventario($query);
            }
        }

        if ($request->boolean('solo_con_stock')) {
            $query->where('stock', '>', 0);
        }

        if ($request->boolean('ambito_mayorista')) {
            $almacenIds = AlmacenAmbito::scope(Almacen::query()->where('activo', true), AlmacenAmbito::MAYORISTA)
                ->pluck('almacenid');

            if ($almacenIds->isNotEmpty()) {
                $query->whereIn('almacenid', $almacenIds);
            } else {
                $query->whereRaw('1 = 0');
            }

            if ($request->boolean('requiere_almacen') && ! $request->filled('almacenid')) {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->boolean('ambito_planta')) {
            $almacenIds = AlmacenAmbito::scope(Almacen::query()->where('activo', true), AlmacenAmbito::PLANTA)
                ->pluck('almacenid');

            if ($almacenIds->isNotEmpty()) {
                $query->whereIn('almacenid', $almacenIds);
            } else {
                $query->whereRaw('1 = 0');
            }

            if ($request->boolean('solo_materia_prima_cosecha')) {
                $query = InsumoCatalogo::aplicarFiltroMateriaPrimaCosecha($query);
            } elseif ($request->boolean('solo_producto_terminado')) {
                $query = InsumoCatalogo::aplicarFiltroProductoTerminado($query);
            }

            if ($request->boolean('solo_con_presentacion') && Schema::hasTable('insumo_presentacion')) {
                $query->whereHas('presentaciones', function ($q) {
                    $q->where('activo', true);
                });
            }
        }

        if ($request->filled('almacenid')) {
            $query->where('almacenid', (int) $request->almacenid);
        }

        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'descripcion']);

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (Insumo $i) use ($request, $inventarioPresentacion) {
            $unidad = $i->unidadMedida?->abreviatura ?? $i->unidadMedida?->nombre ?? 'ud';
            $alm = $i->almacen?->nombre;
            $stock = (float) ($i->stock ?? 0);
            $esSemilla = InsumoCatalogo::slugFromNombreTipo($i->tipo?->nombre) === 'material_siembra';
            $esProductoTerminado = mb_strtolower(trim($i->tipo?->nombre ?? '')) === 'producto terminado';
            if ($request->boolean('ambito_planta') && $esProductoTerminado) {
                $almacenId = $request->filled('almacenid') ? (int) $request->almacenid : (int) $i->almacenid;
                if ($almacenId > 0) {
                    $inventarioPresentacion->asegurarInventarioDesdeStock($almacenId, (int) $i->insumoid);
                    $presentaciones = InsumoPresentacion::query()
                        ->where('insumoid', $i->insumoid)
                        ->where('activo', true)
                        ->pluck('insumo_presentacionid');
                    $stockKg = 0.0;
                    foreach ($presentaciones as $presId) {
                        $stockKg += $inventarioPresentacion->stockTotalKg($almacenId, (int) $presId);
                    }
                    if ($stockKg > 0) {
                        $stock = $stockKg;
                    }
                }
                $unidad = 'kg';
            }
            $tipoEtiqueta = $request->boolean('solo_materia_prima_cosecha') && $esSemilla
                ? 'Cosecha'
                : ($esProductoTerminado ? 'Producto terminado' : ($i->tipo?->nombre ?? 'Insumo'));
            $stockEtiqueta = $esSemilla
                ? CultivoSiembraCatalogo::etiquetaStockSemilla($i)
                : 'Stock: '.number_format($stock, 2).' '.$unidad;
            $dosisUnidad = $esSemilla ? ($i->dosis_unidad ?? $unidad) : null;
            $dosisPorHa = $esSemilla ? (float) ($i->dosis_por_ha ?? 0) : null;
            if ($esSemilla && ($dosisPorHa === null || $dosisPorHa <= 0)) {
                $fallback = CultivoSiembraCatalogo::dosisPorNombreCultivo(PedidoCatalogo::cultivoDesdeInsumo($i));
                if ($fallback) {
                    $dosisPorHa = (float) $fallback['por_ha'];
                    $dosisUnidad = $fallback['unidad'];
                }
            }

            $metaSemilla = null;
            $metaLineas = null;
            if ($esSemilla && ! $request->boolean('ambito_planta')) {
                $ubicacion = 'Inventario agrícola';
                if ($i->almacen && AlmacenAmbito::resolverAmbito($i->almacen) === AlmacenAmbito::AGRICOLA) {
                    $ubicacion = $alm ?? 'Almacén agrícola';
                }
                $metaSemilla = $ubicacion.' · '.$stockEtiqueta;
                $metaLineas = [
                    ['icon' => 'fa-boxes', 'text' => $stockEtiqueta],
                    ['icon' => 'fa-warehouse', 'text' => $ubicacion],
                ];
                if ($dosisPorHa > 0) {
                    $metaLineas[] = [
                        'icon' => 'fa-ruler-combined',
                        'text' => number_format($dosisPorHa, 2, ',', '.').' '.($dosisUnidad ?? 'kg').'/ha',
                    ];
                }
            }

            return [
                'id' => $i->insumoid,
                'label' => $i->nombre,
                'meta' => $metaSemilla ?? trim(
                    ($alm ? $alm.' · ' : '')
                    .$tipoEtiqueta.' · '.$stockEtiqueta
                ),
                'meta_lineas' => $metaLineas,
                'extra' => [
                    'stock' => $stock,
                    'unidad' => $unidad,
                    'almacen' => $alm,
                    'precio' => $i->preciounitario ?? 0,
                    'sin_stock' => $stock <= 0,
                    'dosis_por_ha' => $esSemilla ? (float) ($dosisPorHa ?? 0) : null,
                    'dosis_unidad' => $dosisUnidad,
                    'dosis_unidad_legible' => $esSemilla ? CultivoSiembraCatalogo::unidadLegible($dosisUnidad) : null,
                    'semillas_por_kg' => $esSemilla ? CultivoSiembraCatalogo::semillasPorKgDesdeInsumo($i) : null,
                    'cultivo' => $esSemilla ? PedidoCatalogo::cultivoDesdeInsumo($i) : null,
                ],
            ];
        });
    }

    public function presentacionesProducto(Request $request, InventarioPresentacionService $inventarioPresentacion, CatalogoProductoPlantaPdvService $catalogoPdv, DisponibilidadMayoristaPdvService $catalogoMayorista): JsonResponse
    {
        $insumoId = (int) $request->query('insumoid', 0);
        $almacenId = (int) $request->query('almacenid', 0);
        if ($insumoId <= 0) {
            return response()->json(['data' => []]);
        }

        if ($request->boolean('catalogo_mayorista_pdv')) {
            $almacenMayoristaId = (int) $request->query('almacen_mayorista_origenid', 0);

            $items = $catalogoMayorista->presentacionesParaSolicitud(
                $insumoId,
                $almacenMayoristaId > 0 ? $almacenMayoristaId : null
            )
                ->map(function (array $row) {
                    /** @var InsumoPresentacion $p */
                    $p = $row['presentacion'];

                    return [
                        'id' => $row['insumo_presentacionid'],
                        'label' => $p->nombre,
                        'meta' => $row['stock_etiqueta'],
                        'extra' => [
                            'insumoid' => $row['insumoid'],
                            'presentacion_nombre' => $row['presentacion_nombre'],
                            'peso_neto_kg' => $p->pesoNetoKg(),
                            'tipo_envase' => $p->tipo_envase,
                            'tipo_empaque' => $p->tipoEmpaque?->nombre,
                            'unidad_etiqueta' => $row['unidad'],
                            'stock_unidades' => $row['stock_unidades'],
                            'stock_kg' => $row['stock_kg'],
                            'stock_kg_producto' => $row['stock_kg'],
                            'tiene_stock' => $row['tiene_stock'],
                        ],
                    ];
                })
                ->values()
                ->all();

            return response()->json(['data' => $items]);
        }

        if ($request->boolean('catalogo_pdv')) {
            $producto = $catalogoPdv->productosConDisponibilidad()
                ->first(fn (array $row) => (int) $row['insumo']->insumoid === $insumoId);

            $items = collect($producto['presentaciones'] ?? [])
                ->map(function (array $row) {
                    /** @var InsumoPresentacion $p */
                    $p = $row['presentacion'];

                    return [
                        'id' => $p->insumo_presentacionid,
                        'label' => $p->nombre,
                        'meta' => number_format($p->pesoNetoKg(), 3, '.', '').' kg · '.$row['stock_etiqueta'],
                        'extra' => [
                            'peso_neto_kg' => $p->pesoNetoKg(),
                            'tipo_envase' => $p->tipo_envase,
                            'unidad_etiqueta' => $p->etiquetaUnidad(),
                            'stock_unidades' => $row['stock_unidades'],
                        ],
                    ];
                })
                ->values()
                ->all();

            return response()->json(['data' => $items]);
        }

        if ($almacenId > 0) {
            $inventarioPresentacion->asegurarInventarioDesdeStock($almacenId, $insumoId);
        }

        $items = InsumoPresentacion::query()
            ->with('tipoEmpaque')
            ->where('insumoid', $insumoId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->map(function (InsumoPresentacion $p) use ($almacenId, $inventarioPresentacion) {
                $tipoNombre = $p->tipoEmpaque?->nombre;
                $stockUnidades = $almacenId > 0
                    ? $inventarioPresentacion->stockTotalUnidades($almacenId, (int) $p->insumo_presentacionid)
                    : null;
                $stockKg = $almacenId > 0
                    ? $inventarioPresentacion->stockTotalKg($almacenId, (int) $p->insumo_presentacionid)
                    : null;

                return [
                    'id' => $p->insumo_presentacionid,
                    'label' => $p->nombre,
                    'meta' => number_format($p->pesoNetoKg(), 3, '.', '').' kg por '.$p->etiquetaUnidad()
                        .($tipoNombre ? ' · '.$tipoNombre : '')
                        .($stockUnidades !== null ? ' · Disp. '.number_format($stockUnidades, 0).' '.$p->etiquetaUnidad() : ''),
                    'extra' => [
                        'peso_neto_kg' => $p->pesoNetoKg(),
                        'tipo_envase' => $p->tipo_envase,
                        'tipo_empaque' => $tipoNombre,
                        'tipoempaqueid' => $p->tipoempaqueid,
                        'unidad_etiqueta' => $p->etiquetaUnidad(),
                        'unidades_por_caja' => $p->unidades_por_caja,
                        'sku' => $p->sku,
                        'stock_unidades' => $stockUnidades,
                        'stock_kg' => $stockKg,
                    ],
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }

    public function productosMayoristaPdv(Request $request, DisponibilidadMayoristaPdvService $catalogo): JsonResponse
    {
        $items = $catalogo->productosConStock()
            ->map(fn (array $row) => [
                'id' => $row['catalogo_id'],
                'label' => $row['label'],
                'meta' => $row['stock_etiqueta'],
                'extra' => [
                    'insumoid' => $row['insumoid'],
                    'almacen_mayorista_origenid' => $row['almacen_mayorista_origenid'],
                    'mayorista_nombre' => $row['mayorista_nombre'],
                    'almacen_nombre' => $row['almacen_nombre'],
                    'ubicacion' => $row['ubicacion'],
                    'lat' => $row['lat'],
                    'lng' => $row['lng'],
                    'stock_kg' => $row['stock_kg'],
                    'presentaciones_count' => $row['presentaciones_count'],
                    'producto_nombre' => $row['nombre'],
                ],
            ])
            ->values()
            ->all();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = array_values(array_filter(
                $items,
                fn (array $item) => str_contains(mb_strtolower($item['label']), $needle)
            ));
        }

        return response()->json(['data' => $items]);
    }

    public function productosPlantaPdv(Request $request, CatalogoProductoPlantaPdvService $catalogo): JsonResponse
    {
        $items = $catalogo->productosConDisponibilidad()
            ->map(function (array $row) {
                /** @var Insumo $insumo */
                $insumo = $row['insumo'];

                return [
                    'id' => $insumo->insumoid,
                    'label' => $insumo->nombre,
                    'meta' => $row['stock_mayorista_etiqueta'],
                    'extra' => [
                        'stock_mayorista_kg' => $row['stock_mayorista_kg'],
                        'unidad' => $insumo->unidadMedida?->abreviatura ?? 'kg',
                        'presentaciones_count' => count($row['presentaciones']),
                    ],
                ];
            })
            ->values()
            ->all();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = array_values(array_filter(
                $items,
                fn (array $item) => str_contains(mb_strtolower($item['label']), $needle)
            ));
        }

        return response()->json(['data' => $items]);
    }

    public function stockPresentacionLote(Request $request, InventarioPresentacionService $inventarioPresentacion): JsonResponse
    {
        $almacenId = (int) $request->query('almacenid', 0);
        $presentacionId = (int) $request->query('insumo_presentacionid', 0);

        if ($almacenId <= 0 || $presentacionId <= 0) {
            return response()->json(['data' => [], 'stock_total_unidades' => 0]);
        }

        $presentacion = InsumoPresentacion::query()->find($presentacionId);
        if ($presentacion !== null) {
            $inventarioPresentacion->asegurarInventarioDesdeStock($almacenId, (int) $presentacion->insumoid);
        }

        $lotes = $inventarioPresentacion->lotesDisponibles($almacenId, $presentacionId);
        $presentacion = $presentacion?->load('tipoEmpaque') ?? InsumoPresentacion::query()->with('tipoEmpaque')->find($presentacionId);
        $unidad = $presentacion?->etiquetaUnidad() ?? 'unidades';

        $items = $lotes->map(function ($inv) use ($unidad, $presentacion) {
            $etiqueta = $inv->etiquetaLote();
            $u = (float) $inv->cantidad_unidades;
            $kg = (float) $inv->cantidad_kg;

            return [
                'id' => $inv->inventario_presentacion_loteid,
                'label' => $etiqueta.' · '.number_format($u, 0).' '.$unidad,
                'meta' => number_format($kg, 2).' kg',
                'extra' => [
                    'cantidad_unidades' => $u,
                    'cantidad_kg' => $kg,
                    'referencia_lote' => $inv->referencia_lote,
                    'loteproduccionpedidoid' => $inv->loteproduccionpedidoid,
                    'peso_neto_kg' => $presentacion?->pesoNetoKg(),
                ],
            ];
        })->values()->all();

        return response()->json([
            'data' => $items,
            'stock_total_unidades' => $inventarioPresentacion->stockTotalUnidades($almacenId, $presentacionId),
        ]);
    }

    public function pedidos(Request $request): JsonResponse
    {
        $query = Pedido::query();

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $this->aplicarBusqueda($query, (string) $request->q, [
            'numero_solicitud',
            'nombre_planta',
            'direccion_texto',
            'observaciones',
        ]);

        return $this->respuestaPaginada(
            $request,
            $query->orderByDesc('fechapedido'),
            function (Pedido $p) {
                $fecha = $p->fechapedido
                    ? (\Carbon\Carbon::parse($p->fechapedido)->format('d/m/Y'))
                    : null;

                return [
                    'id' => $p->pedidoid,
                    'label' => $p->numero_solicitud,
                    'meta' => trim(
                        ($p->nombre_planta ?? '')
                        .($fecha ? ' · '.$fecha : '')
                        .($p->estado ? ' · '.ucfirst(str_replace('_', ' ', (string) $p->estado)) : '')
                    ),
                    'extra' => [
                        'estado' => $p->estado,
                        'planta' => $p->nombre_planta,
                    ],
                ];
            }
        );
    }

    public function actores(Request $request): JsonResponse
    {
        $query = ActorAbastecimiento::query()->where('activo', true);
        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'contacto', 'email']);

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (ActorAbastecimiento $a) {
            $meta = $a->tipo_actor ? ucfirst((string) $a->tipo_actor) : null;
            if ($a->contacto ?? $a->email) {
                $meta = trim(($meta ? $meta.' · ' : '').($a->contacto ?? $a->email));
            }

            return [
                'id' => $a->actorid,
                'label' => $a->nombre,
                'meta' => $meta,
            ];
        });
    }

    public function almacenes(Request $request): JsonResponse
    {
        AlmacenAmbito::asegurarAmbitosEnRegistros();

        $query = Almacen::query()->where('activo', true);

        if (! $request->boolean('incluir_sin_capacidad')) {
            $query->where('capacidad', '>', 0);
        }

        if ($request->filled('ambito') && AlmacenAmbito::esValido($request->string('ambito')->toString())) {
            $query = AlmacenAmbito::scope($query, $request->string('ambito')->toString());
        }

        $pedidosListosPorAlmacen = collect();
        if ($request->filled('almacenids_pedidos')) {
            $idsPedidos = collect(explode(',', (string) $request->almacenids_pedidos))
                ->map(fn (string $id) => (int) trim($id))
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($idsPedidos !== []) {
                $query->whereIn('almacenid', $idsPedidos);
                $pedidosListosPorAlmacen = \App\Models\PedidoDistribucion::query()
                    ->where('estado', \App\Support\PedidoDistribucionCatalogo::ESTADO_CONFIRMADO)
                    ->whereNull('rutadistribucionid')
                    ->whereIn('almacen_mayorista_origenid', $idsPedidos)
                    ->selectRaw('almacen_mayorista_origenid, count(*) as total')
                    ->groupBy('almacen_mayorista_origenid')
                    ->pluck('total', 'almacen_mayorista_origenid');
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->boolean('con_stock')) {
            $query->whereHas('almacenamientos', fn (Builder $q) => $q->where('cantidad', '>', 0));
        }

        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'ubicacion']);

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (Almacen $a) use ($pedidosListosPorAlmacen, $request) {
            $resuelto = UbicacionGpsParser::resolverAlmacen(
                (int) $a->almacenid,
                $a->nombre,
                $a->ubicacion
            );

            $meta = $resuelto['estimada']
                ? $resuelto['direccion'].' (ubicación referencial)'
                : \Illuminate\Support\Str::limit($resuelto['direccion'], 80);

            if ($request->filled('almacenids_pedidos')) {
                $n = (int) ($pedidosListosPorAlmacen[$a->almacenid] ?? 0);
                $meta = $n.' pedido'.($n === 1 ? '' : 's').' listo'.($n === 1 ? '' : 's');
            }

            return [
                'id' => $a->almacenid,
                'label' => $a->nombre,
                'meta' => $meta,
                'extra' => [
                    'lat' => $resuelto['lat'],
                    'lng' => $resuelto['lng'],
                    'direccion' => $resuelto['direccion'],
                    'ambito' => $a->ambito ?? AlmacenAmbito::AGRICOLA,
                    'ubicacion_estimada' => $resuelto['estimada'],
                    'pedidos_listos' => (int) ($pedidosListosPorAlmacen[$a->almacenid] ?? 0),
                ],
            ];
        });
    }

    public function puntosVenta(Request $request): JsonResponse
    {
        $query = PuntoVentaAccess::scopePuntosDelUsuario(
            PuntoVenta::query()->with('minorista'),
            $request->user()
        );

        if (! $request->boolean('incluir_inactivos')) {
            $query->where('activo', true);
        }

        if ($request->filled('minorista_usuarioid')) {
            $query->where('usuarioid', (int) $request->minorista_usuarioid);
        }

        if ($request->filled('q')) {
            $term = $request->string('q')->trim()->toString();
            $query->where(function (Builder $w) use ($term) {
                $w->where('nombre', 'like', "%{$term}%")
                    ->orWhere('direccion', 'like', "%{$term}%")
                    ->orWhereHas('minorista', function (Builder $m) use ($term) {
                        $m->where('nombre', 'like', "%{$term}%")
                            ->orWhere('apellido', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (PuntoVenta $pv) {
            $minorista = trim(($pv->minorista?->nombre ?? '').' '.($pv->minorista?->apellido ?? ''));

            return [
                'id' => $pv->puntoventaid,
                'label' => $pv->nombre,
                'meta' => trim(collect([
                    $minorista !== '' ? $minorista : null,
                    $pv->resumenUbicacion(),
                    $pv->activo ? null : 'Inactivo',
                ])->filter()->implode(' · ')),
                'extra' => [
                    'lat' => $pv->latitud,
                    'lng' => $pv->longitud,
                    'direccion' => $pv->ubicacionVisible()['direccion'],
                    'ubicacion_resumen' => $pv->resumenUbicacion(),
                    'minorista' => $minorista,
                    'activo' => (bool) $pv->activo,
                ],
            ];
        });
    }

    public function puntoVentaCapacidadPdv(Request $request, PuntoVentaAlmacenService $almacenPdv): JsonResponse
    {
        $puntoId = (int) $request->query('puntoventaid', 0);
        if ($puntoId <= 0) {
            return response()->json(['data' => null]);
        }

        $punto = PuntoVenta::query()->find($puntoId);
        if ($punto === null) {
            return response()->json(['data' => null], 404);
        }

        abort_unless(PuntoVentaAccess::puedeVerPunto($request->user(), $punto), 403);

        $cantidad = max(0, (float) $request->query('cantidad', 0));
        $pesoNetoKg = max(0, (float) $request->query('peso_neto_kg', 0));
        $kgIngreso = $cantidad > 0 && $pesoNetoKg > 0 ? $cantidad * $pesoNetoKg : 0;

        $resumen = $almacenPdv->resumenCapacidadPedido($punto, $kgIngreso);

        return response()->json(['data' => $resumen]);
    }

    public function productosPedido(Request $request): JsonResponse
    {
        AlmacenAmbito::asegurarAmbitosEnRegistros();

        $q = mb_strtolower(trim((string) $request->q));
        $almacenId = $request->filled('almacenid') ? (int) $request->almacenid : null;
        $items = collect();

        foreach (PedidoCatalogo::insumosMaterialSiembraGlobales() as $insumo) {
            if ($almacenId && (int) $insumo->almacenid !== $almacenId) {
                continue;
            }
            if ((float) $insumo->stock <= 0) {
                continue;
            }

            $almacen = $insumo->almacen?->nombre;
            $refInsumo = 'insumo:'.$insumo->insumoid;
            $stockKg = PedidoCatalogo::stockDisponibleProductoPedido($refInsumo, (int) $insumo->almacenid) ?? 0.0;
            $stock = number_format($stockKg, 2);
            $label = $insumo->nombre;
            $meta = trim(collect([$almacen, "Stock: {$stock} kg"])->filter()->implode(' · '));

            if ($q !== '' && ! str_contains(mb_strtolower($label.' '.$meta), $q)) {
                continue;
            }

            $items->push([
                'id' => $refInsumo,
                'label' => $label,
                'meta' => $meta !== '' ? $meta : 'Insumo · Material de siembra',
                'extra' => [
                    'tipo' => 'insumo',
                    'almacen' => $almacen,
                    'almacenid' => $insumo->almacenid,
                    'stock' => $stockKg,
                    'stock_kg' => $stockKg,
                    'unidad' => 'kg',
                    'calibre_id' => PedidoCatalogo::calibreSugeridoIdParaProducto($refInsumo),
                    'insumoid_calibre' => PedidoCatalogo::insumoIdParaCalibres($refInsumo),
                ],
            ]);
        }

        foreach (PedidoCatalogo::cosechasAgricolasDisponibles() as $cosecha) {
            if ($almacenId && (int) $cosecha->almacenid !== $almacenId) {
                continue;
            }

            $cultivo = $cosecha->produccion?->lote?->cultivo?->nombre ?? 'Cultivo';
            $lote = $cosecha->produccion?->lote?->nombre ?? 'Lote';
            $almacen = $cosecha->almacen?->nombre ?? 'Almacén agrícola';
            $refCosecha = 'cosecha:'.$cosecha->produccionalmacenamientoid;
            $stockKg = PedidoCatalogo::stockDisponibleProductoPedido($refCosecha, (int) $cosecha->almacenid) ?? 0.0;
            $cantidad = number_format($stockKg, 2);
            $label = PedidoCatalogo::etiquetaCosechaSelector($cultivo, $lote);
            $meta = "{$almacen} · {$cantidad} kg disponibles";

            if ($q !== '' && ! str_contains(mb_strtolower($label.' '.$meta), $q)) {
                continue;
            }

            $items->push([
                'id' => $refCosecha,
                'label' => $label,
                'meta' => $meta,
                'extra' => [
                    'tipo' => 'cosecha',
                    'almacen' => $almacen,
                    'almacenid' => $cosecha->almacenid,
                    'stock' => $stockKg,
                    'stock_kg' => $stockKg,
                    'unidad' => 'kg',
                    'calibre_id' => PedidoCatalogo::calibreSugeridoIdParaProducto($refCosecha),
                    'insumoid_calibre' => PedidoCatalogo::insumoIdParaCalibres($refCosecha),
                ],
            ]);
        }

        if ($items->isEmpty() && ! $almacenId) {
            foreach (\App\Models\Cultivo::query()->orderBy('nombre')->get() as $cultivo) {
                $label = $cultivo->nombre;
                $meta = 'Cultivo de producción agrícola';

                if ($q !== '' && ! str_contains(mb_strtolower($label.' '.$meta), $q)) {
                    continue;
                }

                $refCultivo = 'cultivo:'.$cultivo->cultivoid;
                \App\Support\CalibresVerdurasCatalogo::sincronizarParaNombreCultivo($label);
                $items->push([
                    'id' => $refCultivo,
                    'label' => $label,
                    'meta' => $meta,
                    'extra' => [
                        'tipo' => 'cultivo',
                        'calibre_id' => PedidoCatalogo::calibreSugeridoIdParaProducto($refCultivo),
                        'insumoid_calibre' => PedidoCatalogo::insumoVerduraPorNombreCultivo($label)?->insumoid
                            ?? PedidoCatalogo::insumoPorNombreCultivo($label)?->insumoid,
                    ],
                ]);
            }
        }

        $perPage = min(50, max(5, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));
        $total = $items->count();
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function produccionesStockAlmacen(Request $request): JsonResponse
    {
        $preciosPorProduccion = Venta::query()
            ->select('produccionid', DB::raw('MAX(preciounitario) as ultimo_precio'))
            ->groupBy('produccionid')
            ->pluck('ultimo_precio', 'produccionid');

        $preciosPorCultivo = Venta::query()
            ->join('produccion', 'venta.produccionid', '=', 'produccion.produccionid')
            ->join('lote', 'produccion.loteid', '=', 'lote.loteid')
            ->select('lote.cultivoid', DB::raw('ROUND(AVG(venta.preciounitario), 2) as precio_prom'))
            ->groupBy('lote.cultivoid')
            ->pluck('precio_prom', 'cultivoid');

        $query = Produccion::query()
            ->with(['lote.cultivo', 'unidadMedida', 'almacenamientos.almacen'])
            ->whereHas('almacenamientos', fn (Builder $q) => $q->where('cantidad', '>', 0));

        if ($request->filled('cultivoid')) {
            $query->whereHas('lote', fn (Builder $l) => $l->where('cultivoid', (int) $request->cultivoid));
        }

        if ($request->filled('almacenid')) {
            $query->whereHas('almacenamientos', function (Builder $q) use ($request) {
                $q->where('almacenid', (int) $request->almacenid)
                    ->where('cantidad', '>', 0);
            });
        }

        $q = trim((string) $request->q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->whereHas('lote', fn (Builder $l) => $l->where('nombre', 'like', $like))
                    ->orWhereHas('lote.cultivo', fn (Builder $c) => $c->where('nombre', 'like', $like))
                    ->orWhereHas('almacenamientos.almacen', fn (Builder $a) => $a->where('nombre', 'like', $like));
            });
        }

        return $this->respuestaPaginada($request, $query->orderByDesc('produccionid'), function (Produccion $p) use ($preciosPorProduccion, $preciosPorCultivo) {
            $stock = (float) $p->almacenamientos->sum('cantidad');
            $cultivo = $p->lote->cultivo->nombre ?? 'Producto';
            $lote = $p->lote->nombre ?? 'Lote';
            $almacen = $p->almacenamientos->first()->almacen->nombre ?? 'Sin almacén';
            $unidad = $p->unidadMedida->abreviatura ?? 'kg';
            $cultivoId = $p->lote->cultivoid ?? null;
            $precio = $preciosPorProduccion[$p->produccionid]
                ?? ($cultivoId ? ($preciosPorCultivo[$cultivoId] ?? null) : null);

            return [
                'id' => $p->produccionid,
                'label' => PedidoCatalogo::etiquetaCosechaSelector($cultivo, $lote).' · '.$almacen,
                'meta' => number_format($stock, 2).' '.$unidad.' disponibles',
                'extra' => [
                    'disponible' => $stock,
                    'unidad' => $unidad,
                    'unidad_id' => $p->unidadmedidaid,
                    'cultivo' => $cultivo,
                    'lote' => $lote,
                    'almacen' => $almacen,
                    'precio' => $precio !== null ? (float) $precio : null,
                ],
            ];
        });
    }

    public function producciones(Request $request): JsonResponse
    {
        $query = Produccion::query()->with(['lote', 'destino']);

        if ($request->filled('loteid')) {
            $query->where('loteid', (int) $request->loteid);
        }

        $q = trim((string) $request->q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->whereHas('lote', fn ($l) => $l->where('nombre', 'like', $like))
                    ->orWhere('observaciones', 'like', $like);
            });
        }

        return $this->respuestaPaginada($request, $query->orderByDesc('produccionid'), function (Produccion $p) {
            $fecha = $p->fechacosecha ? $p->fechacosecha->format('d/m/Y') : null;

            return [
                'id' => $p->produccionid,
                'label' => ($p->lote->nombre ?? 'Cosecha').' #'.$p->produccionid,
                'meta' => trim(($fecha ? $fecha.' · ' : '').number_format((float) ($p->cantidad ?? 0), 2).' · '.($p->destino->nombre ?? '')),
            ];
        });
    }

    public function procesosPlanta(Request $request): JsonResponse
    {
        $query = $request->boolean('activo', true)
            ? \App\Support\ProcesoPlantaCatalogo::queryActivos()
            : ProcesoPlanta::query();

        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'descripcion']);

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (ProcesoPlanta $p) {
            return [
                'id' => $p->procesoplantaid,
                'label' => $p->nombre,
                'meta' => $p->descripcion ? \Illuminate\Support\Str::limit($p->descripcion, 60) : null,
            ];
        });
    }

    public function maquinasPlanta(Request $request): JsonResponse
    {
        $query = MaquinaPlanta::query();
        $this->aplicarBusqueda($query, (string) $request->q, ['nombre', 'codigo', 'descripcion']);

        if ($request->filled('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        if ($request->filled('procesoplantaid')) {
            $procesoId = (int) $request->procesoplantaid;
            $maquinaIds = \App\Models\ProcesoMaquinaPlanta::query()
                ->where('procesoplantaid', $procesoId)
                ->pluck('maquinaplantaid');
            if ($maquinaIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('maquinaplantaid', $maquinaIds);
            }
        }

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (MaquinaPlanta $m) {
            return [
                'id' => $m->maquinaplantaid,
                'label' => $m->nombre,
                'meta' => $m->codigo ?: ($m->activo ? 'Activa' : 'Mantenimiento'),
                'extra' => [
                    'codigo' => $m->codigo,
                    'descripcion' => $m->descripcionMostrar(),
                    'imagen' => $m->imagenSrc(),
                    'activo' => (bool) $m->activo,
                ],
            ];
        });
    }

    public function plantillasTransformacion(Request $request): JsonResponse
    {
        $query = PlantillaTransformacion::query()
            ->with(['pasos.proceso', 'pasos.maquina']);

        $disponibilidad = (string) $request->input('disponibilidad', 'operativas');
        if ($disponibilidad === 'operativas') {
            $query->operativas();
        } elseif ($disponibilidad === 'mantenimiento') {
            $query->bloqueadasPorMantenimiento();
        }

        $q = trim((string) $request->q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('nombre', 'like', $like)
                    ->orWhere('descripcion', 'like', $like)
                    ->orWhere('producto_ejemplo', 'like', $like)
                    ->orWhere('palabras_clave', 'like', $like);
            });
        }

        return $this->respuestaPaginada($request, $query->orderBy('nombre'), function (PlantillaTransformacion $p) {
            $bloqueada = $p->bloqueadaPorMantenimiento();
            $pasos = $p->pasos->map(function ($paso) {
                $procesoNombre = $paso->proceso?->nombre ?? '—';
                $maquina = $paso->maquina
                    ? $paso->maquina->nombre.($paso->maquina->codigo ? ' ('.$paso->maquina->codigo.')' : '')
                    : 'Cualquiera compatible';

                return [
                    'orden' => (int) $paso->orden,
                    'proceso' => $procesoNombre,
                    'maquina' => $maquina,
                    'notas' => $paso->notas,
                    'es_cierre' => ProcesoPlantaCatalogo::esCierreTransformacion($procesoNombre),
                    'maquina_mantenimiento' => $paso->maquina && ! $paso->maquina->activo,
                ];
            })->values()->all();

            $metaPartes = [];
            if ($p->producto_ejemplo) {
                $metaPartes[] = 'Ej: '.$p->producto_ejemplo;
            }
            $metaPartes[] = count($pasos).' paso'.(count($pasos) === 1 ? '' : 's');
            $metaPartes[] = $bloqueada ? 'En mantenimiento' : 'Disponible';

            return [
                'id' => $p->plantillatransformacionid,
                'label' => $p->nombre,
                'meta' => implode(' · ', $metaPartes),
                'extra' => [
                    'descripcion' => $p->descripcion,
                    'producto_ejemplo' => $p->producto_ejemplo,
                    'palabras_clave' => $p->palabrasClaveLista(),
                    'estado' => $bloqueada ? 'mantenimiento' : 'disponible',
                    'pasos' => $pasos,
                    'url' => route('plantillas-transformacion.show', $p),
                    'seleccionable' => ! $bloqueada,
                ],
            ];
        });
    }

    private function aplicarBusqueda(Builder $query, string $q, array $columnas): void
    {
        if ($q === '') {
            return;
        }

        $like = '%'.BusquedaTexto::normalizar($q).'%';
        $query->where(function (Builder $w) use ($columnas, $like) {
            foreach ($columnas as $col) {
                $w->orWhereRaw(BusquedaTexto::sqlSinAcentos($col).' LIKE ?', [$like]);
            }
        });
    }

    private function aplicarBusquedaTransportista(Builder $query, string $q, bool $esTransportista): void
    {
        if ($q === '') {
            return;
        }

        $like = '%'.$q.'%';
        $columnas = ['nombre', 'apellido', 'email', 'nombreusuario', 'telefono'];

        $query->where(function (Builder $w) use ($columnas, $like, $esTransportista) {
            foreach ($columnas as $col) {
                $w->orWhere($col, 'like', $like);
            }

            if ($esTransportista) {
                $w->orWhereHas('perfilTransportista.vehiculo', function (Builder $v) use ($like) {
                    $v->where('placa', 'like', $like)
                        ->orWhere('marca', 'like', $like)
                        ->orWhere('modelo', 'like', $like);
                });
            }
        });
    }

    public function insumosActividad(Request $request, ActividadInsumoService $actividadInsumos): JsonResponse
    {
        $tipoSlug = trim((string) $request->input('tipo_slug', ''));
        if ($tipoSlug === '') {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $lote = null;
        if ($request->filled('loteid')) {
            $lote = Lote::query()->with(['cultivo', 'insumoSemilla.unidadMedida'])->find((int) $request->loteid);
        }

        $items = $actividadInsumos->listarInsumosParaModal($tipoSlug, $lote);
        $sugerencia = null;
        if ($tipoSlug === 'material_siembra' && $lote) {
            $sugerencia = CultivoSiembraCatalogo::sugerenciaDesdeLote($lote);
            if ($sugerencia === null && $lote->cultivo) {
                $sugerencia = CultivoSiembraCatalogo::sugerenciaParaLote($lote->cultivo, (float) $lote->superficie);
            }
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'total' => count($items),
                'max_insumos' => ActividadDetalleCatalogo::maxInsumosPorTipo(
                    match ($tipoSlug) {
                        'fertilizantes' => 'Fertilización',
                        'pesticidas' => 'Control de plagas',
                        'material_siembra' => 'Siembra',
                        default => '',
                    }
                ),
                'sugerencia_siembra' => $sugerencia,
                'superficie_ha' => $lote ? (float) $lote->superficie : null,
                'tipos_riego' => ActividadDetalleCatalogo::TIPOS_RIEGO,
            ],
        ]);
    }

    private function respuestaPaginada(Request $request, Builder $query, callable $mapper): JsonResponse
    {
        $paginator = $query->paginate(
            min(50, max(5, (int) $request->input('per_page', 20))),
            ['*'],
            'page',
            max(1, (int) $request->input('page', 1))
        );

        return response()->json([
            'data' => collect($paginator->items())->map($mapper)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
