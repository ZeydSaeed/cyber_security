<?php

namespace App\Http\Requests\Admin;

use App\Access\Permission;
use App\Access\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Adding a person, or changing one (the route's {user} is present): name,
 * address, password, roles and direct permissions. On a change, a blank
 * password keeps the current one.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $updating = $user instanceof User;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($updating ? $user->id : null)],
            'password' => [$updating ? 'nullable' : 'required', 'string', Password::default()],
            'roles' => ['array'],
            'roles.*' => ['string', 'distinct', Rule::enum(Role::class)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
        ];
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return array_values(array_map(fn (string $role): Role => Role::from($role), (array) $this->validated('roles', [])));
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('permissions', [])));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        // Unticked boxes send nothing; a hidden "" keeps the key present.
        foreach (['roles', 'permissions'] as $key) {
            $this->merge([$key => array_values(array_filter((array) $this->input($key, []), fn ($value): bool => $value !== '' && $value !== null))]);
        }

        if ($this->input('password') === '') {
            $this->merge(['password' => null]);
        }
    }
}
