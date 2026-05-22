<?php
declare(strict_types=1);

namespace App;

/**
 * Persists streaming SSH job state to temp files so the browser can
 * reconnect after a page refresh and replay the accumulated output.
 *
 * One active job slot per (user, server) pair — starting a new command
 * overwrites the previous one.
 */
class JobStore
{
    private int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Register a new job and clear previous output. Returns the job ID.
     */
    public function start(int $serverId, string $command): string
    {
        $jobId = bin2hex(random_bytes(16));
        $meta  = [
            'job_id'     => $jobId,
            'server_id'  => $serverId,
            'command'    => $command,
            'status'     => 'running',
            'started_at' => time(),
        ];
        @file_put_contents($this->metaPath($serverId), json_encode($meta));
        @file_put_contents($this->outPath($serverId), '');
        return $jobId;
    }

    /**
     * Append one NDJSON event to the output buffer.
     */
    public function appendEvent(int $serverId, array $event): void
    {
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($this->outPath($serverId), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Mark the job as finished.
     * $status should be 'success', 'failure', or 'error'.
     */
    public function finish(int $serverId, string $status): void
    {
        $path = $this->metaPath($serverId);
        if (!file_exists($path)) return;
        $meta = json_decode((string)@file_get_contents($path), true) ?? [];
        $meta['status']   = $status;
        $meta['ended_at'] = time();
        @file_put_contents($path, json_encode($meta));
    }

    /**
     * Return job metadata or null if no job exists for this server.
     */
    public function get(int $serverId): ?array
    {
        $path = $this->metaPath($serverId);
        if (!file_exists($path)) return null;
        return json_decode((string)@file_get_contents($path), true) ?: null;
    }

    /**
     * Return stored output events starting from $offset (line index).
     * Returns ['lines' => array, 'total' => int].
     */
    public function getOutput(int $serverId, int $offset = 0): array
    {
        $path = $this->outPath($serverId);
        if (!file_exists($path)) return ['lines' => [], 'total' => 0];

        $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) return ['lines' => [], 'total' => 0];

        $total = count($raw);
        $slice = array_slice($raw, $offset);
        $lines = array_values(array_filter(array_map(
            fn($l) => json_decode($l, true),
            $slice
        )));

        return ['lines' => $lines, 'total' => $total];
    }

    // ── Paths ────────────────────────────────────────────────────────────

    private function metaPath(int $serverId): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR
             . 'sshmgr_' . $this->userId . '_' . $serverId . '_job.json';
    }

    private function outPath(int $serverId): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR
             . 'sshmgr_' . $this->userId . '_' . $serverId . '_out.ndjson';
    }
}
