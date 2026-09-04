<?php

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
});

require __DIR__.'/auth.php';
