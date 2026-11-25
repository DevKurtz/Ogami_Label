<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('workbook_batches', 'workbook_path')) {
            Schema::table('workbook_batches', function (Blueprint $table) {
                $table->dropColumn('workbook_path');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('workbook_batches', 'workbook_path')) {
            Schema::table('workbook_batches', function (Blueprint $table) {
                $table->string('workbook_path')->nullable();
            });
        }
    }
};
