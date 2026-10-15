<?php

namespace Tests\Feature;

use App\Models\User;
use App\Jobs\StatUserJob;
use App\Models\ServerV2node;
use App\Services\MieruTrafficService;
use App\Services\ServerService;
use App\Utils\Helper;
use App\Utils\Mieru;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class MieruTest extends TestCase
{
    private const UUID = '11111111-2222-4333-8444-555555555555';
    private const AGENT = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'mieru.enabled' => true, 'v2board.server_token' => 'local-test-token', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('v2_server_v2node', function (Blueprint $t) {
            $t->increments('id');
            foreach (['name', 'host', 'listen_ip', 'port', 'protocol', 'network', 'group_id', 'rate'] as $column) $t->string($column);
            foreach (['server_port', 'tls', 'show', 'sort', 'parent_id', 'created_at', 'updated_at'] as $column) $t->integer($column)->nullable();
            $t->integer('up_mbps'); $t->integer('down_mbps');
        });
        Schema::create('v2_user', function (Blueprint $t) {
            $t->increments('id'); $t->string('uuid'); $t->integer('group_id');
            foreach (['u', 'd', 't', 'banned', 'speed_limit', 'device_limit', 'updated_at'] as $column) $t->bigInteger($column)->default(0);
            $t->bigInteger('transfer_enable')->default(1000000); $t->integer('expired_at')->nullable();
        });
        Schema::create('v2_stat_user', function (Blueprint $t) {
            $this->statColumns($t); $t->integer('user_id'); $t->decimal('server_rate', 10, 2);
            $t->unique(['user_id', 'server_rate', 'record_at']);
        });
        Schema::create('v2_stat_server', function (Blueprint $t) {
            $this->statColumns($t); $t->integer('server_id'); $t->string('server_type');
            $t->unique(['server_id', 'server_type', 'record_at']);
        });
        $this->artisan('mieru:setup')->assertExitCode(0);
        $this->artisan('mieru:setup')->assertExitCode(0);
        ServerV2node::create(['name' => '本地试验', 'host' => '127.0.0.1', 'listen_ip' => '127.0.0.1',
            'port' => '16201', 'server_port' => 16201, 'protocol' => 'mieru', 'network' => 'tcp',
            'tls' => 0, 'group_id' => [1], 'rate' => '1.25', 'show' => 1, 'up_mbps' => 0, 'down_mbps' => 0]);
        DB::table('v2_user')->insert(['id' => 1, 'uuid' => self::UUID, 'group_id' => 1]);
    }

    private function statColumns(Blueprint $t)
    {
        $t->increments('id'); $t->bigInteger('u'); $t->bigInteger('d'); $t->integer('record_at');
        $t->string('record_type'); $t->integer('created_at'); $t->integer('updated_at');
    }

    private function endpoint($action) { return '/api/v1/server/Mieru/' . $action . '?node_id=1'; }
    private function auth() { return ['Authorization' => 'Bearer local-test-token']; }
    private function report($u, $d, $agent = self::AGENT)
    {
        return $this->postJson($this->endpoint('traffic'), ['agent_id' => $agent,
            'counters' => [['id' => 1, 'u' => $u, 'd' => $d]]], $this->auth());
    }

    public function test_default_disabled_and_authentication()
    {
        config(['mieru.enabled' => false]);
        $this->getJson($this->endpoint('snapshot'), $this->auth())->assertNotFound();
        config(['mieru.enabled' => true]);
        $this->getJson($this->endpoint('snapshot'))->assertForbidden();
        $this->getJson($this->endpoint('snapshot'), ['Authorization' => 'Bearer wrong'])->assertForbidden();
    }

    public function test_initial_empty_report_binds_agent_and_new_nodes_start_hidden()
    {
        $this->postJson($this->endpoint('traffic'), ['agent_id' => self::AGENT, 'counters' => []], $this->auth())
            ->assertOk()->assertExactJson(['accepted' => true]);
        $this->assertSame(self::AGENT, DB::table('v2_mieru_agent')->value('agent_id'));
        $this->assertEquals(0, DB::table('v2_user')->value('u'));
        $this->artisan('mieru:create', ['name' => 'New hidden node', 'host' => 'example.invalid',
            'port' => '16202', '--group' => ['1']])->assertExitCode(0);
        $this->assertEquals(0, ServerV2node::find(2)->show);
        $this->assertEquals(0, ServerV2node::find(2)->up_mbps);
        $this->assertEquals(0, ServerV2node::find(2)->down_mbps);
        $this->artisan('mieru:visibility', ['id' => 2, '--show' => '1'])->assertExitCode(0);
        $this->assertEquals(1, ServerV2node::find(2)->show);
        ServerV2node::where('id', 1)->update(['protocol' => 'vless']);
        $this->artisan('mieru:visibility', ['id' => 1, '--show' => '0'])->assertExitCode(1);
        $this->assertEquals(1, ServerV2node::find(1)->show);
    }

    public function test_snapshot_and_subscription_credentials_match_and_empty_means_revoke()
    {
        $this->getJson($this->endpoint('snapshot'), $this->auth())->assertOk()->assertJsonPath('users.0.name', Mieru::username(self::UUID));
        $node = ServerV2node::find(1)->toArray(); $node['type'] = 'v2node';
        $uri = Helper::buildUri(self::UUID, $node);
        $this->assertStringStartsWith('mierus://' . Mieru::username(self::UUID) . ':' . self::UUID . '@', $uri);
        parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
        $this->assertSame('16201', $query['port']); $this->assertSame('TCP', $query['protocol']);
        $this->assertSame(self::UUID, Mieru::mihomo(self::UUID, $node)['password']);
        DB::table('v2_user')->update(['banned' => 1]);
        $this->getJson($this->endpoint('snapshot'), $this->auth())->assertOk()->assertExactJson([
            'version' => 1, 'listen_ip' => '127.0.0.1', 'port' => 16201, 'users' => [],
        ]);
    }

    public function test_group_expiry_quota_and_unsupported_limits()
    {
        foreach ([['group_id' => 2], ['expired_at' => time()-1], ['u' => 1000000]] as $change) {
            DB::table('v2_user')->where('id', 1)->update($change);
            $this->getJson($this->endpoint('snapshot'), $this->auth())->assertOk()->assertJsonCount(0, 'users');
            DB::table('v2_user')->where('id', 1)->update(['group_id' => 1, 'expired_at' => null, 'u' => 0]);
        }
        DB::table('v2_user')->update(['device_limit' => 1]);
        $this->getJson($this->endpoint('snapshot'), $this->auth())->assertStatus(409);
        DB::table('v2_user')->update(['device_limit' => 0, 'speed_limit' => 10]);
        $this->getJson($this->endpoint('snapshot'), $this->auth())->assertStatus(409);
    }

    public function test_retries_and_response_loss_do_not_double_bill_and_fraction_is_retained()
    {
        $this->report(1, 2)->assertOk();
        $this->report(1, 2)->assertOk(); // Same report after an unknown response outcome.
        $this->report(4, 8)->assertOk();
        $user = DB::table('v2_user')->first();
        $this->assertEquals(5, $user->u); $this->assertEquals(10, $user->d);
        $this->assertEquals(4, DB::table('v2_stat_user')->value('u'));
        $this->assertEquals(8, DB::table('v2_stat_server')->value('d'));
        $this->report(3, 8)->assertStatus(409);
        $this->report(4, 8, 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee')->assertStatus(409);
        $this->assertEquals(5, DB::table('v2_user')->value('u'));
    }

    public function test_failed_transaction_rolls_back_usage_statistics_and_checkpoint()
    {
        DB::statement("CREATE TRIGGER reject_mieru_stats BEFORE INSERT ON v2_stat_server BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        try {
            (new MieruTrafficService())->record(1, self::AGENT, [['id' => 1, 'u' => 100, 'd' => 200]]);
            $this->fail('Expected injected database failure');
        } catch (\Illuminate\Database\QueryException $expected) {
            $this->assertEquals(0, DB::table('v2_user')->value('u'));
            $this->assertEquals(0, DB::table('v2_stat_user')->count());
            $this->assertEquals(0, DB::table('v2_mieru_counter')->count());
        }
        DB::statement('DROP TRIGGER reject_mieru_stats');
        $this->report(100, 200)->assertOk();
        $this->assertEquals(125, DB::table('v2_user')->value('u'));
    }

    public function test_invalid_reports_and_legacy_node_ids_are_rejected()
    {
        $this->report(-1, 0)->assertStatus(422);
        ServerV2node::where('id', 1)->update(['protocol' => 'vless']);
        $this->getJson($this->endpoint('snapshot'), $this->auth())->assertNotFound();
        $this->report(100, 100)->assertNotFound();
        $this->assertEquals(0, DB::table('v2_user')->value('u'));
    }

    public function test_switch_only_hides_new_protocol_and_preserves_existing_nodes()
    {
        $legacy = ServerV2node::find(1)->replicate(); $legacy->protocol = 'vless'; $legacy->save();
        $service = new ServerService(); $user = User::find(1);
        config(['mieru.enabled' => false]);
        $servers = $service->getAvailableV2node($user);
        $this->assertCount(1, $servers); $this->assertSame('vless', $servers[0]['protocol']);
        config(['mieru.enabled' => true]);
        $this->assertCount(2, $service->getAvailableV2node($user));
    }

    public function test_legacy_concurrency_usage_cannot_overwrite_mieru_usage()
    {
        ServerV2node::where('id', 1)->update(['rate' => '1.00']);
        Redis::shouldReceive('exists')->with('traffic_reset_lock')->once()->andReturn(false);
        Redis::shouldReceive('hgetall')->with('v2board_upload_traffic')->once()->andReturn([1 => '10']);
        Redis::shouldReceive('hgetall')->with('v2board_download_traffic')->once()->andReturn([1 => '20']);
        Redis::shouldReceive('del')->with('v2board_upload_traffic')->once();
        Redis::shouldReceive('del')->with('v2board_download_traffic')->once();
        // Commit Mieru traffic after the legacy reader obtained its snapshot.
        User::retrieved(function ($user) {
            (new MieruTrafficService())->record(1, self::AGENT, [['id' => $user->id, 'u' => 100, 'd' => 200]]);
        });
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertEquals(110, DB::table('v2_user')->value('u'));
        $this->assertEquals(220, DB::table('v2_user')->value('d'));
    }

    public static function statInterleavings()
    {
        return [[false], [true]];
    }

    public function test_legacy_statistics_batch_boundaries_and_repeated_increments()
    {
        $data = array_fill_keys(range(1, 1001), [10, 20]);
        $job = new StatUserJob($data, ['rate' => '1.00', 'id' => 1], 'v2node');
        $job->handle();
        $this->assertEquals(1001, DB::table('v2_stat_user')->count());
        $this->assertEquals(10010, DB::table('v2_stat_user')->sum('u'));
        $job->handle();
        $this->assertEquals(1001, DB::table('v2_stat_user')->count());
        $this->assertEquals(20020, DB::table('v2_stat_user')->sum('u'));
        $this->assertEquals(40040, DB::table('v2_stat_user')->sum('d'));
    }

    /** @dataProvider statInterleavings */
    public function test_legacy_concurrency_statistics_preserve_both_writers($existing)
    {
        ServerV2node::where('id', 1)->update(['rate' => '1.00']);
        if ($existing) {
            DB::table('v2_stat_user')->insert(['user_id' => 1, 'server_rate' => '1.00', 'u' => 0, 'd' => 0,
                'record_at' => strtotime(date('Y-m-d')), 'record_type' => 'd', 'created_at' => time(), 'updated_at' => time()]);
        }
        $queries = 0; $injected = false;
        DB::listen(function ($query) use (&$queries, &$injected, $existing) {
            if ($injected || strpos($query->sql, 'v2_stat_user') === false) return;
            $queries++;
            if ($queries !== ($existing ? 2 : 1)) return;
            $injected = true;
            (new MieruTrafficService())->record(1, self::AGENT, [['id' => 1, 'u' => 100, 'd' => 200]]);
        });
        (new StatUserJob([1 => [10, 20]], ['rate' => '1.00', 'id' => 1], 'v2node'))->handle();
        $this->assertTrue($injected, 'The competing writer must run during the legacy job');
        $this->assertEquals(110, DB::table('v2_stat_user')->value('u'));
        $this->assertEquals(220, DB::table('v2_stat_user')->value('d'));
    }
}
