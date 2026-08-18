<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_orden')->unique();
            $table->foreignId('mesa_id')->nullable()->constrained('mesas')->onDelete('set null');
            
            // MODIFICADO: Ahora permite nulos (los pedidos web no tienen mesero)
            $table->foreignId('mesero_id')->nullable()->constrained('users');
            
            $table->foreignId('capitan_id')->nullable()->constrained('users');
            
            $table->string('estado'); // Ej: pendiente, en proceso, servida, pagada
            
            // --- NUEVOS CAMPOS WEB ---
            $table->string('origen')->default('local'); // Para diferenciar 'local' de 'web'
            $table->string('nombre_cliente')->nullable(); // Nombre de quien pide en la web
            $table->string('telefono_cliente')->nullable(); // Teléfono de contacto
            
            // --- CAMPOS FINANCIEROS ---
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('propina', 10, 2)->default(0); // <--- AGREGADO AQUÍ
            $table->string('metodo_pago')->nullable(); // Ej: efectivo, tarjeta, transferencia
            
            $table->timestamp('abierta_el')->nullable();
            $table->timestamp('cerrada_el')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ordenes');
    }
};