<?php

namespace App\Support;

final class TrazabilidadFotoReferencia
{
    /** Restore presentation only; keep the original evidence URL in the database. */
    public static function paraUrl(?string $url): ?string
    {
        $path = parse_url((string) $url, PHP_URL_PATH) ?: '';
        $references = [
            'moF3BJtBeYRRkQN0P9duOdQixpHd3gIsyTI5CwKK.png' => 'images/insumos/catalogo-20260922/insumo-145.jpg',
            'tfdKSrICuPC2XuwVtKrm2qcxQlHlUEj4qwbk6oL2.jpg' => 'images/insumos/catalogo-20260922/insumo-19.jpg',
            'Gyc7JzFEayHXQKoehhqCFgUPZNQqSmldFcFF3dD7.jpg' => 'images/insumos/catalogo-20260922/insumo-59.jpg',
            '7DnWy2CJPfzQQEVA6ygRl9xxvE5gOfjZyuqQb76u.png' => 'images/trazabilidad/riego-referencia.jpg',
            'Ykn09WBiFahNCo8k7wwOBcbfL7zzKiPaADgDT0cE.jpg' => 'images/insumos/catalogo-20260922/insumo-148.jpg',
        ];
        if (!str_starts_with($path, '/storage/') || is_file(storage_path('app/public/'.substr($path, 9)))) {
            return null;
        }
        $reference = $references[basename($path)] ?? null;
        return $reference && is_file(public_path($reference)) ? asset($reference) : null;
    }
}
