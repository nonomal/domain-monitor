<?php

namespace App\Services;

class UpdateService
{
    private const GITHUB_BRANCH_API = 'https://api.github.com/repos/Hosteroid/domain-monitor/branches/release/1.2.0';

    public function checkPendingMigrations(): array
    {
        $pending = [];
        try {
            $pdo = \Core\Database::getConnection();
            // Read executed migrations if table exists
            $executed = [];
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'migrations'");
                if ($stmt && $stmt->fetch()) {
                    $rows = $pdo->query("SELECT migration FROM migrations")->fetchAll(\PDO::FETCH_COLUMN);
                    $executed = $rows ?: [];
                }
            } catch (\Exception $e) {
                $executed = [];
            }

            // List migration files
            $dir = __DIR__ . '/../../database/migrations';
            $files = @scandir($dir) ?: [];
            $all = array_values(array_filter($files, function ($f) {
                return preg_match('/^\d{3}_.+\.sql$/', $f) === 1;
            }));
            sort($all, SORT_STRING);

            // Pending are those not in executed
            foreach ($all as $f) {
                if (!in_array($f, $executed, true)) {
                    $pending[] = $f;
                }
            }
        } catch (\Exception $e) {
            // On error, return empty (don't block other checks)
            $pending = [];
        }

        return $pending;
    }

    public function fetchRemoteMainSha(?string $githubToken = null, int $timeoutSeconds = 8): ?string
    {
        $ch = curl_init(self::GITHUB_BRANCH_API);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeaders($githubToken));
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $status < 200 || $status >= 300 || !$body) {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['commit']['sha'])) {
            return null;
        }
        return $json['commit']['sha'];
    }

    private function buildHeaders(?string $githubToken): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: domain-monitor-update-check'
        ];
        if (!empty($githubToken)) {
            $headers[] = 'Authorization: Bearer ' . $githubToken;
        }
        return $headers;
    }

    public function performUpdateCheck(): array
    {
        $setting = new \App\Models\Setting();
        $githubToken = getenv('GITHUB_TOKEN') ?: null;

        // Always perform a fresh check (no time-based rate limiting)

        $pending = $this->checkPendingMigrations();
        $remoteSha = $this->fetchRemoteMainSha($githubToken);
        $storedDeployedSha = $setting->getValue('deployed_commit_sha');
        // Try local git detection every time to avoid staleness
        $autoSha = $this->detectLocalGitSha();
        if (!empty($autoSha)) {
            // Prefer auto-detected when available; refresh stored value if different
            if ($storedDeployedSha !== $autoSha) {
                $setting->setValue('deployed_commit_sha', $autoSha);
            }
            $deployedSha = $autoSha;
        } else {
            // Fallback to stored value if auto-detection unavailable
            $deployedSha = $storedDeployedSha;
        }

        // Persist snapshot
        $setting->setValue('update_last_check_at', date('Y-m-d H:i:s'));
        if ($remoteSha) {
            $setting->setValue('update_last_remote_sha', $remoteSha);
        }
        $setting->setValue('update_has_pending_migrations', !empty($pending) ? '1' : '0');

        return [
            'rate_limited' => false,
            'pending_migrations' => count($pending),
            'pending_migration_files' => $pending,
            'remote_sha' => $remoteSha,
            'deployed_sha' => $deployedSha,
        ];
    }

    public function markDeployed(?string $sha = null): bool
    {
        $setting = new \App\Models\Setting();
        $target = $sha;
        if (!$target) {
            // Prefer local git detection, then last known remote
            $target = $this->detectLocalGitSha();
            if (!$target) {
                $target = $setting->getValue('update_last_remote_sha');
            }
        }
        if (!$target) {
            return false;
        }
        return $setting->setValue('deployed_commit_sha', $target);
    }

    /**
     * Attempt to detect the current commit SHA from the local .git directory or git CLI.
     */
    private function detectLocalGitSha(): ?string
    {
        try {
            $root = defined('PATH_ROOT') ? PATH_ROOT : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
            $gitDir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.git';

            // If HEAD is a symbolic ref, resolve to ref file, else it already contains the SHA
            $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
            if (@is_file($headFile)) {
                $head = @trim(@file_get_contents($headFile) ?: '');
                if ($head !== '') {
                    if (strpos($head, 'ref:') === 0) {
                        $refPath = trim(substr($head, 4));
                        $refFile = $gitDir . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $refPath);
                        if (@is_file($refFile)) {
                            $sha = @trim(@file_get_contents($refFile) ?: '');
                            if ($this->isValidSha($sha)) {
                                return $sha;
                            }
                        }
                        // Fallback to packed-refs
                        $packed = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';
                        if (@is_file($packed)) {
                            $lines = @file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                            foreach ($lines as $line) {
                                if ($line[0] === '#') { continue; }
                                if ($line[0] === '^') { continue; }
                                $parts = preg_split('/\s+/', trim($line));
                                if (count($parts) >= 2 && $parts[1] === $refPath && $this->isValidSha($parts[0])) {
                                    return $parts[0];
                                }
                            }
                        }
                    } else {
                        // HEAD contains SHA directly (detached HEAD)
                        if ($this->isValidSha($head)) {
                            return $head;
                        }
                    }
                }
            }

            // Last resort: use git CLI if available
            $output = @shell_exec('git rev-parse HEAD 2>&1');
            $sha = $output ? trim($output) : '';
            if ($this->isValidSha($sha)) {
                return $sha;
            }
        } catch (\Throwable $e) {
            // ignore and return null
        }
        return null;
    }

    private function isValidSha(?string $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/i', $sha) === 1;
    }
}


