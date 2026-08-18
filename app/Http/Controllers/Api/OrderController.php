<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Orden;
use App\Models\Mesa;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // 1. Validar los datos de entrada
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'phone'         => 'nullable|string|max:20',
            'items'         => 'required|array|min:1',
            'items.*.product_id' => 'required|integer', 
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric',
            'items.*.notes'      => 'nullable|string|max:255',
            'total'         => 'required|numeric',
        ]);

        try {
            $orden = DB::transaction(function () use ($validated) {
                
                // 1. CREAR UNA MESA ÚNICA PARA ESTE PEDIDO
                // Usamos una combinación para que el número sea único e identificable en Caja
                $mesaWeb = Mesa::create([
                    'numero'    => 'WEB ' . substr(time(), -4), 
                    'tipo'      => 'local',
                    'estado'    => Mesa::ESTADO_OCUPADA, // Para que aparezca ocupada de inmediato
                    'capacidad' => 1,
                    'seccion'   => 'WEB'
                ]);

               // 2. Crear la orden vinculada exclusivamente a esta nueva mesa
                $nuevaOrden = Orden::create([
                    // QUITAMOS EL "WEB-" PARA QUE SEA UN NÚMERO PURO (Ej: 178707450123)
                    'numero_orden'     => time() . rand(10, 99), 
                    'mesa_id'          => $mesaWeb->id, 
                    'estado'           => Orden::ESTADO_PENDIENTE,
                    'origen'           => Orden::ORIGEN_WEB,
                    'nombre_cliente'   => $validated['customer_name'],
                    'telefono_cliente' => $validated['phone'] ?? null,
                    'total'            => $validated['total'],
                    'abierta_el'       => now(),
                ]);

                // 3. Guardar los detalles
                foreach ($validated['items'] as $item) {
                    $nuevaOrden->detalles()->create([
                        'producto_id'        => $item['product_id'], 
                        'cantidad'           => $item['quantity'],
                        'precio_unitario'    => $item['price'],
                        'notas'              => $item['notes'] ?? null,
                        'estado'             => 'pendiente',
                        'estado_preparacion' => 'pendiente',
                        'lote_envio'         => 1,
                    ]);
                }

                return $nuevaOrden;
            });

            return response()->json([
                'success' => true,
                'message' => 'Pedido registrado en mesa propia',
                'orden_id' => $orden->id,
                'numero_orden' => $orden->numero_orden // <--- SOLUCIÓN AL UNDEFINED
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el pedido',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}