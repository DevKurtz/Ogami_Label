<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workbook_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('original_filename');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
        });

        Schema::create('label_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('workbook_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('serial_no', 128)->nullable();
            $table->string('part_number', 128)->nullable();
            $table->text('qr_code');
            $table->string('serial_code', 255);
            $table->string('quantity_code', 255);
            $table->string('part_number_code', 255);
            $table->string('supplier_code', 255);
            $table->json('data');
            $table->timestamps();
            $table->unique(['batch_id', 'row_number']);
            $table->index(['serial_no', 'part_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_records');
        Schema::dropIfExists('workbook_batches');
    }
};
