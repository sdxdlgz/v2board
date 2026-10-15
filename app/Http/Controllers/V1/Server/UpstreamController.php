<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerUpstream;
use App\Services\UpstreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class UpstreamController extends Controller
{
    private $item;

    public function __construct(Request $request)
    {
        abort_unless(config('upstream.enabled'), 404);
        $id = $request->query('id');
        abort_unless(is_string($id) && ctype_digit($id), 403);
        $item = ServerUpstream::find($id);
        $token = $request->bearerToken();
        abort_unless($item && is_string($token) && hash_equals($item->agent_token_hash, hash('sha256', $token)), 403);
        $this->item = $item;
    }

    public function config(Request $request, UpstreamService $service)
    {
        abort_unless($request->isMethod('get'), 405);
        $item = $this->item;
        $node = $service->node($item->node_type, $item->node_id);
        $enabled = $item->enabled && $node && !$node->parent_id;
        return response()->json(['data' => [
            'id' => $item->id, 'revision' => $item->revision, 'enabled' => $enabled,
            'source' => $enabled ? Crypt::decryptString($item->source_encrypted) : '',
            'selector' => $item->selector ?? '', 'refresh_interval' => $item->refresh_interval,
        ]])->header('Cache-Control', 'no-store');
    }

    public function report(Request $request)
    {
        abort_unless($request->isMethod('post'), 405);
        $data = $request->validate([
            'revision' => 'required|integer|min:1',
            'applied_revision' => 'nullable|integer|min:1',
            'status' => 'required|in:ready,error,disabled',
            'error_code' => 'nullable|in:source_fetch,core_start,invalid_subscription,select_node,probe_failed,panel_unavailable,unsupported_core,internal',
            'protocol' => 'nullable|string|max:32|regex:/^[A-Za-z0-9 -]+$/',
        ]);
        // A delayed report from an earlier configuration cannot mark a new edit ready.
        $values = [
            'status' => $data['status'], 'last_seen_at' => time(),
            'error_code' => $data['error_code'] ?? null,
            'protocol' => $data['protocol'] ?? null,
            'applied_revision' => $data['applied_revision'] ?? null,
        ];
        if ($data['status'] === 'ready') {
            abort_unless(($data['applied_revision'] ?? 0) === $data['revision'], 422);
            $values['last_success_at'] = time();
        }
        $updated = ServerUpstream::where('id', $this->item->id)->where('revision', $data['revision'])->update($values);
        abort_unless($updated, 409);
        return ['data' => true];
    }
}
