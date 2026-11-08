<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Response;
use Filegator\Services\View\ViewInterface;

class ViewController
{
    public function index(Response $response, ViewInterface $view)
    {
        return $response->html($view->getIndexPage());
    }

    public function getFrontendConfig(Response $response, Config $config)
    {
        $front = (array) $config->get('frontend_config');
        if (isset($front['upload_chunk_size'])) {
            $front['upload_chunk_size'] = self::chunkSize((int) $front['upload_chunk_size']);
        }

        return $response->json($front);
    }

    /**
     * The upload piece size the browser should use: the configured one, made smaller when this PHP would refuse pieces that big
     * (upload_max_filesize, post_max_size). A piece PHP refuses arrives as an empty upload and the whole upload fails, so a large setting
     * on a server with small limits must not break uploads; it only makes them use more, smaller pieces.
     */
    public static function chunkSize(int $configured, ?string $upload_max = null, ?string $post_max = null): int
    {
        $limits = [];
        $upload = self::iniBytes($upload_max ?? (string) ini_get('upload_max_filesize'));
        if ($upload > 0) {
            $limits[] = $upload;
        }
        $post = self::iniBytes($post_max ?? (string) ini_get('post_max_size'));
        if ($post > 0) {
            $limits[] = $post - 64 * 1024;   // the form fields travel in the same request
        }
        $size = $configured > 0 ? $configured : 1024 * 1024;
        if ($limits) {
            $size = min($size, min($limits));
        }

        return max(64 * 1024, $size);
    }

    /** "2M", "512K", "1G" or plain bytes. 0 means no limit. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^(\d+)\s*([kmg]?)/i', $value, $m)) {
            return 0;
        }
        $n = (int) $m[1];
        switch (strtolower($m[2])) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }

        return $n;
    }
}
