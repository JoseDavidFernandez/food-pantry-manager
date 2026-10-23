<?php

use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// A real 1×1 PNG: the image is checked by its bytes, not by its headers.
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');

    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
    $this->tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();
});

function commonsPage(string $title = 'File:Tomato je.jpg', string $thumb = 'https://upload.wikimedia.org/thumb/tomato-320px.jpg'): array
{
    return ['query' => ['pages' => [[
        'title' => $title,
        'imageinfo' => [[
            'thumburl' => $thumb,
            'mime' => 'image/jpeg',
            'descriptionurl' => 'https://commons.wikimedia.org/wiki/'.$title,
            'extmetadata' => [
                'ObjectName' => ['value' => 'Tomate'],
                'Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Ana">Ana</a>'],
                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0'],
            ],
        ]],
    ]]]];
}

function offProduct(string $code = '8410000000001'): array
{
    return [
        'code' => $code,
        'product_name_es' => 'Tomate frito',
        'brands' => 'Marca',
        'image_front_url' => "https://images.openfoodfacts.org/images/products/{$code}/front_es.400.jpg",
        'image_front_small_url' => "https://images.openfoodfacts.org/images/products/{$code}/front_es.200.jpg",
    ];
}

test('CA-20: searching generic photos returns candidates with their attribution, and assigns nothing', function () {
    Http::fake(['commons.wikimedia.org/*' => Http::response(commonsPage())]);

    $this->getJson(route('ingredients.images.search', ['source' => 'photos', 'q' => 'tomate']))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('results.0.provider', 'commons')
        ->assertJsonPath('results.0.reference', 'File:Tomato je.jpg')
        ->assertJsonPath('results.0.author', 'Ana')
        ->assertJsonPath('results.0.license', 'CC BY-SA 4.0');

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->header('User-Agent')[0] ?? '', 'FoodPantryManager'));
    expect($this->tomate->fresh()->image_path)->toBeNull();
});

test('packaged products can be searched by name or by barcode in Open Food Facts', function () {
    Http::fake([
        'es.openfoodfacts.org/cgi/search.pl*' => Http::response(['products' => [offProduct(), ['code' => 'x']]]),
        'world.openfoodfacts.org/api/v2/product/8410000000001*' => Http::response(['status' => 1, 'product' => offProduct()]),
    ]);

    $this->getJson(route('ingredients.images.search', ['source' => 'products', 'q' => 'tomate frito']))
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.title', 'Tomate frito · Marca')
        ->assertJsonPath('results.0.license', 'CC BY-SA 3.0');

    $this->getJson(route('ingredients.images.search', ['source' => 'barcode', 'q' => '8410000000001']))
        ->assertJsonPath('results.0.reference', '8410000000001');

    $this->getJson(route('ingredients.images.search', ['source' => 'barcode', 'q' => '84A10']))
        ->assertUnprocessable();
});

test('CA-20: no results and provider failures are normal answers: the user can continue without an image', function () {
    Http::fake([
        'commons.wikimedia.org/*' => Http::response(['query' => []]),
        'es.openfoodfacts.org/*' => Http::response('Service Unavailable', 503),
        'world.openfoodfacts.org/*' => Http::response(['status' => 0]),
    ]);

    $this->getJson(route('ingredients.images.search', ['source' => 'photos', 'q' => 'xyzzy']))
        ->assertOk()->assertJsonPath('results', [])->assertJsonPath('error', null);
    $this->getJson(route('ingredients.images.search', ['source' => 'products', 'q' => 'tomate']))
        ->assertOk()->assertJsonPath('results', [])
        ->assertJsonPath('error', fn (string $error) => str_contains($error, 'continúa sin imagen'));
    $this->getJson(route('ingredients.images.search', ['source' => 'barcode', 'q' => '00000000']))
        ->assertOk()->assertJsonPath('results', []);
});

test('a timeout of the provider is reported, not thrown', function () {
    Http::fake(['commons.wikimedia.org/*' => fn () => throw new ConnectionException('timeout')]);

    $this->getJson(route('ingredients.images.search', ['source' => 'photos', 'q' => 'tomate']))
        ->assertOk()->assertJsonPath('error', fn (?string $error) => $error !== null);
});

test('CA-20: choosing a result keeps a local copy with its attribution, resolved again on the server', function () {
    Http::fake([
        'commons.wikimedia.org/*' => Http::response(commonsPage()),
        'upload.wikimedia.org/*' => Http::response(base64_decode(PNG_1X1), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'commons', 'reference' => 'File:Tomato je.jpg'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $tomate = $this->tomate->fresh();
    expect($tomate->image_provider)->toBe('commons')
        ->and($tomate->image_author)->toBe('Ana')
        ->and($tomate->image_license)->toBe('CC BY-SA 4.0')
        ->and($tomate->image_source_url)->toBe('https://commons.wikimedia.org/wiki/File:Tomato je.jpg')
        ->and($tomate->image_path)->toEndWith('.png');
    Storage::disk('local')->assertExists($tomate->image_path);

    // Served from the local copy: no external call when the image is shown.
    Http::fake(fn () => throw new RuntimeException('no external call expected'));
    $this->get($tomate->visualPayload()['image_url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

test('a packaged product image is kept from its barcode', function () {
    Http::fake([
        'world.openfoodfacts.org/api/v2/product/*' => Http::response(['status' => 1, 'product' => offProduct()]),
        'images.openfoodfacts.org/*' => Http::response(base64_decode(PNG_1X1)),
    ]);

    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'off', 'reference' => '8410000000001'])
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->tomate->fresh())
        ->image_provider->toBe('off')
        ->image_license->toBe('CC BY-SA 3.0')
        ->image_source_url->toBe('https://world.openfoodfacts.org/product/8410000000001');
});

test('the server never downloads from a host outside the providers', function () {
    Http::fake([
        'commons.wikimedia.org/*' => Http::response(commonsPage(thumb: 'https://evil.example/steal.png')),
    ]);

    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'commons', 'reference' => 'File:Tomato je.jpg'])
        ->assertInertiaFlash('toast.type', 'error');

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'evil.example'));
    expect($this->tomate->fresh()->image_path)->toBeNull();
});

test('a download that is not an image is rejected and nothing changes', function () {
    Http::fake([
        'commons.wikimedia.org/*' => Http::response(commonsPage()),
        'upload.wikimedia.org/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/png']),
    ]);

    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'commons', 'reference' => 'File:Tomato je.jpg'])
        ->assertInertiaFlash('toast.type', 'error');

    expect($this->tomate->fresh()->image_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('references that do not identify an image are rejected', function () {
    Http::fake();

    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'commons', 'reference' => 'https://evil.example/a.png'])
        ->assertInertiaFlash('toast.type', 'error');
    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'unsplash', 'reference' => 'x'])
        ->assertSessionHasErrors('provider');

    Http::assertNothingSent();
});

test('removing the image deletes the copy and falls back to the category icon', function () {
    Storage::disk('local')->put('ingredient-images/x.png', base64_decode(PNG_1X1));
    $this->tomate->update(['image_path' => 'ingredient-images/x.png', 'image_provider' => 'commons']);

    $this->delete(route('ingredients.image.destroy', $this->tomate))->assertRedirect();

    expect($this->tomate->fresh()->image_path)->toBeNull();
    Storage::disk('local')->assertMissing('ingredient-images/x.png');

    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('foods.0.image_url', null)
        ->where('foods.0.image_credit', null));
});

test('deleting an ingredient also deletes its image file', function () {
    Storage::disk('local')->put('ingredient-images/x.png', base64_decode(PNG_1X1));
    $this->tomate->update(['image_path' => 'ingredient-images/x.png']);

    $this->delete(route('ingredients.destroy', $this->tomate))->assertRedirect();

    Storage::disk('local')->assertMissing('ingredient-images/x.png');
});

test('CA-20: another household cannot see, set or remove the image of an ingredient', function () {
    Storage::disk('local')->put('ingredient-images/x.png', base64_decode(PNG_1X1));
    $this->tomate->update(['image_path' => 'ingredient-images/x.png']);
    Http::fake();

    $this->actingAs(User::factory()->withHousehold()->create());

    $this->get(route('ingredients.image.show', $this->tomate))->assertForbidden();
    $this->post(route('ingredients.image.store', $this->tomate), ['provider' => 'commons', 'reference' => 'File:A.jpg'])->assertForbidden();
    $this->delete(route('ingredients.image.destroy', $this->tomate))->assertForbidden();

    Http::assertNothingSent();
    expect($this->tomate->fresh()->image_path)->toBe('ingredient-images/x.png');
});

test('guests cannot search images', function () {
    auth()->logout();

    $this->get(route('ingredients.images.search', ['source' => 'photos', 'q' => 'tomate']))->assertRedirect(route('login'));
});

test('the image search can be disabled by configuration', function () {
    config(['services.ingredient_images.enabled' => false]);

    $this->getJson(route('ingredients.images.search', ['source' => 'photos', 'q' => 'tomate']))->assertNotFound();
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page->where('imageSearch', false));
});
