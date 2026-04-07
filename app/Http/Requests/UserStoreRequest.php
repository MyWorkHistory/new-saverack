<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\JobPositions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('job_position') && $this->input('job_position') === '') {
            $this->merge(['job_position' => null]);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['required', Rule::in(['pending', 'active', 'inactive'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'personal_email' => ['nullable', 'email', 'max:190'],
            'birthday' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:32'],
            'region' => ['nullable', 'string', 'max:120'],
            'employee_type' => ['nullable', 'string', 'max:50'],
            'job_position' => ['nullable', 'string', 'max:100', Rule::in(JobPositions::allowed())],
            'hire_date' => ['nullable', 'date'],
            'terminate_date' => ['nullable', 'date'],
            'bio' => ['nullable', 'string', 'max:20000'],
            'client_account_id' => ['prohibited'],
            'account_user_role' => ['prohibited'],
            'is_account_primary' => ['prohibited'],
        ];
    }
}
