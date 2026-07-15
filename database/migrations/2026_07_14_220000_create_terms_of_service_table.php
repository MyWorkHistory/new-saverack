<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTermsOfServiceTable extends Migration
{
    public function up(): void
    {
        Schema::create('terms_of_service', function (Blueprint $table) {
            $table->id();
            $table->longText('body')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_of_service');
    }
}
