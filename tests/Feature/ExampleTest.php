<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_la_racine_redirige_vers_la_connexion(): void
    {
        $this->get('/')->assertRedirect('/tableau-de-bord');
        $this->get('/tableau-de-bord')->assertRedirect('/connexion');
    }
}
