<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\CuentaDivision;
use App\Models\DetalleOrden;
use App\Models\Mesa;
use App\Models\Orden;
use App\Models\CajaMovimiento;
use App\Models\FlujoCaja;
use App\Services\CajaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MesaOperacionController extends Controller
{
    protected $cajaService;

    public function __construct(CajaService $cajaService)
    {
        $this->cajaService = $cajaService;
    }

    public function cobrar($id)
    {
        if (!CajaMovimiento::where('estado', 'abierta')->exists()) {
            return redirect()->route('admin.caja.index')->with('error', 'Debes abrir una caja antes de procesar cobros.');
        }

        $mesa = Mesa::findOrFail($id);
        $desglose = $this->cajaService->obtenerDesgloseMesa($mesa);
        $ordenes = $desglose['ordenes'];

        if ($ordenes->isEmpty()) {
            if ($mesa->estado === Mesa::ESTADO_OCUPADA) {
                $this->cajaService->liberarMesa($mesa);
            }
            return redirect()->route('admin.caja.index')->with('error', 'La mesa no tiene órdenes activas.');
        }

        $orden = $ordenes->first()->load('mesero');

        return view('admin.cobrar.index', [
            'mesa' => $mesa,
            'ordenes' => $ordenes,
            'orden' => $orden, 
            'subtotal' => $desglose['subtotal'],
            'subtotalBruto' => $desglose['subtotalBruto'],
            'descuentoPromociones' => $desglose['descuentoPromociones'],
            'productosConDescuento' => $desglose['productosConDescuento'],
            /* IVA_BLOCK_START — iva_cobrar_view
            'iva' => $desglose['iva'],
            'ivaHabilitado' => $desglose['ivaHabilitado'],
            'ivaPorcentaje' => $desglose['ivaPorcentaje'],
            IVA_BLOCK_END */
            'iva' => 0,
            'ivaHabilitado' => false,
            'ivaPorcentaje' => 0,
            'propina' => $desglose['propina'],
            'totalPagar' => $desglose['total'],
            'cuentasDivididas' => $desglose['cuentasDivididas'],
            'totalCuentasDivision' => $desglose['totalCuentasDivision'],
            'division' => $desglose['division'],
            // --- NUEVO: comisión de plataforma de delivery ---
            'esDelivery' => $desglose['esDelivery'],
            'plataformaNombre' => $desglose['plataformaNombre'],
            'comisionPorcentaje' => $desglose['comisionPorcentaje'],
            'comisionMonto' => $desglose['comisionMonto'],
            'comisionIvaPorcentaje' => $desglose['comisionIvaPorcentaje'],
            'comisionIvaMonto' => $desglose['comisionIvaMonto'],
            'comisionTotal' => $desglose['comisionTotal'],
            // Descuento manual aplicado desde Caja
            'descuentoPorcentaje' => $desglose['descuentoPorcentaje'],
            'descuentoCaja' => $desglose['descuentoCaja'],
        ]);
    }

    public function procesarPago(Request $request): JsonResponse
    {
        $request->validate([
            'mesa_id' => 'required|exists:mesas,id',
            // NUEVO: si la mesa está dividida, indica QUÉ parte se está cobrando.
            // Si se omite y la mesa no tiene división activa, se cobra completa (comportamiento original).
            'cuenta_division_id' => 'nullable|integer|exists:cuentas_division,id',
            'pagos' => 'required|array|min:1',
            'pagos.*.metodo' => 'required|string|in:efectivo,tarjeta,transferencia',
            'pagos.*.monto' => 'required|numeric|min:0',
            'pagos.*.referencia' => 'nullable|string|max:255',
        ]);

        $cajaActiva = CajaMovimiento::where('estado', 'abierta')->first();

        if (!$cajaActiva) {
            return response()->json([
                'success' => false, 
                'message' => 'No hay ningún turno de caja abierto en este momento.'
            ], 422);
        }

        try {
            $resultado = DB::transaction(function () use ($request, $cajaActiva) {
                $mesa = Mesa::findOrFail($request->mesa_id);

                // Si viene cuenta_division_id, estamos cobrando SOLO esa parte.
                $cuentaDivision = null;
                if ($request->filled('cuenta_division_id')) {
                    $cuentaDivision = CuentaDivision::where('id', $request->cuenta_division_id)
                        ->where('mesa_id', $mesa->id)
                        ->firstOrFail();

                    if ($cuentaDivision->estado === 'pagada') {
                        throw new \Exception('Esta parte de la cuenta ya fue pagada.');
                    }
                } elseif ($mesa->cuentasDivisionPendientes()->exists()) {
                    // La mesa tiene una división activa pero se intentó cobrar
                    // todo de golpe sin indicar a quién: lo bloqueamos para no
                    // duplicar el cobro de las partes ya pagadas.
                    throw new \Exception('Esta mesa tiene la cuenta dividida. Selecciona a la persona que vas a cobrar.');
                }

                // AJUSTE: se toman TODAS las órdenes activas de la mesa, no
                // solo la primera. La propina puede vivir en cualquiera de
                // ellas (normalmente se concentra en la primera vía
                // actualizarPropina), así que sumamos por seguridad.
                $ordenesActivas = $mesa->ordenesActivas()->get();
                $orden = $ordenesActivas->first();

                // La propina "base" a prorratear es la de la parte que se está
                // cobrando (si hay división) o la de toda la mesa (pago único).
                $propinaBase = $cuentaDivision
                    ? (float) $cuentaDivision->propina
                    : $ordenesActivas->sum(fn ($o) => floatval($o->propina));

                $sumaTotal = collect($request->pagos)->sum(fn($p) => floatval($p['monto']));

                // --- VALIDACIÓN CRÍTICA: el monto pagado debe cubrir lo que se debe ---
                // Sin esto, se podía "cobrar" una cuenta de $450 con solo $400 y el
                // sistema la marcaba como pagada igual. Se usa una tolerancia de 1
                // centavo para absorber redondeos de floats, no como margen real.
                $montoEsperado = $cuentaDivision
                    ? (float) $cuentaDivision->total
                    : $this->cajaService->obtenerDesgloseMesa($mesa)['total'];

                if ($sumaTotal < $montoEsperado - 0.01) {
                    $faltante = round($montoEsperado - $sumaTotal, 2);
                    throw new \Exception("El monto pagado es insuficiente. Faltan $" . number_format($faltante, 2) . " para cubrir el total de $" . number_format($montoEsperado, 2) . ".");
                }

                // --- SE DESCUENTA EL CAMBIO ANTES DE REGISTRAR EL INGRESO ---
                //
                // El cajero teclea lo que le ENTREGA el cliente (si la cuenta es
                // de $260 y paga con un billete de $500, se captura $500). Ese
                // excedente es el cambio: sale del cajón de vuelta al cliente y
                // NO es venta. Antes se registraba completo en flujo_caja, así
                // que Finanzas contaba $500 donde solo entraron $260.
                //
                // El excedente se resta SOLO del efectivo, porque el cambio
                // siempre se devuelve en efectivo: nadie paga de más con tarjeta
                // o transferencia esperando vuelto.
                $excedente = round($sumaTotal - $montoEsperado, 2);

                $pagosNormalizados = [];
                foreach ($request->pagos as $pago) {
                    $monto  = floatval($pago['monto']);
                    $metodo = strtolower($pago['metodo']);

                    if ($metodo === 'efectivo' && $excedente > 0) {
                        $descuento = min($monto, $excedente);
                        $monto     = round($monto - $descuento, 2);
                        $excedente = round($excedente - $descuento, 2);
                    }

                    $pagosNormalizados[] = [
                        'metodo'     => $metodo,
                        'monto'      => $monto,
                        'referencia' => $pago['referencia'] ?? null,
                    ];
                }

                // A partir de aquí se trabaja con los montos REALES cobrados.
                // La propina se prorratea sobre estos y no sobre lo entregado:
                // si no, un billete grande inflaba el denominador y la parte
                // rastreable de la propina salía más baja de lo que debía.
                $sumaTotal = collect($pagosNormalizados)->sum(fn($p) => $p['monto']);
                $sumaRastreable = collect($pagosNormalizados)
                    ->whereIn('metodo', ['tarjeta', 'transferencia'])
                    ->sum(fn($p) => $p['monto']);

                $propinaRastreableTotal = ($sumaTotal > 0)
                    ? round($propinaBase * ($sumaRastreable / $sumaTotal), 2)
                    : 0;

                $etiquetaPersona = $cuentaDivision
                    ? " (Persona {$cuentaDivision->numero_cuenta}/{$cuentaDivision->total_partes})"
                    : '';

                // Datos del descuento para el historial
                $descuentoPct  = (float) ($orden?->descuento_porcentaje ?? 0);
                $desgloseMesa  = $this->cajaService->obtenerDesgloseMesa($mesa);
                $subtotalBruto = $desgloseMesa['subtotalBruto'] ?? $desgloseMesa['subtotal'];
                $montoCobrado  = $sumaTotal; // ya sin cambio ni excedente

                // Armar sufijo del concepto con descuento si aplica
                $sufDescuento = '';
                if ($descuentoPct > 0) {
                    $montoDescontado = round($subtotalBruto - $montoCobrado, 2);
                    $sufDescuento = " — Descuento {$descuentoPct}% (\$" . number_format($montoDescontado, 2) . ") por " . (auth()->user()->nombre ?? auth()->user()->name);
                }

                // Caso especial: descuento 100% — registrar aunque el monto sea $0
                if ($montoCobrado <= 0 && $descuentoPct > 0) {
                    FlujoCaja::create([
                        'caja_movimiento_id' => $cajaActiva->id,
                        'tipo'               => 'ingreso',
                        'categoria'          => 'Ventas',
                        'concepto'           => "Pago Mesa #M" . $mesa->numero . $etiquetaPersona . $sufDescuento,
                        'monto'              => 0,
                        'metodo_pago'        => 'descuento',
                        'referencia'         => "Descuento {$descuentoPct}% — {$subtotalBruto} original",
                        'fecha'              => now(),
                        'registrado_por'     => auth()->id(),
                        'flujoable_id'       => $orden ? $orden->id : null,
                        'flujoable_type'     => $orden ? get_class($orden) : null,
                    ]);
                }

                foreach ($pagosNormalizados as $pago) {
                    $monto = $pago['monto'];
                    $metodo = $pago['metodo'];
                    
                    if ($monto > 0) {
                        FlujoCaja::create([
                            'caja_movimiento_id' => $cajaActiva->id, 
                            'tipo'               => 'ingreso',
                            'categoria'          => 'Ventas',
                            'concepto'           => "Pago Mesa #M" . $mesa->numero . $etiquetaPersona . $sufDescuento,
                            'monto'              => $monto,
                            'metodo_pago'        => $metodo,
                            'referencia'         => !empty($pago['referencia']) ? trim($pago['referencia']) : null,
                            'fecha'              => now(),

                            // Quien COBRA, que no siempre es quien abrio el turno:
                            // varios cajeros pueden trabajar en el mismo turno.
                            'registrado_por'     => auth()->id(),

                            'flujoable_id'       => $orden ? $orden->id : null,
                            'flujoable_type'     => $orden ? get_class($orden) : null,
                        ]);

                        if ($orden && $orden->mesero_id && $propinaRastreableTotal > 0 && in_array($metodo, ['tarjeta', 'transferencia'])) {
                            $montoPropina = round($propinaRastreableTotal * ($monto / $sumaRastreable), 2);

                            if ($montoPropina > 0) {
                                \App\Models\PropinaMesero::create([
                                    'caja_movimiento_id' => $cajaActiva->id,
                                    'orden_id'           => $orden->id,
                                    'mesa_id'            => $mesa->id,
                                    'mesero_id'          => $orden->mesero_id,
                                    'metodo_pago'        => $metodo,
                                    'monto'              => $montoPropina,
                                ]);
                            }
                        }
                    }
                }

                if ($cuentaDivision) {
                    $cuentaDivision->update([
                        'estado'             => 'pagada',
                        'pagada_el'          => now(),
                        'caja_movimiento_id' => $cajaActiva->id,
                    ]);

                    if (!$mesa->cuentasDivisionPendientes()->exists()) {
                        $this->cajaService->liberarMesa($mesa);
                        
                        // LIMPIEZA AUTOMÁTICA DE MESAS WEB TEMPORALES
                        if ($mesa->seccion === 'WEB' && $mesa->id != 1) {
                            $mesa->delete();
                        }
                        
                        return ['mesaLiberada' => true];
                    }

                    return ['mesaLiberada' => false];
                }

                $this->cajaService->liberarMesa($mesa);
                
                // LIMPIEZA AUTOMÁTICA DE MESAS WEB TEMPORALES (Pago Normal)
                if ($mesa->seccion === 'WEB' && $mesa->id != 1) {
                    $mesa->delete();
                }

                return ['mesaLiberada' => true];
            });

            return response()->json([
                'success'       => true,
                'mesa_liberada' => $resultado['mesaLiberada'],
                'message'       => $resultado['mesaLiberada']
                    ? 'El pago se procesó y registró correctamente.'
                    : 'Pago registrado. Aún quedan personas pendientes de pagar en esta mesa.',
                'redirect_url'  => $resultado['mesaLiberada'] ? route('admin.caja.index') : null,
            ]);

        } catch (\Exception $e) {
            Log::error('Error procesando pago de la mesa #' . $request->mesa_id . ': ' . $e->getMessage());
            
            return response()->json([
                'success' => false, 
                'message' => $e->getMessage() ?: 'Hubo un problema al procesar la venta en el servidor.'
            ], 422);
        }
    }

    // ==========================================================================
    // DIVISIÓN DE CUENTA
    // ==========================================================================

    /**
     * Inicia la división de la cuenta de una mesa: 'equitativa' (partes
     * iguales) o 'por_producto' (cada quien paga lo que consumió).
     */
    public function iniciarDivision(Request $request): JsonResponse
    {
        $request->validate([
            'mesa_id'  => 'required|exists:mesas,id',
            'tipo'     => 'required|in:equitativa,por_producto',
            'personas' => 'required|integer|min:2|max:20',
        ]);

        try {
            $mesa = Mesa::findOrFail($request->mesa_id);
            $division = $this->cajaService->iniciarDivision($mesa, $request->tipo, (int) $request->personas);

            return response()->json(['success' => true, 'division' => $division]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Asigna N unidades de un producto de la comanda a una persona de la
     * división 'por_producto'. Permite partir un mismo renglón (ej. "3
     * pizzas") entre varias personas. Lo puede hacer el mesero al enviar
     * la comanda o el cajero al momento de cobrar.
     */
    public function asignarProductoDivision(Request $request): JsonResponse
    {
        $request->validate([
            'mesa_id'       => 'required|exists:mesas,id',
            'detalle_id'    => 'required|exists:detalles_orden,id',
            'numero_cuenta' => 'required|integer|min:1',
            'cantidad'      => 'required|integer|min:0',
        ]);

        try {
            $mesa = Mesa::findOrFail($request->mesa_id);
            $detalle = DetalleOrden::findOrFail($request->detalle_id);

            $division = $this->cajaService->asignarProductoAPersona(
                $mesa,
                $detalle,
                (int) $request->numero_cuenta,
                (int) $request->cantidad
            );

            return response()->json(['success' => true, 'division' => $division]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Cancela la división activa de una mesa (vuelve a cobro normal).
     */
    public function cancelarDivision(Request $request): JsonResponse
    {
        $request->validate(['mesa_id' => 'required|exists:mesas,id']);

        try {
            $mesa = Mesa::findOrFail($request->mesa_id);
            $this->cajaService->cancelarDivision($mesa);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function liberarMesa(Request $request): JsonResponse
    {
        $validated = $request->validate(['mesa_id' => 'required|exists:mesas,id']);
        $mesa = Mesa::findOrFail($validated['mesa_id']);
        $this->cajaService->liberarMesa($mesa);
        
        return response()->json(['success' => true, 'message' => 'Mesa liberada correctamente.']);
    }

    public function getEstadoMesa(Request $request): JsonResponse
    {
        $validated = $request->validate(['mesa_id' => 'required|exists:mesas,id']);
        $mesa = Mesa::findOrFail($validated['mesa_id']);
        
        return response()->json([
            'success' => true,
            'estado' => $mesa->estado,
            'ordenes_activas' => $mesa->ordenesActivas()->count(),
            'esta_disponible' => $mesa->estado === Mesa::ESTADO_DISPONIBLE && $mesa->ordenesActivas()->count() === 0,
        ]);
    }

    public function actualizarPropina(Request $request, $id): JsonResponse
    {
        $request->validate([
            'tipo'  => 'required|in:porcentaje,manual',
            'valor' => 'required|numeric|min:0',
        ]);

        $orden = \App\Models\Orden::findOrFail($id);
        $mesa = Mesa::findOrFail($orden->mesa_id);

        // AJUSTE: la propina debe calcularse sobre el total de TODA la mesa
        // (todas sus órdenes activas), no solo sobre los detalles de esta
        // orden individual. Usamos CajaService, la misma fuente de verdad
        // que ya usa el modal de cobro y el ticket final, para que el %
        // de propina siempre se aplique sobre la base correcta.
        $desglose = $this->cajaService->obtenerDesgloseMesa($mesa);
        $subtotal = $desglose['subtotal'];
        /* IVA_BLOCK_START — iva_propina
        $iva = $desglose['iva'];
        $base = $subtotal + $iva;
        IVA_BLOCK_END */
        $iva = 0; // IVA desactivado
        $base = $subtotal;

        if ($request->tipo === 'porcentaje') {
            if ($request->valor > 100) {
                return response()->json(['success' => false, 'message' => 'El porcentaje no puede ser mayor a 100.'], 422);
            }
            $propina = round($base * ($request->valor / 100), 2);
        } else {
            $propina = round($request->valor, 2);
        }

        DB::transaction(function () use ($mesa, $orden, $propina) {
            // AJUSTE: la propina completa se concentra en ESTA orden, y se
            // resetea a 0 en las demás órdenes activas de la mesa. Así,
            // CajaService::obtenerDesgloseMesa (que suma la propina de TODAS
            // las órdenes activas) nunca la cuenta duplicada ni la pierde.
            $mesa->ordenesActivas()->where('id', '!=', $orden->id)->update(['propina' => 0]);
            $orden->update(['propina' => $propina]);

            // NUEVO: la propina se puede cambiar en cualquier momento,
            // incluso con la mesa ya dividida. Si hay una división activa,
            // repartimos la propina nueva entre las partes que aún no se
            // han pagado (las ya pagadas se quedan como quedaron cobradas).
            $this->cajaService->recalcularDivisionTrasPropina($mesa);
        });

        return response()->json([
            'success'  => true,
            'propina'  => $propina,
            'total'    => round($base + $propina, 2),
            // NUEVO: para que el frontend actualice los montos por persona
            // sin recargar la página cuando la mesa está dividida.
            'division' => $this->cajaService->obtenerEstadoDivision($mesa),
        ]);
    }

    /**
     * Cancela la cuenta COMPLETA de una mesa sin cobrarla.
     *
     * Para casos en que el dinero no va a entrar: el cliente se fue sin pagar,
     * se levantó la comanda por error, o se decidió absorber el consumo como
     * cortesía.
     *
     * Restringido a Caja por la ruta (permiso:Caja,eliminar). No basta con
     * esconder el botón: sin la validación en el servidor, cualquiera con la
     * URL podría borrar una cuenta pendiente.
     *
     * NO se registra nada en flujo_caja a propósito: en una cuenta cancelada
     * NO entró ni salió dinero del cajón. Si se anotara como egreso, el
     * arqueo de efectivo pediría menos dinero del que realmente debe haber y
     * aparecerían sobrantes falsos en cada corte. La pérdida queda guardada en
     * la propia orden (estado, motivo, quién y cuánto) para poder reportarla.
     */
    public function cancelarCuenta(Request $request): JsonResponse
    {
        $request->validate([
            'mesa_id' => 'required|exists:mesas,id',
            'motivo'  => 'required|string|min:5|max:255',
        ], [
            'motivo.required' => 'Escribe el motivo de la cancelación.',
            'motivo.min'      => 'El motivo debe explicar qué pasó (mínimo 5 caracteres).',
        ]);

        $cajaActiva = CajaMovimiento::where('estado', 'abierta')->first();

        if (!$cajaActiva) {
            return response()->json([
                'success' => false,
                'message' => 'No hay un turno de caja abierto. Abre la caja para poder cancelar cuentas.',
            ], 422);
        }

        try {
            $resultado = DB::transaction(function () use ($request, $cajaActiva) {
                $mesa = Mesa::findOrFail($request->mesa_id);

                $ordenesActivas = $mesa->ordenesActivas()->get();

                if ($ordenesActivas->isEmpty()) {
                    throw new \Exception('Esta mesa no tiene ninguna cuenta abierta que cancelar.');
                }

                // Se calcula ANTES de cancelar: es el dinero que se deja de
                // percibir y debe quedar registrado para el reporte.
                $montoPerdido = $this->cajaService->obtenerDesgloseMesa($mesa)['total'];

                foreach ($ordenesActivas as $orden) {
                    $orden->update([
                        'estado'           => Orden::ESTADO_CANCELADA,
                        'cancelada_motivo' => trim($request->motivo),
                        'cancelada_por'    => auth()->id(),
                        'cancelada_en'     => now(),
                        'monto_cancelado'  => $montoPerdido,
                        'cerrada_el'       => now(),
                    ]);
                }

                // --- QUEDA ASENTADO EN EL FLUJO DE CAJA ---
                //
                // Se registra con categoría propia ("Cancelaciones") y
                // metodo_pago 'no_aplica' A PROPÓSITO:
                //
                //  - La categoría permite mostrarlo como bloque aparte en la
                //    pantalla y en los PDF, sin revolverlo con los gastos
                //    reales (un consumo no cobrado no es una compra).
                //  - 'no_aplica' lo deja fuera del arqueo de efectivo: aquí no
                //    salió dinero del cajón. Si contara como salida, el corte
                //    pediría menos efectivo del que debe haber y aparecerían
                //    sobrantes falsos.
                //
                // El motivo va dentro del concepto para que se lea directo en
                // el reporte, sin tener que cruzar tablas.
                FlujoCaja::create([
                    'caja_movimiento_id' => $cajaActiva->id,
                    'tipo'               => 'egreso',
                    'categoria'          => 'Cancelaciones',
                    'concepto'           => 'Cuenta cancelada Mesa ' . $mesa->numero . ' — ' . trim($request->motivo),
                    'monto'              => $montoPerdido,
                    'metodo_pago'        => 'no_aplica',
                    'referencia'         => 'Canceló: ' . (auth()->user()->nombre ?? auth()->id()),
                    'fecha'              => now(),
                    'flujoable_id'       => $ordenesActivas->first()->id ?? null,
                    'flujoable_type'     => Orden::class,
                ]);

                // Si la mesa tenía la cuenta dividida, esas partes ya no
                // aplican: se van con la cuenta cancelada.
              $mesa->cuentasDivision()->delete();

                $this->cajaService->liberarMesa($mesa);

                // LIMPIEZA AUTOMÁTICA AL CANCELAR
                if ($mesa->seccion === 'WEB' && $mesa->id != 1) {
                    $mesa->delete();
                }

                return [
                    'numero' => $mesa->numero,
                    'monto'  => $montoPerdido,
                ];
            });

            return response()->json([
                'success'      => true,
                'message'      => 'Cuenta cancelada. Se registró una pérdida de $' . number_format($resultado['monto'], 2) . '.',
                'monto'        => $resultado['monto'],
                'redirect_url' => route('admin.caja.index'),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Aplica (o quita) un descuento porcentual a la cuenta desde Caja.
     *
     * El descuento se movió del módulo de Mesas a Caja: ahora lo autoriza
     * quien cobra, no quien levanta el pedido. Se guarda en TODAS las órdenes
     * activas de la mesa porque una mesa puede tener varias rondas de envío
     * y el descuento aplica a la cuenta completa.
     *
     * Enviar 0 lo elimina.
     */
    public function aplicarDescuento(Request $request): JsonResponse
    {
        $request->validate([
            'mesa_id'    => 'required|exists:mesas,id',
            'porcentaje' => 'required|numeric|min:0|max:100',
        ], [
            'porcentaje.max' => 'El descuento no puede ser mayor a 100%.',
            'porcentaje.min' => 'El descuento no puede ser negativo.',
        ]);

        try {
            $mesa = Mesa::findOrFail($request->mesa_id);
            $porcentaje = round((float) $request->porcentaje, 2);

            $ordenes = $mesa->ordenesActivas()->get();

            if ($ordenes->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta mesa no tiene una cuenta abierta.',
                ], 422);
            }

            DB::transaction(function () use ($ordenes, $mesa, $porcentaje) {
                foreach ($ordenes as $orden) {
                    $orden->update(['descuento_porcentaje' => $porcentaje]);
                }

                // Si la mesa está dividida, las partes deben recalcularse:
                // el descuento cambia el total de cada persona.
                $this->cajaService->recalcularDivisionTrasPropina($mesa);
            });

            $desglose = $this->cajaService->obtenerDesgloseMesa($mesa->fresh());

            return response()->json([
                'success'    => true,
                'message'    => $porcentaje > 0
                    ? 'Descuento del ' . rtrim(rtrim(number_format($porcentaje, 2), '0'), '.') . '% aplicado.'
                    : 'Descuento eliminado.',
                'porcentaje' => $porcentaje,
                'descuento'  => $desglose['descuentoCaja'],
                'subtotal'   => $desglose['subtotal'],
                'iva'        => $desglose['iva'],
                'total'      => $desglose['total'],
                'division'   => $desglose['division'],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $mesa = Mesa::findOrFail($id);
            if ($mesa->estado !== Mesa::ESTADO_DISPONIBLE) {
                return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar mesas disponibles.'], 422);
            }
            $mesa->delete();
            return response()->json(['success' => true, 'message' => 'Mesa eliminada.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error interno.'], 500);
        }
    }
}