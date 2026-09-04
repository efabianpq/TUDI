<?php

namespace Database\Factories;

use App\Models\IngredienteDisponible;
use App\Models\RegistroDiario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredienteDisponible>
 */
class IngredienteDisponibleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registro_diario_id' => RegistroDiario::factory(),
            'nombre' => fake()->randomElement(['Pechuga de pollo', 'Arroz integral', 'Huevo', 'Avena', 'Brócoli', 'Aceite de oliva', 'Atún']),
            'cantidad_g' => fake()->randomFloat(2, 20, 500),
            'calorias_por_100g' => fake()->randomFloat(2, 20, 900),
            'proteina_por_100g' => fake()->randomFloat(2, 0, 35),
            'grasa_por_100g' => fake()->randomFloat(2, 0, 100),
            'carbohidratos_por_100g' => fake()->randomFloat(2, 0, 90),
        ];
    }
}
