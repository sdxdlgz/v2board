<?php

namespace App\Console\Commands;

use App\Models\ServerV2node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class MieruCreate extends Command
{
    protected $signature = 'mieru:create {name} {host} {port} {--server-port=} {--group=*} {--rate=1.00}';
    protected $description = 'Create a hidden experimental TCP Mieru node';

    public function handle()
    {
        $data = [
            'name' => $this->argument('name'), 'host' => $this->argument('host'),
            'port' => $this->argument('port'),
            'server_port' => $this->option('server-port') ?: $this->argument('port'),
            'group_id' => $this->option('group'), 'rate' => $this->option('rate'),
        ];
        Validator::make($data, [
            'name' => 'required|string|max:255', 'host' => 'required|string|max:255|regex:/^[a-zA-Z0-9.:-]+$/',
            'port' => 'required|integer|between:1,65535', 'server_port' => 'required|integer|between:1,65535',
            'group_id' => 'required|array|min:1', 'group_id.*' => 'integer|min:1',
            'rate' => ['required', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'numeric', 'between:0,1000'],
        ])->validate();
        $node = ServerV2node::create(array_merge($data, [
            'group_id' => array_map('intval', $data['group_id']),
            'protocol' => 'mieru', 'listen_ip' => '0.0.0.0',
            'network' => 'tcp', 'tls' => 0, 'show' => 0, 'up_mbps' => 0, 'down_mbps' => 0,
        ]));
        $this->info('Created hidden Mieru node ' . $node->id . '. Do not edit it with the legacy protocol form.');
        return 0;
    }
}
