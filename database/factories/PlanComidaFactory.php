<?php

namespace Database\Factories;

use App\Models\PlanComida;
use App\Models\RegistroDiario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanComida>
 */
class PlanComidaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registro_diario_id' => RegistroDiario::factory(),
            'tipo_comida' => fake()->randomElement(['desayuno', 'almuerzo', 'cena', 'snack']),
            'descripcion' => fake()->sentence(8),
            'calorias_estimadas' => fake()->randomFloat(2, 200, 900),
            'proteina_g' => fake()->randomFloat(2, 10, 60),
            'grasa_g' => fake()->randomFloat(2, 5, 40),
            'carbohidratos_g' => fake()->randomFloat(2, 10, 100),
        ];
    }
}
