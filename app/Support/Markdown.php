<?php

namespace App\Support;

use League\CommonMark\CommonMarkConverter;

class Markdown
{
    public static function toHtml(?string $text): string
    {
        if (! $text) {
            return '';
        }

        static $converter;
        $converter ??= new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert(self::normalizeSlideBreaks($text));
    }

    /**
     * AI kadang nulis semua bagian "Slide 1: ... Slide 2: ..." nyambung
     * jadi satu paragraf panjang tanpa baris baru, walaupun sudah diminta
     * markdown di prompt. Ini jaring pengaman di sisi tampilan: paksa tiap
     * penanda Slide/Adegan N jadi heading bold di paragraf sendiri, supaya
     * brief lama yang sudah kepalang tersimpan berantakan pun ikut rapi
     * tanpa perlu di-generate ulang.
     */
    private static function normalizeSlideBreaks(string $text): string
    {
        $text = preg_replace_callback(
            '/\*{0,2}\s*(Slide|Adegan)\s*(\d+)\s*\*{0,2}\s*:?\s*/iu',
            function ($m) {
                $label = strcasecmp($m[1], 'Slide') === 0 ? 'SLIDE' : 'ADEGAN';
                return "\n\n**{$label} {$m[2]}**\n\n";
            },
            $text
        );

        // Sub-label opsional dalam kurung siku di awal isi slide/adegan,
        // mis. "[Judul Besar] Mau nongkrong..." - dijadikan italic biar
        // kebeda dari isi utamanya.
        $text = preg_replace('/^\[(.+?)\]\s*/mu', '_$1_ — ', $text);

        return trim($text);
    }
}
