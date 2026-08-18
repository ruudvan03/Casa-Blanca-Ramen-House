<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Mesa;
use App\Models\Orden;
use App\Models\FlujoCaja;
use App\Models\TicketImpreso;

class TicketService
{
    /**
     * Devuelve el folio correlativo (001, 002...) y la fecha/hora exacta de
     * impresión de una venta, creándolos la primera vez que se piden.
     *
     * Se identifica la venta por el id de su PRIMERA orden, que es estable:
     * no cambia si se reconsulta el ticket ni si la mesa se libera/borra.
     * Por eso, aunque se vuelva a abrir esta misma pantalla, el folio y la
     * hora no cambian — igual que un recibo real no cambia de número ni de
     * hora cada vez que lo vuelves a ver.
     */
    private function obtenerOFolio(?Orden $primeraOrden, ?Mesa $mesa): TicketImpreso
    {
        if (!$primeraOrden) {
            // No debería pasar (siempre hay al menos una orden para poder
            // cobrar), pero por seguridad no se rompe: se crea un folio sin
            // referencia a ninguna orden real.
            return TicketImpreso::create([
                'orden_referencia_id' => 0,
                'mesa_numero'         => $mesa->numero ?? null,
                'impreso_en'          => now(),
            ]);
        }

        $existente = TicketImpreso::where('orden_referencia_id', $primeraOrden->id)->first();
        if ($existente) {
            return $existente;
        }

        return TicketImpreso::create([
            'orden_referencia_id' => $primeraOrden->id,
            'mesa_numero'         => $mesa->numero ?? null,
            'mesero_id'           => $primeraOrden->mesero_id,
            'cajero_id'           => auth()->id(),
            'impreso_en'          => now(),
        ]);
    }

    /**
     * Genera los datos del ticket final de caja para una MESA completa,
     * agregando TODAS sus órdenes activas (puede haber más de una si
     * hubo varias rondas de envío a cocina que crearon órdenes separadas).
     * Esta es la misma unidad de agregación que ya usa CajaService::obtenerDesgloseMesa(),
     * así el ticket impreso siempre coincide con lo que se cobró.
     */
    public function obtenerDatosTicketPorMesa(int $mesaId): array
    {
        // withTrashed() es obligatorio aquí: las mesas de delivery se retiran
        // (borrado suave) en cuanto se cobran, y el ticket se imprime JUSTO
        // DESPUÉS del pago. Sin esto, findOrFail lanzaría 404 al imprimir el
        // ticket de cualquier pedido de Rappi/Uber/DiDi. También permite
        // reimprimir tickets viejos desde el historial.
        $mesa = Mesa::withTrashed()->findOrFail($mesaId);

        $ordenes = $mesa->ordenesActivas()
            ->with([
                'mesero',
                'detalles.producto',
                'detalles.promocionAplicada.promocion',
            ])
            ->get();

        if ($ordenes->isEmpty()) {
            // Fallback: la mesa ya fue liberada (caso normal justo después de
            // cobrar, ya que procesarPago() libera la mesa ANTES de que el
            // frontend pida el ticket). Buscamos TODAS las órdenes que se
            // cerraron juntas en el mismo pago -comparten el mismo
            // 'cerrada_el', porque CajaService::liberarMesa() las actualiza
            // a todas de una sola vez con el mismo timestamp- en vez de
            // tomar solo la última orden (que reintroducía el mismo bug de
            // productos/total faltantes justo en el momento del cobro).
            $ultimaCerrada = Orden::where('mesa_id', $mesa->id)
                ->where('estado', Orden::ESTADO_PAGADA)
                ->latest('cerrada_el')
                ->first();

            $ordenes = $ultimaCerrada
                ? Orden::where('mesa_id', $mesa->id)
                    ->where('estado', Orden::ESTADO_PAGADA)
                    ->where('cerrada_el', $ultimaCerrada->cerrada_el)
                    ->with(['mesero', 'detalles.producto', 'detalles.promocionAplicada.promocion'])
                    ->get()
                : collect();
        }

        // --- Items: se aplanan los detalles de TODAS las órdenes de la mesa ---
        $items = $ordenes->flatMap(function ($orden) {
            return $orden->detalles->map(function ($detalle) {
                $descuento = $detalle->promocionAplicada?->monto_descuento ?? 0;
                $subtotalLinea = $detalle->subtotal ?? ($detalle->cantidad * $detalle->precio_unitario);

                return [
                    'cantidad'         => $detalle->cantidad,
                    'nombre'           => $detalle->producto->nombre ?? 'Producto sin registro',
                    'subtotal'         => $subtotalLinea,
                    'descuento'        => $descuento,
                    'promocion_nombre' => $detalle->promocionAplicada?->promocion?->nombre,
                    'notas'            => $detalle->notas,
                ];
            });
        });

        $ordenIds = $ordenes->pluck('id');

        // --- Pagos: se buscan por TODAS las órdenes de la mesa, no solo una ---
        $pagos = FlujoCaja::whereIn('flujoable_id', $ordenIds)
            ->where('flujoable_type', Orden::class)
            ->where('categoria', 'Ventas')
            ->get()
            ->map(fn ($flujo) => [
                'metodo'     => ucfirst($flujo->metodo_pago),
                'monto'      => $flujo->monto,
                'referencia' => $flujo->referencia,
            ]);

        $subtotalBruto  = $items->sum('subtotal');
        $descuentoTotal = $items->sum('descuento'); // descuentos de PROMOCIONES
        $subtotalTrasPromociones = $subtotalBruto - $descuentoTotal;

        // --- Descuento aplicado en Caja al cobrar (distinto al de promociones) ---
        // Se calcula EXACTAMENTE igual que CajaService::obtenerDesgloseMesa():
        // sobre lo que queda tras las promociones y ANTES del IVA. Si no se
        // hiciera igual aquí, el ticket impreso no cuadraría con lo cobrado.
        $descuentoCajaPorcentaje = (float) ($ordenes->max('descuento_porcentaje') ?? 0);
        $descuentoCajaPorcentaje = max(0, min(100, $descuentoCajaPorcentaje));
        $descuentoCajaMonto = round($subtotalTrasPromociones * ($descuentoCajaPorcentaje / 100), 2);

        $baseImponible = round($subtotalTrasPromociones - $descuentoCajaMonto, 2);

        // --- IVA: unificado con la MISMA fuente de verdad que usa CajaService
        // y ComandaController (Configuracion), en vez de session(), para que
        // el ticket de caja siempre coincida con el desglose que ya se cobró.
        /* IVA_BLOCK_START — iva_ticket
        $ivaHabilitado = Configuracion::ivaHabilitado();
        $ivaPorcentaje = Configuracion::ivaPorcentaje();
        IVA_BLOCK_END */
        $ivaHabilitado = false; // IVA desactivado
        $ivaPorcentaje = 0;
        $iva = $ivaHabilitado ? round($baseImponible * ($ivaPorcentaje / 100), 2) : 0;

        // Propina: se suma la de TODAS las órdenes de la mesa. En la
        // práctica solo una tendrá valor > 0 (actualizarPropina() la
        // concentra en una sola orden y resetea las demás a 0), pero
        // sumar todas es seguro y no depende de ese detalle interno.
        $propina = $ordenes->sum(fn ($orden) => $orden->propina ?? 0);

        // --- NUEVO: comisión de plataforma de delivery (Rappi/Uber/DiDi) ---
        // Se lee el % "congelado" en la propia mesa (columna comision_porcentaje /
        // comision_iva_porcentaje) para que el ticket siempre coincida con lo que
        // se cobró, aunque después cambies el % en Configuración > Delivery.
        $esDelivery = $mesa->esDelivery();
        $comisionPorcentaje = $esDelivery ? (float) ($mesa->comision_porcentaje ?? 0) : 0;
        $comisionIvaPorcentaje = $esDelivery ? (float) ($mesa->comision_iva_porcentaje ?? 0) : 0;

        $baseComision = $baseImponible + $iva;
        $comisionMonto = $esDelivery ? round($baseComision * ($comisionPorcentaje / 100), 2) : 0;
        $comisionIvaMonto = $esDelivery ? round($comisionMonto * ($comisionIvaPorcentaje / 100), 2) : 0;
        $comisionTotal = round($comisionMonto + $comisionIvaMonto, 2);

        $totalCalculado = $baseImponible + $iva + $propina + $comisionTotal;

        $primeraOrden = $ordenes->first();

        // Folio correlativo + fecha/hora EXACTA de impresión (no de cierre de
        // la orden): se fijan la primera vez que se pide este ticket.
        $ticketImpreso = $this->obtenerOFolio($primeraOrden, $mesa);

        return [
            'folio'          => $ticketImpreso->folio_formateado, // "001", "002"...
            'fecha'          => $ticketImpreso->impreso_en->format('d/m/Y'),
            'hora'           => $ticketImpreso->impreso_en->format('h:i A'),
            'mesa'           => $mesa->numero ?? null,
            'mesero'         => optional($primeraOrden?->mesero)->nombre ?? optional($primeraOrden?->mesero)->name,
            'cajero'         => optional($ticketImpreso->cajero)->nombre ?? optional($ticketImpreso->cajero)->name,
            'items'          => $items->values(),
            'subtotal'       => $subtotalBruto,
            'descuentoTotal' => $descuentoTotal,
            // --- NUEVO: descuento aplicado en Caja (si lo hubo) ---
            'descuentoCajaPorcentaje' => $descuentoCajaPorcentaje,
            'descuentoCajaMonto'      => $descuentoCajaMonto,
            'iva'            => $iva,
            'ivaPorcentaje'  => $ivaPorcentaje,
            'ivaHabilitado'  => $ivaHabilitado,
            'propina'        => $propina,
            // --- NUEVO ---
            'esDelivery'            => $esDelivery,
            'plataformaNombre'      => $esDelivery ? optional($mesa->plataformaDelivery)->nombre : null,
            'comisionPorcentaje'    => $comisionPorcentaje,
            'comisionMonto'         => $comisionMonto,
            'comisionIvaPorcentaje' => $comisionIvaPorcentaje,
            'comisionIvaMonto'      => $comisionIvaMonto,
            'comisionTotal'         => $comisionTotal,
            'total'          => round($totalCalculado, 2),
            'pagos'          => $pagos,
            'negocio'        => ['nombre' => 'Agostadero'],
        ];
    }
}