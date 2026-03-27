<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('phone', 50)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('user_type', 50)->nullable()->index();
            $table->string('tag', 100)->nullable();
            $table->string('skype', 100)->nullable();
            $table->string('telegram', 100)->nullable();
            $table->string('slack', 255)->nullable();
            $table->string('slack_member_id', 100)->nullable();
            $table->text('bio')->nullable();
            $table->text('html_bio')->nullable();
            $table->date('birthday')->nullable();
            $table->string('personal_email', 190)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('zip', 32)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('employee_type', 50)->nullable();
            $table->string('pin', 20)->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->decimal('full_hours', 8, 2)->nullable();
            $table->decimal('half_hours', 8, 2)->nullable();
            $table->decimal('pto', 8, 2)->nullable();
            $table->decimal('pto_accrual_rate', 8, 4)->nullable();
            $table->decimal('sick_days', 8, 2)->nullable();
            $table->decimal('absence', 8, 2)->nullable();
            $table->decimal('holiday', 8, 2)->nullable();
            $table->decimal('remote', 8, 2)->nullable();
            $table->decimal('other', 8, 2)->nullable();
            $table->decimal('late', 8, 2)->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->date('hire_date')->nullable();
            $table->date('terminate_date')->nullable();
            $table->text('terminate_reason')->nullable();
            $table->string('quote', 255)->nullable();
            $table->date('quote_date')->nullable();
            $table->string('lunch_status', 50)->nullable();
            $table->string('punch_status', 50)->nullable();
            $table->string('punch_time', 50)->nullable();
            $table->boolean('is_clock')->nullable();
            $table->boolean('crm_access')->nullable();
            $table->boolean('wh_access')->nullable();
            $table->boolean('is_permission')->nullable();
            $table->boolean('is_email')->nullable();
            $table->boolean('is_deleted_soft')->nullable()->comment('1 active 2 archive legacy mapping');
            $table->string('owner', 100)->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->string('chat', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('manager_slack_channel', 255)->nullable();
            $table->decimal('fulfillment_percent', 8, 4)->nullable();
            $table->decimal('referral_percent', 8, 4)->nullable();
            $table->decimal('prepay_percent', 8, 4)->nullable();
            $table->decimal('on_demand_percent', 8, 4)->nullable();
            $table->decimal('shipment_bonus', 12, 2)->nullable();
            $table->unsignedTinyInteger('legacy_numeric_role')->nullable()->index();
            $table->json('legacy_fields')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
