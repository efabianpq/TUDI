<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pesoKg = fake()->randomFloat(2, 50, 120);
        $nivelActividad = fake()->randomFloat(3, 1.2, 1.725);
        $tipoDeficit = fake()->randomElement(['porcentaje', 'fijo']);
        $proteinaFactor = fake()->randomFloat(2, 1.6, 2.2);
        $grasaFactor = fake()->randomFloat(2, 0.6, 1.0);

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'peso_kg' => $pesoKg,
            'estatura_m' => fake()->randomFloat(2, 1.50, 2.00),
            'edad' => fake()->numberBetween(18, 65),
            'sexo' => fake()->randomElement(['masculino', 'femenino']),
            'nivel_actividad' => $nivelActividad,
            'tipo_deficit' => $tipoDeficit,
            'valor_deficit' => $tipoDeficit === 'porcentaje'
                ? fake()->randomFloat(2, 0.1, 0.25)
                : fake()->randomFloat(2, 200, 500),
            'proteina_factor' => $proteinaFactor,
            'grasa_factor' => $grasaFactor,
            'calorias_objetivo' => fake()->randomFloat(2, 1200, 3000),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
