<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'NovaCorp') · NovaCorp</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    <nav class="nav">
        <a class="brand" href="{{ route('dashboard') }}">NovaCorp</a>
        <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Tableau de bord</a>
        <a href="{{ route('demandes.index') }}" @class(['active' => request()->routeIs('demandes.*')])>Demandes</a>
        <a href="{{ route('employes.index') }}" @class(['active' => request()->routeIs('employes.*')])>Employés</a>
        <a href="{{ route('projets.index') }}" @class(['active' => request()->routeIs('projets.*')])>Projets & CA</a>
        @if (auth()->user()->hasRole('admin', 'rh'))
            <a href="{{ route('logs.index') }}" @class(['active' => request()->routeIs('logs.*')])>Logs</a>
        @endif
        <span class="spacer"></span>
        <span>{{ auth()->user()->nom_complet }} <small class="muted">({{ auth()->user()->role?->libelle }})</small></span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button>Déconnexion</button></form>
    </nav>
@endauth

<main>
    @if (session('success'))<div class="alert ok">{{ session('success') }}</div>@endif
    @yield('content')
</main>
</body>
</html>
