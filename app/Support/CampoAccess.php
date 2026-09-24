<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\Lote;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Alcance de almacenes agrícolas (jefe vs operario).
 * El operario solo consulta almacenes de su equipo; no administra.
 *
 * AGR-04: un jefe agricultor tiene un único almacén agrícola operativo.
 */
final class CampoAccess
{
    /** @return list<int> */
    public static function idsResponsablesVisibles(?Usuario $user): array
    {
        if (! $user) {
            return [];
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return [];
        }

        if (UsuarioRol::esJefeAgricultor($user)) {
            return [(int) $user->usuarioid];
        }

        if (UsuarioRol::debeAcotarPorAsignacion($user)) {
            $ids = [(int) $user->usuarioid];
            if ($user->supervisor_usuarioid) {
                $ids[] = (int) $user->supervisor_usuarioid;
            }

            return array_values(array_unique($ids));
        }

        return [];
    }

    public static function puedeVerAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (($almacen->ambito ?? '') !== AlmacenAmbito::AGRICOLA) {
            return false;
        }

        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return UsuarioRol::gestionaCampo($user);
        }

        $ids = self::idsResponsablesVisibles($user);
        if ($ids === []) {
            return false;
        }

        return in_array((int) ($almacen->responsable_usuarioid ?? 0), $ids, true);
    }

    public static function puedeGestionarAlmacen(?Usuario $user, Almacen $almacen): bool
    {
        if (! $user) {
            return false;
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return true;
        }

        if (! UsuarioRol::esJefeAgricultor($user)) {
            return false;
        }

        return self::puedeVerAlmacen($user, $almacen)
            && (int) ($almacen->responsable_usuarioid ?? 0) === (int) $user->usuarioid;
    }

    /**
     * @param  Builder<\App\Models\Almacen>  $query
     * @return Builder<\App\Models\Almacen>
     */
    public static function scopeAlmacenesAgricolas(Builder $query, ?Usuario $user): Builder
    {
        $query = AlmacenAmbito::scope($query, AlmacenAmbito::AGRICOLA);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (UsuarioRol::esAdminGlobal($user)) {
            return $query;
        }

        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return $query->whereRaw('1 = 0');
        }

        $ids = self::idsResponsablesVisibles($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('responsable_usuarioid', $ids);
    }

    /**
     * Almacenes agrícolas activos del responsable (sin elegir automáticamente).
     *
     * @return Collection<int, Almacen>
     */
    public static function almacenesAgricolasDeResponsable(int $responsableUsuarioId): Collection
    {
        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return collect();
        }

        return AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::AGRICOLA)
            ->where('activo', true)
            ->where('responsable_usuarioid', $responsableUsuarioId)
            ->orderBy('almacenid')
            ->get();
    }

    /**
     * Único almacén agrícola operativo del jefe. Si hay varios, no elige: lanza.
     */
    public static function almacenAgricolaOperativoDeJefe(?Usuario $jefe): ?Almacen
    {
        if (! $jefe || ! UsuarioRol::esJefeAgricultor($jefe)) {
            return null;
        }

        $almacenes = self::almacenesAgricolasDeResponsable((int) $jefe->usuarioid);

        if ($almacenes->count() === 1) {
            return $almacenes->first();
        }

        if ($almacenes->count() > 1) {
            $ids = $almacenes->pluck('almacenid')->implode(', ');
            throw new InvalidArgumentException(
                'El jefe agrícola tiene varios almacenes operativos (ids: '.$ids.'). '
                .'Se requiere decisión funcional antes de abastecer o consumir insumos; no se elige automáticamente.'
            );
        }

        return null;
    }

    public static function jefeAgricultorDesdeLote(Lote $lote): ?Usuario
    {
        $lote->loadMissing('usuario');
        $owner = $lote->usuario;
        if (! $owner) {
            return null;
        }

        if (UsuarioRol::esJefeAgricultor($owner)) {
            return $owner;
        }

        if ($owner->supervisor_usuarioid) {
            $jefe = Usuario::query()->find((int) $owner->supervisor_usuarioid);
            if ($jefe && UsuarioRol::esJefeAgricultor($jefe)) {
                return $jefe;
            }
        }

        return null;
    }

    /**
     * Almacén agrícola operativo para abastecer un lote (AGR-04 / OPA-08).
     */
    public static function almacenAgricolaParaLote(Lote $lote): Almacen
    {
        $jefe = self::jefeAgricultorDesdeLote($lote);
        if ($jefe === null) {
            throw ValidationException::withMessages([
                'loteid' => 'No se pudo determinar el jefe agrícola del lote para resolver su almacén de insumos.',
            ]);
        }

        try {
            $almacen = self::almacenAgricolaOperativoDeJefe($jefe);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'detalle_actividad_json' => $e->getMessage(),
            ]);
        }

        if ($almacen === null) {
            throw ValidationException::withMessages([
                'detalle_actividad_json' => 'El jefe agrícola no tiene un almacén agrícola operativo. Cree uno antes de asignar insumos.',
            ]);
        }

        return $almacen;
    }

    /**
     * Bloquea crear un segundo almacén agrícola operativo para el mismo responsable.
     *
     * Nota de despliegue (multi-almacén legacy):
     * - Este assert solo corre en CREATE, o en UPDATE cuando cambia `responsable_usuarioid`.
     * - Editar nombre/ubicación/capacidad de un almacén existente NO se bloquea aunque el jefe
     *   ya tenga varios almacenes legacy; el bloqueo de ambigüedad opera en consumo
     *   (`almacenAgricolaOperativoDeJefe`) y en la creación de un almacén adicional.
     * - Antes de activar en producción: ejecutar reporteJefesConVariosAlmacenesAgricolas()
     *   y resolver manualmente; nunca elegir el primer almacén automáticamente.
     */
    public static function assertPuedeCrearAlmacenAgricolaPara(int $responsableUsuarioId): void
    {
        $existentes = self::almacenesAgricolasDeResponsable($responsableUsuarioId);
        if ($existentes->isEmpty()) {
            return;
        }

        $ids = $existentes->pluck('almacenid')->implode(', ');
        throw ValidationException::withMessages([
            'responsable_usuarioid' => 'Este jefe agrícola ya tiene almacén operativo (id: '.$ids.'). '
                .'La política AGR-04 permite un único almacén agrícola por jefe. '
                .'No se eligió automáticamente cuál conservar: resuelva el caso antes de crear otro.',
        ]);
    }

    /**
     * Reporte: responsables con más de un almacén agrícola activo.
     *
     * @return list<array{usuarioid: int, cantidad: int, almacen_ids: list<int>, nombres: list<string>}>
     */
    public static function reporteJefesConVariosAlmacenesAgricolas(): array
    {
        if (! Schema::hasColumn('almacen', 'responsable_usuarioid')) {
            return [];
        }

        $grouped = AlmacenAmbito::scope(Almacen::query(), AlmacenAmbito::AGRICOLA)
            ->where('activo', true)
            ->whereNotNull('responsable_usuarioid')
            ->orderBy('almacenid')
            ->get(['almacenid', 'nombre', 'responsable_usuarioid'])
            ->groupBy(fn (Almacen $a) => (int) $a->responsable_usuarioid);

        $out = [];
        foreach ($grouped as $uid => $items) {
            if ($items->count() <= 1) {
                continue;
            }
            $out[] = [
                'usuarioid' => (int) $uid,
                'cantidad' => $items->count(),
                'almacen_ids' => $items->pluck('almacenid')->map(fn ($id) => (int) $id)->values()->all(),
                'nombres' => $items->pluck('nombre')->values()->all(),
            ];
        }

        return $out;
    }
}
