<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = [
            ['email_google' => 'doc.alvaro.pinto.ma@unifranz.edu.bo', 'nombre' => 'Ing. Alvaro Pinto', 'rol' => 'admin', 'activo' => true],
            ['email_google' => 'jhosuepozo777@gmail.com', 'nombre' => 'Jhosue Fariñas (Admin)', 'rol' => 'admin', 'activo' => true],
            ['email_google' => 'jhosuepozo77757@gmail.com', 'nombre' => 'Operador Caseta 1', 'rol' => 'operador', 'activo' => true],
        ];
        foreach ($usuarios as $u) {
            DB::table('usuarios')->updateOrInsert(
                ['email_google' => $u['email_google']],
                array_merge($u, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        if (DB::table('casetas')->count() === 0) {
            DB::table('casetas')->insert([
                ['nombre' => 'Caseta Autopista La Paz - El Alto', 'ubicacion' => 'Km 4 Autopista', 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Caseta Achica Arriba', 'ubicacion' => 'Carretera Oruro Km 25', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (DB::table('tarifas')->count() === 0) {
            DB::table('tarifas')->insert([
                ['categoria' => 'moto', 'ejes' => 2, 'monto' => 2.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'auto', 'ejes' => 2, 'monto' => 5.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'minibus', 'ejes' => 2, 'monto' => 8.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'bus', 'ejes' => 2, 'monto' => 15.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'bus', 'ejes' => 3, 'monto' => 22.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'camion', 'ejes' => 2, 'monto' => 20.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'camion', 'ejes' => 3, 'monto' => 35.00, 'created_at' => now(), 'updated_at' => now()],
                ['categoria' => 'camion', 'ejes' => 4, 'monto' => 45.00, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $vehiculos = [
            ['placa' => '1234ABC', 'propietario' => 'Carlos Mamani', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 100.00],
            ['placa' => '2345DEF', 'propietario' => 'María Quispe', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 50.00],
            ['placa' => '3456GHI', 'propietario' => 'Sindicato Eduardo Abaroa', 'categoria' => 'minibus', 'ejes' => 2, 'saldo' => 120.00],
            ['placa' => '4567JKL', 'propietario' => 'Trans Copacabana', 'categoria' => 'bus', 'ejes' => 3, 'saldo' => 300.00],
            ['placa' => '5678MNO', 'propietario' => 'Logística Andina SRL', 'categoria' => 'camion', 'ejes' => 3, 'saldo' => 500.00],
            ['placa' => '6789PQR', 'propietario' => 'Juan Pérez', 'categoria' => 'moto', 'ejes' => 2, 'saldo' => 20.00],
            ['placa' => '1011STU', 'propietario' => 'Roberto Condori (Sin Saldo)', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 0.00],
            ['placa' => '2022VWX', 'propietario' => 'Transportes Illimani (Sin Saldo)', 'categoria' => 'camion', 'ejes' => 4, 'saldo' => 2.00],
            ['placa' => '3033YZA', 'propietario' => 'Ana Flores', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 80.00],
            ['placa' => '4044BCD', 'propietario' => 'Sindicato 14 de Septiembre', 'categoria' => 'minibus', 'ejes' => 2, 'saldo' => 95.00],
            ['placa' => '5055EFG', 'propietario' => 'Luis Mendoza', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 40.00],
            ['placa' => '6066HIJ', 'propietario' => 'Flota Bolívar', 'categoria' => 'bus', 'ejes' => 2, 'saldo' => 210.00],
            ['placa' => '7077KLM', 'propietario' => 'Constructora Titicaca', 'categoria' => 'camion', 'ejes' => 3, 'saldo' => 400.00],
            ['placa' => '8088NOP', 'propietario' => 'Pedro Choque', 'categoria' => 'moto', 'ejes' => 2, 'saldo' => 15.00],
            ['placa' => '9099QRS', 'propietario' => 'Elena Vargas', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 65.00],
            ['placa' => '1122TUV', 'propietario' => 'Minibuses El Alto', 'categoria' => 'minibus', 'ejes' => 2, 'saldo' => 110.00],
            ['placa' => '3344WXY', 'propietario' => 'Carga Pesada Bolivia', 'categoria' => 'camion', 'ejes' => 4, 'saldo' => 600.00],
            ['placa' => '5566ZAB', 'propietario' => 'Sofía Morales', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 30.00],
            ['placa' => '7788CDE', 'propietario' => 'Expreso Sur', 'categoria' => 'bus', 'ejes' => 3, 'saldo' => 250.00],
            ['placa' => '9900FGH', 'propietario' => 'Diego Rojas (Sin Saldo)', 'categoria' => 'minibus', 'ejes' => 2, 'saldo' => 0.00],
        ];

        foreach ($vehiculos as $v) {
            DB::table('vehiculos')->updateOrInsert(
                ['placa' => $v['placa']],
                array_merge($v, ['activo' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}