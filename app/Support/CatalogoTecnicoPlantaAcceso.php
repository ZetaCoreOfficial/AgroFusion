<?php

namespace App\Support;

use App\Models\Usuario;

/**
 * Acceso a catálogos técnicos de planta (máquinas, procesos, plantillas, variables).
 *
 * OWNERSHIP / SCOPE (Fase D — JPL-07 / OPP-01):
 * Los modelos MaquinaPlanta, ProcesoPlanta, PlantillaTransformacion y VariableEstandar
 * son catálogos GLOBALES: no tienen FK a almacén, planta ni responsable.
 * No se inventa ownership por planta. El aislamiento A≠B no aplica hasta una migración
 * de esquema; la capa SCOPE se reduce a rol: solo gestionaPlanta muta y administra UI.
 *
 * Operario (rol planta): consulta lo necesario en procesamiento/tareas; no CRUD ni
 * pantallas de administración de catálogo.
 */
final class CatalogoTecnicoPlantaAcceso
{
    /** Admin de catálogo (index/show/formularios). */
    public static function puedeAdministrar(?Usuario $user): bool
    {
        return UsuarioRol::gestionaPlanta($user);
    }

    /** Crear / editar / eliminar / activar-desactivar. */
    public static function puedeGestionar(?Usuario $user): bool
    {
        return UsuarioRol::gestionaPlanta($user);
    }

    /**
     * Lecturas auxiliares JSON usadas por formularios de procesamiento
     * (p. ej. variables sugeridas de máquina).
     */
    public static function puedeLeerAuxiliar(?Usuario $user): bool
    {
        return UsuarioRol::esAdminGlobal($user)
            || UsuarioRol::esPlantaOperativo($user);
    }
}
