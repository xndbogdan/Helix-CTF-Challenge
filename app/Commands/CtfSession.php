<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CtfSession extends Command
{
    protected $signature = 'ctf:session';
    protected $description = 'Resume CTF session and store cookies/tokens for other commands';

    public function handle()
    {
        $base = config('ctf.base_url');

        $response = Http::post("{$base}/api/resume", [
            'resume_code' => config('ctf.resume_code'),
        ]);

        if (!$response->ok()) {
            $this->error("Failed to resume session: {$response->body()}");
            return 1;
        }

        $data = $response->json();
        $this->info("Session resumed for: {$data['handle']}");
        $this->line("Player ID: {$data['id']}");

        Storage::put('session.json', json_encode($data, JSON_PRETTY_PRINT));
        $this->info('Session saved to storage/app/session.json');

        // Also check /api/me for current status
        $me = Http::withCookies([
            'helix_player' => config('ctf.resume_code'),
        ], 'challenge.qadna.co')->get("{$base}/api/me");

        if ($me->ok()) {
            $meData = $me->json();
            $this->line("Rank: {$meData['rank']}/{$meData['total_players']}");
            $this->line("Points: {$meData['total_points']}");
            $this->line("Solves: " . count($meData['solves']));
            $this->line("Event: {$meData['event_status']}");
        }

        return 0;
    }
}
