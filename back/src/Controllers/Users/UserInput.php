<?php

declare(strict_types=1);

namespace Wps\Controllers\Users;

use Wps\Http\ApiException;
use Wps\Persistence\UserRepository;

final class UserInput
{
    /**
     * @param array<string, mixed> $body
     * @return array{email?: string, password?: string, role?: string}
     */
    public static function validate(array $body, bool $creating): array
    {
        $errors = [];
        $data = [];

        if ($creating || array_key_exists('email', $body)) {
            $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['field' => 'email', 'message' => 'A valid email is required'];
            } else {
                $data['email'] = $email;
            }
        }
        $password = $body['password'] ?? null;
        if ($creating || (is_string($password) && $password !== '')) {
            if (!is_string($password) || mb_strlen($password) < 6) {
                $errors[] = ['field' => 'password', 'message' => 'Password must be at least 6 characters'];
            } else {
                $data['password'] = $password;
            }
        }
        if ($creating || array_key_exists('role', $body)) {
            $role = $body['role'] ?? null;
            if (!is_string($role) || !in_array($role, UserRepository::ROLES, true)) {
                $errors[] = ['field' => 'role', 'message' => 'Role must be one of: ' . implode(', ', UserRepository::ROLES)];
            } else {
                $data['role'] = $role;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return $data;
    }
}
