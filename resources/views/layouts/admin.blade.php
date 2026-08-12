<!DOCTYPE html>
<html lang="es">
<head>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CasaBlanca RamenHouse')</title>

    <!-- Favicon tradicional (para la pestaña del navegador) -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    <!-- Iconos de alta resolución para Web Apps, Barra de tareas y Accesos directos -->
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/mrlogo.png') }}">
    <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('images/mrlogo.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/mrlogo.png') }}">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    @vite(['resources/js/app.js'])

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        'luxury-bg': 'var(--bg-color)',      
                        'luxury-card': 'var(--card-color)',    
                        'luxury-border': 'var(--border-color)',  
                        'luxury-accent': '#3B82F6',  
                        'luxury-text': 'var(--text-color)',    
                        'luxury-muted': 'var(--text-muted)',   
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --bg-color: #030303; 
            --sidebar-bg: rgba(6, 6, 8, 0.8);
            --header-bg: rgba(3, 3, 3, 0.7);
            --card-color: #0e0e11;
            --text-color: #FAFAFA;
            --text-muted: #81818a; 
            --border-color: rgba(255, 255, 255, 0.04);
            --glass-bg: linear-gradient(145deg, rgba(20, 20, 23, 0.8) 0%, rgba(10, 10, 12, 0.6) 100%);
            --glass-hover: linear-gradient(145deg, rgba(25, 25, 29, 0.9) 0%, rgba(12, 12, 14, 0.8) 100%);
            --input-bg: #111114;
            --panel-card: rgba(15, 23, 42, 0.75);
            --panel-border: rgba(59, 130, 246, 0.20);
            --panel-secondary: rgba(148, 163, 184, 0.12);
        }

        body.modo-crema {
            --bg-color: #F5F5F7;
            --sidebar-bg: rgba(245, 243, 239, 0.92);
            --header-bg: rgba(250, 247, 243, 0.92);
            --card-color: #F7F4EF;
            --text-color: #111111;
            --text-muted: #4F5258;
            --border-color: rgba(79, 84, 94, 0.14);
            --glass-bg: rgba(255, 255, 255, 0.80);
            --glass-hover: rgba(255, 255, 255, 0.94);
            --input-bg: #ECE9E4;
            --panel-card: rgba(250, 247, 242, 0.94);
            --panel-border: rgba(59, 130, 246, 0.18);
            --panel-secondary: rgba(115, 123, 149, 0.12);
        }

        body { background-color: var(--bg-color); font-family: 'Inter', sans-serif; color: var(--text-color); overflow-x: hidden; margin: 0; padding: 0; transition: background-color 0.4s ease, color 0.4s ease; }
        .glass-card { backdrop-filter: blur(20px); border: 1px solid var(--border-color); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); background-color: var(--glass-bg); }
        body:not(.modo-crema) .glass-card { background-color: var(--glass-bg); box-shadow: inset 0 1px 1px 0 rgba(255, 255, 255, 0.03), 0 10px 30px -10px rgba(0, 0, 0, 0.5); }
        body.modo-crema .glass-card { background-color: rgba(255, 255, 255, 0.9); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.01), 0 2px 4px -1px rgba(0, 0, 0, 0.005); }
        ::-webkit-scrollbar { width: 4px; } ::-webkit-scrollbar-track { background: transparent; } ::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

        /* ===== ESTILOS DEL TOAST GLOBAL ===== */
        @keyframes shrink-bar {
            from { width: 100%; }
            to { width: 0%; }
        }
        .animate-shrink {
            animation: shrink-bar 3s linear forwards;
        }
    </style>

    {{-- Hojas de estilo que empujan las vistas con @push('styles').
         Este stack FALTABA: las vistas hacian el push (por ejemplo el plano
         espacial con css/mesas.css) pero nunca se imprimia, asi que esos
         estilos jamas llegaban al navegador. --}}
    @stack('styles')
</head>
{{-- data-usuario-id lo usa el plano espacial para pintar de amarillo las
     mesas del mesero en sesion y de rosa las de los demas. --}}
<body class="selection:bg-[#3B82F6]/30 selection:text-[var(--text-color)]"
      data-usuario-id="{{ auth()->id() }}">

    <script>
        // 1. Recuperar estado del Tema
        const temaGuardado = localStorage.getItem('tema-ollintem');
        if (temaGuardado === 'crema') {
            document.body.classList.add('modo-crema');
            document.documentElement.classList.remove('dark');
        } else {
            document.body.classList.remove('modo-crema');
            document.documentElement.classList.add('dark');
        }

        // 2. Recuperar estado del Sidebar inmediatamente para evitar parpadeos
        const sidebarGuardado = localStorage.getItem('sidebar-ollintem');
        if (sidebarGuardado === 'expandido') {
            document.body.classList.add('sidebar-expandido');
        }
    </script>

    <div class="fixed top-[-20%] left-[-10%] w-[50vw] h-[50vw] rounded-full bg-blue-600/5 blur-[150px] pointer-events-none z-0 modo-crema:hidden"></div>
    <div class="fixed bottom-[-20%] right-[-10%] w-[40vw] h-[40vw] rounded-full bg-orange-600/5 blur-[150px] pointer-events-none z-0 modo-crema:hidden"></div>

    {{-- ===== TOAST GLOBAL DE ALERTAS (Éxito / Error) =====
         Este mismo contenedor recibe tanto los toasts de sesión (session('success')/session('error'))
         como los toasts dinámicos generados desde JS con showToast(). ===== --}}
    <div id="toastContainerGlobal" class="fixed top-6 right-6 z-[100] flex flex-col gap-4">
        @if(session('success'))
            <div id="toast-exito" class="relative overflow-hidden bg-white dark:bg-[#0f1015] border border-gray-100 dark:border-white/5 rounded-2xl shadow-2xl p-4 flex gap-3.5 items-start w-[320px] transition-all duration-300 transform translate-x-0 opacity-100">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-400 to-cyan-400"></div>

                <div class="flex items-center justify-center w-8 h-8 rounded-full border border-emerald-500/30 bg-emerald-500/10 text-emerald-500 dark:text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.15)] flex-shrink-0 mt-1">
                    <i class="fas fa-check text-[11px]"></i>
                </div>

                <div class="flex-1 pr-3">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-400 mb-1">Operación Exitosa</p>
                    <p class="text-[13px] font-bold text-gray-900 dark:text-white leading-tight">{{ session('success') }}</p>
                </div>

                <button onclick="cerrarToast('toast-exito')" class="absolute top-3.5 right-3.5 text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors outline-none">
                    <i class="fas fa-times text-[10px]"></i>
                </button>

                <div class="absolute bottom-0 left-0 h-1 bg-gradient-to-r from-emerald-400 to-cyan-400 animate-shrink"></div>
            </div>
        @endif

        @if(session('error'))
            <div id="toast-error" class="relative overflow-hidden bg-white dark:bg-[#0f1015] border border-gray-100 dark:border-white/5 rounded-2xl shadow-2xl p-4 flex gap-3.5 items-start w-[320px] transition-all duration-300 transform translate-x-0 opacity-100">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-rose-400 to-red-500"></div>

                <div class="flex items-center justify-center w-8 h-8 rounded-full border border-rose-500/30 bg-rose-500/10 text-rose-500 dark:text-rose-400 shadow-[0_0_15px_rgba(244,63,94,0.15)] flex-shrink-0 mt-1">
                    <i class="fas fa-exclamation text-[11px]"></i>
                </div>

                <div class="flex-1 pr-3">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-rose-600 dark:text-rose-400 mb-1">Atención</p>
                    <p class="text-[13px] font-bold text-gray-900 dark:text-white leading-tight">{{ session('error') }}</p>
                </div>

                <button onclick="cerrarToast('toast-error')" class="absolute top-3.5 right-3.5 text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors outline-none">
                    <i class="fas fa-times text-[10px]"></i>
                </button>

                <div class="absolute bottom-0 left-0 h-1 bg-gradient-to-r from-rose-400 to-red-500 animate-shrink"></div>
            </div>
        @endif

        @if($errors->any())
            <div id="toast-validacion" class="relative overflow-hidden bg-white dark:bg-[#0f1015] border border-gray-100 dark:border-white/5 rounded-2xl shadow-2xl p-4 flex gap-3.5 items-start w-[320px] transition-all duration-300 transform translate-x-0 opacity-100">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-rose-400 to-red-500"></div>

                <div class="flex items-center justify-center w-8 h-8 rounded-full border border-rose-500/30 bg-rose-500/10 text-rose-500 dark:text-rose-400 shadow-[0_0_15px_rgba(244,63,94,0.15)] flex-shrink-0 mt-1">
                    <i class="fas fa-exclamation text-[11px]"></i>
                </div>

                <div class="flex-1 pr-3">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-rose-600 dark:text-rose-400 mb-1">Datos inválidos</p>
                    <p class="text-[13px] font-bold text-gray-900 dark:text-white leading-tight">{{ $errors->first() }}</p>
                </div>

                <button onclick="cerrarToast('toast-validacion')" class="absolute top-3.5 right-3.5 text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors outline-none">
                    <i class="fas fa-times text-[10px]"></i>
                </button>

                <div class="absolute bottom-0 left-0 h-1 bg-gradient-to-r from-rose-400 to-red-500 animate-shrink"></div>
            </div>
        @endif
    </div>

    @hasSection('no-sidebar')
        <main class="flex-1 relative z-10 flex flex-col min-h-screen">
            @yield('content')
        </main>
    @else
        <div class="flex h-screen overflow-hidden relative z-10">
            @include('layouts.sidebar')

            <main class="flex-1 overflow-y-auto min-w-0 relative z-10 flex flex-col">
                <header class="backdrop-blur-2xl border-b sticky top-0 z-30 px-4 sm:px-6 lg:px-10 py-4 lg:py-5 flex justify-between items-center gap-3 bg-[var(--header-bg)] border-[var(--border-color)]">
                    <div class="flex items-center gap-3 min-w-0">
                        <button id="mobileMenuBtn" type="button" aria-label="Abrir menú" class="lg:hidden shrink-0 w-9 h-9 rounded-full bg-[var(--sidebar-bg)] border border-[var(--border-color)] flex items-center justify-center text-[var(--text-muted)] hover:text-[var(--text-color)] transition-all shadow-inner">
                            <i class="fas fa-bars text-sm"></i>
                        </button>
                        <div class="flex flex-col min-w-0">
                            <h1 class="text-lg sm:text-xl lg:text-[22px] font-black text-[var(--text-color)] tracking-tight truncate">@yield('header-title', 'Gestión de Personal')</h1>
                            <p class="text-[11px] text-[var(--text-muted)] font-medium mt-1 opacity-80 truncate hidden sm:block">@yield('header-subtitle', 'Administra tu sistema')</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4 shrink-0">
                        @hasSection('header-actions')
                            <div class="hidden md:flex items-center gap-3">@yield('header-actions')</div>
                        @endif
                        <button onclick="toggleTheme()" class="w-9 h-9 rounded-full bg-[var(--sidebar-bg)] border border-[var(--border-color)] flex items-center justify-center text-[var(--text-muted)] hover:text-[var(--text-color)] transition-all shadow-inner">
                            <i id="dashThemeIcon" class="fas fa-sun text-sm"></i>
                        </button>
                    </div>
                </header>
                @yield('content')
            </main>
        </div>
    @endif

    @yield('modals')

    <script>
        // --- GESTIÓN DEL TEMA ---
        function toggleTheme() {
            const body = document.body;
            const html = document.documentElement;
            body.classList.toggle('modo-crema');
            const esCrema = body.classList.contains('modo-crema');
            if (esCrema) {
                html.classList.remove('dark');
                localStorage.setItem('tema-ollintem', 'crema');
            } else {
                html.classList.add('dark');
                localStorage.setItem('tema-ollintem', 'negro');
            }
            actualizarIcono(esCrema);
        }

        function actualizarIcono(esCrema) {
            const icon = document.getElementById('dashThemeIcon');
            if (icon) {
                if (esCrema) {
                    icon.classList.replace('fa-sun', 'fa-moon');
                    icon.classList.add('text-blue-500');
                    icon.classList.remove('text-yellow-400');
                } else {
                    icon.classList.replace('fa-moon', 'fa-sun');
                    icon.classList.add('text-yellow-400');
                    icon.classList.remove('text-blue-500');
                }
            }
        }

        // --- GESTIÓN DEL SIDEBAR ---
        function toggleSidebar() {
            const body = document.body;
            body.classList.toggle('sidebar-expandido');
            if (body.classList.contains('sidebar-expandido')) {
                localStorage.setItem('sidebar-ollintem', 'expandido');
            } else {
                localStorage.setItem('sidebar-ollintem', 'minimizado');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const esCrema = document.body.classList.contains('modo-crema');
            actualizarIcono(esCrema);
        });

        // --- GESTIÓN DEL TOAST DE SESIÓN (renderizado por Blade) ---
        function cerrarToast(id) {
            const toast = document.getElementById(id);
            if (toast) {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                if (document.getElementById('toast-exito')) cerrarToast('toast-exito');
                if (document.getElementById('toast-error')) cerrarToast('toast-error');
            }, 3000);
        });

        // --- TOAST DINÁMICO (para llamadas fetch/AJAX sin recargar página) ---
        function crearToastElemento(mensaje, tipo) {
            const id = 'toast-' + Date.now();
            const esExito = tipo !== 'error';
            const colorGradiente = esExito ? 'from-emerald-400 to-cyan-400' : 'from-rose-400 to-red-500';
            const colorIcono = esExito ? 'emerald' : 'rose';
            const icono = esExito ? 'fa-check' : 'fa-exclamation';
            const titulo = esExito ? 'Operación Exitosa' : 'Atención';

            const div = document.createElement('div');
            div.id = id;
            div.className = 'relative overflow-hidden bg-white dark:bg-[#0f1015] border border-gray-100 dark:border-white/5 rounded-2xl shadow-2xl p-4 flex gap-3.5 items-start w-[320px] transition-all duration-300 transform translate-x-0 opacity-100';
            div.innerHTML = `
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r ${colorGradiente}"></div>
                <div class="flex items-center justify-center w-8 h-8 rounded-full border border-${colorIcono}-500/30 bg-${colorIcono}-500/10 text-${colorIcono}-500 dark:text-${colorIcono}-400 shadow-[0_0_15px_rgba(16,185,129,0.15)] flex-shrink-0 mt-1">
                    <i class="fas ${icono} text-[11px]"></i>
                </div>
                <div class="flex-1 pr-3">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-${colorIcono}-600 dark:text-${colorIcono}-400 mb-1">${titulo}</p>
                    <p class="text-[13px] font-bold text-gray-900 dark:text-white leading-tight">${mensaje}</p>
                </div>
                <button onclick="cerrarToast('${id}')" class="absolute top-3.5 right-3.5 text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors outline-none">
                    <i class="fas fa-times text-[10px]"></i>
                </button>
                <div class="absolute bottom-0 left-0 h-1 bg-gradient-to-r ${colorGradiente} animate-shrink"></div>
            `;
            return { div, id };
        }

        window.showToast = function(mensaje, tipo = 'success') {
            const contenedor = document.getElementById('toastContainerGlobal');
            if (!contenedor) return;
            const { div, id } = crearToastElemento(mensaje, tipo);
            contenedor.appendChild(div);
            setTimeout(() => cerrarToast(id), 3000);
        };

        // --- CONFIRMACIÓN MODAL (reemplazo de confirm() nativo) ---
        window.showConfirm = function(mensaje, onConfirm, opciones = {}) {
            const titulo = opciones.titulo || '¿Estás seguro?';
            const textoConfirmar = opciones.textoConfirmar || 'Confirmar';
            const colorBtn = opciones.peligro === false
                ? 'bg-[#3B82F6] hover:bg-[#2563EB]'
                : 'bg-rose-600 hover:bg-rose-500';

            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 z-[200] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4';
            overlay.innerHTML = `
                <div class="bg-[var(--card-color)] border border-[var(--border-color)] rounded-[2rem] shadow-2xl w-full max-w-sm p-6">
                    <h2 class="text-lg font-black text-[var(--text-color)] tracking-tight mb-2">${titulo}</h2>
                    <p class="text-sm text-[var(--text-muted)] font-medium mb-6">${mensaje}</p>
                    <div class="flex justify-end gap-3">
                        <button id="confirmCancelarBtn" class="px-5 py-2.5 rounded-xl border border-[var(--border-color)] text-xs font-bold text-[var(--text-color)] hover:bg-white/5 transition outline-none">Cancelar</button>
                        <button id="confirmAceptarBtn" class="px-5 py-2.5 rounded-xl ${colorBtn} text-white text-xs font-black uppercase tracking-widest transition outline-none shadow-sm">${textoConfirmar}</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            const cerrar = () => overlay.remove();
            overlay.querySelector('#confirmCancelarBtn').addEventListener('click', cerrar);
            overlay.querySelector('#confirmAceptarBtn').addEventListener('click', () => {
                cerrar();
                onConfirm();
            });
        };
    </script>
    @stack('scripts')

    <div id="teclado-virtual-contenedor" class="teclado-virtual-contenedor oculto">
        <div class="simple-keyboard"></div>
    </div>
</body>
</html>