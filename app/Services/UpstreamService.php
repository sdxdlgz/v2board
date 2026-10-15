<?php

namespace App\Services;

use App\Models\ServerTrojan;
use App\Models\ServerUpstream;
use App\Models\ServerVless;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpstreamService
{
    public function node($type, $id)
    {
        if ($type === 'trojan') return ServerTrojan::find($id);
        if ($type === 'vless') return ServerVless::find($id);
        return null;
    }

    public function entrances()
    {
        $result = [];
        foreach (['trojan' => ServerTrojan::class, 'vless' => ServerVless::class] as $type => $model) {
            foreach ($model::orderBy('id')->get() as $node) {
                if ($node->parent_id) continue;
                $result[] = ['type' => $type, 'id' => $node->id, 'name' => $node->name];
            }
        }
        return $result;
    }

    public function save(array $data)
    {
        return DB::transaction(function () use ($data) {
            $item = empty($data['id']) ? new ServerUpstream() : ServerUpstream::lockForUpdate()->findOrFail($data['id']);
            $node = $this->node($data['node_type'], $data['node_id']);
            if ($item->exists && ($item->node_type !== $data['node_type'] || $item->node_id !== (int) $data['node_id'])) {
                $this->invalid('已有上游不能更换入口，请为新入口建立独立绑定。');
            }
            if ((!$node || $node->parent_id) && (!$item->exists || $data['enabled'])) {
                $this->invalid('请选择独立的 Trojan 或 VLESS 入口节点。');
            }
            $duplicate = ServerUpstream::where('node_type', $data['node_type'])->where('node_id', $data['node_id']);
            if ($item->exists) $duplicate->where('id', '!=', $item->id);
            if ($duplicate->exists()) $this->invalid('这个入口已经绑定了上游。');
            $source = trim($data['source'] ?? '');
            if (!$item->exists && $source === '') $this->invalid('请填写订阅地址或单节点分享链接。');
            if ($source !== '') {
                $item->source_kind = $this->sourceKind($source);
                $item->source_encrypted = Crypt::encryptString($source);
            }
            $token = null;
            if (!$item->exists) {
                $token = bin2hex(random_bytes(32));
                $item->agent_token_hash = hash('sha256', $token);
            }
            $item->node_type = $data['node_type'];
            $item->node_id = $data['node_id'];
            $item->selector = $data['selector'] ?? null;
            $item->refresh_interval = $data['refresh_interval'];
            $item->enabled = $data['enabled'];
            $item->revision = $item->exists ? $item->revision + 1 : 1;
            $item->status = 'pending';
            $item->error_code = null;
            $item->save();
            return ['item' => $item, 'agent_token' => $token];
        });
    }

    public function sourceKind($source)
    {
        if (preg_match('/[\x00-\x20\x7f]/', $source)) $this->invalid('链接不能包含空格或控制字符。');
        $scheme = strtolower(parse_url($source, PHP_URL_SCHEME) ?? '');
        if ($scheme === 'https') {
            if (!parse_url($source, PHP_URL_HOST) || parse_url($source, PHP_URL_USER) !== null) {
                $this->invalid('请填写有效的 HTTPS 订阅地址。');
            }
            return 'subscription';
        }
        if (in_array($scheme, ['vless', 'vmess', 'trojan', 'ss', 'ssr', 'hysteria', 'hysteria2', 'hy2', 'tuic', 'anytls', 'mierus'], true)) {
            return 'uri';
        }
        $this->invalid('需要 HTTPS 订阅地址或受支持的节点分享链接。');
    }

    private function invalid($message)
    {
        throw ValidationException::withMessages(['source' => $message]);
    }
}
