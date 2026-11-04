<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
{
    /** Columnas por las que se permite ordenar. */
    public const SORTABLE = [
        'name',
        'email',
        'created_at',
    ];

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(User::ASSIGNABLE_ROLES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort_by' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function search(): ?string
    {
        $search = trim((string) $this->query('search', ''));

        return $search === '' ? null : $search;
    }

    public function role(): ?string
    {
        return $this->validated('role');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function sortBy(): string
    {
        return (string) ($this->validated('sort_by') ?? 'name');
    }

    public function sortDir(): string
    {
        return (string) ($this->validated('sort_dir') ?? 'asc');
    }
}
