<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerV2node;
use App\Services\MieruTrafficService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use App\Utils\Mieru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MieruController extends Controller
{
    private $node;

    public function __construct(Request $request)
    {
        abort_unless(config('mieru.enabled'), 404);
        $secret = (string) config('v2board.server_token');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->bearerToken()), 403);
        $request->validate(['node_id' => 'required|integer|min:1']);
        $this->node = ServerV2node::findOrFail($request->input('node_id'));
        abort_unless($this->node->protocol === 'mieru', 404);
        abort_if($this->node->parent_id || $this->node->network !== 'tcp' || $this->node->tls, 409);
    }

    public function snapshot(Request $request)
    {
        abort_unless($request->isMethod('GET'), 405);
        $users = (new ServerService())->getAvailableUsers($this->node->group_id);
        // This prototype must not silently bypass limits it cannot enforce.
        abort_if($users->contains(function ($user) {
            return $user->speed_limit > 0 || $user->device_limit > 0;
        }), 409, 'Mieru trial does not implement speed/device limits');
        $users = $users->map(function ($user) {
            return ['id' => $user->id, 'name' => Mieru::username($user->uuid), 'password' => $user->uuid];
        })->values()->all();
        Cache::put(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $this->node->id), time(), 120);
        return response()->json([
            'version' => 1, 'listen_ip' => $this->node->listen_ip,
            'port' => (int) $this->node->server_port, 'users' => $users,
        ])->header('Cache-Control', 'no-store');
    }

    public function traffic(Request $request)
    {
        abort_unless($request->isMethod('POST'), 405);
        $data = $request->validate([
            'agent_id' => 'required|uuid', 'counters' => 'present|array|max:10000',
            'counters.*.id' => 'required|integer|min:1|distinct',
            'counters.*.u' => 'required|integer|min:0|max:9000000000000000',
            'counters.*.d' => 'required|integer|min:0|max:9000000000000000',
        ]);
        (new MieruTrafficService())->record($this->node->id, $data['agent_id'], $data['counters']);
        Cache::put(CacheKey::get('SERVER_V2NODE_LAST_PUSH_AT', $this->node->id), time(), 120);
        return response()->json(['accepted' => true])->header('Cache-Control', 'no-store');
    }
}
