<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\Lote;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AlmacenAmbito
{
    public const AGRICOLA = 'agricola';

    public const PLANTA = 'planta';

    public const PUNTO_VENTA = 'punto_venta';

    public const MAYORISTA = 'mayorista';

    /** @var array<string, string> */
    public const TITULOS = [
        self::AGRICOLA => 'Almacén agrícola',
        self::PLANTA => 'Almacén de planta',
        self::MAYORISTA => 'Almacén mayorista',
        self::PUNTO_VENTA => 'Inventario punto de venta',
    ];

    public static function fromRequest(?Request $request = null): string
    {
        $request ??= request();

        $ambito = $request?->route('ambito');
        if (self::esValido($ambito)) {
            return $ambito;
        }

        $routeName = $request?->route()?->getName() ?? '';
        if (str_starts_with($routeName, self::routePrefix(self::MAYORISTA).'.')) {
            return self::MAYORISTA;
        }
        if (str_starts_with($routeName, self::routePrefix(self::PLANTA).'.')) {
            return self::PLANTA;
        }
        if (str_starts_with($routeName, self::routePrefix(self::PUNTO_VENTA).'.')) {
            return self::PUNTO_VENTA;
        }
        if (str_starts_with($routeName, self::routePrefix(self::AGRICOLA).'.')) {
            return self::AGRICOLA;
        }

        abort(404, 'Ámbito de almacén no definido.');
    }

    public static function esValido(?string $ambito): bool
    {
        return in_array($ambito, [self::AGRICOLA, self::PLANTA, self::MAYORISTA, self::PUNTO_VENTA], true);
    }

    public static function routePrefix(string $ambito): string
    {
        return match ($ambito) {
            self::AGRICOLA => 'almacen-agricola',
            self::PLANTA => 'almacen-planta',
            self::MAYORISTA => 'almacen-mayorista',
            self::PUNTO_VENTA => 'almacen-punto-venta',
            default => 'almacen-agricola',
        };
    }

    public static function titulo(string $ambito): string
    {
        return self::TITULOS[$ambito] ?? 'Almacén';
    }

    /** @return array{ambito: string, rutaPrefijo: string, tituloModulo: string} */
    public static function contexto(?Request $request = null): array
    {
        $ambito = self::fromRequest($request);

        return [
            'ambito' => $ambito,
            'rutaPrefijo' => self::routePrefix($ambito),
            'tituloModulo' => self::titulo($ambito),
        ];
    }

    public static function usuarioPuedeVer(?Usuario $user, string $ambito): bool
    {
        if (! $user) {
            return false;
        }

        // Visibilidad (no operación): el admin supervisa todos los ámbitos.
        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if ($ambito === self::AGRICOLA) {
            return $user->hasAnyRole(['agricultor', 'jefe_agricultor'])
                || ($user->can('inventario.view') && $user->canany(['lotes.view', 'pedidos.view']));
        }

        if ($ambito === self::PLANTA) {
            return $user->hasAnyRole(['planta', 'jefe_planta'])
                || ($user->can('inventario.view') && $user->canany([
                    'panel_planta.view',
                    'recepcion_planta.view',
                    'lote_produccion.view',
                ]));
        }

        if ($ambito === self::MAYORISTA) {
            return $user->hasAnyRole(['mayorista', 'jefe_mayorista'])
                || ($user->can('inventario.view') && $user->can('pedidos_distribucion.view'));
        }

        if ($ambito === self::PUNTO_VENTA) {
            return $user->hasAnyRole(['minorista'])
                || ($user->can('inventario.view') && $user->can('punto_venta.view'));
        }

        return false;
    }

    public static function scope(Builder $query, string $ambito): Builder
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('almacen', 'ambito')) {
            $query = $query->where('ambito', $ambito);

            if ($ambito === self::AGRICOLA) {
                return self::excluirMarcadoresPuntoVenta(
                    self::excluirMarcadoresMayorista(
                        self::excluirMarcadoresPlanta($query)
                    )
                );
            }

            return $query;
        }

        return self::scopeLegacyPorNombre($query, $ambito);
    }

    public static function scopeParaUsuario(Builder $query, string $ambito, ?Usuario $user): Builder
    {
        if ($ambito === self::MAYORISTA) {
            return MayoristaAccess::scopeAlmacenesMayorista($query, $user);
        }

        return self::scope($query, $ambito);
    }

    /** Excluye almacenes mayoristas aunque el campo ambito esté mal cargado. */
    private static function excluirMarcadoresMayorista(Builder $query): Builder
    {
        return $query
            ->whereRaw('LOWER(TRIM(nombre)) NOT LIKE ?', ['%mayorista%'])
            ->whereDoesntHave('tipoAlmacen', fn ($t) => $t->whereRaw('LOWER(TRIM(nombre)) LIKE ?', ['%mayorista%']));
    }

    /** Excluye almacenes de planta aunque el campo ambito esté mal cargado. */
    private static function excluirMarcadoresPlanta(Builder $query): Builder
    {
        return $query
            ->whereRaw('LOWER(TRIM(nombre)) NOT LIKE ?', ['%planta%'])
            ->whereDoesntHave('tipoAlmacen', fn ($t) => $t->whereRaw('LOWER(TRIM(nombre)) LIKE ?', ['%planta%']));
    }

    /** Excluye inventarios de puntos de venta / minoristas. */
    private static function excluirMarcadoresPuntoVenta(Builder $query): Builder
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('punto_venta')) {
            $idsPdv = \Illuminate\Support\Facades\DB::table('punto_venta')
                ->whereNotNull('almacenid')
                ->pluck('almacenid')
                ->filter()
                ->all();

            if ($idsPdv !== []) {
                $query = $query->whereNotIn('almacenid', $idsPdv);
            }
        }

        return $query
            ->whereRaw('LOWER(TRIM(nombre)) NOT LIKE ?', ['almacén —%'])
            ->whereRaw('LOWER(TRIM(nombre)) NOT LIKE ?', ['almacen —%']);
    }

    public static function resolverAmbito(Almacen $almacen): string
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('almacen', 'ambito')
            && self::esValido($almacen->ambito)) {
            return $almacen->ambito;
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('punto_venta')) {
            $vinculadoPdv = \Illuminate\Support\Facades\DB::table('punto_venta')
                ->where('almacenid', $almacen->almacenid)
                ->exists();

            if ($vinculadoPdv) {
                return self::PUNTO_VENTA;
            }
        }

        $nombre = mb_strtolower(trim($almacen->nombre ?? ''));
        $tipo = mb_strtolower(trim($almacen->tipoAlmacen?->nombre ?? ''));

        if (str_contains($nombre, 'mayorista') || str_contains($tipo, 'mayorista')) {
            return self::MAYORISTA;
        }

        if (str_contains($nombre, 'planta') || str_contains($tipo, 'planta')) {
            return self::PLANTA;
        }

        if (
            str_starts_with($nombre, 'almacén —')
            || str_starts_with($nombre, 'almacen —')
            || str_contains($nombre, 'pdv ')
            || str_contains($nombre, 'punto de venta')
            || (str_contains($nombre, 'mercado') && (float) ($almacen->capacidad ?? 0) <= 1000)
        ) {
            return self::PUNTO_VENTA;
        }

        return self::AGRICOLA;
    }

    /** Compatibilidad si aún no existe la columna ambito. */
    private static function scopeLegacyPorNombre(Builder $query, string $ambito): Builder
    {
        if ($ambito === self::PLANTA) {
            return $query->where(function ($q) {
                $q->whereRaw('LOWER(nombre) LIKE ?', ['%planta%'])
                    ->orWhereHas('tipoAlmacen', fn ($t) => $t->whereRaw('LOWER(nombre) LIKE ?', ['%planta%']));
            });
        }

        return $query->where(function ($q) {
            $q->whereRaw('LOWER(nombre) NOT LIKE ?', ['%planta%'])
                ->whereDoesntHave('tipoAlmacen', fn ($t) => $t->whereRaw('LOWER(nombre) LIKE ?', ['%planta%']));
        });
    }

    public static function asegurarAmbitosEnRegistros(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('almacen', 'ambito')) {
            return;
        }

        Almacen::with('tipoAlmacen')->get()->each(function (Almacen $a) {
            $ambito = self::resolverAmbito($a);

            if ($a->ambito !== $ambito) {
                $a->update(['ambito' => $ambito]);
            }
        });
    }

    /**
     * Almacenes agrícolas que el usuario puede elegir al enviar cosecha desde trazabilidad.
     *
     * @param  Builder<\App\Models\Almacen>  $query
     */
    public static function scopeEnvioCampoDesdeLote(Builder $query, Lote $lote, ?Usuario $user): Builder
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return $query;
        }

        if (! $user || UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return $query->where('responsable_usuarioid', (int) $user->usuarioid);
        }

        $ids = array_values(array_unique(array_filter([
            (int) $user->usuarioid,
            $lote->usuarioid ? (int) $lote->usuarioid : null,
        ])));

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('responsable_usuarioid', $ids);
    }

    public static function puedeUsarAlmacenEnvioCampo(Almacen $almacen, Lote $lote, ?Usuario $user): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return true;
        }

        $responsable = (int) ($almacen->responsable_usuarioid ?? 0);
        // Enviar cosecha a un almacén es operativo: el admin supervisor no lo hace.
        if ($responsable <= 0 || ! UsuarioRol::puedeOperar($user)) {
            return false;
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return $responsable === (int) $user->usuarioid;
        }

        $permitidos = array_values(array_unique(array_filter([
            (int) $user->usuarioid,
            $lote->usuarioid ? (int) $lote->usuarioid : null,
        ])));

        return in_array($responsable, $permitidos, true);
    }

    public static function urlVerAlmacen(Almacen $almacen): string
    {
        $prefijo = self::routePrefix(self::resolverAmbito($almacen));

        return route($prefijo.'.show', $almacen);
    }

    public static function puedeVerAlmacenDesdeHistorial(?Usuario $user): bool
    {
        return $user && (
            UsuarioRol::esAdminGlobal($user)
            || UsuarioRol::esJefePlanta($user)
            || UsuarioRol::esJefeAgricultor($user)
        );
    }
}
