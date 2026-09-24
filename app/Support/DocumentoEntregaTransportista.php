<?php

namespace App\Support;

use App\Models\DocumentoEntrega;
use App\Models\EnvioAsignacionMultiple;
use Illuminate\Database\Eloquent\Builder;

final class DocumentoEntregaTransportista
{
    /**
     * POD/comprobante: debe existir una asignación del envío al transportista.
     * Si viene ID de envío externo, se usa ese criterio; si no, pedido.
     */
    public static function puedeSubirParaSusAsignaciones(int $usuarioid, ?string $externoEnvioId, ?int $pedidoId): bool
    {
        $tieneExterno = $externoEnvioId !== null && $externoEnvioId !== '';
        if (!$tieneExterno && $pedidoId === null) {
            return false;
        }

        $q = EnvioAsignacionMultiple::query()->where('transportista_usuarioid', $usuarioid);

        if ($externoEnvioId !== null && $externoEnvioId !== '') {
            return $q->where('externo_envio_id', $externoEnvioId)->exists();
        }

        return $q->where('pedidoid', $pedidoId)->exists();
    }

    /**
     * Listados: envíos asociados por asignación o archivos subidos por el propio usuario.
     */
    public static function restringirConsultaTransportista(Builder $query, int $usuarioid): Builder
    {
        $externoIds = EnvioAsignacionMultiple::query()
            ->where('transportista_usuarioid', $usuarioid)
            ->whereNotNull('externo_envio_id')
            ->pluck('externo_envio_id');

        $pedidoIds = EnvioAsignacionMultiple::query()
            ->where('transportista_usuarioid', $usuarioid)
            ->whereNotNull('pedidoid')
            ->pluck('pedidoid');

        // Rutas planta → mayorista y mayorista → PDV del conductor (TRA-13): sus guías usan el código de ruta.
        $externoIds = $externoIds->merge(self::codigosRutasDelConductor($usuarioid))->unique()->values();

        return $query->where(function (Builder $w) use ($usuarioid, $externoIds, $pedidoIds) {
            $w->where('usuarioid', $usuarioid);
            if ($externoIds->isNotEmpty()) {
                $w->orWhereIn('externo_envio_id', $externoIds);
            }
            if ($pedidoIds->isNotEmpty()) {
                $w->orWhereIn('pedidoid', $pedidoIds);
            }
        });
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private static function codigosRutasDelConductor(int $usuarioid): \Illuminate\Support\Collection
    {
        return \App\Models\RutaDistribucion::query()
            ->where('transportista_usuarioid', $usuarioid)
            ->whereNotNull('codigo')
            ->pluck('codigo')
            ->map(fn ($c) => (string) $c);
    }

    public static function puedeVerDocumento(DocumentoEntrega $documento, int $usuarioid): bool
    {
        if ((int) $documento->usuarioid === $usuarioid) {
            return true;
        }

        $codigo = (string) ($documento->externo_envio_id ?? '');
        if ($codigo !== '' && self::codigosRutasDelConductor($usuarioid)->contains($codigo)) {
            return true;
        }

        return self::puedeSubirParaSusAsignaciones(
            $usuarioid,
            $documento->externo_envio_id,
            $documento->pedidoid ? (int) $documento->pedidoid : null
        );
    }
}
