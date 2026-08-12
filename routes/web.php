<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CocinaController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\ComandaController;
use App\Http\Controllers\PromocionController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\FinanzasController;
use App\Http\Controllers\MeserosFinanzasController;
use App\Http\Controllers\MisMesasController;
use App\Http\Controllers\PlanoEspacialController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\MesaOperacionController;
use App\Http\Controllers\HistorialCajaController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\PlataformaDeliveryController;
use App\Http\Controllers\CorteController;

// ==========================================
// --- AUTENTICACIÓN ---
// ==========================================
Route::get('/', function () { 
    return view('auth.login'); 
})->name('login');

Route::post('/login-pin', [LoginController::class, 'loginConPin'])->name('login.pin');

// Desactivamos la ruta de login interna de Auth para evitar el conflicto
Auth::routes(['login' => false]);
// ==========================================
// --- RUTAS PÚBLICAS (SIN AUTENTICACIÓN) ---
// ==========================================
// Lógica de imágenes desactivada temporalmente.
// Route::get('/productos/api/{id}/imagen', [ProductoController::class, 'imagen'])->name('admin.productos.api.imagen');


// ==========================================
// --- RUTAS PROTEGIDAS (AUTH) ---
// ==========================================
Route::middleware(['auth'])->group(function () {

    // ------------------------------------------
    // MÓDULO MESERO
    // ------------------------------------------
    Route::prefix('mesero')->name('mesero.')->group(function () {
        Route::get('/dashboard', [MesaController::class, 'index'])->name('dashboard');
        Route::get('/comanda/{mesa}', [MesaController::class, 'show'])->name('comanda.show');

        // "Mis mesas": lo que atendio HOY el mesero en sesion. Sin permisos de
        // modulo a proposito: cada quien ve lo suyo y el controlador filtra por
        // el usuario autenticado.
        Route::get('/mis-mesas', [MisMesasController::class, 'index'])->name('mis-mesas');
        Route::get('/mis-mesas/{orden}/detalle', [MisMesasController::class, 'detalle'])->name('mis-mesas.detalle');

        // --- ACCIONES QUE GENERAN CONSUMO ---
        // Requieren un turno de caja abierto. Si la caja está cerrada, lo
        // que se levante aquí no entraría a ningún corte y descuadraría
        // ventas e inventario.
        //
        // Se deja FUERA a propósito: ver la comanda, la precuenta, cancelar
        // un producto ya enviado y transferir entre mesas. Todo eso puede
        // hacer falta para cerrar cuentas que quedaron abiertas de antes, y
        // bloquearlo dejaría al mesero atrapado sin poder resolverlas.
        Route::middleware('caja.abierta')->group(function () {
            Route::post('/comanda/enviar', [MesaController::class, 'enviar'])->name('comanda.enviar');
            Route::post('/mesa/store', [MesaController::class, 'store'])->name('mesa.store');
            Route::post('/mesa/reabrir', [ComandaController::class, 'reabrir'])->name('mesa.reabrir');
            Route::post('/delivery/crear', [DeliveryController::class, 'crear'])->name('delivery.crear');
        Route::delete('/delivery/{mesa}/cancelar-vacio', [DeliveryController::class, 'cancelarVacio'])->name('delivery.cancelar-vacio');
        });

        // NUEVO: cancelación de un producto individual ya enviado a cocina,
        // protegida por confirmación + NIP de Capitán/Administrador.
        Route::patch('/comanda/detalle/{detalle}/cancelar', [MesaController::class, 'cancelarProducto'])->name('comanda.detalle.cancelar');
        
        // --- NUEVA RUTA PARA GUARDAR LA PROPINA ---
        Route::get('/comanda/{mesa}/precuenta', [ComandaController::class, 'precuenta'])->name('comanda.precuenta');

        Route::post('/capitan/verify', [ComandaController::class, 'verificarCapitan'])->name('capitan.verify');
        Route::get('/mesas/abiertas', [ComandaController::class, 'apiMesasAbiertas'])->name('mesas.abiertas');
        Route::get('/meseros/activos', [ComandaController::class, 'apiMeserosActivos'])->name('meseros.activos');
        Route::post('/comanda/transferir', [ComandaController::class, 'transferirProductos'])->name('comanda.transferir');
        Route::patch('/comanda/{mesa}/personas', [MesaController::class, 'actualizarPersonas'])->name('comanda.personas'); 
        Route::get('/comanda/promociones/activas', [MesaController::class, 'promocionesActivas'])->name('comanda.promociones.activas');
        // NOTA: mesa.store, mesa.reabrir y delivery.crear estaban declaradas
        // aquí abajo también. Se quitaron porque Laravel se queda con la
        // ÚLTIMA definición de cada ruta, y esas copias sin el middleware
        // 'caja.abierta' anulaban el bloqueo. Ahora viven solo arriba.
    });
    
    // ------------------------------------------
    // MÓDULOS ADMINISTRATIVOS
    // ------------------------------------------
    Route::name('admin.')->group(function () {
        
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard')
            ->middleware('permiso:Dashboard,mostrar');

        // --- EMPLEADOS ---
        Route::prefix('admin/empleados')->name('empleados.')->group(function () {
            Route::get('/', [EmpleadoController::class, 'index'])->name('index')->middleware('permiso:Empleados,mostrar');
            Route::post('/store', [EmpleadoController::class, 'store'])->name('store')->middleware('permiso:Empleados,crear');
            Route::put('/{id}', [EmpleadoController::class, 'update'])->name('update')->middleware('permiso:Empleados,editar');
            Route::delete('/{id}', [EmpleadoController::class, 'destroy'])->name('destroy')->middleware('permiso:Empleados,eliminar');
            Route::get('/{id}/permisos', [EmpleadoController::class, 'permisos'])->name('permisos')->middleware('permiso:Empleados,gestionar');
            Route::post('/{id}/permisos', [EmpleadoController::class, 'actualizarPermisos'])->name('permisos.update')->middleware('permiso:Empleados,gestionar');
            Route::patch('/{id}/reactivar', [EmpleadoController::class, 'reactivar'])->name('reactivar')->middleware('permiso:Empleados,gestionar');
        });

        // --- INVENTARIO ---
        Route::middleware(['permiso:Inventario,mostrar'])->prefix('/admin/inventario')->name('inventario.')->group(function () {
            Route::get('/bajo-stock-pdf', [InventarioController::class, 'exportarPdfBajoStock'])->name('exportar_pdf_bajo_stock');
            Route::get('/', [InventarioController::class, 'index'])->name('index');
            Route::post('/store', [InventarioController::class, 'store'])->name('store')->middleware('permiso:Inventario,crear');
            Route::post('/movimiento', [InventarioController::class, 'registrarMovimiento'])->name('movimiento')->middleware('permiso:Inventario,crear');
            Route::put('/{id}', [InventarioController::class, 'update'])->name('update')->middleware('permiso:Inventario,editar');
            Route::delete('/{id}', [InventarioController::class, 'destroy'])->name('destroy')->middleware('permiso:Inventario,eliminar');
        });

        // --- PRODUCTOS (ALIMENTOS) ---
        Route::middleware(['permiso:Productos,mostrar'])->prefix('productos')->name('productos.')->group(function () {
            Route::get('/', [ProductoController::class, 'index'])->name('index');
            Route::get('/api/productos', [ProductoController::class, 'getProductos'])->name('api.productos');
            Route::get('/api/estadisticas', [ProductoController::class, 'getEstadisticas'])->name('api.estadisticas');
            // La ruta de la imagen fue movida al bloque de RUTAS PÚBLICAS
            Route::post('/api/store', [ProductoController::class, 'store'])->name('api.store')->middleware('permiso:Productos,crear');
            Route::put('/api/{id}', [ProductoController::class, 'update'])->name('api.update')->middleware('permiso:Productos,editar');
            Route::patch('/api/{id}/toggle-disponibilidad', [ProductoController::class, 'toggleDisponibilidad'])->name('api.toggle')->middleware('permiso:Productos,editar');
            Route::delete('/api/{id}', [ProductoController::class, 'destroy'])->name('api.destroy')->middleware('permiso:Productos,eliminar');
        });

        // --- CATEGORÍAS ---
        Route::middleware(['permiso:Categorías,mostrar'])->resource('categorias', CategoriaController::class);

       // --- MÓDULO CAJA ---
        Route::middleware(['permiso:Caja,mostrar'])->prefix('caja')->name('caja.')->group(function () {
            
            // Dominios Financieros (CajaController)
            Route::get('/', [CajaController::class, 'index'])->name('index');
            Route::get('/flujo', [CajaController::class, 'flujoDeCaja'])->name('flujo');
            Route::get('/reporte-pdf/{id}', [CajaController::class, 'generarReportePdf'])->name('reporte.pdf');
            Route::get('/ticket/orden/{ordenId}', [CajaController::class, 'imprimirTicketPorOrden'])->name('ticket.imprimir.orden');
            Route::get('/ticket/{id}', [CajaController::class, 'imprimirTicket'])->name('ticket.imprimir');
            Route::get('/api/estadisticas', [CajaController::class, 'getEstadisticas'])->name('api.estadisticas');
            Route::get('/api/mesas', [CajaController::class, 'apiMesas'])->name('api.mesas');
            Route::get('/venta/{id}/detalle', [CajaController::class, 'detalleVenta'])->name('venta.detalle');
            Route::get('/api/movimientos', [CajaController::class, 'getMovimientos'])->name('api.movimientos');
            Route::get('/api/promociones-activas', [CajaController::class, 'getPromocionesActivas'])->name('api.promociones');
            
            Route::post('/abrir', [CajaController::class, 'abrir'])->name('abrir')->middleware('permiso:Caja,gestionar');
            Route::post('/cerrar', [CajaController::class, 'cerrar'])->name('cerrar')->middleware('permiso:Caja,gestionar');
            Route::post('/api/store', [CajaController::class, 'store'])->name('api.store')->middleware('permiso:Caja,crear');
            // Dominios Tácticos de Mesa y Cobros (MesaOperacionController)
            Route::get('/cobrar/{id}', [MesaOperacionController::class, 'cobrar'])->name('cobrar');
            Route::post('/api/estado-mesa', [MesaOperacionController::class, 'getEstadoMesa'])->name('api.estado-mesa');
            
            // CORRECCIÓN: Quitamos el '/api/' de la URL física y limpiamos el nombre para que machee con cobro.js
            Route::post('/procesar-pago', [MesaOperacionController::class, 'procesarPago'])->name('procesar-pago')->middleware('permiso:Caja,crear');
            Route::patch('/orden/{id}/propina', [MesaOperacionController::class, 'actualizarPropina'])->name('orden.propina')->middleware('permiso:Caja,crear');

            // --- DIVISIÓN DE CUENTA ---
            Route::post('/division/iniciar', [MesaOperacionController::class, 'iniciarDivision'])->name('division.iniciar')->middleware('permiso:Caja,crear');
            Route::post('/division/asignar', [MesaOperacionController::class, 'asignarProductoDivision'])->name('division.asignar')->middleware('permiso:Caja,crear');
            Route::post('/division/cancelar', [MesaOperacionController::class, 'cancelarDivision'])->name('division.cancelar')->middleware('permiso:Caja,crear');

            // Cancelar la cuenta COMPLETA sin cobrarla (cliente que se fue sin
            // pagar, comanda levantada por error, cortesía...).
            // Exige permiso de ELIMINAR en Caja: es una acción destructiva que
            // cierra una cuenta sin que entre dinero, así que no debería
            // poder hacerla cualquier usuario del módulo.
            Route::post('/cuenta/cancelar', [MesaOperacionController::class, 'cancelarCuenta'])->name('cuenta.cancelar')->middleware('permiso:Caja,eliminar');

            // Descuento de la cuenta. Se movió del módulo de Mesas a Caja:
            // ahora lo autoriza quien cobra, no quien levanta el pedido.
            Route::post('/cuenta/descuento', [MesaOperacionController::class, 'aplicarDescuento'])->name('cuenta.descuento')->middleware('permiso:Caja,editar');
            
            Route::post('/api/liberar-mesa', [MesaOperacionController::class, 'liberarMesa'])->name('api.liberar-mesa')->middleware('permiso:Caja,gestionar');
            Route::post('/api/abrir-mesa', [MesaOperacionController::class, 'abrirMesa'])->name('api.abrir-mesa')->middleware('permiso:Caja,gestionar');

            // CORREGIDO: apunta a ConfiguracionController (guarda en tabla de configuración),
            // NO a CajaController (ese guardaba en sesión y CajaService nunca lo lee).
            Route::post('/toggle-iva', [ConfiguracionController::class, 'toggleIva'])->name('toggle-iva');

            Route::delete('/{id}', [MesaOperacionController::class, 'destroy'])->name('destroy')->middleware('permiso:Caja,eliminar');
        });

        // --- MESAS ---
        Route::middleware(['permiso:Mesas,mostrar'])->prefix('mesas')->name('mesas.')->group(function () {
            Route::get('/', [MesaController::class, 'index'])->name('index');
            Route::get('/api/mesas', [MesaController::class, 'getMesas'])->name('api.mesas');
            Route::post('/api', [MesaController::class, 'store'])->name('api.store')->middleware('permiso:Mesas,crear');
            Route::post('/api/posiciones', [MesaController::class, 'guardarPosiciones'])->name('api.posiciones')->middleware('permiso:Mesas,editar');
            Route::patch('/api/{id}/posicion', [MesaController::class, 'updatePosicion'])->name('api.posicion')->middleware('permiso:Mesas,editar');
            Route::patch('/api/fusionar', [MesaController::class, 'fusionarMesas'])->name('api.fusionar')->middleware('permiso:Mesas,editar');
            Route::patch('/api/{id}/estado', [MesaController::class, 'cambiarEstado'])->name('api.estado')->middleware('permiso:Mesas,editar');
            Route::put('/api/{id}', [MesaController::class, 'update'])->name('api.update')->middleware('permiso:Mesas,editar');
            Route::delete('/api/{id}', [MesaController::class, 'destroy'])->name('api.destroy')->middleware('permiso:Mesas,eliminar');
        });

        // --- PLANO ESPACIAL ---
        Route::middleware(['permiso:Mesas,mostrar'])->prefix('plano-espacial')->name('plano-espacial.')->group(function () {
            Route::get('/', [PlanoEspacialController::class, 'index'])->name('index');
            Route::get('/api/mesas', [PlanoEspacialController::class, 'getMesas'])->name('api.mesas');
            Route::get('/api/mesas/{id}', [PlanoEspacialController::class, 'getMesa'])->name('api.mesa');
            Route::post('/api/guardar', [PlanoEspacialController::class, 'guardarPlano'])->name('api.guardar')->middleware('permiso:Mesas,editar');
            Route::post('/api/crear', [PlanoEspacialController::class, 'store'])->name('api.crear')->middleware('permiso:Mesas,crear');
            Route::post('/api/actualizar/{id}', [PlanoEspacialController::class, 'update'])->middleware('permiso:Mesas,editar');
            Route::delete('/api/eliminar/{id}', [PlanoEspacialController::class, 'eliminarDelPlano'])->name('api.eliminar')->middleware('permiso:Mesas,eliminar');
        });

        // --- COCINA ---
        Route::middleware(['permiso:Cocina,mostrar'])->prefix('cocina')->name('cocina.')->group(function () {
            Route::get('/', [CocinaController::class, 'index'])->name('index');
            Route::get('/api/comandas', [CocinaController::class, 'apiComandas'])->name('api.comandas');
            Route::patch('/orden/{id}/estado', [CocinaController::class, 'actualizarEstado'])->name('orden.estado')->middleware('permiso:Cocina,editar');

            // Historial de comandas: lo que llegó al area al momento del envío.
            // Es el respaldo inmutable que permite resolver discusiones entre
            // el mesero y cocina ("yo lo pedí sin cebolla" / "aquí dice con todo").
            Route::get('/historial', [CocinaController::class, 'historial'])->name('historial');

            // Tachar un producto individual (ya listo) sin marcar toda la comanda.
            // Solo requiere editar porque es una accion operativa del cocinero.
            Route::patch('/detalle/{id}/listo', [CocinaController::class, 'marcarDetalleListoParaCocina'])
                ->name('detalle.listo')
                ->middleware('permiso:Cocina,editar');
        });

        // --- PROMOCIONES ---
        Route::middleware(['permiso:Promociones,mostrar'])->prefix('promociones')->name('promociones.')->group(function () {
            Route::get('/', [PromocionController::class, 'index'])->name('index');
            Route::get('/{promocion}/edit', [PromocionController::class, 'edit'])->name('edit');
            Route::post('/store', [PromocionController::class, 'store'])->name('store')->middleware('permiso:Promociones,crear');
            Route::put('/{promocion}', [PromocionController::class, 'update'])->name('update')->middleware('permiso:Promociones,editar');
            Route::delete('/{promocion}', [PromocionController::class, 'destroy'])->name('destroy')->middleware('permiso:Promociones,eliminar');
        }); 

        // --- ROLES ---
        Route::middleware(['permiso:Roles,mostrar'])->prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RolController::class, 'index'])->name('index');
            Route::post('/', [RolController::class, 'store'])->name('store')->middleware('permiso:Roles,crear');
            Route::put('/{id}', [RolController::class, 'update'])->name('update')->middleware('permiso:Roles,editar');
            Route::delete('/{id}', [RolController::class, 'destroy'])->name('destroy')->middleware('permiso:Roles,eliminar');
        });

        // --- FINANZAS ---
        Route::middleware(['permiso:Finanzas,mostrar'])->prefix('finanzas')->name('finanzas.')->group(function () {
            Route::get('/', [FinanzasController::class, 'index'])->name('index');
            Route::get('/exportar-csv', [FinanzasController::class, 'exportarCSV'])->name('exportar');
            Route::post('/estadisticas-periodo', [FinanzasController::class, 'estadisticasPeriodo'])->name('estadisticas.periodo');
            Route::get('/corte-mensual', [FinanzasController::class, 'corteMensual'])->name('corte.mensual');
            Route::get('/corte-mensual/exportar', [FinanzasController::class, 'exportarCorteCSV'])->name('corte.exportar');
            Route::get('/corte-mensual/pdf', [FinanzasController::class, 'exportarCortePDF'])->name('corte.pdf');

            // --- DESGLOSE POR MESERO Y TURNO ---
            // Ver el desglose y el detalle de mesas solo requiere 'mostrar'.
            Route::get('/meseros', [MeserosFinanzasController::class, 'index'])->name('meseros');
            Route::get('/meseros/detalle', [MeserosFinanzasController::class, 'detalle'])->name('meseros.detalle');

            // Registrar el aporte al fondo de barra y cocina SI mueve dinero,
            // asi que pide permiso de editar y no solo de ver.
            Route::post('/meseros/aporte', [MeserosFinanzasController::class, 'aplicarAporte'])
                ->name('meseros.aporte')
                ->middleware('permiso:Finanzas,editar');
        });

        // --- GASTOS Y NÓMINA ---
        Route::middleware(['permiso:Finanzas,crear'])->group(function () {
            Route::prefix('gastos')->name('gastos.')->group(function () {
                Route::post('/', [FinanzasController::class, 'guardarGasto'])->name('store');
            });
            Route::prefix('pagos-nomina')->name('pagos-nomina.')->group(function () {
                Route::post('/', [FinanzasController::class, 'guardarNomina'])->name('store');
            });
        });

        // --- PRODUCTOS VENDIDOS (Corte por área) ---
        Route::middleware(['permiso:Inventario,mostrar'])->prefix('corte')->name('corte.')->group(function () {
            Route::get('/', [CorteController::class, 'index'])->name('index');
            Route::get('/pdf', [CorteController::class, 'descargarPdf'])->name('pdf');
        });

        Route::post('/permisos/store', [PermisoController::class, 'store'])->name('permisos.store');

        // --- DELIVERY (Configuración de comisiones por plataforma) ---
        Route::middleware(['permiso:Delivery,mostrar'])->prefix('delivery')->name('delivery.')->group(function () {
            Route::get('/', [PlataformaDeliveryController::class, 'index'])->name('index');
            Route::post('/', [PlataformaDeliveryController::class, 'store'])->name('store')->middleware('permiso:Delivery,crear');
            Route::put('/{id}', [PlataformaDeliveryController::class, 'update'])->name('update')->middleware('permiso:Delivery,editar');
            Route::delete('/{id}', [PlataformaDeliveryController::class, 'destroy'])->name('destroy')->middleware('permiso:Delivery,eliminar');
        });

    });

    // ------------------------------------------
    // HISTORIAL CAJAS
    // ------------------------------------------
    Route::middleware(['permiso:Historial de Cajas,mostrar'])->prefix('historial-cajas')->name('historial.')->group(function () {
        Route::get('/', [HistorialCajaController::class, 'index'])->name('index');
        Route::get('/{id}', [HistorialCajaController::class, 'show'])->name('show');

        // PDF del corte desde el historial. Se declara aquí (y no se reutiliza
        // 'admin.caja.reporte.pdf') porque aquella ruta exige permiso de Caja:
        // un usuario que solo tiene "Historial de Cajas" podría ver el detalle
        // en pantalla pero recibiría un 403 al intentar abrir el PDF.
        Route::get('/{id}/pdf', [CajaController::class, 'generarReportePdf'])->name('pdf');
    });

    // ------------------------------------------
    // LOGOUT
    // ------------------------------------------
    Route::post('/logout', function () { 
        Auth::logout(); 
        return redirect()->route('login'); 
    })->name('logout');
});