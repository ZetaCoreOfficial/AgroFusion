<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CROSS-A (TRA-01, TRA-03, MAY-15, MIN-07, TRA-12): cada firma de cierre registra la cuenta que firmó.
 * Permite exigir que la firma de recepción sea de un receptor autenticado distinto del transportista.
 * Columnas nuevas y nulas (las firmas históricas quedan sin firmante); no modifica datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['firma_transportista_envio', 'firma_recepcion_envio'] as $tabla) {
            if (Schema::hasTable($tabla) && ! Schema::hasColumn($tabla, 'firmante_usuarioid')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->unsignedBigInteger('firmante_usuarioid')->nullable();
                    $table->foreign('firmante_usuarioid')
                        ->references('usuarioid')
                        ->on('usuario')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['firma_transportista_envio', 'firma_recepcion_envio'] as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'firmante_usuarioid')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropForeign(['firmante_usuarioid']);
                    $table->dropColumn('firmante_usuarioid');
                });
            }
        }
    }
};
