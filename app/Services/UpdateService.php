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

        // Rate limit basic: 10 minutes
        $lastRun = $setting->getValue('update_last_check_at');
        if ($lastRun && (time() - strtotime($lastRun)) < 600) {
            // Return cached values
            return [
                'rate_limited' => true,
                'pending_migrations' => (int)$setting->getValue('update_has_pending_migrations', 0),
                'remote_sha' => $setting->getValue('update_last_remote_sha'),
                'deployed_sha' => $setting->getValue('deployed_commit_sha'),
            ];
        }

        $pending = $this->checkPendingMigrations();
        $remoteSha = $this->fetchRemoteMainSha($githubToken);
        $deployedSha = $setting->getValue('deployed_commit_sha');

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
            // Fallback to last known remote sha
            $target = $setting->getValue('update_last_remote_sha');
        }
        if (!$target) {
            return false;
        }
        return $setting->setValue('deployed_commit_sha', $target);
    }
}


