<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ConnexionLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\DelaiController;
use App\Http\Controllers\DemandeController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\ProjetController;
use App\Http\Controllers\TacheController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

// --- Liens Valider / Refuser des mails (sans connexion, jeton à usage unique) ---
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/decision/{demande}/{jeton}', [DecisionController::class, 'show'])->name('decision.show');
    Route::get('/taches/{tache}/fixer-deadline/{jeton}', [TacheController::class, 'formulaireJeton'])->name('taches.jeton');
    Route::post('/taches/{tache}/fixer-deadline/{jeton}', [TacheController::class, 'fixerParJeton'])->name('taches.jeton.store');
    Route::post('/decision/{demande}/{jeton}', [DecisionController::class, 'store'])->name('decision.store');
});

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
    Route::post('/demandes/{demande}/action', [DemandeController::class, 'action'])->name('demandes.action');
    Route::get('/pieces-jointes/{pieceJointe}', [DemandeController::class, 'telechargerPieceJointe'])->name('pieces-jointes.download');

    Route::get('/employes', [EmployeController::class, 'index'])->name('employes.index');
    Route::get('/projets', [ProjetController::class, 'index'])->name('projets.index');
    Route::get('/projets/nouveau', [ProjetController::class, 'create'])->name('projets.create');
    Route::post('/projets', [ProjetController::class, 'store'])->name('projets.store');
    Route::get('/projets/{projet}', [ProjetController::class, 'show'])->name('projets.show');
    Route::get('/projets/{projet}/modifier', [ProjetController::class, 'edit'])->name('projets.edit');
    Route::put('/projets/{projet}', [ProjetController::class, 'update'])->name('projets.update');
    Route::post('/projets/{projet}/membres', [ProjetController::class, 'ajouterMembre'])->name('projets.membres.store');
    Route::delete('/projets/{projet}/membres/{user}', [ProjetController::class, 'retirerMembre'])->name('projets.membres.destroy');

    Route::get('/taches', [TacheController::class, 'index'])->name('taches.index');
    Route::get('/taches/nouvelle', [TacheController::class, 'create'])->name('taches.create');
    Route::post('/taches', [TacheController::class, 'store'])->name('taches.store');
    Route::get('/taches/{tache}', [TacheController::class, 'show'])->name('taches.show');
    Route::patch('/taches/{tache}/statut', [TacheController::class, 'updateStatut'])->name('taches.statut');
    Route::post('/taches/{tache}/validation', [TacheController::class, 'valider'])->name('taches.validation');
    Route::patch('/taches/{tache}/deadline', [TacheController::class, 'updateDeadline'])->name('taches.deadline');

    Route::get('/delais', [DelaiController::class, 'index'])
        ->middleware('role:rh,direction,admin,manager')->name('delais.index');
    Route::put('/delais/types', [DelaiController::class, 'updateTypes'])
        ->middleware('role:rh,admin')->name('delais.types');

    Route::get('/logs', [ConnexionLogController::class, 'index'])
        ->middleware('role:admin,rh')->name('logs.index');
});
