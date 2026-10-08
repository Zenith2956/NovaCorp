<?php

namespace App\Mail;

use App\Models\Demande;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mail envoyé au manager lorsqu'un employé crée une demande.
 * Le "Répondre à" pointe vers l'employé : le manager valide en répondant au mail.
 */
class DemandeEnvoyee extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Demande $demande)
    {
    }

    public function envelope(): Envelope
    {
        $demandeur = $this->demande->demandeur;

        return new Envelope(
            replyTo: [new Address($demandeur->email, $demandeur->nom_complet)],
            subject: "[NovaCorp] Demande #{$this->demande->id} – {$this->demande->type_libelle} : {$this->demande->objet}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.demande');
    }

    /** Joint les fichiers déposés (document, vocal, photo, vidéo...). */
    public function attachments(): array
    {
        return $this->demande->piecesJointes
            ->map(fn ($pj) => Attachment::fromStorageDisk(config('novacorp.disque_pieces_jointes'), $pj->chemin)
                ->as($pj->nom_original)
                ->withMime($pj->mime_type ?? 'application/octet-stream'))
            ->all();
    }
}
