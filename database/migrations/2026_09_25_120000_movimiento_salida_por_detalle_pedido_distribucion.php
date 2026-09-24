<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAY-19: la salida mayorista de un pedido se identifica por su línea (detalle), no por
 * «insumo + número de pedido», que colisiona cuando el mismo producto va en dos líneas con
 * distinta presentación. La restricción única impide descontar dos veces la misma línea.
 * Columna nueva y nula: los movimientos históricos no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('almacen_movimiento') || Schema::hasColumn('almacen_movimiento', 'detallepedidodistribucionid')) {
            return;
        }

        Schema::table('almacen_movimiento', function (Blueprint $table) {
            $table->unsignedBigInteger('detallepedidodistribucionid')->nullable();
            $table->unique('detallepedidodistribucionid', 'almacen_movimiento_detalle_pedido_distribucion_unique');
            $table->foreign('detallepedidodistribucionid', 'almacen_movimiento_detalle_pedido_distribucion_fk')
                ->references('detallepedidodistribucionid')
                ->on('detalle_pedido_distribucion')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('almacen_movimiento') || ! Schema::hasColumn('almacen_movimiento', 'detallepedidodistribucionid')) {
            return;
        }

        Schema::table('almacen_movimiento', function (Blueprint $table) {
            $table->dropForeign('almacen_movimiento_detalle_pedido_distribucion_fk');
            $table->dropUnique('almacen_movimiento_detalle_pedido_distribucion_unique');
            $table->dropColumn('detallepedidodistribucionid');
        });
    }
};
