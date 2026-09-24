<?php



return [

    'modules' => [

        'envios' => [

            'create' => 'envios.create',

            'read' => 'envios.view',

            'update' => 'envios.update',

            'delete' => 'envios.delete',

            'admin' => 'envios.admin.view',

        ],

        'vehiculos' => [

            'create' => 'vehiculos.create',

            'read' => 'vehiculos.view',

            'update' => 'vehiculos.update',

            'delete' => 'vehiculos.delete',

        ],

        'transportistas' => [

            'create' => 'transportistas.create',

            'read' => 'transportistas.view',

            'update' => 'transportistas.update',

            'delete' => 'transportistas.delete',

        ],

        'direcciones' => [

            'create' => 'direcciones.create',

            'read' => 'direcciones.view',

            'update' => 'direcciones.update',

            'delete' => 'direcciones.delete',

        ],

        'lotes' => [

            'create' => 'lotes.create',

            'read' => 'lotes.view',

            'update' => 'lotes.update',

            'delete' => 'lotes.delete',

            'admin' => 'lotes.admin',

        ],

        'inventario' => [

            'create' => 'inventario.create',

            'read' => 'inventario.view',

            'update' => 'inventario.update',

            'delete' => 'inventario.delete',

            'admin' => 'inventario.admin',

        ],

        'usuarios' => [

            'create' => 'usuarios.create',

            'read' => 'usuarios.view',

            'update' => 'usuarios.update',

            'delete' => 'usuarios.delete',

            'admin' => 'usuarios.admin',

        ],

        'pedidos' => [

            'create' => 'pedidos.create',

            'read' => 'pedidos.view',

            'update' => 'pedidos.update',

            'delete' => 'pedidos.delete',

            'admin' => 'pedidos.admin',

        ],

        'ventas' => [

            'create' => 'ventas.create',

            'read' => 'ventas.view',

            'update' => 'ventas.update',

            'delete' => 'ventas.delete',

            'admin' => 'ventas.admin',

        ],

        'certificaciones' => [

            'create' => 'certificaciones.create',

            'read' => 'certificaciones.view',

            'update' => 'certificaciones.update',

            'delete' => 'certificaciones.delete',

            'admin' => 'certificaciones.admin',

        ],

        'catalogos' => [

            'create' => 'catalogos.create',

            'read' => 'catalogos.view',

            'update' => 'catalogos.update',

            'delete' => 'catalogos.delete',

            'admin' => 'catalogos.admin',

        ],

        'incidentes' => [

            'create' => 'incidentes.create',

            'read' => 'incidentes.view',

            'update' => 'incidentes.update',

            'delete' => 'incidentes.delete',

            'admin' => 'incidentes.admin',

            'resolve' => 'incidentes.resolve',

        ],

        'documentos' => [

            'create' => 'documentos.create',

            'read' => 'documentos.view',

            'update' => 'documentos.update',

            'delete' => 'documentos.delete',

            'admin' => 'documentos.admin',

        ],

        'rutas_multi' => [

            'create' => 'rutas_multi.create',

            'read' => 'rutas_multi.view',

            'update' => 'rutas_multi.update',

            'delete' => 'rutas_multi.delete',

            'admin' => 'rutas_multi.admin',

            'reorder' => 'rutas_multi.reorder',

        ],

        'asignaciones' => [

            'create' => 'asignaciones.create',

            'read' => 'asignaciones.view',

            'update' => 'asignaciones.update',

            'delete' => 'asignaciones.delete',

            'admin' => 'asignaciones.admin',

            'multiple' => 'asignaciones.multiple',

        ],

        'monitoreo' => [

            'read' => 'monitoreo.view',

            'simulate' => 'monitoreo.simulate',

            'admin' => 'monitoreo.admin',

        ],

        'panel_planta' => [

            'read' => 'panel_planta.view',

        ],

        'recepcion_planta' => [

            'read' => 'recepcion_planta.view',

            'confirm' => 'recepcion_planta.confirm',

        ],

        'lote_produccion' => [

            'read' => 'lote_produccion.view',

            'create' => 'lote_produccion.create',

            'update' => 'lote_produccion.update',

            'delete' => 'lote_produccion.delete',

        ],

        'panel_transportista' => [

            'read' => 'panel_transportista.view',

        ],

        'almacen_ingresos' => [

            'read' => 'almacen.ingresos.view',

            'create' => 'almacen.ingresos.create',

        ],

        'almacen_salidas' => [

            'read' => 'almacen.salidas.view',

            'create' => 'almacen.salidas.create',

        ],

        'almacen_movimientos' => [

            'read' => 'almacen.movimientos.view',

        ],

        'almacen_reportes' => [

            'read' => 'almacen.reportes.view',

        ],

        'reportes' => [

            'read' => 'reportes.view',

        ],

        'panel_agricultor' => [

            'read' => 'panel_agricultor.view',

        ],

        'panel_mayorista' => [

            'read' => 'panel_mayorista.view',

        ],

        'solicitudes' => [

            'read' => 'solicitudes.view',

            'approve' => 'solicitudes.approve',

        ],

        'punto_venta' => [

            'create' => 'punto_venta.create',

            'read' => 'punto_venta.view',

            'update' => 'punto_venta.update',

            'delete' => 'punto_venta.delete',

            // MIN-06: ajuste explícito del stock del PDV (con motivo y movimiento auditado).
            'ajustar' => 'punto_venta.ajuste_stock',

        ],

        'pedidos_distribucion' => [

            'create' => 'pedidos_distribucion.create',

            'read' => 'pedidos_distribucion.view',

            'update' => 'pedidos_distribucion.update',

            'delete' => 'pedidos_distribucion.delete',

        ],

    ],



    'role_permissions' => [

        // Admin = supervisión: consulta todos los módulos y administra usuarios, solicitudes,
        // catálogos y reportes. No ejecuta flujos de negocio (sin create/update/delete operativos).
        'admin' => [

            // Administración de la plataforma
            'usuarios.view',

            'usuarios.create',

            'usuarios.update',

            'usuarios.delete',

            'usuarios.admin',

            'solicitudes.view',

            'solicitudes.approve',

            'catalogos.view',

            'catalogos.create',

            'catalogos.update',

            'catalogos.delete',

            'reportes.view',

            // Datos maestros de flota (no son flujos de negocio; ningún otro rol los administra)
            'vehiculos.view',

            'vehiculos.create',

            'vehiculos.update',

            'vehiculos.delete',

            'transportistas.view',

            'transportistas.create',

            'transportistas.update',

            'transportistas.delete',

            'direcciones.view',

            'direcciones.create',

            'direcciones.update',

            'direcciones.delete',

            // Consulta global (solo lectura)
            'envios.view',

            'envios.admin.view',

            'lotes.view',

            'inventario.view',

            'pedidos.view',

            'ventas.view',

            'certificaciones.view',

            'incidentes.view',

            'documentos.view',

            'rutas_multi.view',

            'asignaciones.view',

            'monitoreo.view',

            'panel_planta.view',

            'recepcion_planta.view',

            'lote_produccion.view',

            'panel_transportista.view',

            'almacen.ingresos.view',

            'almacen.salidas.view',

            'almacen.movimientos.view',

            'almacen.reportes.view',

            'panel_agricultor.view',

            'panel_mayorista.view',

            'punto_venta.view',

            'pedidos_distribucion.view',

        ],

        'jefe_agricultor' => [

            'panel_agricultor.view',

            'lotes.view',

            'lotes.create',

            'lotes.update',

            'lotes.delete',

            'certificaciones.view',

            'certificaciones.create',

            'certificaciones.update',

            'certificaciones.delete',

            'inventario.view',

            'inventario.create',

            'inventario.update',

            'inventario.delete',

            'pedidos.view',

            'pedidos.create',

            'pedidos.update',

            'almacen.movimientos.view',

            'almacen.ingresos.create',

            'almacen.salidas.create',

            'almacen.reportes.view',

            'usuarios.view',

            'usuarios.create',

            'usuarios.update',

            'usuarios.delete',

            'asignaciones.view',

            'envios.view',

            'reportes.view',

            'documentos.view',

            'panel_agricultor.view',

            'lotes.view',

            'certificaciones.view',

            'inventario.view',

        ],

        'agricultor' => [

            'panel_agricultor.view',

            'lotes.view',

            'certificaciones.view',

            'inventario.view',

        ],

        'planta' => [

            'panel_planta.view',

            'recepcion_planta.view',

            'recepcion_planta.confirm',

            'lote_produccion.view',

            'inventario.view',

            'inventario.create',

            'inventario.update',

            'almacen.movimientos.view',

            'almacen.ingresos.create',

            'almacen.salidas.create',

            'almacen.reportes.view',

            'pedidos_distribucion.view',

            'pedidos_distribucion.update',

        ],

        'jefe_planta' => [

            'panel_planta.view',

            'envios.view',

            'asignaciones.view',

            'recepcion_planta.view',

            'recepcion_planta.confirm',

            'lote_produccion.view',

            'lote_produccion.create',

            'lote_produccion.update',

            'lote_produccion.delete',

            'inventario.view',

            'inventario.create',

            'inventario.update',

            'inventario.delete',

            'almacen.movimientos.view',

            'almacen.ingresos.create',

            'almacen.salidas.create',

            'almacen.reportes.view',

            'pedidos.view',

            'pedidos.create',

            'pedidos.update',

            'pedidos.delete',

            'pedidos_distribucion.view',

            'pedidos_distribucion.update',

            'pedidos_distribucion.delete',

            'usuarios.view',

            'usuarios.create',

            'usuarios.update',

            'usuarios.delete',

            'reportes.view',

            'documentos.view',

        ],

        'transportista' => [

            'panel_transportista.view',

            'envios.view',

            'asignaciones.view',

            'rutas_multi.view',

            'documentos.view',

            'documentos.create',

            'incidentes.view',

            'incidentes.create',

            // TRA-11: sin envios.update, pedidos_distribucion.update, monitoreo.view ni incidentes.delete;
            // incidentes.update queda acotado a los incidentes que él reportó (TRA-07).
            'incidentes.update',

            'pedidos.view',

            'pedidos_distribucion.view',

        ],

        'minorista' => [

            'punto_venta.view',

            'punto_venta.create',

            'punto_venta.update',

            'punto_venta.delete',

            'punto_venta.ajuste_stock',

            'pedidos_distribucion.view',

            'pedidos_distribucion.create',

            'pedidos_distribucion.update',

        ],

        'jefe_mayorista' => [

            'panel_mayorista.view',

            'asignaciones.view',

            'envios.view',

            'documentos.view',

            'inventario.view',

            'inventario.create',

            'inventario.update',

            'inventario.delete',

            'almacen.movimientos.view',

            'almacen.ingresos.create',

            'almacen.salidas.create',

            'almacen.reportes.view',

            'pedidos_distribucion.view',

            'pedidos_distribucion.create',

            'pedidos_distribucion.update',

            'pedidos_distribucion.delete',

            'pedidos.create',

            // MAY-01: sin usuarios.* — el mayorista (incluido este slug legacy) no administra usuarios.

        ],

        'mayorista' => [

            'panel_mayorista.view',

            'asignaciones.view',

            'envios.view',

            'documentos.view',

            'inventario.view',

            'inventario.create',

            'inventario.update',

            'inventario.delete',

            'almacen.movimientos.view',

            'almacen.ingresos.create',

            'almacen.salidas.create',

            'almacen.reportes.view',

            'pedidos_distribucion.view',

            'pedidos_distribucion.create',

            'pedidos_distribucion.update',

            'pedidos_distribucion.delete',

            'pedidos.create',

        ],

    ],

];


