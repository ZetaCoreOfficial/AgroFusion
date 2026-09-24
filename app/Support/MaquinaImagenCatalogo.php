<?php

namespace App\Support;

/**
 * URLs públicas (HTTPS) de referencia por código de máquina.
 * Fallback cuando storage/app/public/maquinas_planta no existe en el host (p. ej. Railway).
 */
final class MaquinaImagenCatalogo
{
    /**
     * @return array<string, string> codigo => url https
     */
    public static function urlsPorCodigo(): array
    {
        // Fotografías industriales incluidas y verificadas en cada despliegue.
        return [
            'L-100' => asset('images/maquinas/L-100.jpg'),
            'BC-20' => asset('images/maquinas/BC-20.png'),
            'SE-10' => asset('images/maquinas/SE-10.jpg'),
            'BD-500' => asset('images/maquinas/BD-500.jpg'),
            'MX-200' => asset('images/maquinas/MX-200.jpg'),
            'EX-300' => asset('images/maquinas/EX-300.jpg'),
            'MD-400' => asset('images/maquinas/MD-400.jpg'),
            'SC-500' => asset('images/maquinas/SC-500.png'),
            'TR-600' => asset('images/maquinas/TR-600.jpg'),
            'EV-700' => asset('images/maquinas/EV-700.jpg'),
            'ET-800' => asset('images/maquinas/ET-800.jpg'),
        ];
    }

    public static function urlPorCodigo(?string $codigo): ?string
    {
        $codigo = strtoupper(trim((string) $codigo));
        if ($codigo === '') {
            return null;
        }

        return self::urlsPorCodigo()[$codigo] ?? null;
    }

    public static function urlPorNombre(?string $nombre): ?string
    {
        $nombre = strtoupper(trim((string) $nombre));
        if ($nombre === '') {
            return null;
        }

        foreach (array_keys(self::urlsPorCodigo()) as $codigo) {
            if (str_contains($nombre, $codigo)) {
                return self::urlPorCodigo($codigo);
            }
        }

        return null;
    }
}
