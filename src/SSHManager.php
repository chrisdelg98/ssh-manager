<?php
declare(strict_types=1);

namespace App;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SSHManager
{
    private int    $timeout;
    private int    $outputLimit;

    public function __construct(int $timeout = 15, int $outputLimit = 51200)
    {
        $this->timeout     = $timeout;
        $this->outputLimit = $outputLimit;
    }

    /**
     * Test if port is reachable before attempting SSH.
     */
    public function isReachable(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * Execute a single command on a server.
     * Returns ['output' => string, 'exit_code' => int, 'error' => string|null]
     */
    public function execute(array $server, string $command): array
    {
        if (!$this->isReachable($server['host'], (int)$server['port'])) {
            return [
                'output'    => '',
                'exit_code' => -1,
                'error'     => "Cannot reach {$server['host']}:{$server['port']} — port unreachable or blocked by hosting provider",
            ];
        }

        try {
            $ssh = new SSH2($server['host'], (int)$server['port'], $this->timeout);

            // Authenticate
            if ($server['auth_type'] === 'key') {
                $key = PublicKeyLoader::load($server['credential']);
                if (!$ssh->login($server['ssh_user'], $key)) {
                    return ['output' => '', 'exit_code' => -1, 'error' => 'Key authentication failed'];
                }
            } else {
                if (!$ssh->login($server['ssh_user'], $server['credential'])) {
                    return ['output' => '', 'exit_code' => -1, 'error' => 'Password authentication failed'];
                }
            }

            $ssh->setTimeout($this->timeout);

            $output = $ssh->exec($command);
            $exitCode = $ssh->getExitStatus();

            // Truncate if too long
            if (strlen($output) > $this->outputLimit) {
                $output = substr($output, 0, $this->outputLimit)
                    . "\n\n[Output truncated at " . number_format($this->outputLimit) . " bytes]";
            }

            return [
                'output'    => $output,
                'exit_code' => (int)$exitCode,
                'error'     => null,
            ];

        } catch (\Throwable $e) {
            return [
                'output'    => '',
                'exit_code' => -1,
                'error'     => 'SSH error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute multiple commands in sequence (for templates).
     * Returns array of per-step results.
     */
    public function executeTemplate(array $server, array $steps): array
    {
        if (!$this->isReachable($server['host'], (int)$server['port'])) {
            return [[
                'step'      => 0,
                'command'   => '',
                'output'    => '',
                'exit_code' => -1,
                'error'     => "Cannot reach {$server['host']}:{$server['port']}",
            ]];
        }

        $results = [];

        try {
            $ssh = new SSH2($server['host'], (int)$server['port'], $this->timeout);

            if ($server['auth_type'] === 'key') {
                $key = PublicKeyLoader::load($server['credential']);
                if (!$ssh->login($server['ssh_user'], $key)) {
                    throw new \RuntimeException('Key authentication failed');
                }
            } else {
                if (!$ssh->login($server['ssh_user'], $server['credential'])) {
                    throw new \RuntimeException('Password authentication failed');
                }
            }

            foreach ($steps as $i => $step) {
                $ssh->setTimeout($this->timeout);
                $output   = $ssh->exec($step['command']);
                $exitCode = $ssh->getExitStatus();

                if (strlen($output) > $this->outputLimit) {
                    $output = substr($output, 0, $this->outputLimit) . "\n[Truncated]";
                }

                $results[] = [
                    'step'        => $i + 1,
                    'command'     => $step['command'],
                    'description' => $step['description'] ?? '',
                    'output'      => $output,
                    'exit_code'   => (int)$exitCode,
                    'error'       => null,
                ];

                // Stop on failure if step is marked as critical
                if (($step['stop_on_error'] ?? false) && $exitCode !== 0) {
                    $results[count($results) - 1]['error'] = "Step failed (exit $exitCode) — execution halted";
                    break;
                }
            }

        } catch (\Throwable $e) {
            $results[] = [
                'step'      => count($results) + 1,
                'command'   => '',
                'output'    => '',
                'exit_code' => -1,
                'error'     => 'SSH error: ' . $e->getMessage(),
            ];
        }

        return $results;
    }
}
