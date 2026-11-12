<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_devices') && !Schema::hasColumn('mobile_devices', 'last_login_at')) {
            Schema::table('mobile_devices', function (Blueprint $table) {
                $table->dateTime('last_login_at')->nullable()->after('registered_at');
                $table->decimal('last_login_latitude', 10, 7)->nullable()->after('last_login_at');
                $table->decimal('last_login_longitude', 10, 7)->nullable()->after('last_login_latitude');
                $table->decimal('last_login_accuracy', 8, 2)->nullable()->after('last_login_longitude');
                $table->string('last_login_ip', 45)->nullable()->after('last_login_accuracy');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_devices') && Schema::hasColumn('mobile_devices', 'last_login_at')) {
            Schema::table('mobile_devices', function (Blueprint $table) {
                $table->dropColumn(['last_login_at', 'last_login_latitude', 'last_login_longitude', 'last_login_accuracy', 'last_login_ip']);
            });
        }
    }
};
