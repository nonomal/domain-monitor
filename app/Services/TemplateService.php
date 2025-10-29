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

        // Map helpers as functions
        $twig->addFunction(new TwigFunction('sort_url', function (string $column, string $currentSort, string $currentOrder, array $filters = []): string {
            return \App\Helpers\ViewHelper::sortUrl($column, $currentSort, $currentOrder, $filters);
        }));

        $twig->addFunction(new TwigFunction('sort_icon', function (string $column, string $currentSort, string $currentOrder): string {
            return \App\Helpers\ViewHelper::sortIcon($column, $currentSort, $currentOrder);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('pagination_url', function (int $page, array $filters, int $perPage): string {
            return \App\Helpers\ViewHelper::paginationUrl($page, $filters, $perPage);
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

        // Filters
        $twig->addFilter(new TwigFilter('truncate', function (string $text, int $length = 50, string $suffix = '...'): string {
            return \App\Helpers\ViewHelper::truncate($text, $length, $suffix);
        }, ['is_safe' => ['html']]));

        $twig->addFilter(new TwigFilter('bytes', function (int $bytes, int $precision = 2): string {
            return \App\Helpers\ViewHelper::formatBytes($bytes, $precision);
        }));

        self::$twig = $twig;
        return self::$twig;
    }
}


