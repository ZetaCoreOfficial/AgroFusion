<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAY-14: en la recepción planta → mayorista se registra la cantidad realmente recibida por línea
 * y el motivo de la diferencia. Nulo = sin registrar (se asume lo despachado, como antes).
 * La cantidad está en la unidad principal de la línea (unidades si la línea es por presentación, kg si no).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('detalle_traslado_planta_mayorista')
            || Schema::hasColumn('detalle_traslado_planta_mayorista', 'cantidad_recibida')) {
            return;
        }

        Schema::table('detalle_traslado_planta_mayorista', function (Blueprint $table) {
            $table->decimal('cantidad_recibida', 14, 4)->nullable();
            $table->string('motivo_diferencia', 255)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('detalle_traslado_planta_mayorista')
            || ! Schema::hasColumn('detalle_traslado_planta_mayorista', 'cantidad_recibida')) {
            return;
        }

        Schema::table('detalle_traslado_planta_mayorista', function (Blueprint $table) {
            $table->dropColumn(['cantidad_recibida', 'motivo_diferencia']);
        });
    }
};
