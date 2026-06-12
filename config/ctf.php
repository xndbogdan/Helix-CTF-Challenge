<?php

return [
    'base_url' => env('CTF_BASE_URL', 'https://challenge.qadna.co'),
    'resume_code' => env('CTF_RESUME_CODE', 'HLX-LMST-QL'),
    'handle' => env('CTF_HANDLE', 'Bobee'),
    'player_id' => '8cab29c0-8e02-4f2b-8408-60d6aa139240',

    // Challenge #1 credentials
    'login' => [
        'username' => 'dna_admin',
        'password' => 'Pr0b3_D33p!',
        'code' => 'QX-7291',
    ],

    // added after the fact (from walkthrough PDF) — accessToken leaked by
    // POST /api/profile. Reused by Challenge #2 (escalation) and #5 (resolve).
    'access_token' => 'xK9mQ2vL8nR3',
];
