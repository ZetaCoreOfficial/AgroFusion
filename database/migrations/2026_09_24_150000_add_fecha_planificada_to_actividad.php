<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPA-09 — fecha planificada distinta de fechainicio/fechafin (ejecución real).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('actividad')) {
            return;
        }

        Schema::table('actividad', function (Blueprint $table) {
            if (! Schema::hasColumn('actividad', 'fecha_planificada')) {
                $table->date('fecha_planificada')->nullable()->after('fechainicio');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('actividad')) {
            return;
        }

        Schema::table('actividad', function (Blueprint $table) {
            if (Schema::hasColumn('actividad', 'fecha_planificada')) {
                $table->dropColumn('fecha_planificada');
            }
        });
    }
};
