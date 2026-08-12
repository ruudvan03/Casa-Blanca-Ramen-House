<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket {{ $folio }}</title>
    <style>
        @page { 
            size: 80mm auto; 
            margin: 0; 
        }
        body {
            width: 72mm;
            margin: 4mm auto;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; 
            font-size: 13px; 
            color: #000; 
            text-transform: uppercase;
        }
        

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .text-xl { font-size: 22px; letter-spacing: 1px; }
        .text-lg { font-size: 16px; }
        .mt-1 { margin-top: 5px; }
        .mb-1 { margin-bottom: 5px; }
        .py-1 { padding: 5px 0; }
        
        /* Flexbox para alinear pagos y totales */
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        
        /* Líneas punteadas */
        .dashed-line { border-top: 1px dashed #000; margin: 5px 0; }
        
        /* Tabla de productos */
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th, td { text-align: left; vertical-align: top; padding: 3px 0; }
        th { border-bottom: 1px dashed #000; font-weight: bold; padding-bottom: 3px; font-size: 13px;}
        
        /* Estilos de productos */
        .item-principal { font-size: 16px; font-weight: 900; line-height: 1.2; }
        .sub-item { font-size: 13px; font-weight: bold; color: #333; line-height: 1.2; }
        .precio-text { font-size: 15px; font-weight: bold; }

        /* Estilo para el logo en impresión térmica */
        .ticket-logo {
            width: 140px; 
            height: auto;
            margin: 0 auto 8px auto;
            display: block;
            filter: grayscale(100%) contrast(1.2); 
        }

        @media print { 
            .no-print { display: none !important; } 
            body { margin: 0 auto; }
        }
    </style>
</head>
<body>

    <!-- Encabezado -->
    <div class="text-center mb-1">
        
        <!-- Logo de CasaBlanca RamenHouse -->
        <img src="{{ asset('images/logo_casablanca.jpg') }}" alt="CasaBlanca RamenHouse" class="ticket-logo">
        
        <div style="font-size: 12px; margin-top: 4px;">TICKET</div>

        @if(!empty($folio))
            <div class="font-bold" style="font-size: 13px;">FOLIO: {{ $folio }}</div>
        @endif

        <div style="font-size: 12px;">{{ $fecha }}{{ !empty($hora) ? ' - '.$hora : '' }}</div>

        @if($mesero) 
            <div style="font-size: 12px;">ATENDIÓ: {{ $mesero }}</div> 
        @endif

        @if(!empty($cajero))
            <div style="font-size: 12px;">CAJERO: {{ $cajero }}</div>
        @endif
        
        <!-- Bloque central de Mesa/Delivery con bordes superior e inferior -->
        <div class="font-bold text-lg mt-1 mb-1 py-1" style="border-top: 1px dashed #000; border-bottom: 1px dashed #000;">
            @if($mesa)
                @if($esDelivery ?? false)
                    {{ mb_strtoupper($plataformaNombre ?? 'DELIVERY') }} · {{ mb_strtoupper(preg_replace('/^mesa\s*/i', '', $mesa)) }}
                @else
                    MESA {{ mb_strtoupper(preg_replace('/^mesa\s*/i', '', $mesa)) }}
                @endif
            @else
                PUNTO DE VENTA
            @endif
        </div>
    </div>

    <!-- Lista de Items -->
    <table class="mb-1">
        <thead>
            <tr>
                <th style="width: 75%; padding-left: 2px;">DESCRIPCIÓN</th>
                <th style="width: 25%; text-align: right;">IMPORTE</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr class="item-principal">
                    <td style="padding-top: 8px; padding-left: 2px;">{{ $item['cantidad'] }}X {{ $item['nombre'] }}</td>
                    <td class="text-right precio-text" style="padding-top: 8px;">${{ number_format($item['subtotal'], 2) }}</td>
                </tr>
                
                @if(($item['descuento'] ?? 0) > 0)
                    <tr class="sub-item">
                        <td style="padding-left: 10px; padding-bottom: 4px;">
                            DESC{{ !empty($item['promocion_nombre']) ? ' ('.$item['promocion_nombre'].')' : '' }}
                        </td>
                        <td class="text-right precio-text" style="padding-bottom: 4px;">
                            -${{ number_format($item['descuento'], 2) }}
                        </td>
                    </tr>
                @endif
                
                <!-- Espaciador entre productos -->
                <tr><td colspan="2" style="height: 6px;"></td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="dashed-line"></div>

    <!-- Totales -->
    <div style="padding: 5px 0;">
        <div class="flex-between" style="font-size: 14px; margin-bottom: 3px;">
            <span>SUBTOTAL:</span>
            <span>${{ number_format($subtotal, 2) }}</span>
        </div>
        
        @if(($descuentoTotal ?? 0) > 0)
            <div class="flex-between" style="font-size: 14px; margin-bottom: 3px;">
                <span>DESCUENTO TOTAL:</span>
                <span>-${{ number_format($descuentoTotal, 2) }}</span>
            </div>
        @endif

        @if(($descuentoCajaMonto ?? 0) > 0)
            <div class="flex-between" style="font-size: 14px; margin-bottom: 3px;">
                <span>DESCUENTO CAJA ({{ rtrim(rtrim(number_format($descuentoCajaPorcentaje ?? 0, 2), '0'), '.') }}%):</span>
                <span>-${{ number_format($descuentoCajaMonto, 2) }}</span>
            </div>
        @endif

        @php /* IVA_BLOCK_START — iva_ticket_display
        @if ivaHabilitado && iva > 0
            IVA X% : $X.XX
        @endif
        IVA_BLOCK_END */ @endphp

        @if(($propina ?? 0) > 0)
            <div class="flex-between" style="font-size: 14px; margin-bottom: 3px;">
                <span>PROPINA:</span>
                <span>${{ number_format($propina, 2) }}</span>
            </div>
        @endif

        @if($esDelivery ?? false)
            <div style="margin-top: 6px; font-size: 12px; font-weight: bold;">
                {{ mb_strtoupper($plataformaNombre ?? 'DELIVERY') }} — COMISIÓN
            </div>
            <div class="flex-between" style="font-size: 13px;">
                <span>COMISIÓN ({{ number_format($comisionPorcentaje ?? 0, 0) }}%):</span>
                <span>${{ number_format($comisionMonto ?? 0, 2) }}</span>
            </div>
            <div class="flex-between" style="font-size: 13px; margin-bottom: 3px;">
                <span>IVA COMISIÓN ({{ number_format($comisionIvaPorcentaje ?? 0, 0) }}%):</span>
                <span>${{ number_format($comisionIvaMonto ?? 0, 2) }}</span>
            </div>
        @endif

        <div class="flex-between mt-1" style="border-top: 1px dashed #000; padding-top: 5px;">
            <span class="font-bold text-lg">TOTAL:</span>
            <span class="font-bold text-xl">${{ number_format($total, 2) }}</span>
        </div>
    </div>

    <!-- Pagos -->
    @if(isset($pagos) && collect($pagos)->isNotEmpty())
        <div class="dashed-line"></div>
        <div class="font-bold" style="font-size: 13px; margin-bottom: 5px;">FORMA DE PAGO:</div>
        
        @foreach($pagos as $pago)
            <div style="margin-bottom: 5px;">
                <div class="flex-between font-bold" style="font-size: 14px;">
                    <span>{{ mb_strtoupper($pago['metodo']) }}</span>
                    <span>${{ number_format($pago['monto'], 2) }}</span>
                </div>
                @if(!empty($pago['referencia']))
                    <div style="font-size: 12px; color: #333;">REF: {{ mb_strtoupper($pago['referencia']) }}</div>
                @endif
            </div>
        @endforeach
    @endif

    <div class="dashed-line"></div>

    <!-- Agradecimiento -->
    <div class="text-center mt-1 pt-1" style="margin-top: 15px; font-size: 13px; font-weight: bold;">
        ¡GRACIAS POR SU COMPRA!
    </div>

    <!-- Leyenda promocional del software actualizada -->
    <div class="dashed-line" style="margin-top: 15px;"></div>
    <div class="text-center" style="margin-top: 8px; font-size: 12px; line-height: 1.4;">
        <span class="font-bold">¿NECESITAS UN SOFTWARE PARA TU NEGOCIO?</span><br>
        <span class="font-bold">¡CONTÁCTANOS!</span><br>
        OLLINSOFT | RTV SYSTEMS & SOFTWARE
    </div>

    <!-- Botón de respaldo (Se oculta al imprimir) -->
    <div class="text-center no-print" style="margin-top: 20px;">
        <button style="padding: 8px 16px; cursor: pointer; font-family: 'Helvetica Neue', sans-serif; font-weight: bold; background: #000; color: #fff; border: none; border-radius: 4px; font-size: 14px;" onclick="window.print()">
            IMPRIMIR TICKET
        </button>
    </div>

    <script>
        // JS original mantenido intencionalmente
        // El modal que lo muestra dispara la impresión. 
        // No auto-imprimir ni auto-cerrar.
    </script>
</body>
</html>