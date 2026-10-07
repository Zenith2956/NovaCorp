<!DOCTYPE html>
<html lang="fr">
<body style="font-family:Arial,sans-serif;color:#1d2433">
    <p>Bonjour {{ $demande->manager->prenom }},</p>

    <p><strong>{{ $demande->demandeur->nom_complet }}</strong> vous adresse une demande
       de type <strong>{{ $demande->type_libelle }}</strong>.</p>

    <p><strong>Objet :</strong> {{ $demande->objet }}</p>
    <div style="padding:12px;background:#f4f6fb;border-left:4px solid #3b5bdb;white-space:pre-line">{{ $demande->message }}</div>

    @if ($demande->piecesJointes->isNotEmpty())
        <p>{{ $demande->piecesJointes->count() }} pièce(s) jointe(s) à ce mail.</p>
    @endif

    <p><strong>Pour valider ou refuser, répondez simplement à ce mail.</strong></p>

    <p style="color:#667085;font-size:12px">
        Demandeur : {{ $demande->demandeur->email }} – {{ $demande->demandeur->telephone }}<br>
        Demande #{{ $demande->id }} – NovaCorp
    </p>
</body>
</html>
