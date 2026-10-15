<?php

namespace App\Jobs;

use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d'));
        if ($this->recordType === 'm') {
            //
        }
        $attempt = 0;
        $maxAttempts = 3;
        while ($attempt < $maxAttempts) {
            try {
                DB::beginTransaction();
                foreach (array_chunk($this->data, 500, true) as $chunk) {
                    $this->incrementChunk($chunk, $recordAt);
                }
                DB::commit();
                return;
            } catch (\Exception $e) {
                DB::rollback();
                if (strpos($e->getMessage(), '40001') !== false || strpos(strtolower($e->getMessage()), 'deadlock') !== false) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        sleep(pow(2, $attempt));
                        continue;
                    }
                }
                abort(500, '用户统计数据失败'. $e->getMessage());
            }
        }
    }

    private function incrementChunk(array $data, $recordAt)
    {
        $rows = [];
        $uploadCases = [];
        $downloadCases = [];
        foreach ($data as $userId => $traffic) {
            $userId = (int) $userId;
            $rows[] = [
                'user_id' => $userId, 'server_rate' => $this->server['rate'],
                'record_at' => $recordAt, 'record_type' => $this->recordType,
                'u' => 0, 'd' => 0, 'created_at' => time(), 'updated_at' => time(),
            ];
            $uploadCases[] = 'WHEN ' . $userId . ' THEN ' . (int) $traffic[0];
            $downloadCases[] = 'WHEN ' . $userId . ' THEN ' . (int) $traffic[1];
        }
        // A competing writer may create or increment the same daily row.
        StatUser::insertOrIgnore($rows);
        StatUser::where('record_at', $recordAt)
            ->where('server_rate', $this->server['rate'])
            ->whereIn('user_id', array_keys($data))
            ->update([
                'u' => DB::raw('u + CASE user_id ' . implode(' ', $uploadCases) . ' END'),
                'd' => DB::raw('d + CASE user_id ' . implode(' ', $downloadCases) . ' END'),
            ]);
    }
}
