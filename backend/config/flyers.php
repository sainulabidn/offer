<?php

// Reading offers from flyer PDFs and photos with AI (App\Domain\Flyers). What admins may change
// (provider, model, budget, publishing) is in the admin under Offers → Flyer AI settings.
return [
    // AI calls take up to a few minutes, longer than the default queue connection allows before it hands
    // a job to another worker (retry_after 90 s), so they run on a connection with a longer allowance.
    'queue_connection' => env('FLYER_QUEUE_CONNECTION', 'database-long'),

    // Extra cron workers for the AI queue on servers without Horizon (each one reads one page at a time).
    'workers' => max(1, (int) env('FLYER_WORKERS', 1)),

    // Seconds: one AI request, and one poppler/vips command. A page job makes at most two AI requests (a page
    // read in halves) inside its 240 s; busy or failed requests are retried by the job, not the client.
    'request_timeout' => (int) env('FLYER_REQUEST_TIMEOUT', 100),
    'process_timeout' => (int) env('FLYER_PROCESS_TIMEOUT', 45),

    'max_output_tokens' => 16000,

    // Claude: retry a request the model declines on Anthropic's default fallback model (server-side fallback beta).
    'claude_server_fallback' => (bool) env('FLYER_CLAUDE_SERVER_FALLBACK', true),

    // How each model may think. Opus 5.5 can't turn thinking off (low effort keeps it short); Haiku 5.5 can;
    // Sonnet 5.5 turns it off with "between_tools". Models not listed think as their API default.
    'thinking' => [
        'claude-haiku-5-5' => ['type' => 'disabled'],
        'claude-sonnet-5-5' => ['type' => 'between_tools'],
    ],

    // Fetching flyers from store websites: look host names up and refuse private addresses (tests turn the
    // lookup off; IP addresses are always checked).
    'resolve_hosts' => (bool) env('FLYER_RESOLVE_HOSTS', true),

    // Files staff upload, and files a fetched source downloads.
    'max_file_mb' => 50,
    'disk' => 'local',
    'directory' => 'brochures',
];
