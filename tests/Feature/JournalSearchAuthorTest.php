<?php

use App\Models\Issue;
use App\Models\Journal;
use App\Models\SettingWebsite;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super-admin']);
    SettingWebsite::create([
        'name' => 'Rumah Jurnal Test',
        'about' => 'Pusat Publikasi',
    ]);
});

function createSearchAuthorTestEnvironment(): array
{
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $journal = Journal::create([
        'name' => 'Jurnal Sains dan Teknologi',
        'title' => 'Jurnal Sains dan Teknologi Title',
        'context_id' => 1234,
        'url' => 'https://journal.test/jst',
        'url_path' => 'jst',
        'type' => 'journal',
        'author_fee' => 500000,
        'api_key' => 'secret_api_key',
        'ojs_version' => '3.3',
        'last_sync' => now(),
    ]);

    $issue1 = Issue::create([
        'journal_id' => $journal->id,
        'volume' => '1',
        'number' => '1',
        'year' => '2025',
        'title' => 'Edisi Pertama 2025',
        'author_fee' => 500000,
    ]);

    $issue2 = Issue::create([
        'journal_id' => $journal->id,
        'volume' => '1',
        'number' => '2',
        'year' => '2025',
        'title' => 'Edisi Kedua 2025',
        'author_fee' => 500000,
    ]);

    $submission1 = Submission::create([
        'issue_id' => $issue1->id,
        'submission_id' => 'SUB-101',
        'fullTitle' => ['en' => 'Implementasi Machine Learning pada Sistem Cerdas'],
        'authorsString' => 'Dr. Muhammad Fajri, M.Kom.',
        'author_nik' => '1234567890123456',
        'status' => '3',
        'status_label' => 'Published',
        'payment_status' => 'paid',
        'lastModified' => now()->toDateTimeString(),
    ]);

    $submission2 = Submission::create([
        'issue_id' => $issue2->id,
        'submission_id' => 'SUB-202',
        'fullTitle' => ['en' => 'Analisis Kriptografi Modern pada Jaringan Nirkabel'],
        'authorsString' => 'Prof. Dr. Siti Rahmah, M.Eng.',
        'author_nik' => '9876543210987654',
        'status' => '1',
        'status_label' => 'Queued',
        'payment_status' => 'pending',
        'lastModified' => now()->toDateTimeString(),
    ]);

    return compact('user', 'journal', 'issue1', 'issue2', 'submission1', 'submission2');
}

it('renders cari penulis button and search modal on journal index page', function () {
    $env = createSearchAuthorTestEnvironment();

    $response = $this->actingAs($env['user'])
        ->get(route('back.journal.index', $env['journal']->url_path));

    $response->assertStatus(200);
    $response->assertSee('Cari Penulis');
    $response->assertSee('id="btn_search_author"', false);
    $response->assertSee('id="modal_search_author"', false);
    $response->assertSee('Cari Penulis & Submission', false);
    $response->assertSee('id="input_search_author_keyword"', false);
    $response->assertSee('id="select_search_by"', false);
    $response->assertSee('id="select_search_issue"', false);
    $response->assertSee('id="btn_reset_search_author"', false);
});

it('searches across any issue in the journal by submission_id', function () {
    $env = createSearchAuthorTestEnvironment();

    $response = $this->actingAs($env['user'])
        ->getJson(route('back.journal.search-author', [
            'journal_path' => $env['journal']->url_path,
            'q' => 'SUB-202',
            'search_by' => 'submission_id',
        ]));

    $response->assertStatus(200);
    $response->assertJson(['success' => true, 'count' => 1]);
    expect($response->json('data.0.submission_id'))->toBe('SUB-202');
    expect($response->json('data.0.authors_string'))->toContain('Siti Rahmah');
});

it('searches across any issue in the journal by author name strictly using authorsString', function () {
    $env = createSearchAuthorTestEnvironment();

    // 1. Pencarian nama cocok
    $response = $this->actingAs($env['user'])
        ->getJson(route('back.journal.search-author', [
            'journal_path' => $env['journal']->url_path,
            'q' => 'Fajri',
            'search_by' => 'author',
        ]));
    $response->assertStatus(200);
    expect($response->json('data.0.submission_id'))->toBe('SUB-101');

    // 2. Pencarian NIK pada author tidak cocok karena strict ke authorsString
    $nikResponse = $this->actingAs($env['user'])
        ->getJson(route('back.journal.search-author', [
            'journal_path' => $env['journal']->url_path,
            'q' => '1234567890123456',
            'search_by' => 'author',
        ]));
    expect($nikResponse->json('data'))->toHaveCount(0);
});

it('forbids unauthenticated or unauthorized users from searching authors', function () {
    $env = createSearchAuthorTestEnvironment();

    // Tamu tanpa login -> 401
    $this->getJson(route('back.journal.search-author', $env['journal']->url_path))
        ->assertStatus(401);

    // Pengguna tanpa hak akses jurnal -> 403
    $otherUser = User::factory()->create();
    $this->actingAs($otherUser)
        ->getJson(route('back.journal.search-author', $env['journal']->url_path))
        ->assertStatus(403);
});
