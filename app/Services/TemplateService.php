<?php

namespace App\Services;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

class TemplateService
{
    private static ?Environment $twig = null;

    public static function get(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $viewsPath = __DIR__ . '/../Views';
        $cachePath = __DIR__ . '/../../cache/twig';

        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0777, true);
        }

        $loader = new FilesystemLoader($viewsPath);
        $twig = new Environment($loader, [
            'cache' => $cachePath,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        // Functions
        $twig->addFunction(new TwigFunction('csrf_field', function (): string {
            return function_exists('csrf_field') ? csrf_field() : '';
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return function_exists('csrf_token') ? csrf_token() : '';
        }));

        // User role helpers (use Core\Auth methods)
        $twig->addFunction(new TwigFunction('is_admin', function (): bool {
            return \Core\Auth::isAdmin();
        }));

        $twig->addFunction(new TwigFunction('is_logged_in', function (): bool {
            return \Core\Auth::check();
        }));

        // Domain helpers
        $twig->addFunction(new TwigFunction('format_status_text', function (string $status): string {
            return \App\Helpers\DomainHelper::formatStatusText($status);
        }));

        // Map helpers as functions
        $twig->addFunction(new TwigFunction('sort_url', function (string $column, string $currentSort, string $currentOrder, array $filters = []): string {
            return \App\Helpers\ViewHelper::sortUrl($column, $currentSort, $currentOrder, $filters);
        }));

        $twig->addFunction(new TwigFunction('sort_icon', function (string $column, string $currentSort, string $currentOrder): string {
            return \App\Helpers\ViewHelper::sortIcon($column, $currentSort, $currentOrder);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('pagination_url', function (int $page, string $status = '', string $type = ''): string {
            $params = $_GET;
            $params['page'] = $page;
            if ($status) $params['status'] = $status;
            if ($type) $params['type'] = $type;
            $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            return $currentPath . '?' . http_build_query($params);
        }));

        $twig->addFunction(new TwigFunction('tag_pagination_url', function (int $page, array $filters, int $perPage): string {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/tags?' . http_build_query($params);
        }));

        $twig->addFunction(new TwigFunction('tag_view_pagination_url', function (int $page, array $filters, int $perPage, int $tagId): string {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/tags/' . $tagId . '?' . http_build_query($params);
        }));

        $twig->addFunction(new TwigFunction('tag_view_sort_url', function (string $column, string $currentSort, string $currentOrder, int $tagId): string {
            $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
            $params = $_GET;
            $params['sort'] = $column;
            $params['order'] = $newOrder;
            return '/tags/' . $tagId . '?' . http_build_query($params);
        }));

        $twig->addFunction(new TwigFunction('status_badge', function (string $status): string {
            return \App\Helpers\ViewHelper::statusBadge($status);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('breadcrumbs', function (array $items): string {
            return \App\Helpers\ViewHelper::breadcrumbs($items);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('alert', function (string $type, string $message): string {
            return \App\Helpers\ViewHelper::alert($type, $message);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('http_build_query', function (array $data): string {
            return http_build_query($data);
        }));

        // Filters
        $twig->addFilter(new TwigFilter('truncate', function (string $text, int $length = 50, string $suffix = '...'): string {
            return \App\Helpers\ViewHelper::truncate($text, $length, $suffix);
        }, ['is_safe' => ['html']]));

        $twig->addFilter(new TwigFilter('bytes', function (int $bytes, int $precision = 2): string {
            return \App\Helpers\ViewHelper::formatBytes($bytes, $precision);
        }));

        $twig->addFilter(new TwigFilter('unique', function (array $array): array {
            return array_values(array_unique($array));
        }));

        $twig->addFilter(new TwigFilter('json_decode', function (?string $json): mixed {
            if (empty($json)) {
                return null;
            }
            return json_decode($json, true);
        }));

        self::$twig = $twig;
        return self::$twig;
    }

    /**
     * Get global template data that should be available in all templates
     */
    public static function getGlobalData(): array
    {
        $globalData = [];

        try {
            // Get current user ID
            $userId = \Core\Auth::id();
            
            // Get notifications for top nav (if user is logged in)
            if ($userId) {
                $notificationData = \App\Helpers\LayoutHelper::getNotifications($userId);
                $globalData['recentNotifications'] = $notificationData['items'];
                $globalData['unreadNotifications'] = $notificationData['unread_count'];
            } else {
                $globalData['recentNotifications'] = [];
                $globalData['unreadNotifications'] = 0;
            }

            // Get domain stats for sidebar
            $globalData['domainStats'] = \App\Helpers\LayoutHelper::getDomainStats();

            // Get application settings
            $appSettings = \App\Helpers\LayoutHelper::getAppSettings();
            $globalData['app_name'] = $appSettings['app_name'];
            $globalData['app_timezone'] = $appSettings['app_timezone'];
            $globalData['app_version'] = $appSettings['app_version'];

            // Get current URI for navigation highlighting
            $globalData['current_uri'] = $_SERVER['REQUEST_URI'] ?? '/';

            // Get current user data (if logged in)
            if ($userId) {
                $userModel = new \App\Models\User();
                $user = $userModel->find($userId);
                if ($user) {
                    $globalData['current_user'] = $user;
                    $globalData['session'] = [
                        'user_id' => $userId,
                        'username' => $user['username'],
                        'role' => $user['role'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email']
                    ];
                    
                    // Get user avatar
                    $globalData['user_avatar'] = \App\Helpers\AvatarHelper::getAvatar($user);
                }
            }

            // Get session data for flash messages and clear them (flash messages are one-time use)
            $flashMessages = [];
            
            // Only include flash messages that actually exist
            if (isset($_SESSION['error'])) {
                $flashMessages['error'] = $_SESSION['error'];
                unset($_SESSION['error']);
            }
            if (isset($_SESSION['success'])) {
                $flashMessages['success'] = $_SESSION['success'];
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['warning'])) {
                $flashMessages['warning'] = $_SESSION['warning'];
                unset($_SESSION['warning']);
            }
            if (isset($_SESSION['info'])) {
                $flashMessages['info'] = $_SESSION['info'];
                unset($_SESSION['info']);
            }
            
            $globalData['session'] = array_merge($globalData['session'] ?? [], $flashMessages);

        } catch (\Exception $e) {
            // Fallback defaults if database is not available
            $globalData = array_merge($globalData, [
                'app_name' => 'Domain Monitor',
                'app_version' => '1.0.0',
                'app_timezone' => 'UTC',
                'domainStats' => ['total' => 0, 'expiring_soon' => 0, 'active' => 0],
                'recentNotifications' => [],
                'unreadNotifications' => 0,
                'current_uri' => $_SERVER['REQUEST_URI'] ?? '/',
                'session' => [
                    'error' => $_SESSION['error'] ?? null,
                    'success' => $_SESSION['success'] ?? null,
                    'warning' => $_SESSION['warning'] ?? null,
                    'info' => $_SESSION['info'] ?? null
                ]
            ]);
        }

        return $globalData;
    }
}


