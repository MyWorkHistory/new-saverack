<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmailTemplatesTable extends Migration
{
    public function up()
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64);
            $table->string('name');
            $table->string('description', 512)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down()
    {
        Schema::dropIfExists('email_templates');
    }
}
