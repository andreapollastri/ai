<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    use HasUuids, MassPrunable;

    protected $fillable = [
        'situation',
        'locale',
        'conditions',
        'status',
        'result',
        'error',
        'elapsed_ms',
        'model',
        'engine',
        'slots',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'result' => 'array',
            'slots' => 'array',
            'elapsed_ms' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDay());
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'situation' => $this->situation,
            'locale' => $this->locale,
            'conditions' => $this->conditions,
            'result' => $this->result,
            'error' => $this->error,
            'elapsed_ms' => $this->elapsed_ms,
            'model' => $this->model,
            'engine' => $this->engine,
        ];
    }
}
