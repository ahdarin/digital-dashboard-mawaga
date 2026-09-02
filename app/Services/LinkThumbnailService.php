<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Unfurl" thumbnail (og:image/twitter:image) dari sembarang link post yang
 * di-paste manual staff di Record Publication - beda dari thumbnail_url
 * InstagramMediaSnapshot/TikTokVideoSnapshot (itu dari API resmi platform
 * lewat sync terjadwal). Dipanggil sekali saat publikasi dicatat (lihat
 * WorkflowStatusService), hasilnya disimpan supaya tidak fetch ulang tiap
 * halaman dibuka.
 */
class LinkThumbnailService
{
    public function fetch(?string $url): ?string
    {
        if (! $url || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        try {
            $response = Http::timeout(4)
                ->withUserAgent('Mozilla/5.0 (compatible; DigitalDashboardBot/1.0; +thumbnail-preview)')
                ->get($url);
        } catch (\Throwable $e) {
            Log::info('LinkThumbnailService: gagal fetch link', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $html = $response->body();

        foreach (['og:image', 'twitter:image'] as $property) {
            // Meta content kadang berisi entity HTML (&amp; dst) - didekode
            // supaya URL yang disimpan valid, bukan literal "&amp;" yang
            // nanti di-escape lagi jadi "&amp;amp;" saat dirender Blade.
            if (preg_match('/<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $match)) {
                return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
            }
            // Urutan atribut kadang terbalik (content sebelum property/name).
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\']/i', $html, $match)) {
                return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return null;
    }
}
