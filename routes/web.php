<?php

use App\Http\Controllers\ActivacionController;
use App\Http\Controllers\ActividadFisicaController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\CierreDiarioController;
use App\Http\Controllers\ComidaRealController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IngredienteDisponibleController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PlanComidaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileParametersController;
use App\Http\Controllers\RecomendacionSistemaController;
use App\Http\Controllers\ReporteComidaController;
use App\Http\Controllers\TranscripcionController;
use Illuminate\Support\Facades\Route;

/*
 * Landing pública (CLAUDE.md sección 5.19). Única ruta de la aplicación que se
 * sirve sin sesión; con sesión sigue redirigiendo al panel de inicio, como
 * hacía antes de que existiera la página.
 */
Route::get('/', LandingController::class)->name('landing');

/*
 * Activación de la cuenta (CLAUDE.md sección 4.26). Va fuera del grupo con
 * `cuenta.activa` a propósito: ese middleware redirige justo aquí, y meterla
 * dentro sería un bucle.
 */
Route::middleware('auth')->group(function () {
    Route::get('/activacion', [ActivacionController::class, 'create'])->name('activacion.create');
    Route::post('/activacion', [ActivacionController::class, 'store'])->name('activacion.store');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'cuenta.activa'])
    ->name('dashboard');

/*
 * Consola de administración (sección 4.26): gestión de usuarios y parámetros
 * maestros. Un administrador también tiene que tener su cuenta activa.
 */
Route::middleware(['auth', 'cuenta.activa', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\UsuarioController::class, 'inicio'])->name('inicio');

    Route::get('/usuarios', [Admin\UsuarioController::class, 'index'])->name('usuarios.index');
    Route::patch('/usuarios/{usuario}', [Admin\UsuarioController::class, 'update'])->name('usuarios.update');
    Route::post('/usuarios/{usuario}/plan', [Admin\UsuarioController::class, 'plan'])->name('usuarios.plan');
    Route::delete('/usuarios/{usuario}', [Admin\UsuarioController::class, 'destroy'])->name('usuarios.destroy');

    Route::get('/parametros', [Admin\ParametroMaestroController::class, 'edit'])->name('parametros.edit');
    Route::put('/parametros', [Admin\ParametroMaestroController::class, 'update'])->name('parametros.update');
    Route::post('/parametros/restablecer', [Admin\ParametroMaestroController::class, 'restablecer'])->name('parametros.restablecer');

    // Material de apoyo del usuario: video y guía en PDF (sección 5.15).
    Route::get('/recursos', [Admin\RecursoDidacticoController::class, 'edit'])->name('recursos.edit');
    Route::post('/recursos', [Admin\RecursoDidacticoController::class, 'update'])->name('recursos.update');
    Route::delete('/recursos/{clave}', [Admin\RecursoDidacticoController::class, 'destroy'])->name('recursos.destroy');
});

Route::middleware(['auth', 'cuenta.activa'])->group(function () {
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

    // Reporte de comidas (sección 5.5): cada comida se cierra y se reabre por
    // separado, y desde ese momento entra en el saldo del día. El tipo de
    // comida va en la URL y lo valida el controlador contra DISTRIBUCION_COMIDAS.
    Route::post('/planes/{registroDiario}/comidas/{tipoComida}/cerrar', [ReporteComidaController::class, 'cerrar'])->name('comidas.cerrar');
    Route::post('/planes/{registroDiario}/comidas/{tipoComida}/reabrir', [ReporteComidaController::class, 'reabrir'])->name('comidas.reabrir');

    // Las dos acciones destructivas del plan diario (sección 5.16).
    Route::post('/planes/{registroDiario}/resetear', [PlanComidaController::class, 'resetear'])->name('planes.resetear');
    Route::delete('/planes/{registroDiario}', [PlanComidaController::class, 'destroy'])->name('planes.destroy');
    Route::post('/planes/{registroDiario}/generar', [PlanComidaController::class, 'generar'])->name('planes.generar');
    Route::post('/planes/{registroDiario}/actividades', [ActividadFisicaController::class, 'store'])->name('actividades.store');
    Route::post('/planes/{registroDiario}/cierre', [CierreDiarioController::class, 'cerrar'])->name('cierre.cerrar');
    Route::post('/planes/{registroDiario}/reabrir', [CierreDiarioController::class, 'reabrir'])->name('cierre.reabrir');

    Route::get('/plan/{planComida}/comida-real', [ComidaRealController::class, 'create'])->name('comida-real.create');
    Route::post('/plan/{planComida}/comida-real', [ComidaRealController::class, 'store'])->name('comida-real.store');
    // "Cambiar mi respuesta" del cierre (sección 5.5): esta sí está enlazada.
    Route::delete('/plan/{planComida}/comida-real', [ComidaRealController::class, 'destroy'])->name('comida-real.destroy');

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

    // Dictado por voz para navegadores sin Web Speech API utilizable (Safari de
    // iOS — CLAUDE.md sección 4.21). Limitado por usuario: cada llamada gasta
    // cuota del proveedor y ocupa un worker mientras dura.
    Route::post('/transcribir', TranscripcionController::class)
        ->middleware('throttle:30,1')
        ->name('transcribir');
});

require __DIR__.'/auth.php';
