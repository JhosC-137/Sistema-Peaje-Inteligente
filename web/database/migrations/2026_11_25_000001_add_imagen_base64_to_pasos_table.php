<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pasos', function (Blueprint $table) {
            $table->longText('imagen_base64')->nullable()->after('imagen_url');
        });
    }

    public function down(): void
    {
        Schema::table('pasos', function (Blueprint $table) {
            $table->dropColumn('imagen_base64');
        });
    }
};
