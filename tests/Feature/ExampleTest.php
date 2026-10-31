<?php

use App\Models\SettingWebsite;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a successful response', function () {
    SettingWebsite::create([
        'name' => 'Rumah Jurnal',
        'about' => 'Pusat Riset',
    ]);

    $response = $this->get('/');

    $response->assertStatus(200);
});

