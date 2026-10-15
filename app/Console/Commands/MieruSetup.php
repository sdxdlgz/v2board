<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MieruSetup extends Command
{
    protected $signature = 'mieru:setup';
    protected $description = 'Create only the experimental Mieru accounting tables';

    public function handle()
    {
        if (!Schema::hasTable('v2_mieru_agent')) {
            Schema::create('v2_mieru_agent', function (Blueprint $table) {
                $table->unsignedInteger('node_id')->primary();
                $table->uuid('agent_id');
            });
        }
        if (!Schema::hasTable('v2_mieru_counter')) {
            Schema::create('v2_mieru_counter', function (Blueprint $table) {
                $table->unsignedInteger('node_id');
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->unsignedInteger('u_remainder')->default(0);
                $table->unsignedInteger('d_remainder')->default(0);
                $table->primary(['node_id', 'user_id']);
            });
        }
        $this->info('Experimental Mieru tables ready; existing tables were not altered.');
        return 0;
    }
}
