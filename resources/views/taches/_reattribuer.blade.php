{{-- R2 – Réattribuer une tâche en pause (chef de projet / admin). Variables : $tache (projet.membres et projet.chefProjet chargés) --}}
@php
    $responsables = $tache->projet->membres->sortBy('nom')->push($tache->projet->chefProjet)->filter()->unique('id');
    $minimum = $tache->deadlineMinimum();
    $depassee = $tache->deadline && $tache->deadline->toDateString() < \App\Support\Calendrier::aujourdhui()->toDateString();
@endphp
<form class="inline" method="POST" action="{{ route('taches.reattribuer', $tache) }}" style="flex-wrap:wrap;gap:.5rem">
    @csrf
    <select name="responsable_id" required style="max-width:240px">
        <option value="">— Confier à —</option>
        @foreach ($responsables as $r)
            <option value="{{ $r->id }}" @selected(old('responsable_id') == $r->id)>{{ $r->nom_complet }}{{ $r->id === $tache->projet->chef_projet_id ? ' (chef de projet)' : '' }}</option>
        @endforeach
    </select>
    <label class="inline" style="margin:0;font-weight:normal">
        {{ $depassee ? 'Nouvelle deadline (obligatoire)' : 'Nouvelle deadline (facultatif)' }}
        <input type="date" name="deadline" min="{{ $minimum->toDateString() }}" value="{{ old('deadline') }}" @required($depassee) style="max-width:170px">
    </label>
    <button class="btn">Réattribuer</button>
</form>
@error('responsable_id')<div class="err">{{ $message }}</div>@enderror
@error('deadline')<div class="err">{{ $message }}</div>@enderror
