<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function create()
    {
        return view('auth.register', [
            // L'auto-inscription ne permet pas de choisir "admin" ni "direction"
            'roles' => Role::whereNotIn('slug', ['admin', 'direction'])->orderBy('libelle')->get(),
            'managers' => User::whereHas('role', fn ($q) => $q->where('slug', 'manager'))->orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'prenom' => ['required', 'string', 'max:100'],
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', Rule::exists('roles', 'id')->whereNotIn('slug', ['admin', 'direction'])],
            'manager_id' => ['nullable', 'exists:users,id'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($data);

        event(new Registered($user));   // -> journalisé par AuthEventSubscriber
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Compte créé, bienvenue !');
    }
}
