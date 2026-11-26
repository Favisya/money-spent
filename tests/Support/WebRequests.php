<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Browser-like requests against the booted kernel in $this->app, from the host Mac (127.0.0.1) by default.
 *
 * @property KernelInterface $app
 */
trait WebRequests
{
    private const string ORIGIN = 'http://localhost';

    /** Request::create() pretends to be an English browser; the app is used in Russian. */
    private const array BROWSER = ['HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9'];

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    private function get(string $uri, array $server = [], array $cookies = []): Response
    {
        $request = Request::create($uri, 'GET', cookies: $cookies, server: [...self::BROWSER, ...$server]);

        return $this->app->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    /**
     * @param array<string, mixed>       $data
     * @param array<string, string>|null $server  null means a same-origin browser post
     * @param array<string, string>      $cookies
     */
    private function post(string $uri, array $data, ?array $server = null, array $cookies = []): Response
    {
        $request = Request::create(
            $uri,
            'POST',
            $data,
            $cookies,
            server: [...self::BROWSER, ...($server ?? ['HTTP_ORIGIN' => self::ORIGIN])],
        );

        return $this->app->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, UploadedFile> $files
     */
    private function upload(string $uri, array $data, array $files): Response
    {
        $request = Request::create(
            $uri,
            'POST',
            $data,
            [],
            $files,
            [...self::BROWSER, 'HTTP_ORIGIN' => self::ORIGIN],
        );

        return $this->app->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    /**
     * Cyrillic words a reader of the English page would see, apart from the participants' names.
     *
     * @return list<string>
     */
    private function russianLeftovers(string $html): array
    {
        $html  = (string) preg_replace('#<span class="avatar[^"]*"[^>]*>[^<]*</span>#u', '', $html);
        $shown = [strip_tags($html)];

        preg_match_all('/\s(?:title|aria-label|placeholder|data-tip|alt)="([^"]*)"/u', $html, $attributes);
        array_push($shown, ...$attributes[1]);

        $text = str_replace(['Анна', 'Борис'], '', html_entity_decode(implode("\n", $shown), ENT_QUOTES | ENT_HTML5));
        preg_match_all('/\p{Cyrillic}+/u', $text, $words);

        return array_values(array_unique($words[0]));
    }
}
