<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'second_last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['masculino', 'femenino']),
            'rfc' => strtoupper(fake()->unique()->bothify('????######???')),
            'curp' => strtoupper(fake()->unique()->bothify('????######?????##')),
            'nss' => fake()->unique()->numerify('###########'),
            'birth_country' => 'México',
            'marital_status' => 'Soltero(a)',
            'birthdate' => fake()->date('Y-m-d', '-20 years'),
            'work_phone' => fake()->numerify('33########'),
            'personal_phone' => fake()->numerify('33########'),
            'personal_email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
            'municipality' => 'Guadalajara',
            'postal_code' => '44100',
            'hire_date' => '2024-01-15',
        ];
    }
}
