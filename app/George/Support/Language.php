<?php

namespace App\George\Support;

final class Language
{
    /**
     * George is English-in. This flag is only used to warn when the
     * situation still looks like Italian.
     */
    public static function looksItalian(string $text): bool
    {
        $italian = preg_match_all(
            '/\b(il|lo|la|gli|le|del|della|che|non|per|una|un|sono|questa|questo|essere|con|come|più|anche|degli|nelle|sulla|agli|delle|dei|vorrei|rimborso|urgente|ordine|cliente)\b/iu',
            $text,
        ) ?: 0;

        $english = preg_match_all(
            '/\b(the|and|of|to|in|is|that|for|with|this|are|from|have|not|was|were|you|your)\b/i',
            $text,
        ) ?: 0;

        return $italian >= 2 && $italian > $english;
    }
}
