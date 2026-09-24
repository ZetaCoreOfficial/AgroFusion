<?php

namespace App\Support;

use App\Models\Insumo;
use App\Models\TipoInsumo;
use App\Models\UnidadMedida;
use App\Services\InsumoEliminacionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InsumoCatalogo
{
    /** Umbral fijo de alerta: stock en o por debajo de este valor */
    public const UMBRAL_ALERTA_STOCK = 5;

    /** @var array<string, string> slug => nombre visible */
    public const TIPOS = [
        'material_siembra' => 'Material de Siembra',
        'fertilizantes' => 'Fertilizantes',
        'pesticidas' => 'Control de plagas',
    ];

    /** Nombres de unidad (clave para buscar en BD) por tipo */
    public const UNIDADES_POR_TIPO = [
        'material_siembra' => ['Kilogramo', 'Gramo', 'Quintal', 'Unidad'],
        'fertilizantes' => ['Kilogramo', 'Gramo', 'Quintal', 'Litro'],
        'pesticidas' => ['Kilogramo', 'Gramo', 'Mililitro', 'Litro'],
    ];

    /** @var array<string, string> */
    private const LEGACY_TIPO_SLUG = [
        'semilla' => 'material_siembra',
        'material de siembra' => 'material_siembra',
        'fertilizante' => 'fertilizantes',
        'pesticida' => 'pesticidas',
        'pesticidas' => 'pesticidas',
        'plaguicida' => 'pesticidas',
        'control de plagas' => 'pesticidas',
        'bioinsumo' => 'pesticidas',
    ];

    public static function slugFromNombreTipo(?string $nombre): ?string
    {
        if ($nombre === null || trim($nombre) === '') {
            return null;
        }

        $key = mb_strtolower(trim($nombre));

        if (isset(self::LEGACY_TIPO_SLUG[$key])) {
            return self::LEGACY_TIPO_SLUG[$key];
        }

        foreach (self::TIPOS as $slug => $label) {
            if (mb_strtolower($label) === $key) {
                return $slug;
            }
        }

        return null;
    }

    public static function asegurarCatalogosBase(): void
    {
        foreach (self::TIPOS as $label) {
            TipoInsumo::updateOrCreate(['nombre' => $label], ['nombre' => $label]);
        }

        self::normalizarTiposObsoletos();

        $unidades = [
            ['nombre' => 'Kilogramo', 'abreviatura' => 'kg', 'categoria' => 'peso'],
            ['nombre' => 'Gramo', 'abreviatura' => 'g', 'categoria' => 'peso'],
            ['nombre' => 'Quintal', 'abreviatura' => 'qq', 'categoria' => 'peso'],
            ['nombre' => 'Litro', 'abreviatura' => 'l', 'categoria' => 'volumen'],
            ['nombre' => 'Mililitro', 'abreviatura' => 'ml', 'categoria' => 'volumen'],
            ['nombre' => 'Metro', 'abreviatura' => 'm', 'categoria' => 'longitud'],
            ['nombre' => 'Unidad', 'abreviatura' => 'und', 'categoria' => 'cantidad'],
        ];

        foreach ($unidades as $u) {
            $data = ['nombre' => $u['nombre']];
            if (\Illuminate\Support\Facades\Schema::hasColumn('unidadmedida', 'abreviatura')) {
                $data['abreviatura'] = $u['abreviatura'];
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('unidadmedida', 'categoria')) {
                $data['categoria'] = $u['categoria'];
            }
            UnidadMedida::updateOrCreate(['nombre' => $u['nombre']], $data);
        }
    }

    /** @return Collection<int, TipoInsumo> */
    public static function tiposOrdenados(): Collection
    {
        self::asegurarCatalogosBase();

        $porNombre = TipoInsumo::query()->get()->keyBy(
            fn (TipoInsumo $t) => self::slugFromNombreTipo($t->nombre) ?? 'zzz_'.$t->tipoinsumoid
        );

        return collect(self::TIPOS)
            ->map(fn (string $nombre, string $slug) => $porNombre->get($slug))
            ->filter()
            ->values();
    }

    /**
     * Mapa slug tipo => [{id, nombre, abreviatura}, ...] para el formulario.
     *
     * @return array<string, array<int, array{id: int, nombre: string, abreviatura: string}>>
     */
    public static function unidadesPorTipoParaJs(): array
    {
        self::asegurarCatalogosBase();

        $todas = UnidadMedida::query()->get()->keyBy(fn ($u) => mb_strtolower(trim($u->nombre)));

        $out = [];
        foreach (self::UNIDADES_POR_TIPO as $slug => $nombres) {
            $out[$slug] = [];
            foreach ($nombres as $nombre) {
                $um = $todas->get(mb_strtolower($nombre));
                if ($um) {
                    $out[$slug][] = [
                        'id' => (int) $um->unidadmedidaid,
                        'nombre' => $um->nombre,
                        'abreviatura' => $um->abreviatura ?? $um->nombre,
                    ];
                }
            }
        }

        return $out;
    }

    /** Abreviaturas válidas para el campo dosis_unidad según tipo de insumo. */
    public static function abreviaturasDosisValidasPorTipo(string $slug): array
    {
        return collect(self::unidadesPorTipoParaJs()[$slug] ?? [])
            ->pluck('abreviatura')
            ->filter(fn ($a) => $a !== null && trim((string) $a) !== '')
            ->map(fn ($a) => mb_strtolower(trim((string) $a)))
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizarDosisUnidad(?string $unidad, ?string $slug = null): ?string
    {
        if ($unidad === null || trim($unidad) === '') {
            return null;
        }

        $u = mb_strtolower(trim($unidad));

        if ($slug === 'material_siembra' && in_array($u, ['unidad', 'planta', 'plantas', 'semilla', 'semillas', 'und'], true)) {
            return 'und';
        }

        return $u;
    }

    public static function dosisUnidadEsValida(?string $unidad, string $slug): bool
    {
        $normalizada = self::normalizarDosisUnidad($unidad, $slug);
        if ($normalizada === null) {
            return true;
        }

        return in_array($normalizada, self::abreviaturasDosisValidasPorTipo($slug), true);
    }

    public static function stockCritico(float $stock): bool
    {
        return $stock <= self::UMBRAL_ALERTA_STOCK;
    }

    public static function stockMedio(float $stock): bool
    {
        return $stock > self::UMBRAL_ALERTA_STOCK && $stock <= self::UMBRAL_ALERTA_STOCK * 2;
    }

    public static function claseStock(float $stock): string
    {
        if (self::stockCritico($stock)) {
            return 'low';
        }
        if (self::stockMedio($stock)) {
            return 'medium';
        }

        return 'high';
    }

    /** IDs de tipos válidos (solo los cuatro del catálogo oficial). */
    public static function tiposValidosIds(): array
    {
        self::asegurarCatalogosBase();

        return self::tiposOrdenados()->pluck('tipoinsumoid')->map(fn ($id) => (int) $id)->all();
    }

    /** Solo insumos operativos del campo (excluye tipos obsoletos como «Producto agrícola»). */
    public static function aplicarFiltroOperativo(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $ids = self::tiposValidosIds();

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('tipoinsumoid', $ids);
    }

    /**
     * Material de siembra solo desde inventario agrícola (nunca planta, mayorista ni PDV).
     */
    public static function aplicarFiltroMaterialSiembraInventario(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('almacen') || ! \Illuminate\Support\Facades\Schema::hasColumn('insumo', 'almacenid')) {
            return $query;
        }

        $almacenesAgricolas = \App\Models\Almacen::query()
            ->where('activo', true)
            ->where(function ($q) {
                \App\Support\AlmacenAmbito::scope($q, \App\Support\AlmacenAmbito::AGRICOLA);
            })
            ->pluck('almacenid')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        return $query->where(function ($q) use ($almacenesAgricolas) {
            // Operativo: solo existencias ligadas a almacén agrícola.
            // Legacy null se conserva en BD pero no se ofrece como inventario agrícola nuevo.
            if ($almacenesAgricolas !== []) {
                $q->whereIn('almacenid', $almacenesAgricolas);
            } else {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public static function tipoProductoTerminadoId(): ?int
    {
        $id = TipoInsumo::query()
            ->whereRaw('LOWER(TRIM(nombre)) = ?', ['producto terminado'])
            ->value('tipoinsumoid');

        return $id ? (int) $id : null;
    }

    /** Solo productos terminados de planta (empaquetados). */
    public static function aplicarFiltroProductoTerminado(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $tipoId = self::tipoProductoTerminadoId();

        return $tipoId ? $query->where('tipoinsumoid', $tipoId) : $query->whereRaw('1 = 0');
    }

    /** Excluye productos ya procesados; útil para elegir materia prima de cosecha. */
    public static function aplicarFiltroExcluirProductoTerminado(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $tipoId = self::tipoProductoTerminadoId();

        return $tipoId ? $query->where('tipoinsumoid', '!=', $tipoId) : $query;
    }

    /** Materia prima de cosecha en planta (verduras a granel, no producto empaquetado). */
    public static function aplicarFiltroMateriaPrimaCosecha(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $query = self::aplicarFiltroExcluirProductoTerminado($query);

        $tipoIds = self::tiposOrdenados()
            ->filter(fn (TipoInsumo $t) => self::slugFromNombreTipo($t->nombre) === 'material_siembra')
            ->pluck('tipoinsumoid')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $tipoIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('tipoinsumoid', $tipoIds);
    }

    public static function esInsumoOperativo(?\App\Models\Insumo $insumo): bool
    {
        if ($insumo === null) {
            return false;
        }

        return in_array((int) $insumo->tipoinsumoid, self::tiposValidosIds(), true);
    }

    /** Insumo operativo de campo o producto terminado en planta/mayorista. */
    public static function esInsumoGestionable(?Insumo $insumo): bool
    {
        return self::esInsumoOperativo($insumo) || self::esProductoTerminadoDistribucion($insumo);
    }

    public static function asegurarInsumoGestionable(Insumo $insumo): void
    {
        if (! self::esInsumoGestionable($insumo)) {
            abort(404, 'El registro no corresponde al catálogo de insumos operativos.');
        }
    }

    /** Producto terminado en almacén de planta o mayorista (distribución). */
    public static function esProductoTerminadoDistribucion(?Insumo $insumo): bool
    {
        if ($insumo === null) {
            return false;
        }

        if ((int) $insumo->tipoinsumoid !== self::tipoProductoTerminadoId()) {
            return false;
        }

        $insumo->loadMissing('almacen');

        return in_array($insumo->almacen?->ambito ?? '', [AlmacenAmbito::PLANTA, AlmacenAmbito::MAYORISTA], true);
    }

    /** Cosecha recibida en planta desde un pedido agrícola. */
    public static function esCosechaRecepcionPlanta(?Insumo $insumo): bool
    {
        if ($insumo === null) {
            return false;
        }

        $insumo->loadMissing('almacen');

        return ($insumo->almacen?->ambito ?? '') === AlmacenAmbito::PLANTA
            && str_starts_with(trim((string) ($insumo->descripcion ?? '')), 'Recepción pedido');
    }

    /**
     * @return array{clase: string, etiqueta: string, icono: string, porcentaje: float, mensaje: string, umbral: float}
     */
    public static function estadoStockAlmacen(Insumo $insumo): array
    {
        $stock = (float) $insumo->stock;
        $umbral = (float) ($insumo->stockminimo ?? self::UMBRAL_ALERTA_STOCK);
        if ($umbral <= 0) {
            $umbral = (float) self::UMBRAL_ALERTA_STOCK;
        }

        if ($stock <= 0) {
            return [
                'clase' => 'agotado',
                'etiqueta' => 'Agotado',
                'icono' => 'times-circle',
                'porcentaje' => 0.0,
                'mensaje' => 'Sin stock disponible en este almacén.',
                'umbral' => $umbral,
            ];
        }

        if (self::stockCritico($stock) || $stock <= $umbral) {
            return [
                'clase' => 'bajo',
                'etiqueta' => 'Stock bajo',
                'icono' => 'exclamation-triangle',
                'porcentaje' => min(100.0, max(5.0, ($stock / max($umbral * 2, 1)) * 100)),
                'mensaje' => 'El stock está por debajo del mínimo recomendado.',
                'umbral' => $umbral,
            ];
        }

        if (self::stockMedio($stock)) {
            return [
                'clase' => 'medio',
                'etiqueta' => 'Stock moderado',
                'icono' => 'info-circle',
                'porcentaje' => min(100.0, ($stock / max($umbral * 4, 1)) * 100),
                'mensaje' => 'Stock dentro del rango aceptable; conviene reponer pronto.',
                'umbral' => $umbral,
            ];
        }

        return [
            'clase' => 'ok',
            'etiqueta' => 'Stock saludable',
            'icono' => 'check-circle',
            'porcentaje' => 100.0,
            'mensaje' => 'Hay suficiente inventario para operar con normalidad.',
            'umbral' => $umbral,
        ];
    }

    /** @return Collection<int|string, string> */
    public static function insumosVerdurasParaLogistica(): Collection
    {
        self::asegurarCatalogosBase();

        return self::aplicarFiltroMateriaPrimaCosecha(Insumo::query())
            ->orderBy('nombre')
            ->pluck('nombre', 'insumoid');
    }

    /**
     * Fusiona insumos duplicados de material de siembra con el mismo nombre (p. ej. por almacén).
     */
    public static function consolidarMaterialSiembraPorNombre(): int
    {
        if (! Schema::hasTable('insumo')) {
            return 0;
        }

        self::asegurarCatalogosBase();

        $tipoId = TipoInsumo::query()
            ->where('nombre', self::TIPOS['material_siembra'])
            ->value('tipoinsumoid');

        if (! $tipoId) {
            return 0;
        }

        $svc = app(InsumoEliminacionService::class);
        $fusionados = 0;

        $grupos = Insumo::query()
            ->where('tipoinsumoid', $tipoId)
            ->orderBy('insumoid')
            ->get()
            ->groupBy(fn (Insumo $insumo) => mb_strtolower(trim($insumo->nombre)).'|'.(int) ($insumo->almacenid ?? 0));

        foreach ($grupos as $insumos) {
            if ($insumos->count() <= 1) {
                continue;
            }

            $canonico = $insumos->sort(function (Insumo $a, Insumo $b) {
                $porStock = (float) $b->stock <=> (float) $a->stock;

                return $porStock !== 0 ? $porStock : (int) $a->insumoid <=> (int) $b->insumoid;
            })->first();

            if ($canonico === null) {
                continue;
            }

            foreach ($insumos as $dup) {
                if ((int) $dup->insumoid === (int) $canonico->insumoid) {
                    continue;
                }

                $canonico->stock = (float) $canonico->stock + (float) $dup->stock;
                $canonico->save();
                $svc->fusionarEn((int) $dup->insumoid, (int) $canonico->insumoid);
                $fusionados++;
            }
        }

        return $fusionados;
    }

    public static function asegurarInsumoOperativo(\App\Models\Insumo $insumo): void
    {
        if (! self::esInsumoOperativo($insumo)) {
            abort(404, 'El registro no corresponde al catálogo de insumos operativos.');
        }
    }

    /**
     * Elimina insumos cuyo tipo no es uno de los cuatro oficiales y sus aplicaciones en lote.
     */
    public static function purgarInsumosConTipoInvalido(): int
    {
        self::asegurarCatalogosBase();
        $validIds = self::tiposValidosIds();

        if ($validIds === []) {
            return 0;
        }

        $invalidIds = \App\Models\Insumo::query()
            ->whereNotIn('tipoinsumoid', $validIds)
            ->pluck('insumoid');

        if ($invalidIds->isEmpty()) {
            return 0;
        }

        if (Schema::hasTable('loteinsumo')) {
            \App\Models\LoteInsumo::query()->whereIn('insumoid', $invalidIds)->delete();
        }

        if (Schema::hasTable('catalogo_tamano_conteo')) {
            DB::table('catalogo_tamano_conteo')->whereIn('insumoid', $invalidIds)->delete();
        }

        if (Schema::hasTable('insumo_presentacion')) {
            $presentacionIds = DB::table('insumo_presentacion')
                ->whereIn('insumoid', $invalidIds)
                ->pluck('insumo_presentacionid');
            if ($presentacionIds->isNotEmpty() && Schema::hasTable('inventario_presentacion_lote')) {
                DB::table('inventario_presentacion_lote')
                    ->whereIn('insumo_presentacionid', $presentacionIds)
                    ->delete();
            }
            DB::table('insumo_presentacion')->whereIn('insumoid', $invalidIds)->delete();
        }

        if (Schema::hasTable('detalle_pedido_distribucion')) {
            DB::table('detalle_pedido_distribucion')->whereIn('insumoid', $invalidIds)->update(['insumoid' => null]);
        }

        if (Schema::hasTable('almacen_movimiento')) {
            DB::table('almacen_movimiento')->whereIn('insumoid', $invalidIds)->delete();
        }

        if (Schema::hasTable('lote_produccion_materia_prima')) {
            DB::table('lote_produccion_materia_prima')->whereIn('insumoid', $invalidIds)->update(['insumoid' => null]);
        }

        if (Schema::hasTable('detalle_traslado_planta_mayorista')) {
            DB::table('detalle_traslado_planta_mayorista')->whereIn('insumoid', $invalidIds)->delete();
        }

        if (Schema::hasTable('detallepedido')) {
            DB::table('detallepedido')->whereIn('insumoid', $invalidIds)->update(['insumoid' => null]);
        }

        if (Schema::hasTable('lote') && Schema::hasColumn('lote', 'insumosemillaid')) {
            DB::table('lote')->whereIn('insumosemillaid', $invalidIds)->update(['insumosemillaid' => null]);
        }

        return \App\Models\Insumo::query()->whereIn('insumoid', $invalidIds)->delete();
    }

    /**
     * Asegura tipos oficiales y plantillas de existencias por almacén agrícola (AGR-04).
     * Ya no crea una fila global compartida (almacenid null) con stock demo.
     */
    public static function asegurarInsumosCampo(): void
    {
        self::asegurarCatalogosBase();
        self::purgarInsumosConTipoInvalido();
        self::reasignarTiposLegacyEnInsumos();

        $tiposPorSlug = self::tiposOrdenados()
            ->mapWithKeys(fn (\App\Models\TipoInsumo $t) => [
                (string) self::slugFromNombreTipo($t->nombre) => (int) $t->tipoinsumoid,
            ]);

        $kgId = \App\Models\UnidadMedida::query()->whereRaw('LOWER(nombre) LIKE ?', ['%kilogramo%'])->value('unidadmedidaid')
            ?? \App\Models\UnidadMedida::query()->value('unidadmedidaid');
        $gId = \App\Models\UnidadMedida::query()->whereRaw('LOWER(nombre) LIKE ?', ['%gramo%'])->value('unidadmedidaid')
            ?? $kgId;
        $lId = \App\Models\UnidadMedida::query()->whereRaw('LOWER(nombre) LIKE ?', ['%litro%'])->value('unidadmedidaid')
            ?? $kgId;

        $catalogo = [
            ['nombre' => 'Fertilizante NPK 15-15-15', 'slug' => 'fertilizantes', 'um' => $kgId],
            ['nombre' => 'Urea granulada 46%', 'slug' => 'fertilizantes', 'um' => $kgId],
            ['nombre' => 'Abono orgánico compost', 'slug' => 'fertilizantes', 'um' => $kgId],
            ['nombre' => 'Fungicida cobre hidróxido', 'slug' => 'pesticidas', 'um' => $gId],
            ['nombre' => 'Insecticida piretroides', 'slug' => 'pesticidas', 'um' => $lId],
            ['nombre' => 'Herbicida glifosato', 'slug' => 'pesticidas', 'um' => $lId],
        ];

        $almacenes = collect();
        if (
            \Illuminate\Support\Facades\Schema::hasTable('almacen')
            && \Illuminate\Support\Facades\Schema::hasColumn('insumo', 'almacenid')
        ) {
            $almacenes = \App\Models\Almacen::query()
                ->where('activo', true)
                ->where(function ($q) {
                    \App\Support\AlmacenAmbito::scope($q, \App\Support\AlmacenAmbito::AGRICOLA);
                })
                ->orderBy('almacenid')
                ->get();
        }

        foreach ($catalogo as $def) {
            $tipoId = $tiposPorSlug->get($def['slug']);
            if (! $tipoId || ! $def['um']) {
                continue;
            }

            // Existencia explícita por almacén (stock inicial 0; el jefe registra ingresos).
            foreach ($almacenes as $almacen) {
                $insumo = \App\Models\Insumo::query()->firstOrNew([
                    'nombre' => $def['nombre'],
                    'almacenid' => (int) $almacen->almacenid,
                ]);
                if (! $insumo->exists) {
                    $insumo->stock = 0;
                    $insumo->stockminimo = self::UMBRAL_ALERTA_STOCK;
                }
                $insumo->tipoinsumoid = $tipoId;
                $insumo->unidadmedidaid = (int) $def['um'];
                if (! InsumoImagenCatalogo::esImagenPersonalizada((string) ($insumo->imagenurl ?? ''))) {
                    $insumo->imagenurl = InsumoImagenCatalogo::urlPorNombreYTipo($def['nombre'], $def['slug']);
                }
                $insumo->save();
            }
        }

        self::rellenarImagenesInsumosOperativos();
    }

    /** Fusiona tipos legacy y elimina categorías retiradas del catálogo. */
    private static function normalizarTiposObsoletos(): void
    {
        $oficialPlagas = TipoInsumo::query()->where('nombre', 'Control de plagas')->first();
        if ($oficialPlagas === null) {
            $oficialPlagas = TipoInsumo::query()->whereIn('nombre', ['Pesticidas', 'Pesticida'])->first();
            if ($oficialPlagas) {
                $oficialPlagas->update(['nombre' => 'Control de plagas']);
            }
        }

        if ($oficialPlagas) {
            $legacyPlagas = TipoInsumo::query()
                ->whereIn('nombre', ['Pesticidas', 'Pesticida'])
                ->where('tipoinsumoid', '!=', $oficialPlagas->tipoinsumoid)
                ->get();

            foreach ($legacyPlagas as $legacy) {
                \App\Models\Insumo::query()
                    ->where('tipoinsumoid', $legacy->tipoinsumoid)
                    ->update(['tipoinsumoid' => $oficialPlagas->tipoinsumoid]);
                $legacy->delete();
            }
        }

        $tiposRiego = TipoInsumo::query()
            ->whereIn('nombre', ['Material de Riego', 'Material de riego', 'Riego'])
            ->get();

        foreach ($tiposRiego as $tipoRiego) {
            $insumoIds = \App\Models\Insumo::query()
                ->where('tipoinsumoid', $tipoRiego->tipoinsumoid)
                ->pluck('insumoid');

            if ($insumoIds->isNotEmpty()) {
                if (\Illuminate\Support\Facades\Schema::hasTable('loteinsumo')) {
                    \App\Models\LoteInsumo::query()->whereIn('insumoid', $insumoIds)->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('almacen_movimiento')) {
                    \Illuminate\Support\Facades\DB::table('almacen_movimiento')
                        ->whereIn('insumoid', $insumoIds)
                        ->delete();
                }
                \App\Models\Insumo::query()->whereIn('insumoid', $insumoIds)->delete();
            }

            $tipoRiego->delete();
        }
    }

    /** Reasigna tipoinsumoid según el nombre del tipo legacy. */
    public static function reasignarTiposLegacyEnInsumos(): void
    {
        $oficial = self::tiposOrdenados()->keyBy(fn (\App\Models\TipoInsumo $t) => (string) self::slugFromNombreTipo($t->nombre));

        \App\Models\Insumo::query()->with('tipo')->chunkById(100, function ($insumos) use ($oficial) {
            foreach ($insumos as $insumo) {
                $slug = self::slugFromNombreTipo($insumo->tipo?->nombre);
                if ($slug === null) {
                    $slug = self::inferirSlugDesdeNombreInsumo($insumo->nombre);
                }
                if ($slug === null) {
                    continue;
                }

                $tipoOficial = $oficial->get($slug);
                if ($tipoOficial && (int) $insumo->tipoinsumoid !== (int) $tipoOficial->tipoinsumoid) {
                    $insumo->tipoinsumoid = (int) $tipoOficial->tipoinsumoid;
                    $insumo->save();
                }
            }
        });
    }

    private static function inferirSlugDesdeNombreInsumo(string $nombre): ?string
    {
        $n = mb_strtolower($nombre);
        if (str_contains($n, 'fertiliz') || str_contains($n, 'npk') || str_contains($n, 'urea') || str_contains($n, 'abono') || str_contains($n, 'compost')) {
            return 'fertilizantes';
        }
        if (str_contains($n, 'fungicida') || str_contains($n, 'insecticida') || str_contains($n, 'herbicida')
            || str_contains($n, 'plaguicida') || str_contains($n, 'fitosanit')) {
            return 'pesticidas';
        }

        return null;
    }

    public static function rellenarImagenesInsumosOperativos(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('insumo', 'imagenurl')) {
            return;
        }

        $query = self::aplicarFiltroOperativo(\App\Models\Insumo::query()->with('tipo'));
        $query->chunkById(100, function ($insumos) {
            foreach ($insumos as $insumo) {
                $slug = self::slugFromNombreTipo($insumo->tipo?->nombre);
                $actual = (string) ($insumo->imagenurl ?? '');
                if (InsumoImagenCatalogo::esImagenPersonalizada($actual)) {
                    continue;
                }
                // No pisar URLs https ya válidas (salvo placeholders / Wikimedia incorrectas)
                if ($actual !== ''
                    && (str_starts_with($actual, 'http://') || str_starts_with($actual, 'https://'))
                    && ! InsumoImagenCatalogo::esUrlPlaceholder($actual)
                    && ! InsumoImagenCatalogo::esUrlWikimediaProblematica($actual)) {
                    continue;
                }
                $canonica = InsumoImagenCatalogo::urlPorNombreYTipo((string) $insumo->nombre, $slug);
                if ($actual !== $canonica || InsumoImagenCatalogo::esUrlPlaceholder($actual)) {
                    $insumo->imagenurl = $canonica;
                    $insumo->save();
                }
            }
        });
    }
}
