<?php

namespace App\Console\Commands;

use App\Models\ServerV2node;
use Illuminate\Console\Command;

class MieruVisibility extends Command
{
    protected $signature = 'mieru:visibility {id} {--show=0}';
    protected $description = 'Show or hide only an experimental Mieru node';

    public function handle()
    {
        $node = ServerV2node::findOrFail($this->argument('id'));
        if ($node->protocol !== 'mieru' || !in_array($this->option('show'), ['0', '1'], true)) {
            $this->error('Requires a Mieru node and --show=0 or --show=1.');
            return 1;
        }
        $node->show = (int) $this->option('show');
        $node->save();
        $this->info('Mieru node visibility updated.');
        return 0;
    }
}
