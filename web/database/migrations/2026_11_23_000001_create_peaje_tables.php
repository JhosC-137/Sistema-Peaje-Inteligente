<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('email_google')->unique();
            $table->string('nombre');
            $table->enum('rol', ['admin', 'operador'])->default('operador');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->string('placa', 15)->unique();
            $table->string('propietario');
            $table->enum('categoria', ['moto', 'auto', 'minibus', 'camion', 'bus']);
            $table->integer('ejes')->default(2);
            $table->decimal('saldo', 10, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('casetas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('ubicacion');
            $table->timestamps();
        });

        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->enum('categoria', ['moto', 'auto', 'minibus', 'camion', 'bus']);
            $table->integer('ejes')->default(2);
            $table->decimal('monto', 8, 2);
            $table->timestamps();
        });

        Schema::create('pasos', function (Blueprint $table) {
            $table->id();
            $table->string('placa_leida', 20)->nullable();
            $table->string('placa_normalizada', 20)->nullable()->index();
            $table->float('confianza_ocr')->default(0);
            $table->string('categoria_detectada', 20)->nullable();
            $table->integer('ejes_detectados')->default(2);
            $table->integer('pasajeros')->default(0);
            $table->decimal('monto', 8, 2)->default(0);
            $table->foreignId('caseta_id')->constrained('casetas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('imagen_url')->nullable();
            $table->enum('estado', ['autorizado', 'no_registrada', 'sin_saldo']);
            $table->timestamps();
        });

        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paso_id')->constrained('pasos')->onDelete('cascade');
            $table->string('tipo');
            $table->boolean('atendida')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('pasos');
        Schema::dropIfExists('tarifas');
        Schema::dropIfExists('casetas');
        Schema::dropIfExists('vehiculos');
        Schema::dropIfExists('usuarios');
    }
};