<?php

use App\Models\IngredienteDisponible;
use App\Models\RegistroDiario;
use App\Services\MealPlanGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('inspect', function () {
    $r = RegistroDiario::factory()->create(['fecha' => now()->toDateString()]);
    foreach ([
        ['Pechuga de pollo', 1000, 165, 31.0, 3.6, 0.0],
        ['Arroz integral', 1000, 350, 7.5, 2.7, 72.0],
        ['Avena', 500, 389, 16.9, 6.9, 66.0],
        ['Huevo', 500, 155, 13.0, 11.0, 1.1],
        ['Aceite de oliva', 200, 884, 0.0, 100.0, 0.0],
    ] as [$n, $c, $k, $p, $g, $ch]) {
        IngredienteDisponible::factory()->for($r, 'registroDiario')->create([
            'nombre' => $n, 'cantidad_g' => $c, 'calorias_por_100g' => $k,
            'proteina_por_100g' => $p, 'grasa_por_100g' => $g, 'carbohidratos_por_100g' => $ch,
        ]);
    }

    $planes = app(MealPlanGeneratorService::class)->generarPlan($r, [
        'calorias_objetivo' => 2112.0, 'proteina_g' => 144.0, 'grasa_g' => 64.0, 'carbohidratos_g' => 240.0,
    ]);

    foreach ($planes as $plan) {
        fwrite(STDERR, sprintf(
            "%-9s %8s kcal  P %6s  G %6s  C %6s  | %s\n",
            $plan->tipo_comida, $plan->calorias_estimadas, $plan->proteina_g,
            $plan->grasa_g, $plan->carbohidratos_g, $plan->descripcion,
        ));
    }
    fwrite(STDERR, sprintf(
        "TOTAL     %8.2f kcal  P %6.2f  G %6.2f  C %6.2f  (objetivo 2112 / 144 / 64 / 240)\n",
        $planes->sum(fn ($p) => (float) $p->calorias_estimadas),
        $planes->sum(fn ($p) => (float) $p->proteina_g),
        $planes->sum(fn ($p) => (float) $p->grasa_g),
        $planes->sum(fn ($p) => (float) $p->carbohidratos_g),
    ));

    expect(true)->toBeTrue();
});
