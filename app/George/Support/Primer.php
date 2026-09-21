<?php

namespace App\George\Support;

/**
 * Forced teaching for slot C: a short operator rulebook plus a few
 * worked answers. The ONNX weights stay frozen; the reasoner only sees
 * these turns in-context.
 *
 * Situations here are written to be unlike the UI examples and the
 * blind set. Do not paste those texts into the prompt.
 */
final class Primer
{
    public const RULES = 'Decide from operational facts, not wording or volume.'
        ."\n".'- A lookalike sender or a sudden change of bank details is fraud until verified out of band. Do not pay. Do not send it to billing to execute.'
        ."\n".'- An alert inside an announced maintenance or test window is not an incident and should not page.'
        ."\n".'- Shouting, "urgent", or "critical" is not urgency. A deadline, worsening harm, or a statutory clock is.'
        ."\n".'- Polite tone does not make a data leak or a regulated request routine.'
        ."\n".'- The words invoice, refund, or payment do not pick billing if the facts are fraud, impersonation, or data exposure.';

    /**
     * @var list<array{situation: string, question: string, options: list<string>, letter: string}>
     */
    public const EXAMPLES = [
        [
            'situation' => 'A long-standing contractor mailed from billlng@roofworks-inc.net asking us to wire this month\'s 9,800 USD to a new account by 16:00. Their real domain is roofworks-inc.com.',
            'question' => 'Which team should handle this?',
            'options' => [
                'billing: Refunds, payments and invoices',
                'security: Fraud, impersonation and abuse',
                'support: General questions and follow ups',
            ],
            'letter' => 'B',
        ],
        [
            'situation' => 'Pager: search p95 is 8.4s and the error rate is 5%. #eng-announce and the status page both say "search cluster reindex 01:00-03:00, expect timeouts, suppress paging". It is 02:05.',
            'question' => 'Should this wake an on call engineer right now?',
            'options' => [
                'Yes. Revenue affecting and still getting worse',
                'No. Contained, or already recovering on its own',
            ],
            'letter' => 'B',
        ],
        [
            'situation' => 'I was charged twice for a coffee last Monday. Reverse the extra charge when you have a moment. No rush.',
            'question' => 'Does this message convey urgency?',
            'options' => [
                'Yes. Explicitly time-sensitive or escalating',
                'No. No time pressure expressed',
            ],
            'letter' => 'B',
        ],
    ];

    public static function enabled(): bool
    {
        return true;
    }

    public static function system(): string
    {
        return Prompt::SYSTEM."\n\n".self::RULES;
    }

    /**
     * Completed user/assistant turns shown before the live question.
     *
     * @return list<array{user: string, assistant: string}>
     */
    public static function shots(): array
    {
        return array_map(
            fn (array $example): array => [
                'user' => Prompt::user($example['situation'], $example['question'], $example['options']),
                'assistant' => $example['letter'],
            ],
            self::EXAMPLES,
        );
    }
}
