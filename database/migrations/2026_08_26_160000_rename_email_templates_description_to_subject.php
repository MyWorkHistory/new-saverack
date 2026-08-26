<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RenameEmailTemplatesDescriptionToSubject extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('email_templates', 'subject')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->string('subject', 512)->nullable()->after('name');
            });
        }

        if (Schema::hasColumn('email_templates', 'description')) {
            DB::table('email_templates')->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('email_templates')
                        ->where('id', $row->id)
                        ->update(['subject' => $row->description]);
                }
            });

            Schema::table('email_templates', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }

    public function down()
    {
        if (! Schema::hasColumn('email_templates', 'description')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->string('description', 512)->nullable()->after('name');
            });
        }

        if (Schema::hasColumn('email_templates', 'subject')) {
            DB::table('email_templates')->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('email_templates')
                        ->where('id', $row->id)
                        ->update(['description' => $row->subject]);
                }
            });

            Schema::table('email_templates', function (Blueprint $table) {
                $table->dropColumn('subject');
            });
        }
    }
}
