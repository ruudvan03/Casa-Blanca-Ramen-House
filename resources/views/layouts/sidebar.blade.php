<aside id="sidebar" class="w-[260px] bg-[var(--sidebar-bg)] backdrop-blur-2xl border-r border-[var(--border-color)] flex flex-col justify-between z-50 shrink-0 shadow-2xl overflow-x-hidden relative">
    
    {{-- LÓGICA CSS PURA (Adiós bugs visuales al contraer) --}}
    <style>
        #sidebar { transition: width 0.4s cubic-bezier(0.25, 1, 0.5, 1); }
        .sidebar-text { transition: opacity 0.2s ease, width 0.3s ease, margin 0.3s ease; white-space: nowrap; overflow: hidden; }
        
        /* --- ESTADO COLAPSADO (MEMORIA) --- */
        #sidebar.colapsado { width: 88px !important; }
        
        /* 1. Textos se esfuman y no empujan las cajas */
        #sidebar.colapsado .sidebar-text { width: 0 !important; opacity: 0 !important; margin: 0 !important; pointer-events: none; }
        
        /* 2. Menú de navegación: Centramos los iconos perfectos */
        #sidebar.colapsado .menu-link { padding-left: 0 !important; padding-right: 0 !important; justify-content: center !important; }
        
        /* 3. Header: Ocultamos el logo suavemente y centramos la hamburguesa vertical y horizontalmente */
        #sidebar.colapsado .logo-wrapper { opacity: 0; pointer-events: none; position: absolute; }
        #sidebar.colapsado #toggleSidebar { right: 0; left: 0; margin: 0 auto; top: 50%; transform: translateY(-50%); }
        
        /* 4. Footer: Modo mini y transparente */
        #sidebar.colapsado .user-footer { padding-left: 0; padding-right: 0; background: transparent; border-color: transparent; box-shadow: none; align-items: center; margin-bottom: 1rem; }
        
        /* --- AJUSTE PERFECTO PARA EL BOTÓN ROJO CUADRADO --- */
        #sidebar.colapsado .btn-logout { width: 44px !important; height: 44px !important; padding: 0 !important; justify-content: center !important; }

        /* Modo crema: contraste y fondo limpio en sidebar */
        body.modo-crema #sidebar { background: rgba(255, 255, 255, 0.96); border-color: rgba(15, 23, 42, 0.08); box-shadow: 0 30px 70px rgba(15, 23, 42, 0.08); }
        body.modo-crema #sidebar .logo-wrapper { color: #111827; }
        body.modo-crema #sidebar .logo-wrapper .sidebar-text span:first-child { color: #111827; }
        body.modo-crema #sidebar .logo-wrapper .sidebar-text span:last-child { color: #dc2626 !important; } /* Mantenemos el rojo en modo claro */
        body.modo-crema #sidebar .menu-link { border-color: rgba(15, 23, 42, 0.08); }
        body.modo-crema #sidebar .menu-link:hover { background: rgba(15, 23, 42, 0.04); }
        body.modo-crema #sidebar .menu-link .sidebar-text { color: #4b5563; }
        body.modo-crema #sidebar .menu-link .menu-icon { background: rgba(248, 250, 252, 0.96); border-color: rgba(15, 23, 42, 0.08); color: #374151; }
        body.modo-crema #sidebar .menu-link.active { background: rgba(59, 130, 246, 0.12); border-color: rgba(59, 130, 246, 0.18); box-shadow: 0 12px 30px rgba(59, 130, 246, 0.12); }
        body.modo-crema .user-footer { background: #ffffff; border-color: rgba(15, 23, 42, 0.08); box-shadow: 0 25px 50px rgba(15, 23, 42, 0.06); }
        
        /* Modificado para que el degradado rojo siga activo en modo crema */
        body.modo-crema .btn-logout { border-color: rgba(15, 23, 42, 0.08); }

        /* =========================================================
           MODO MÓVIL: el sidebar se convierte en un drawer que se
           desliza y se oculta, en vez de empujar/tapar el contenido.
           Solo aplica debajo de 1024px; en desktop no cambia nada.
        ========================================================= */
        @media (max-width: 1023.98px) {
            #sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 84vw;
                max-width: 300px;
                transform: translateX(-100%);
                transition: transform 0.35s cubic-bezier(0.25, 1, 0.5, 1);
            }
            /* En móvil ignoramos el estado "colapsado" de desktop (88px) */
            #sidebar.colapsado { width: 84vw; max-width: 300px; }
            #sidebar.colapsado .sidebar-text { width: auto !important; opacity: 1 !important; margin: 0 0 0 0.75rem !important; pointer-events: auto; }
            #sidebar.colapsado .logo-wrapper { opacity: 1; pointer-events: auto; position: relative; }
            #sidebar.colapsado .menu-link { padding-left: 0.75rem !important; padding-right: 0.75rem !important; justify-content: flex-start !important; }

            /* Estado abierto del drawer */
            #sidebar.abierto { transform: translateX(0); }

            #sidebarOverlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 40;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            #sidebarOverlay.visible { display: block; opacity: 1; }
        }
    </style>

    {{-- Script en línea para aplicar el estado ANTES de que renderice la página (evita parpadeo) --}}
    <script>
        (function() {
            if (window.matchMedia('(min-width: 1024px)').matches && localStorage.getItem('sidebarState') === 'collapsed') {
                document.getElementById('sidebar').classList.add('colapsado');
            }
        })();
    </script>

    {{-- Header del Logo ACTUALIZADO --}}
    <div class="h-24 flex items-center px-5 relative shrink-0 w-full transition-all">
        <!-- El resplandor trasero ahora es rojo para combinar con el logo -->
        <div class="absolute top-1/2 left-10 -translate-y-1/2 w-20 h-20 bg-red-500/10 blur-[30px] rounded-full pointer-events-none"></div>
        
        <div class="logo-wrapper flex items-center relative z-10 w-full transition-opacity duration-300">
            
            <!-- Logo circular con efecto de difuminado -->
            <div class="w-12 h-12 shrink-0 bg-white rounded-full flex items-center justify-center shadow-sm border border-gray-100 overflow-hidden">
                <img src="{{ asset('images/logo_casablanca.jpg') }}" 
                     alt="CasaBlanca" 
                     class="w-full h-full object-contain scale-110"
                     style="-webkit-mask-image: radial-gradient(circle at center, black 55%, transparent 95%); mask-image: radial-gradient(circle at center, black 55%, transparent 95%);">
            </div>

            <!-- Textos a dos líneas con control de desbordamiento (min-w-0 y truncate) -->
            <div class="sidebar-text ml-3 flex flex-col min-w-0">
                <span class="font-black tracking-wide text-[15px] text-[var(--text-color)] leading-none truncate" title="CASABLANCA">
                    CASABLANCA
                </span>
                <span class="text-[9px] text-red-600 font-bold uppercase tracking-[0.2em] mt-1.5 truncate" title="RESTAURANTE">
                    RESTAURANTE
                </span>
            </div>
        </div>
        
        {{-- SE AGREGÓ top-7 AQUÍ PARA ALINEAR CON EL TÍTULO --}}
        <button id="toggleSidebar" class="absolute right-4 top-7 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-[var(--input-bg)] text-[var(--text-muted)] hover:text-[var(--text-color)] transition-all cursor-pointer z-50 shrink-0">
            <i class="fas fa-bars text-sm"></i>
        </button>
    </div>

   {{-- Navegación --}}
    <nav class="py-4 px-3 space-y-6 overflow-y-auto overflow-x-hidden max-h-[calc(100vh-14rem)] scrollbar-hide relative z-10 flex-1" id="nav-container">
        
        @php
            $menu = [
                'Administración' => [
                    ['route' => 'admin.dashboard', 'icon' => 'fas fa-th-large', 'label' => 'Dashboard', 'modulo_id' => 1],
                    ['route' => 'admin.empleados.index', 'icon' => 'fas fa-users', 'label' => 'Empleados', 'modulo_id' => 3],
                    ['route' => 'admin.roles.index', 'icon' => 'fas fa-id-badge', 'label' => 'Roles', 'modulo_id' => 11],
                    ['route' => 'admin.finanzas.index', 'icon' => 'fas fa-chart-line', 'label' => 'Finanzas', 'modulo_id' => 10],
                    ['route' => 'historial.index', 'icon' => 'fas fa-history', 'label' => 'Historial Cajas', 'modulo_id' => 12],
                ],
                'Productos' => [
                    ['route' => 'admin.productos.index', 'icon' => 'fas fa-utensils', 'label' => 'Menú', 'modulo_id' => 4],
                    ['route' => 'admin.inventario.index', 'icon' => 'fas fa-cube', 'label' => 'Inventario', 'modulo_id' => 2],
                    ['route' => 'admin.categorias.index', 'icon' => 'fas fa-layer-group', 'label' => 'Categorías', 'modulo_id' => 5],
                    ['route' => 'admin.promociones.index', 'icon' => 'fas fa-tags', 'label' => 'Promociones', 'modulo_id' => 7],
                ],
                'Operaciones' => [
                    ['route' => 'admin.cocina.index', 'icon' => 'fas fa-fire-burner', 'label' => 'Cocina', 'modulo_id' => 8],
                    ['route' => 'admin.mesas.index', 'icon' => 'fas fa-chair', 'label' => 'Mesas', 'modulo_id' => 6],
                    ['route' => 'admin.delivery.index', 'icon' => 'fas fa-motorcycle', 'label' => 'Delivery', 'modulo_id' => 13],
                ],
                'Caja' => [
                    ['route' => 'admin.caja.index', 'icon' => 'fas fa-cash-register', 'label' => 'Caja', 'modulo_id' => 9],
                    ['route' => 'admin.caja.flujo', 'icon' => 'fas fa-money-bill-wave', 'label' => 'Flujo de Caja', 'modulo_id' => 9],
                ]
            ];
        @endphp

        @foreach($menu as $titulo => $items)
            @php
                $mostrarSeccion = collect($items)->contains(fn($item) => auth()->user()->tienePermiso($item['modulo_id'], 'mostrar'));
            @endphp

            @if($mostrarSeccion)
                {{-- Título de la Sección --}}
                <div class="px-3 pt-2">
                    <span class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em] sidebar-text transition-all duration-300">
                        {{ $titulo }}
                    </span>
                </div>

                @foreach($items as $item)
                    @if(auth()->user()->tienePermiso($item['modulo_id'], 'mostrar'))
                        @php
                            try {
                                $url = route($item['route']);
                                $isActive = request()->routeIs(str_replace('.index', '.*', $item['route'])) || request()->routeIs($item['route']);
                            } catch (Exception $e) {
                                $url = '#';
                                $isActive = false;
                            }
                        @endphp

                        <a href="{{ $url }}" class="menu-link relative flex items-center px-3 py-2.5 rounded-xl transition-all duration-300 group overflow-hidden {{ $isActive ? 'bg-blue-600/10 border border-blue-500/30' : 'border border-transparent hover:bg-[var(--input-bg)]' }}">
                            <div class="menu-icon flex h-10 w-10 items-center justify-center rounded-lg transition-all duration-300 shrink-0 relative z-10 {{ $isActive ? 'bg-blue-500 text-white' : 'bg-[var(--card-color)] border border-[var(--border-color)] text-[var(--text-muted)] group-hover:text-[var(--text-color)]' }}">
                                <i class="{{ $item['icon'] }} text-[15px]"></i>
                            </div>
                            <span class="sidebar-text ml-4 text-[14px] tracking-wide {{ $isActive ? 'text-[var(--text-color)] font-bold' : 'text-[var(--text-muted)] font-medium group-hover:text-[var(--text-color)]' }}">
                                {{ $item['label'] }}
                            </span>
                            @if($isActive)
                                <div class="absolute left-[-1px] top-1/2 -translate-y-1/2 w-[3px] h-[60%] bg-blue-500 rounded-r-md"></div>
                            @endif
                        </a>
                    @endif
                @endforeach
            @endif
        @endforeach
    </nav>

    {{-- Footer de Usuario --}}
    <div class="user-footer p-4 mx-3 mb-5 mt-2 rounded-2xl bg-[var(--card-color)] border border-[var(--border-color)] flex flex-col gap-3 shrink-0 relative transition-all duration-300 shadow-lg">
        <div class="flex items-center w-full">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center font-black text-sm shrink-0 shadow-[0_0_10px_rgba(59,130,246,0.3)] mx-auto">
                {{ substr(auth()->user()->nombre ?? 'U', 0, 2) }}
            </div>
            <div class="sidebar-text ml-3 flex flex-col justify-center">
                <p class="text-[13px] font-bold text-[var(--text-color)]">{{ auth()->user()->nombre ?? 'Usuario' }}</p>
                <p class="text-[10px] text-[var(--text-muted)] uppercase tracking-widest font-black mt-1">{{ optional(auth()->user()->rol)->nombre ?? 'Rol' }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-1 w-full flex justify-center">
            @csrf
            <button type="submit" class="btn-logout w-full h-[44px] px-3 flex items-center bg-gradient-to-tr from-red-600 to-red-500 hover:from-red-500 hover:to-red-400 text-white rounded-2xl transition-all duration-300 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 active:scale-95 group overflow-hidden">
                <div class="flex items-center justify-center shrink-0 w-6">
                    <i class="fas fa-sign-out-alt text-[15px] transition-transform group-hover:scale-110"></i>
                </div>
                <span class="sidebar-text ml-2 text-[11px] font-bold tracking-widest mt-[2px]">CERRAR SESIÓN</span>
            </button>
        </form>
    </div>
</aside>

<div id="sidebarOverlay"></div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const navContainer = document.getElementById('nav-container');
        const overlay = document.getElementById('sidebarOverlay');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const esMovil = () => window.matchMedia('(max-width: 1023.98px)').matches;

        // 1. Revisar posición guardada del scroll en el menú
        if (navContainer) {
            const savedScrollPos = localStorage.getItem('sidebarScrollPosition');
            if (savedScrollPos) {
                navContainer.scrollTop = savedScrollPos;
            }

            navContainer.addEventListener('scroll', () => {
                localStorage.setItem('sidebarScrollPosition', navContainer.scrollTop);
            });
        }

        function abrirDrawerMovil() {
            sidebar.classList.add('abierto');
            overlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
        }

        function cerrarDrawerMovil() {
            sidebar.classList.remove('abierto');
            overlay.classList.remove('visible');
            document.body.style.overflow = '';
        }

        mobileMenuBtn?.addEventListener('click', abrirDrawerMovil);
        overlay?.addEventListener('click', cerrarDrawerMovil);

        document.querySelectorAll('#nav-container .menu-link').forEach(link => {
            link.addEventListener('click', () => {
                if (esMovil()) cerrarDrawerMovil();
            });
        });

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                if (esMovil()) {
                    cerrarDrawerMovil();
                    return;
                }
                sidebar.classList.toggle('colapsado');
                
                if (sidebar.classList.contains('colapsado')) {
                    localStorage.setItem('sidebarState', 'collapsed');
                } else {
                    localStorage.setItem('sidebarState', 'expanded');
                }
            });
        }

        window.addEventListener('resize', () => {
            if (!esMovil()) {
                cerrarDrawerMovil();
            }
        });
    });
</script>