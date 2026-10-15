<?php

namespace Tests\Unit;

use App\Utils\Helper;
use App\Protocols\ClashMeta;
use App\Utils\Mieru;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MieruLegacyTest extends TestCase
{
    public function test_existing_protocol_uris_remain_byte_identical()
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/../Fixtures/legacy-uris.json'), true);
        $this->assertCount(16, $fixtures['cases']);
        foreach ($fixtures['cases'] as $fixture) {
            $server = array_merge($fixtures['server'], ['type' => $fixture['type'], 'host' => $fixture['host']]);
            $this->assertSame($fixture['uri'], Helper::buildUri($fixtures['uuid'], $server));
            $generic = $server;
            $generic['protocol'] = $generic['type']; $generic['type'] = 'v2node';
            $this->assertSame($fixture['uri'], Helper::buildUri($fixtures['uuid'], $generic));
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_full_subscription_preserves_legacy_output_and_adds_new_node_to_groups()
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/../Fixtures/legacy-uris.json'), true);
        $servers = [];
        foreach (['shadowsocks', 'vmess', 'vless', 'trojan'] as $type) {
            $server = $fixtures['server'];
            $server['type'] = 'v2node'; $server['protocol'] = $type; $server['name'] = $type;
            $servers[] = $server;
        }
        $user = ['uuid' => $fixtures['uuid'], 'u' => 0, 'd' => 0, 'transfer_enable' => 1000000, 'expired_at' => null];
        $legacy = (new ClashMeta($user, $servers))->handle();
        $this->assertSame(trim(file_get_contents(__DIR__ . '/../Fixtures/legacy-meta.sha256')), hash('sha256', $legacy));
        $new = array_merge($servers[0], ['name' => 'Mieru integration', 'protocol' => 'mieru']);
        $servers[] = $new;
        $combined = Yaml::parse((new ClashMeta($user, $servers))->handle());
        $this->assertCount(5, $combined['proxies']);
        $this->assertSame(Mieru::mihomo($user['uuid'], $new), $combined['proxies'][4]);
        foreach ($combined['proxy-groups'] as $group) {
            $this->assertContains('Mieru integration', $group['proxies']);
        }
    }
}
