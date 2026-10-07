<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ConnexionLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemandeController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\ProjetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

// --- Invités : connexion / inscription ---
Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/inscription', [RegisterController::class, 'create'])->name('register');
    Route::post('/inscription', [RegisterController::class, 'store']);
});

// --- Utilisateurs connectés ---
Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/tableau-de-bord', DashboardController::class)->name('dashboard');

    Route::get('/demandes', [DemandeController::class, 'index'])->name('demandes.index');
    Route::get('/demandes/nouvelle', [DemandeController::class, 'create'])->name('demandes.create');
    Route::post('/demandes', [DemandeController::class, 'store'])->name('demandes.store');
    Route::get('/demandes/{demande}', [DemandeController::class, 'show'])->name('demandes.show');
    Route::patch('/demandes/{demande}/statut', [DemandeController::class, 'updateStatut'])->name('demandes.statut');
    Route::get('/pieces-jointes/{pieceJointe}', [DemandeController::class, 'telechargerPieceJointe'])->name('pieces-jointes.download');

    Route::get('/employes', [EmployeController::class, 'index'])->name('employes.index');
    Route::get('/projets', [ProjetController::class, 'index'])->name('projets.index');

    Route::get('/logs', [ConnexionLogController::class, 'index'])
        ->middleware('role:admin,rh')->name('logs.index');
});
