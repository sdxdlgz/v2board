<?php

namespace App\Services;

use App\Models\ServerV2node;
use Illuminate\Support\Facades\DB;

class MieruTrafficService
{
    public function record($nodeId, $agentId, array $counters)
    {
        // The node lock serializes reports, including the very first agent binding.
        DB::transaction(function () use ($nodeId, $agentId, $counters) {
            $node = ServerV2node::where('id', $nodeId)->lockForUpdate()->firstOrFail();
            abort_unless($node->protocol === 'mieru', 409);
            $agent = DB::table('v2_mieru_agent')->where('node_id', $nodeId)->lockForUpdate()->first();
            abort_if($agent && $agent->agent_id !== $agentId, 409, 'Agent state does not match this node');
            DB::table('v2_mieru_agent')->insertOrIgnore(['node_id' => $nodeId, 'agent_id' => $agentId]);
            foreach ($counters as $counter) {
                $this->recordUser($node, $counter);
            }
        }, 3);
    }

    private function recordUser(ServerV2node $node, array $counter)
    {
        $key = ['node_id' => $node->id, 'user_id' => $counter['id']];
        DB::table('v2_mieru_counter')->insertOrIgnore($key);
        // Current reads avoid a stale snapshot before shared user/statistics writes.
        $previous = DB::table('v2_mieru_counter')->where($key)->lockForUpdate()->first();
        $u = $counter['u'] - $previous->u;
        $d = $counter['d'] - $previous->d;
        abort_if($u < 0 || $d < 0, 409, 'Cumulative traffic cannot decrease');
        if ($u === 0 && $d === 0) return;
        $rate = $this->rateCents($node->rate);
        [$chargedU, $remainderU] = $this->charge($u, $rate, $previous->u_remainder);
        [$chargedD, $remainderD] = $this->charge($d, $rate, $previous->d_remainder);
        // Atomic increments also coexist with legacy nodes' user traffic increments.
        DB::table('v2_user')->where('id', $counter['id'])->update([
            'u' => DB::raw('u + ' . $chargedU), 'd' => DB::raw('d + ' . $chargedD), 't' => time(),
        ]);
        $this->statistics($node, $counter['id'], $u, $d);
        DB::table('v2_mieru_counter')->where($key)->update([
            'u' => $counter['u'], 'd' => $counter['d'],
            'u_remainder' => $remainderU, 'd_remainder' => $remainderD,
        ]);
    }

    private function rateCents($rate)
    {
        abort_unless(preg_match('/^\d{1,4}(\.\d{1,2})?$/', (string) $rate) && $rate <= 1000, 409, 'Unsupported rate');
        return (int) round((float) $rate * 100);
    }

    private function charge($bytes, $rate, $remainder)
    {
        abort_if($rate && $bytes > intdiv(PHP_INT_MAX - 99, $rate), 422, 'Traffic overflow');
        $scaled = $bytes * $rate + $remainder;
        return [intdiv($scaled, 100), $scaled % 100];
    }

    private function statistics(ServerV2node $node, $userId, $u, $d)
    {
        $day = strtotime(date('Y-m-d'));
        $common = ['record_at' => $day, 'record_type' => 'd'];
        $user = ['user_id' => $userId, 'server_rate' => $node->rate, 'record_at' => $day];
        $server = ['server_id' => $node->id, 'server_type' => 'v2node', 'record_at' => $day];
        $initial = $common + ['u' => 0, 'd' => 0, 'created_at' => time(), 'updated_at' => time()];
        DB::table('v2_stat_user')->insertOrIgnore($user + $initial);
        DB::table('v2_stat_server')->insertOrIgnore($server + $initial);
        $increments = ['u' => DB::raw('u + ' . $u), 'd' => DB::raw('d + ' . $d), 'updated_at' => time()];
        DB::table('v2_stat_user')->where($user)->update($increments);
        DB::table('v2_stat_server')->where($server)->update($increments);
    }
}
