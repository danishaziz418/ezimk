<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smstransactions', function (Blueprint $table) {
            $table->string('whatsapp_message_id')->nullable()->after('type');
            $table->string('reply_to_message_id')->nullable()->after('whatsapp_message_id');
            $table->string('conversation_id')->nullable()->after('reply_to_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('smstransactions', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_message_id', 'reply_to_message_id', 'conversation_id']);
        });
    }
};
