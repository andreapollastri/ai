<?php

namespace App\George\Support;

final class Hypotheses
{
    /**
     * English MNLI / zeroshot-v2 models are trained on this template.
     * Using fragments (`{}` alone) collapses quality.
     */
    public const TEMPLATE = 'This example is {}.';

    public const NOUL = self::TEMPLATE;

    /**
     * Routing options read better as a category claim than as a bare
     * description: sharper mass, same accuracy, on the Jev benchmark.
     */
    public const CHOICE = 'This is a case of {}.';

    public const SCORE = self::TEMPLATE;

    /**
     * Turn a UI criterion into an NLI label fragment that fits
     * "This example is {}."
     */
    public static function phrase(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^(does this|is this|should this|can this|will this|are these)\s+/i', '', $text) ?? $text;
        $text = rtrim($text, " \t.?!");

        return lcfirst(trim($text));
    }

    public static function choice(string $label, string $applies): string
    {
        $label = trim($label);
        $applies = trim($applies);

        // The display label is for the UI. The NLI hypothesis is the
        // "when it applies" clause, which is the actual claim.
        return $applies !== '' ? $applies : $label;
    }

    /**
     * Jev scores (situation + question + criteria). Dropping the question
     * makes abstract yes/no criteria much weaker under NLI.
     */
    public static function premise(string $situation, string $prompt = ''): string
    {
        $situation = trim($situation);
        $prompt = trim($prompt);

        if ($prompt === '') {
            return $situation;
        }

        return $situation."\n\nQuestion: ".$prompt;
    }
}
