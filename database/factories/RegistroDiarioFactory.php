<?php

namespace Database\Factories;

use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistroDiario>
 */
class RegistroDiarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'fecha' => fake()->unique()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'calorias_objetivo_dia' => fake()->randomFloat(2, 1200, 3000),
            'calorias_consumidas' => fake()->randomFloat(2, 1000, 3200),
            'calorias_actividad_ajustada' => fake()->randomFloat(2, 0, 600),
            'deficit_diario' => fake()->randomFloat(2, -300, 800),
            'cerrado' => fake()->boolean(70),
        ];
    }
}
