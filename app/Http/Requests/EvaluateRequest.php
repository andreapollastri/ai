<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EvaluateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxSituation = (int) config('george.max_situation_chars', 2000);
        $maxConditions = (int) config('george.max_conditions', 5);
        $maxOptions = (int) config('george.max_options', 8);
        $maxLevels = (int) config('george.max_levels', 7);

        return [
            'situation' => ['required', 'string', 'min:8', 'max:'.$maxSituation],
            'locale' => ['nullable', 'in:en'],
            'conditions' => ['required', 'array', 'min:1', 'max:'.$maxConditions],
            'conditions.*.type' => ['required', 'in:noul,choice,score'],
            'conditions.*.name' => ['required', 'string', 'max:80'],
            'conditions.*.prompt' => ['required', 'string', 'max:240'],
            'conditions.*.yes' => ['nullable', 'string', 'max:240'],
            'conditions.*.no' => ['nullable', 'string', 'max:240'],
            'conditions.*.options' => ['nullable', 'array', 'min:2', 'max:'.$maxOptions],
            'conditions.*.options.*.label' => ['required_with:conditions.*.options', 'string', 'max:80'],
            'conditions.*.options.*.applies' => ['nullable', 'string', 'max:240'],
            'conditions.*.levels' => ['nullable', 'array', 'min:2', 'max:'.$maxLevels],
            'conditions.*.levels.*' => ['required_with:conditions.*.levels', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('conditions', []) as $i => $condition) {
                $type = $condition['type'] ?? null;

                if ($type === 'noul' && blank($condition['yes'] ?? null) && blank($condition['prompt'] ?? null)) {
                    $validator->errors()->add("conditions.$i.yes", 'A yes/no condition needs a prompt or a yes criterion.');
                }

                if ($type === 'choice' && count($condition['options'] ?? []) < 2) {
                    $validator->errors()->add("conditions.$i.options", 'A pick-one condition needs at least two options.');
                }

                if ($type === 'score' && count($condition['levels'] ?? []) < 2) {
                    $validator->errors()->add("conditions.$i.levels", 'A rating condition needs at least two levels.');
                }
            }
        });
    }
}
