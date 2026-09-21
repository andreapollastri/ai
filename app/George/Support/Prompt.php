<?php

namespace App\George\Support;

final class Prompt
{
    public const SYSTEM = 'You are an experienced operations lead triaging real messages and events. '
        .'Read the situation as a person would: weigh tone, sarcasm, deadlines, money at stake, '
        .'what is being asked and what would happen if nobody acted. '
        .'Then apply the criteria and pick the option that a sensible human operator would pick.';

    public const INSTRUCTION = 'Pick the single option a sensible human operator would choose for this situation. '
        .'Consider implicit signals, not just explicit words. Answer with the letter only.';

    public const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    /**
     * The option texts the reasoner chooses between, in condition order.
     *
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    public static function options(array $condition): array
    {
        return match ($condition['type'] ?? '') {
            'noul' => [
                'Yes. '.self::clean((string) ($condition['yes'] ?? $condition['prompt'] ?? 'It applies')),
                'No. '.self::clean((string) (($condition['no'] ?? '') !== '' ? $condition['no'] : 'It does not apply')),
            ],
            'choice' => array_values(array_map(
                fn (array $option): string => self::clean((string) ($option['label'] ?? '')).': '
                    .self::clean((string) ($option['applies'] ?? '')),
                array_values($condition['options'] ?? []),
            )),
            'score' => array_values(array_map(
                fn (mixed $level): string => self::clean((string) $level),
                array_values($condition['levels'] ?? []),
            )),
            default => throw new \InvalidArgumentException('Unknown condition type.'),
        };
    }

    /**
     * @param  list<string>  $options
     */
    public static function user(string $situation, string $question, array $options): string
    {
        $lines = [];

        foreach (array_values($options) as $i => $option) {
            $lines[] = self::LETTERS[$i].'. '.$option;
        }

        return "Situation:\n".trim($situation)
            ."\n\nQuestion: ".trim($question)
            ."\n\nOptions:\n".implode("\n", $lines)
            ."\n\n".self::INSTRUCTION;
    }

    public const FORMATS = ['chatml', 'chatml_plain', 'phi3', 'llama3'];

    /**
     * The chat turn layout the model was tuned on. The prompt ends where
     * the assistant's first token goes, so the next token is the letter.
     *
     *   chatml        Qwen3, with an empty think block so it answers at once
     *                  instead of opening a reasoning trace
     *   chatml_plain   Qwen2.5 and other ChatML models with no thinking mode
     *   phi3           Phi-3 / Phi-3.5
     *   llama3         Llama 3.x instruct
     *
     * @param  list<array{user: string, assistant: string}>  $shots
     */
    public static function chat(string $system, string $user, string $format = 'chatml', array $shots = []): string
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'chatml';
        $out = self::open($system, $format);

        foreach ($shots as $shot) {
            $out .= self::done((string) ($shot['user'] ?? ''), (string) ($shot['assistant'] ?? ''), $format);
        }

        return $out.self::ask($user, $format);
    }

    private static function open(string $system, string $format): string
    {
        return match ($format) {
            'phi3' => '<|system|>'."\n".$system.'<|end|>'."\n",
            'llama3' => '<|begin_of_text|><|start_header_id|>system<|end_header_id|>'."\n\n".$system.'<|eot_id|>',
            default => '<|im_start|>system'."\n".$system.'<|im_end|>'."\n",
        };
    }

    private static function done(string $user, string $assistant, string $format): string
    {
        $letter = strtoupper(substr(trim($assistant), 0, 1));

        return match ($format) {
            'phi3' => '<|user|>'."\n".$user.'<|end|>'."\n"
                .'<|assistant|>'."\n".$letter.'<|end|>'."\n",
            'llama3' => '<|start_header_id|>user<|end_header_id|>'."\n\n".$user.'<|eot_id|>'
                .'<|start_header_id|>assistant<|end_header_id|>'."\n\n".$letter.'<|eot_id|>',
            default => '<|im_start|>user'."\n".$user.'<|im_end|>'."\n"
                .'<|im_start|>assistant'."\n".$letter.'<|im_end|>'."\n",
        };
    }

    private static function ask(string $user, string $format): string
    {
        return match ($format) {
            'phi3' => '<|user|>'."\n".$user.'<|end|>'."\n"
                .'<|assistant|>'."\n",
            'llama3' => '<|start_header_id|>user<|end_header_id|>'."\n\n".$user.'<|eot_id|>'
                .'<|start_header_id|>assistant<|end_header_id|>'."\n\n",
            'chatml_plain' => '<|im_start|>user'."\n".$user.'<|im_end|>'."\n"
                .'<|im_start|>assistant'."\n",
            default => '<|im_start|>user'."\n".$user.'<|im_end|>'."\n"
                .'<|im_start|>assistant'."\n"
                ."<think>\n\n</think>\n\n",
        };
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
