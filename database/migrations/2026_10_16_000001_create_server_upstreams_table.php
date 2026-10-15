<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServerUpstreamsTable extends Migration
{
    public function up()
    {
        Schema::create('v2_server_upstream', function (Blueprint $table) {
            $table->increments('id');
            $table->string('node_type', 16);
            $table->unsignedInteger('node_id');
            $table->text('source_encrypted');
            $table->string('source_kind', 16);
            $table->string('selector', 255)->nullable();
            $table->unsignedInteger('refresh_interval')->default(3600);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('agent_token_hash', 64);
            $table->string('status', 32)->default('pending');
            $table->string('error_code', 48)->nullable();
            $table->string('protocol', 32)->nullable();
            $table->unsignedInteger('applied_revision')->nullable();
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->unsignedInteger('last_success_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unique(['node_type', 'node_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_server_upstream');
    }
}
