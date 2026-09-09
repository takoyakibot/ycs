<?php

namespace App\Helpers;

class ArtistTagHelper
{
    public static function splitArtistToTags(string $artist): array
    {
        if ($artist === '') {
            return [];
        }

        $normalized = preg_replace('/\s+feat\.?\s+/ui', "\x00", $artist);
        $normalized = preg_replace('/\s+ft\.?\s+/ui', "\x00", $normalized);
        $normalized = str_replace(['×', '＆'], "\x00", $normalized);
        $normalized = preg_replace('/\s+x\s+/u', "\x00", $normalized);
        $normalized = str_replace(['/', '／', ',', '、', '&'], "\x00", $normalized);

        $parts = explode("\x00", $normalized);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn ($p) => $p !== '');

        return array_values($parts);
    }
}
