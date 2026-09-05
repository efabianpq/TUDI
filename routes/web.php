<?php

use App\Http\Controllers\ActividadFisicaController;
use App\Http\Controllers\CierreDiarioController;
use App\Http\Controllers\ComidaRealController;
use App\Http\Controllers\IngredienteDisponibleController;
use App\Http\Controllers\PlanComidaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileParametersController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/profile/parametros', [ProfileParametersController::class, 'edit'])->name('profile.parametros.edit');
    Route::put('/profile/parametros', [ProfileParametersController::class, 'update'])->name('profile.parametros.update');

    Route::get('/ingredientes', [IngredienteDisponibleController::class, 'create'])->name('ingredientes.create');
    Route::post('/ingredientes', [IngredienteDisponibleController::class, 'store'])->name('ingredientes.store');
    Route::get('/registros-diarios/{registroDiario}/ingredientes', [IngredienteDisponibleController::class, 'index'])->name('ingredientes.index');
    Route::put('/ingredientes/{ingrediente}', [IngredienteDisponibleController::class, 'update'])->name('ingredientes.update');
    Route::delete('/ingredientes/{ingrediente}', [IngredienteDisponibleController::class, 'destroy'])->name('ingredientes.destroy');

    Route::get('/plan', [PlanComidaController::class, 'index'])->name('planes.index');
    Route::post('/plan/generar', [PlanComidaController::class, 'generar'])->name('planes.generar');

    Route::get('/plan/{planComida}/comida-real', [ComidaRealController::class, 'create'])->name('comida-real.create');
    Route::post('/plan/{planComida}/comida-real', [ComidaRealController::class, 'store'])->name('comida-real.store');

    Route::get('/actividades', [ActividadFisicaController::class, 'create'])->name('actividades.create');
    Route::post('/actividades', [ActividadFisicaController::class, 'store'])->name('actividades.store');

    Route::get('/cierre', [CierreDiarioController::class, 'index'])->name('cierre.index');
    Route::post('/cierre', [CierreDiarioController::class, 'cerrar'])->name('cierre.cerrar');
    Route::post('/cierre/{registroDiario}/reabrir', [CierreDiarioController::class, 'reabrir'])->name('cierre.reabrir');
});

require __DIR__.'/auth.php';
