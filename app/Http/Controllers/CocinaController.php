<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\DetalleOrden;
use Illuminate\Http\Request;

class CocinaController extends Controller
{
    /**
     * Muestra la pantalla de cocina/barra con las órdenes activas y contadores.
     *
     * IMPORTANTE: cada tarjeta que ve Cocina/Barra representa un
     * "lote_envio" + "área" (una ronda de "Enviar Orden" o de traspaso,
     * separada además por Cocina/Barra usando area_impresion). El estado
     * de cada tarjeta (pendiente/en proceso/servida) vive en el campo
     * 'estado_preparacion' de cada DetalleOrden, y se actualiza SOLO para
     * los detalles de esa tarjeta específica — así, marcar Barra como
     * lista ya no afecta a Cocina, ni viceversa.
     */
    public function index(Request $request)
    {
        $areaSeleccionada = $this->resolverAreaSeleccionada($request);

        $datos = $this->construirComandas($areaSeleccionada);

        return view('admin.cocina.index', array_merge($datos, [
            'areaSeleccionada' => $areaSeleccionada,
        ]));
    }

    /**
     * NUEVO: endpoint JSON consultado cada 5 segundos por la pantalla de
     * Cocina/Barra (polling) para reflejar pedidos nuevos y cambios de
     * estado sin que nadie tenga que recargar la página. Devuelve el HTML
     * ya renderizado de las tarjetas (partial) más los contadores, para
     * que el JS solo reemplace el contenido sin duplicar lógica de Blade.
     */
    public function apiComandas(Request $request)
    {
        $areaSeleccionada = $this->resolverAreaSeleccionada($request);

        $datos = $this->construirComandas($areaSeleccionada);

        $html = view('admin.cocina.partials.comandas', array_merge($datos, [
            'areaSeleccionada' => $areaSeleccionada,
        ]))->render();

        return response()->json([
            'success'              => true,
            'html'                 => $html,
            'pendientes'           => $datos['pendientes'],
            'enProceso'            => $datos['enProceso'],
            'servidas'             => $datos['servidas'],
            'ordenesActivasEnArea' => $datos['ordenesActivasEnArea'],
        ]);
    }

    /**
 * Actualiza el estado de UNA tarjeta específica (orden + lote + área).
 */
public function actualizarEstado(Request $request, $id)
{
    $request->validate([
        'estado' => 'required|in:pendiente,en proceso,servida',
        'lote'   => 'required|string',
        'area'   => 'required|in:cocina,barra',
    ]);

    $areaObjetivo = $request->area === 'barra' ? 'Barra' : 'Cocina';

    $orden = Orden::with('detalles.producto.categoria')->findOrFail($id);

    // 1. Buscamos y actualizamos solo los detalles del lote y área seleccionada
    $idsAActualizar = $orden->detalles
        ->filter(function ($detalle) use ($request) {
            $lote = $detalle->lote_envio ?? 'sin-lote';
            return $lote === $request->lote;
        })
        ->filter(function ($detalle) use ($areaObjetivo) {
            return $this->resolverAreaDetalle($detalle) === $areaObjetivo;
        })
        ->pluck('id');

    if ($idsAActualizar->isNotEmpty()) {
        DetalleOrden::whereIn('id', $idsAActualizar)->update([
            'estado_preparacion' => $request->estado,
        ]);
    }

    // 2. Sincronizamos el estado global de la Orden
    $orden->refresh();

    // NUEVO: Excluimos los productos cancelados de la verificación
    $detallesRelevantes = $orden->detalles->where('estado', '!=', 'cancelado');

    $todosServidos = $detallesRelevantes->isNotEmpty()
        && $detallesRelevantes->every(fn ($d) => $d->estado_preparacion === 'servida');

    if ($todosServidos) {
        if ($orden->estado !== 'servida') {
            $orden->update(['estado' => 'servida']);
        }
    } else {
        // NUEVO: Si no están todos servidos pero ya entró a cocina, pasa a 'en proceso'
        if ($orden->estado === 'pendiente') {
            $orden->update(['estado' => 'en proceso']);
        }
    }

    if ($request->wantsJson() || $request->ajax()) {
        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado correctamente.',
            'estado'  => $request->estado,
        ]);
    }

    return redirect()->route('admin.cocina.index', ['area' => $request->area])
                     ->with('success', 'Estado actualizado correctamente.');
}

    /**
     * Lee el área seleccionada desde el query param ?area=, con 'cocina'
     * como valor por defecto.
     */
    private function resolverAreaSeleccionada(Request $request): string
    {
        return strtolower($request->query('area', 'cocina')) === 'barra' ? 'Barra' : 'Cocina';
    }

    /**
     * Resuelve el área de un DetalleOrden exactamente igual que
     * ComandaService::procesarEnvio, para que ambos lugares siempre
     * coincidan.
     */
    private function resolverAreaDetalle(DetalleOrden $detalle): string
    {
        $area = $detalle->producto->categoria->area_impresion ?? 'Cocina';
        return $area !== 'Barra' ? 'Cocina' : 'Barra';
    }

        
    private function construirComandas(string $areaSeleccionada): array
    {
        $ordenes = Orden::with(['mesa:id,id,numero,tipo,plataforma_delivery_id', 'mesa.plataformaDelivery:id,nombre', 'mesero:id,nombre', 'detalles.producto.categoria'])
            ->whereIn('estado', ['pendiente', 'en proceso'])
            ->whereHas('detalles')
            ->orderBy('abierta_el', 'asc')
            ->get();

        $comandasTodas = collect();
        foreach ($ordenes as $orden) {
            // Los productos cancelados nunca deben llegar a la cocina/barra.
            $detallesActivos = $orden->detalles->where('estado', '!=', 'cancelado');

            $porLote = $detallesActivos->groupBy(function ($detalle) {
                return $detalle->lote_envio ?? 'sin-lote';
            });

            foreach ($porLote as $lote => $detallesLote) {
                $porArea = $detallesLote->groupBy(fn ($detalle) => $this->resolverAreaDetalle($detalle));

                foreach ($porArea as $area => $detallesArea) {
                    
                    $estadosPresentes = $detallesArea->pluck('estado_preparacion')->unique();
                    
                    // NUEVA LÓGICA: Si los detalles no están servidos, nacen DIRECTO en proceso.
                    if ($estadosPresentes->contains('servida') && $estadosPresentes->count() === 1) {
                        $estadoTarjeta = 'servida';
                    } else {
                        // Si contiene 'pendiente' o 'en proceso', entra directo como 'en proceso'
                        $estadoTarjeta = 'en proceso';
                    }

                    // No mostramos tarjetas ya servidas en el tablero activo
                    // No mostramos tarjetas ya servidas en el tablero activo
                    if ($estadoTarjeta === 'servida') {
                        continue;
                    }

                    $comandasTodas->push((object) [
                        'id'             => $orden->id . '-' . $lote . '-' . $area,
                        'orden_id'       => $orden->id,
                        'lote'           => $lote,
                        'area'           => $area,
                        'mesa'           => $orden->mesa,
                        'mesero'         => $orden->mesero,
                        'estado'         => $estadoTarjeta,
                        'detalles'       => $detallesArea,
                        'creado_en'      => $detallesArea->min('created_at'),
                        // NUEVAS COLUMNAS PARA LA WEB
                        'origen'         => $orden->origen,
                        'nombre_cliente' => $orden->nombre_cliente,
                    ]);
                }
            }
        }

        $comandas = $comandasTodas
            ->where('area', $areaSeleccionada)
            ->sortBy('creado_en')
            ->values();

        // Ajuste de contadores: Ya no hay 'pendientes' aislados en cocina
        $pendientes = 0; 
        $enProceso  = $comandas->count(); // Todo lo que está en pantalla está en proceso

        // "Servidas" del turno: detalles marcados como servidos hoy, en esta área
        $servidas = DetalleOrden::where('estado_preparacion', 'servida')
            ->whereDate('updated_at', now()->toDateString())
            ->whereHas('producto.categoria', function ($q) use ($areaSeleccionada) {
                if ($areaSeleccionada === 'Barra') {
                    $q->where('area_impresion', 'Barra');
                } else {
                    $q->where(function ($sub) {
                        $sub->where('area_impresion', '!=', 'Barra')->orWhereNull('area_impresion');
                    });
                }
            })
            ->count();

        $ordenesActivasEnArea = $comandas->pluck('orden_id')->unique()->count();

        return compact('comandas', 'pendientes', 'enProceso', 'servidas', 'ordenesActivasEnArea');
    }

    /**
     * Marca UN producto de la comanda como listo (o lo desmarca).
     *
     * Es diferente a "avanzar toda la comanda": aqui el cocinero puede ir
     * tachando platillo por platillo conforme los saca, sin que la tarjeta
     * desaparezca hasta que todos esten listos. Cuando el ultimo se tacha
     * automaticamente se avanza el estado de toda la comanda a "servida".
     *
     * Estado que se usa: 'listo_cocina' — no existe en la validacion del
     * metodo actualizarEstado (que maneja la comanda completa), asi que no
     * hay riesgo de colision.
     */
    public function marcarDetalleListoParaCocina(Request $request, $id)
    {
        $detalle = DetalleOrden::with('orden.detalles')->findOrFail($id);

        // Alternar: si ya esta listo, desmarcarlo (por si se taco por error).
        $nuevoEstado = $detalle->estado_preparacion === 'listo_cocina'
            ? 'en proceso'
            : 'listo_cocina';

        $detalle->update(['estado_preparacion' => $nuevoEstado]);

        // Si TODOS los detalles del mismo lote y area ya estan listos,
        // avanzar toda la comanda a "servida" automaticamente.
        $lote = $detalle->lote_envio;
        $orden = $detalle->orden;

        if ($lote && $orden && $nuevoEstado === 'listo_cocina') {
            $detallesDelLote = $orden->detalles->filter(fn($d) => $d->lote_envio === $lote);
            $todosListos = $detallesDelLote->every(fn($d) => $d->id === $detalle->id || $d->estado_preparacion === 'listo_cocina');

            if ($todosListos) {
                $detallesDelLote->each(fn($d) => $d->update(['estado_preparacion' => 'servida']));
            }
        }

        return response()->json([
            'success'      => true,
            'nuevo_estado' => $nuevoEstado,
            'todos_listos' => isset($todosListos) && $todosListos,
        ]);
    }

    /**
     * area seleccionada, ordenados del mas reciente al mas antiguo.
     *
     * El campo "contenido" es INMUTABLE: se guardo tal cual llego el pedido
     * al momento del envio. Si despues el mesero dice "lo pedi sin cebolla"
     * y las notas dicen otra cosa, este registro es el respaldo oficial de
     * lo que decia el sistema cuando cocina recibio la comanda.
     */
    public function historial(Request $request)
    {
        $area = $this->resolverAreaSeleccionada($request);

        // Se buscan los print_jobs del TURNO activo. Si la caja esta cerrada
        // se muestran los del ultimo turno cerrado, para que cocina pueda
        // seguir consultando el historial del dia aunque ya se haya hecho el
        // corte de caja.
        $cajaMovimiento = \App\Models\CajaMovimiento::where('estado', 'abierta')->first()
            ?? \App\Models\CajaMovimiento::where('estado', 'cerrada')->latest('updated_at')->first();

        $query = \App\Models\PrintJob::with('orden.mesero')
            ->orderByDesc('created_at');

        // Filtra por area: Cocina ve sus comandas, Barra ve las suyas.
        if ($area === 'Barra') {
            $query->where('area', 'Barra');
        } else {
            $query->where(function ($q) {
                $q->where('area', '!=', 'Barra')->orWhereNull('area');
            });
        }

        // Solo los del turno activo / ultimo turno del dia
        if ($cajaMovimiento) {
            $query->whereDate('created_at', $cajaMovimiento->created_at->toDateString());
        } else {
            $query->whereDate('created_at', now()->toDateString());
        }

        $jobs = $query->get()->groupBy('lote_envio');

        return view('admin.cocina.historial', [
            'jobs'             => $jobs,
            'area'             => $area,
            'areaSeleccionada' => $area,
            'fecha'            => now()->format('d/m/Y'),
        ]);
    }
}
