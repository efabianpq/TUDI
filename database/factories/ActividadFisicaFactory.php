<?php

namespace Database\Factories;

use App\Models\ActividadFisica;
use App\Models\RegistroDiario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActividadFisica>
 */
class ActividadFisicaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $caloriasDispositivo = fake()->randomFloat(2, 100, 800);
        $factorCorreccion = fake()->randomFloat(2, 0.8, 0.9);

        return [
            'registro_diario_id' => RegistroDiario::factory(),
            'tipo' => fake()->randomElement(['caminata', 'trote', 'ciclismo', 'pesas', 'natación']),
            'duracion_min' => fake()->numberBetween(10, 120),
            'calorias_dispositivo' => $caloriasDispositivo,
            'factor_correccion' => $factorCorreccion,
            'calorias_ajustadas' => round($caloriasDispositivo * $factorCorreccion, 2),
        ];
    }
}
