<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerUpstream;
use App\Services\UpstreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpstreamController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(config('upstream.enabled'), 404);
            return $next($request);
        });
    }

    public function fetch(UpstreamService $service)
    {
        return response()->json(['data' => [
            'items' => ServerUpstream::orderBy('id')->get(),
            'entrances' => $service->entrances(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, UpstreamService $service)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|min:1',
            'node_type' => 'required|in:trojan,vless',
            'node_id' => 'required|integer|min:1',
            'source' => 'nullable|string|max:16384',
            'selector' => 'nullable|string|max:255',
            'refresh_interval' => 'required|integer|min:300|max:86400',
            'enabled' => 'required|boolean',
        ]);
        return response()->json(['data' => $service->save($data)])->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request)
    {
        $id = $request->validate(['id' => 'required|integer|min:1'])['id'];
        ServerUpstream::findOrFail($id);
        ServerUpstream::whereKey($id)->update([
            'revision' => DB::raw('revision + 1'), 'status' => 'pending', 'error_code' => null,
        ]);
        return ['data' => true];
    }

    public function rotateToken(Request $request)
    {
        $id = $request->validate(['id' => 'required|integer|min:1'])['id'];
        $token = bin2hex(random_bytes(32));
        ServerUpstream::findOrFail($id);
        ServerUpstream::whereKey($id)->update([
            'agent_token_hash' => hash('sha256', $token), 'revision' => DB::raw('revision + 1'),
            'status' => 'pending', 'error_code' => null,
        ]);
        return response()->json(['data' => ['agent_token' => $token]])->header('Cache-Control', 'no-store');
    }

    public function drop(Request $request)
    {
        $id = $request->validate(['id' => 'required|integer|min:1'])['id'];
        $item = ServerUpstream::findOrFail($id);
        abort_if($item->enabled, 422, '请先停用上游，再删除绑定。');
        $item->delete();
        return ['data' => true];
    }
}
