<?php

namespace App\Support;

use App\Models\Insumo;

final class InsumoImagenCatalogo
{
    private const DEFAULT_LOCAL = 'images/insumos/semillas-generico.jpg';

    /**
     * Fotos locales reales en public/images/insumos (van en el deploy).
     *
     * @var array<string, string>
     */
    private const LOCAL_POR_NOMBRE = [
        'chirimoya' => 'images/insumos/catalogo-20260922/insumo-148.jpg',
        'zanahoria imperator envasada' => 'images/insumos/catalogo-20260922/insumo-147.jpg',
        'papa huaycha lavada' => 'images/insumos/catalogo-20260922/insumo-146.jpg',
        'semilla de chirimoya' => 'images/insumos/catalogo-20260922/insumo-145.jpg',
        'papa huaycha' => 'images/insumos/catalogo-20260922/insumo-140.jpg',
        'papa rubíola granel' => 'images/insumos/catalogo-20260922/insumo-121.jpg',
        'semilla papa huaycha' => 'images/insumos/catalogo-20260922/insumo-17.jpg',
        'herbicida glifosato' => 'images/insumos/catalogo-20260922/insumo-37.webp',
        'insecticida piretroides' => 'images/insumos/catalogo-20260922/insumo-36.jpg',
        'fungicida cobre hidróxido' => 'images/insumos/catalogo-20260922/insumo-35.jpg',
        'fungicida cobre hidroxido' => 'images/insumos/fungicida-cobre.jpg',
        'fungicida cobre plus' => 'images/insumos/fungicida-cobre.jpg',
        'abono orgánico compost' => 'images/insumos/catalogo-20260922/insumo-34.jpg',
        'abono organico compost' => 'images/insumos/abono-compost.jpg',
        'fertilizante npk 15-15-15' => 'images/insumos/catalogo-20260922/insumo-18.png',
        'urea granulada 46%' => 'images/insumos/catalogo-20260922/insumo-33.jpg',
        'bioinsecticida neem' => 'images/insumos/catalogo-20260922/insumo-19.jpg',
        'semilla zanahoria imperator' => 'images/insumos/catalogo-20260922/insumo-58.jpg',
        'semilla certificada tomate' => 'images/insumos/semillas-generico.jpg',
        'papa amarilla' => 'images/insumos/catalogo-20260922/insumo-1.jpg',
        'herbicida orgánico ecoweed' => 'images/insumos/herbicida-glifosato.jpg',
        'herbicida organico ecoweed' => 'images/insumos/herbicida-glifosato.jpg',
        'bioestimulante foliar' => 'images/insumos/fertilizante-npk.jpg',
    ];

    /** @var array<string, string> fragmento => archivo local */
    private const LOCAL_POR_FRAGMENTO = [
        'glifosato' => 'images/insumos/herbicida-glifosato.jpg',
        'herbicida' => 'images/insumos/herbicida-glifosato.jpg',
        'piretroides' => 'images/insumos/insecticida-piretroides.jpg',
        'insecticida' => 'images/insumos/insecticida-piretroides.jpg',
        'neem' => 'images/insumos/bioinsecticida-neem.jpg',
        'fungicida' => 'images/insumos/fungicida-cobre.jpg',
        'cobre' => 'images/insumos/fungicida-cobre.jpg',
        'compost' => 'images/insumos/abono-compost.jpg',
        'abono' => 'images/insumos/abono-compost.jpg',
        'npk' => 'images/insumos/fertilizante-npk.jpg',
        'fertilizante' => 'images/insumos/fertilizante-npk.jpg',
        'urea' => 'images/insumos/urea-granulada.jpg',
        'zanahoria' => 'images/insumos/semilla-zanahoria.jpg',
        'semilla' => 'images/insumos/semillas-generico.jpg',
        'papa' => 'images/insumos/papa-amarilla.jpg',
    ];

    /** @var array<string, string> fallback Wikimedia (si no hay local) */
    private const WIKI_POR_NOMBRE = [
        'tomate pera granel' => 'images/insumos/catalogo-20260922/insumo-125.jpg',
        'cebolla colorada granel' => 'images/insumos/catalogo-20260922/insumo-124.jpg',
        'cebolla blanca granel' => 'images/insumos/catalogo-20260922/insumo-123.jpg',
        'zanahoria fresca imperator' => 'images/insumos/catalogo-20260922/insumo-122.jpg',
        'papa industrial monalisa' => 'images/insumos/catalogo-20260922/insumo-120.png',
        'lechuga crespa granel' => 'images/insumos/catalogo-20260922/insumo-126.jpg',
        'repollo blanco granel' => 'images/insumos/catalogo-20260922/insumo-127.jpg',
        'mandioca fresca' => 'images/insumos/catalogo-20260922/insumo-128.jpg',
        'maíz grano amarillo' => 'images/insumos/catalogo-20260922/insumo-129.jpg',
        'maiz grano amarillo' => 'Corn.jpg',
        'naranja valencia' => 'images/insumos/catalogo-20260922/insumo-130.jpg',
        'mango tommy' => 'images/insumos/catalogo-20260922/insumo-131.jpg',
    ];

    private const POR_TIPO_LOCAL = [
        'fertilizantes' => 'images/insumos/fertilizante-npk.jpg',
        'pesticidas' => 'images/insumos/insecticida-piretroides.jpg',
        'control_de_plagas' => 'images/insumos/insecticida-piretroides.jpg',
        'material_siembra' => 'images/insumos/semillas-generico.jpg',
    ];

    public static function urlPara(?Insumo $insumo, int $width = 256): string
    {
        if ($insumo === null) {
            return self::urlArchivoLocal(self::DEFAULT_LOCAL);
        }

        $stored = trim((string) ($insumo->imagenurl ?? ''));
        if ($stored !== '') {
            if (self::esImagenPersonalizada($stored)) {
                return self::urlArchivoLocal($stored);
            }
            // URLs Wikimedia rotas / incorrectas (p.ej. Blister roundup) → recalcular
            if (self::esUrlPlaceholder($stored) || self::esUrlWikimediaProblematica($stored)) {
                return self::urlPorNombreYTipo(
                    (string) $insumo->nombre,
                    InsumoCatalogo::slugFromNombreTipo($insumo->tipo?->nombre),
                    $width
                );
            }
            if (! str_contains($stored, 'commons.wikimedia.org')) {
                return self::ajustarAncho($stored, $width);
            }
            // Preferir local si existe mapeo
            $local = self::resolverLocal((string) $insumo->nombre, InsumoCatalogo::slugFromNombreTipo($insumo->tipo?->nombre));
            if ($local !== null) {
                return self::urlArchivoLocal($local);
            }

            return self::ajustarAncho($stored, $width);
        }

        return self::urlPorNombreYTipo(
            (string) $insumo->nombre,
            InsumoCatalogo::slugFromNombreTipo($insumo->tipo?->nombre),
            $width
        );
    }

    public static function esImagenPersonalizada(?string $valor): bool
    {
        if ($valor === null || trim($valor) === '') {
            return false;
        }

        $valor = trim($valor);

        return str_starts_with($valor, 'insumos/')
            || str_starts_with($valor, 'images/insumos/')
            || str_contains($valor, '/storage/insumos/')
            || str_contains($valor, '/images/insumos/');
    }

    public static function urlArchivoLocal(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'images/insumos/')) {
            return asset($path);
        }

        if (str_starts_with($path, 'insumos/')) {
            // Compat: storage o public/images
            if (is_file(public_path('images/'.$path))) {
                return asset('images/'.$path);
            }

            return asset('storage/'.$path);
        }

        return asset('storage/'.$path);
    }

    public static function rutaAlmacenamiento(?string $valor): ?string
    {
        if (! self::esImagenPersonalizada($valor)) {
            return null;
        }

        $valor = trim((string) $valor);
        if (str_starts_with($valor, 'insumos/')) {
            return $valor;
        }

        if (preg_match('#/storage/(insumos/.+)$#', $valor, $coincidencias)) {
            return $coincidencias[1];
        }

        return null;
    }

    public static function urlPorNombreYTipo(string $nombre, ?string $tipoSlug = null, int $width = 256): string
    {
        $local = self::resolverLocal($nombre, $tipoSlug);
        if ($local !== null) {
            return self::urlArchivoLocal($local);
        }

        $n = mb_strtolower(trim($nombre));
        if (isset(self::WIKI_POR_NOMBRE[$n])) {
            return self::wiki(self::WIKI_POR_NOMBRE[$n], $width);
        }

        return self::urlArchivoLocal(self::DEFAULT_LOCAL);
    }

    public static function ajustarAncho(string $url, int $width): string
    {
        if (str_contains($url, 'commons.wikimedia.org/wiki/Special:FilePath')) {
            if (preg_match('/width=\d+/', $url)) {
                return (string) preg_replace('/width=\d+/', 'width='.$width, $url);
            }

            return $url.(str_contains($url, '?') ? '&' : '?').'width='.$width;
        }

        if (str_contains($url, '/thumb/') && preg_match('/\/\d+px-/', $url)) {
            return (string) preg_replace('/\/\d+px-/', '/'.$width.'px-', $url);
        }

        return $url;
    }

    public static function esUrlPlaceholder(string $url): bool
    {
        return str_contains($url, 'picsum.photos')
            || str_contains($url, 'loremflickr.com')
            || str_contains($url, 'placehold.co')
            || str_contains($url, 'placeholder.com');
    }

    public static function esUrlWikimediaProblematica(string $url): bool
    {
        $u = mb_strtolower($url);

        return str_contains($u, 'blister')
            || str_contains($u, 'urea_n46')
            || str_contains($u, 'seeds_on_a_white');
    }

    private static function resolverLocal(string $nombre, ?string $tipoSlug = null): ?string
    {
        $n = mb_strtolower(trim($nombre));

        if (isset(self::LOCAL_POR_NOMBRE[$n])) {
            return self::LOCAL_POR_NOMBRE[$n];
        }

        $fragmentos = self::LOCAL_POR_FRAGMENTO;
        uksort($fragmentos, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($fragmentos as $frag => $file) {
            if (str_contains($n, $frag)) {
                return $file;
            }
        }

        if ($tipoSlug !== null && isset(self::POR_TIPO_LOCAL[$tipoSlug])) {
            return self::POR_TIPO_LOCAL[$tipoSlug];
        }

        return null;
    }

    private static function wiki(string $file, int $width): string
    {
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($file).'?width='.$width;
    }
}
