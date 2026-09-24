<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAY-13 / MIN-09: canal de origen explícito del pedido de distribución.
 *
 *   sistema    → el minorista lo creó en la plataforma
 *   whatsapp / telefono / presencial / otro → el mayorista lo registró a mano
 *
 * Columnas nuevas con valor por defecto; los envíos iniciados por el mayorista ya existentes
 * se marcan como registro manual («otro», registrado por su creador) para que no parezcan
 * creados por el minorista. No se borra ni modifica ningún otro dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pedido_distribucion')) {
            return;
        }

        if (! Schema::hasColumn('pedido_distribucion', 'canal_origen')) {
            Schema::table('pedido_distribucion', function (Blueprint $table) {
                $table->string('canal_origen', 20)->default('sistema');
                $table->unsignedBigInteger('registrado_manual_por_usuarioid')->nullable();
                $table->foreign('registrado_manual_por_usuarioid', 'pedido_distribucion_registrado_manual_fk')
                    ->references('usuarioid')
                    ->on('usuario')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('pedido_distribucion', 'envio_iniciado_mayorista')) {
            DB::table('pedido_distribucion')
                ->where('envio_iniciado_mayorista', true)
                ->where('canal_origen', 'sistema')
                ->update([
                    'canal_origen' => 'otro',
                    'registrado_manual_por_usuarioid' => DB::raw('creado_por_usuarioid'),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pedido_distribucion') || ! Schema::hasColumn('pedido_distribucion', 'canal_origen')) {
            return;
        }

        Schema::table('pedido_distribucion', function (Blueprint $table) {
            $table->dropForeign('pedido_distribucion_registrado_manual_fk');
            $table->dropColumn(['canal_origen', 'registrado_manual_por_usuarioid']);
        });
    }
};
