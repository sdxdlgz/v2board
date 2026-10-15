<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerUpstream extends Model
{
    protected $table = 'v2_server_upstream';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['source_encrypted', 'agent_token_hash'];
    protected $casts = [
        'enabled' => 'boolean', 'revision' => 'integer',
        'node_id' => 'integer', 'refresh_interval' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
    ];
}
