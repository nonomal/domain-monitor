<?php

namespace App\Controllers;

use Core\Controller;
use App\Services\Logger;

class InstallerController extends Controller
{
    private $db = null;
    private Logger $logger;
    
    public function __construct()
    {
        $this->logger = new Logger('installer');
    }
    
    /**
     * Check if system is already installed
     */
    private function isInstalled(): bool
    {
        try {
            $pdo = \Core\Database::getConnection();
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            return $stmt->fetchColumn() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Check pending migrations
     */
    private function getPendingMigrations(bool $createMigrationsTable = true): array
    {
        try {
            $pdo = \Core\Database::getConnection();
            
            // FIRST: Check ONLY for core application tables BEFORE creating migrations table
            // Core tables: users, domains, settings, notification_groups
            // These are the only reliable indicators of a real installation
            $hasUsers = false;
            $hasDomains = false;
            $hasSettings = false;
            $hasNotificationGroups = false;
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                $hasUsers = $stmt->fetchColumn() > 0;
            } catch (\Exception $e) {
                // Users table doesn't exist
            }
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM domains");
                $hasDomains = true; // Table exists
            } catch (\Exception $e) {
                // Domains table doesn't exist
            }
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
                $hasSettings = true; // Table exists
            } catch (\Exception $e) {
                // Settings table doesn't exist
            }
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM notification_groups");
                $hasNotificationGroups = true; // Table exists
            } catch (\Exception $e) {
                // Notification groups table doesn't exist
            }
            
            // If no core application tables exist - this is a fresh install
            // Core tables are: users, domains, settings, notification_groups
            // Note: sessions, password_reset_tokens, etc. might exist from app startup but don't indicate real installation
            if (!$hasUsers && !$hasDomains && !$hasSettings && !$hasNotificationGroups) {
                $this->logger->info("Fresh install detected - no core tables exist, using latest consolidated schema");
                $latestSchema = $this->getLatestConsolidatedSchema();
                return $latestSchema ? [$latestSchema] : ['000_initial_schema_v1.1.0.sql'];
            }
            
            $this->logger->debug("Not fresh install", [
                'hasUsers' => $hasUsers,
                'hasDomains' => $hasDomains,
                'hasSettings' => $hasSettings,
                'hasNotificationGroups' => $hasNotificationGroups
            ]);
            
            // Additional check: if we have some tables but no actual data in core tables, treat as fresh install
            // This handles cases where tables might be created by app startup but no real data exists
            if ($hasUsers && !$hasDomains && !$hasSettings && !$hasNotificationGroups) {
                // Only users table exists, check if it has any real data
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                    $adminCount = $stmt->fetchColumn();
                    if ($adminCount == 0) {
                        $latestSchema = $this->getLatestConsolidatedSchema();
                        return $latestSchema ? [$latestSchema] : ['000_initial_schema_v1.1.0.sql'];
                    }
                } catch (\Exception $e) {
                    $latestSchema = $this->getLatestConsolidatedSchema();
                    return $latestSchema ? [$latestSchema] : ['000_initial_schema_v1.1.0.sql'];
                }
            }
            
            // Create migrations table if it doesn't exist (only when actually installing)
            if ($createMigrationsTable) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS migrations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        migration VARCHAR(255) NOT NULL UNIQUE,
                        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_migration (migration)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }
            
            // Get executed migrations (only if migrations table exists)
            $executed = [];
            if ($createMigrationsTable) {
                try {
                    $stmt = $pdo->query("SELECT migration FROM migrations");
                    $executed = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                } catch (\Exception $e) {
                    // Migrations table doesn't exist yet
                    $executed = [];
                }
            }
            
            // If no migrations executed but has data - treat as existing install, compute pending dynamically
            if (empty($executed) && ($hasUsers || $hasDomains)) {
                // If critical tables are missing, treat as broken fresh install and use consolidated schema
                if (!$hasSettings || !$hasNotificationGroups) {
                    $pdo->exec("DELETE FROM migrations");
                    $latestSchema = $this->getLatestConsolidatedSchema();
                    return $latestSchema ? [$latestSchema] : ['000_initial_schema_v1.1.0.sql'];
                }
                // Otherwise, fall through to dynamic pending calculation below
            }
            
            // If no migrations executed and no data - fresh install (use consolidated)
            if (empty($executed)) {
                return $freshInstallMigration;
            }
            
            // If has executed migrations - check for pending incremental ones dynamically
            $allMigrations = $this->listIncrementalMigrations();
            // Preserve order
            $pending = array_values(array_filter($allMigrations, function($m) use ($executed) {
                return !in_array($m, $executed);
            }));
            
            // If we have executed migrations but critical tables are missing, something went wrong
            // Clear migrations and use fresh install
            if (!empty($executed) && (!$hasSettings || !$hasNotificationGroups)) {
                $pdo->exec("DELETE FROM migrations");
                return $freshInstallMigration;
            }
            
            return $pending;
            
        } catch (\Exception $e) {
            // If critical error - assume fresh install using latest consolidated schema
            $latestSchema = $this->getLatestConsolidatedSchema();
            return $latestSchema ? [$latestSchema] : ['000_initial_schema_v1.1.0.sql'];
        }
    }

    /**
     * List incremental migration files (excludes consolidated schemas)
     */
    private function listIncrementalMigrations(): array
    {
        $dir = __DIR__ . '/../../database/migrations';
        $files = @scandir($dir) ?: [];
        $migrations = array_values(array_filter($files, function($f) {
            return preg_match('/^\d{3}_.+\.sql$/', $f) && strpos($f, '000_initial_schema_') !== 0;
        }));
        sort($migrations, SORT_STRING);
        return $migrations;
    }

    /**
     * Get the latest consolidated schema filename by version in name
     */
    private function getLatestConsolidatedSchema(): ?string
    {
        $dir = __DIR__ . '/../../database/migrations';
        $files = @scandir($dir) ?: [];
        $schemas = array_values(array_filter($files, function($f) {
            return strpos($f, '000_initial_schema_') === 0 && substr($f, -4) === '.sql';
        }));
        if (empty($schemas)) {
            return null;
        }
        // Pick max by semantic version in filename
        usort($schemas, function($a, $b) {
            $va = $this->extractVersionFromSchema($a);
            $vb = $this->extractVersionFromSchema($b);
            return version_compare($vb, $va);
        });
        return $schemas[0] ?? null;
    }

    private function extractVersionFromSchema(string $filename): string
    {
        if (preg_match('/000_initial_schema_v([0-9]+\.[0-9]+\.[0-9]+)\.sql/', $filename, $m)) {
            return $m[1];
        }
        return '0.0.0';
    }
    
    /**
     * Show installer welcome page
     */
    public function index()
    {
        if ($this->isInstalled()) {
            // Check for pending migrations without executing them
            $pending = $this->getPendingMigrations(false);
            if (empty($pending)) {
                $_SESSION['info'] = 'System is already installed and up to date';
                $this->redirect('/');
                return;
            }
            // Has pending migrations - show updater
            $this->redirect('/install/update');
            return;
        }
        
        $this->view('installer/welcome', [
            'title' => 'Install Domain Monitor'
        ]);
    }
    
    /**
     * Check database connection
     */
    public function checkDatabase()
    {
        try {
            $pdo = \Core\Database::getConnection();
            $pdo->query("SELECT 1");
            
            $this->view('installer/database-check', [
                'title' => 'Database Connection',
                'success' => true
            ]);
        } catch (\Exception $e) {
            $this->view('installer/database-check', [
                'title' => 'Database Connection',
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Run installation
     */
    public function install()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/install');
            return;
        }
        
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $adminPassword = trim($_POST['admin_password'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        
        // Validate username format and length
        $usernameError = \App\Helpers\InputValidator::validateUsername($adminUsername, 3, 50);
        if ($usernameError) {
            $_SESSION['error'] = $usernameError;
            $this->redirect('/install');
            return;
        }
        
        if (empty($adminPassword) || strlen($adminPassword) < 8) {
            $_SESSION['error'] = 'Admin password must be at least 8 characters';
            $this->redirect('/install');
            return;
        }
        
        if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid admin email';
            $this->redirect('/install');
            return;
        }
        
        try {
            $pdo = \Core\Database::getConnection();
            
            // Run all migrations
            $migrations = $this->getPendingMigrations();
            $results = [];
            
            // Debug: Log what migrations are being executed
            $this->logger->debug("Executing migrations: " . implode(', ', $migrations));
            
            // Set session data for progress tracking
            $_SESSION['install_progress'] = [
                'total_steps' => count($migrations) + 3, // migrations + admin + encryption + complete
                'current_step' => 0,
                'status' => 'running'
            ];
            
            // For fresh installs, ONLY execute the consolidated schema
            // It already includes the migrations table and marks itself as executed
            if (count($migrations) === 1 && strpos($migrations[0], '000_initial_schema_') === 0) {
                $this->logger->debug("Fresh install - executing consolidated schema only");
                
                $file = __DIR__ . '/../../database/migrations/' . $migrations[0];
                $sql = file_get_contents($file);
                
                // Replace admin credentials
                $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
                $sql = str_replace('{{ADMIN_PASSWORD_HASH}}', $passwordHash, $sql);
                $sql = str_replace('{{ADMIN_USERNAME}}', $adminUsername, $sql);
                $sql = str_replace('{{ADMIN_EMAIL}}', $adminEmail, $sql);
                
                // Execute the entire consolidated schema at once
                // This is safe because MySQL can handle multiple statements with CREATE TABLE IF NOT EXISTS
                try {
                    $pdo->exec($sql);
                    $this->logger->info("Consolidated schema executed successfully");
                    $results[] = '000_initial_schema_v1.1.0.sql';
                } catch (\PDOException $e) {
                    $this->logger->error("Consolidated schema execution failed: " . $e->getMessage());
                    // Fallback to statement-by-statement parsing
                    $statements = $this->parseSqlStatements($sql);
                    $successCount = 0;
                    foreach ($statements as $statement) {
                        if (!empty(trim($statement))) {
                            try {
                                $pdo->exec($statement);
                                $successCount++;
                            } catch (\PDOException $e2) {
                                // Ignore duplicate/already exists errors - these are expected with IF NOT EXISTS
                                if (strpos($e2->getMessage(), 'Duplicate') === false && 
                                    strpos($e2->getMessage(), 'already exists') === false &&
                                    strpos($e2->getMessage(), 'Table') === false) {
                                    $this->logger->error("Statement failed: " . $statement . " - Error: " . $e2->getMessage());
                                    throw $e2;
                                }
                            }
                        }
                    }
                    $this->logger->info("Consolidated schema executed with fallback method - $successCount statements successful");
                    $results[] = '000_initial_schema_v1.1.0.sql';
                }
                
                // Mark all individual migrations as executed since the consolidated schema includes them all
                $allIndividualMigrations = $this->listIncrementalMigrations();
                
                $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE migration=migration");
                foreach ($allIndividualMigrations as $migration) {
                    try {
                        $stmt->execute([$migration]);
                    } catch (\Exception $e) {
                        $this->logger->warning("Failed to mark migration as executed: " . $migration, [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                $this->logger->info("All individual migrations marked as executed", [
                    'count' => count($allIndividualMigrations)
                ]);
            } else {
                // For incremental updates, create migrations table and execute migrations normally
                $this->logger->debug("Incremental update - ensuring migrations table exists");
                
                // Ensure migrations table exists for tracking
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS migrations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        migration VARCHAR(255) NOT NULL UNIQUE,
                        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_migration (migration)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                foreach ($migrations as $migration) {
                    $file = __DIR__ . '/../../database/migrations/' . $migration;
                    if (!file_exists($file)) continue;
                    
                    $sql = file_get_contents($file);
                    
                    // Execute SQL - use robust method
                    try {
                        $pdo->exec($sql);
                    } catch (\PDOException $e) {
                        // If that fails, try the statement-by-statement approach as fallback
                        $statements = $this->parseSqlStatements($sql);
                        foreach ($statements as $statement) {
                            if (!empty(trim($statement))) {
                                try {
                                    $pdo->exec($statement);
                                } catch (\PDOException $e2) {
                                    // Ignore duplicate/already exists errors
                                    if (strpos($e2->getMessage(), 'Duplicate') === false && 
                                        strpos($e2->getMessage(), 'already exists') === false &&
                                        strpos($e2->getMessage(), 'Table') === false) {
                                        throw $e2;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Mark as executed
                    $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE migration=migration");
                    $stmt->execute([$migration]);
                    
                    $results[] = $migration;
                }
            }
            
            // Update admin user to ensure role and verified status (in case migration already had defaults)
            $stmt = $pdo->prepare("UPDATE users SET role = 'admin', email_verified = 1 WHERE username = ?");
            $stmt->execute([$adminUsername]);
            $this->logger->info("Admin user configured", ['username' => $adminUsername]);
            
            // Generate encryption key if not exists
            if (empty($_ENV['APP_ENCRYPTION_KEY'])) {
                $this->generateEncryptionKey();
                $this->logger->info("Encryption key generated");
            }
            
            // Create .installed flag file
            $installedFile = __DIR__ . '/../../.installed';
            file_put_contents($installedFile, date('Y-m-d H:i:s'));
            $this->logger->info("Installation flag file created");
            
            // Create welcome notification for admin
            try {
                // Get the admin user ID
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$adminUsername]);
                $adminUser = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($adminUser) {
                    $notificationService = new \App\Services\NotificationService();
                    $notificationService->notifyWelcome($adminUser['id'], $adminUsername);
                    $this->logger->info("Welcome notification created", ['user_id' => $adminUser['id']]);
                }
            } catch (\Exception $e) {
                // Don't fail install if notification fails
                $this->logger->error("Failed to create welcome notification: " . $e->getMessage());
            }
            
            // Redirect to complete page
            $_SESSION['install_complete'] = true;
            
            $this->logger->info("Installation completed successfully");
            $this->redirect('/install/complete');
            
        } catch (\Exception $e) {
            $this->logger->error("Installation failed: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            $_SESSION['error'] = 'Installation failed: ' . $e->getMessage();
            $this->redirect('/install');
        }
    }
    
    /**
     * Show update page
     */
    public function showUpdate()
    {
        $pending = $this->getPendingMigrations();
        
        if (empty($pending)) {
            $_SESSION['info'] = 'No updates available';
            $this->redirect('/');
            return;
        }
        
        $this->view('installer/update', [
            'title' => 'System Update',
            'migrations' => $pending
        ]);
    }
    
    /**
     * Run update
     */
    public function runUpdate()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/install/update');
            return;
        }
        
        try {
            $pdo = \Core\Database::getConnection();
            $migrations = $this->getPendingMigrations();
            $executed = [];
            
            // Ensure migrations table exists for tracking
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_migration (migration)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            foreach ($migrations as $migration) {
                $file = __DIR__ . '/../../database/migrations/' . $migration;
                if (!file_exists($file)) continue;
                
                $sql = file_get_contents($file);
                
                // Execute SQL - use same robust method as install()
                try {
                    // For complex migration files, execute the entire SQL at once
                    $pdo->exec($sql);
                } catch (\PDOException $e) {
                    // If that fails, try the statement-by-statement approach as fallback
                    $statements = $this->parseSqlStatements($sql);
                    foreach ($statements as $statement) {
                        if (!empty(trim($statement))) {
                            try {
                                $pdo->exec($statement);
                            } catch (\PDOException $e2) {
                                // Ignore duplicate/already exists errors
                                if (strpos($e2->getMessage(), 'Duplicate') === false && 
                                    strpos($e2->getMessage(), 'already exists') === false &&
                                    strpos($e2->getMessage(), 'Table') === false) {
                                    throw $e2;
                                }
                            }
                        }
                    }
                }
                
                // Mark as executed
                $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE migration=migration");
                $stmt->execute([$migration]);
                
                $executed[] = $migration;
            }
            
            // Create .installed flag file if doesn't exist (for v1.0.0 upgrades)
            $installedFile = __DIR__ . '/../../.installed';
            if (!file_exists($installedFile)) {
                file_put_contents($installedFile, date('Y-m-d H:i:s'));
                $this->logger->info("Installation flag file created");
            }
            
            // Notify admins about upgrade (if migrations were executed)
            if (!empty($executed)) {
                $this->logger->info("Migrations executed", [
                    'count' => count($executed),
                    'migrations' => $executed
                ]);
                
                try {
                    $settingModel = new \App\Models\Setting();
                    // Read version before and after migrations
                    $fromVersion = $settingModel->getAppVersion();
                    // Force reload by reading again (if cached elsewhere, still safe)
                    $toVersion = $settingModel->getAppVersion();
                    $notificationService = new \App\Services\NotificationService();
                    $notificationService->notifyAdminsUpgrade($fromVersion, $toVersion, count($executed));
                } catch (\Exception $e) {
                    // Don't fail upgrade if notification fails
                    $this->logger->error("Failed to create upgrade notification: " . $e->getMessage());
                }
            }
            
            $_SESSION['success'] = count($executed) . ' migration(s) executed successfully';
            $this->logger->info("Update completed successfully", ['migrations_executed' => count($executed)]);
            $this->redirect('/');
            
        } catch (\Exception $e) {
            $this->logger->error("Update failed: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            $_SESSION['error'] = 'Update failed: ' . $e->getMessage();
            $this->redirect('/install/update');
        }
    }
    
    /**
     * Show installation complete page
     */
    public function complete()
    {
        if (!isset($_SESSION['install_complete'])) {
            $this->redirect('/');
            return;
        }
        
        unset($_SESSION['install_complete']);
        
        $this->view('installer/complete', [
            'title' => 'Installation Complete'
        ]);
    }
    
    /**
     * Parse SQL statements from a SQL file (fallback method)
     */
    private function parseSqlStatements(string $sql): array
    {
        // Remove comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Split by semicolon, but be more careful about it
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                // Check for escaped quotes
                if ($i > 0 && $sql[$i-1] !== '\\') {
                    $inString = false;
                }
            } elseif (!$inString && $char === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }
            
            $current .= $char;
        }
        
        // Add the last statement if it doesn't end with semicolon
        if (!empty(trim($current))) {
            $statements[] = trim($current);
        }
        
        return array_filter($statements, function($stmt) {
            return !empty(trim($stmt));
        });
    }

    /**
     * Generate encryption key
     */
    private function generateEncryptionKey()
    {
        $encryptionKey = base64_encode(random_bytes(32));
        $envFile = __DIR__ . '/../../.env';
        
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            
            if (strpos($envContent, 'APP_ENCRYPTION_KEY=') !== false) {
                $envContent = preg_replace(
                    '/APP_ENCRYPTION_KEY=.*$/m',
                    "APP_ENCRYPTION_KEY=$encryptionKey",
                    $envContent
                );
            } else {
                $envContent .= "\nAPP_ENCRYPTION_KEY=$encryptionKey\n";
            }
            
            file_put_contents($envFile, $envContent);
        }
    }
}

