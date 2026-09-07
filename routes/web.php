<?php

use App\Http\Controllers\ActividadFisicaController;
use App\Http\Controllers\CierreDiarioController;
use App\Http\Controllers\ComidaRealController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IngredienteDisponibleController;
use App\Http\Controllers\PlanComidaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileParametersController;
use App\Http\Controllers\RecomendacionSistemaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Calculadora Déficit: los parámetros nutricionales del usuario y el
    // objetivo calórico diario que se deriva de ellos (CLAUDE.md sección 4.14).
    Route::get('/calculadora', [ProfileParametersController::class, 'edit'])->name('calculadora.edit');
    Route::put('/calculadora', [ProfileParametersController::class, 'update'])->name('calculadora.update');

    // Planes diarios (CLAUDE.md sección 4.12): el histórico de días y el
    // detalle de cada uno, donde transcurre el día completo — cálculo
    // alimenticio, actividad física y cierre.
    Route::get('/planes', [PlanComidaController::class, 'index'])->name('planes.index');
    Route::post('/planes', [PlanComidaController::class, 'crear'])->name('planes.crear');
    Route::get('/planes/{registroDiario}', [PlanComidaController::class, 'show'])->name('planes.show');
    Route::post('/planes/{registroDiario}/distribucion', [PlanComidaController::class, 'distribuir'])->name('planes.distribucion');
    Route::post('/planes/{registroDiario}/peso', [PlanComidaController::class, 'peso'])->name('planes.peso');
    Route::post('/planes/{registroDiario}/generar', [PlanComidaController::class, 'generar'])->name('planes.generar');
    Route::post('/planes/{registroDiario}/actividades', [ActividadFisicaController::class, 'store'])->name('actividades.store');
    Route::post('/planes/{registroDiario}/cierre', [CierreDiarioController::class, 'cerrar'])->name('cierre.cerrar');
    Route::post('/planes/{registroDiario}/reabrir', [CierreDiarioController::class, 'reabrir'])->name('cierre.reabrir');

    Route::get('/plan/{planComida}/comida-real', [ComidaRealController::class, 'create'])->name('comida-real.create');
    Route::post('/plan/{planComida}/comida-real', [ComidaRealController::class, 'store'])->name('comida-real.store');

    // Reporte estructurado de ingredientes (sección 4.1). Ya no es un menú:
    // el camino normal es el texto libre del plan diario, pero las rutas se
    // conservan porque MealPlanGeneratorService sigue funcionando sobre ellas.
    Route::get('/ingredientes', [IngredienteDisponibleController::class, 'create'])->name('ingredientes.create');
    Route::post('/ingredientes', [IngredienteDisponibleController::class, 'store'])->name('ingredientes.store');
    Route::get('/registros-diarios/{registroDiario}/ingredientes', [IngredienteDisponibleController::class, 'index'])->name('ingredientes.index');
    Route::put('/ingredientes/{ingrediente}', [IngredienteDisponibleController::class, 'update'])->name('ingredientes.update');
    Route::delete('/ingredientes/{ingrediente}', [IngredienteDisponibleController::class, 'destroy'])->name('ingredientes.destroy');

    // "Mi progreso" se fusionó con el dashboard (sección 4.17). La ruta se
    // conserva como redirect para no romper enlaces guardados.
    Route::redirect('/progreso', '/dashboard')->name('progreso.index');

    Route::post('/recomendaciones/{recomendacion}/confirmar', [RecomendacionSistemaController::class, 'confirmar'])->name('recomendaciones.confirmar');
    Route::post('/recomendaciones/{recomendacion}/rechazar', [RecomendacionSistemaController::class, 'rechazar'])->name('recomendaciones.rechazar');
});

require __DIR__.'/auth.php';
