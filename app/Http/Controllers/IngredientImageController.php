<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\Images\CommonsImages;
use App\Actions\Ingredients\Images\ImageCandidate;
use App\Actions\Ingredients\Images\ImageProviderUnavailable;
use App\Actions\Ingredients\Images\IngredientImages;
use App\Actions\Ingredients\Images\OpenFoodFactsImages;
use App\Models\Ingredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ingredient images (TECHNICAL-DESIGN §10): search on demand, choose explicitly, serve the local copy.
 */
class IngredientImageController extends Controller
{
    public function __construct(private readonly IngredientImages $images) {}

    /**
     * Results to choose from; never assigns anything. A provider failure is a normal answer
     * with an error message, so the user can retry or continue without an image.
     */
    public function search(Request $request): JsonResponse
    {
        abort_unless((bool) config('services.ingredient_images.enabled'), 404);

        $data = $request->validate([
            'source' => ['required', Rule::in(['photos', 'products', 'barcode'])],
            'q' => ['required', 'string', 'max:100', Rule::when($request->input('source') === 'barcode', ['regex:/^\d{8,14}$/'])],
        ], [
            'q.regex' => 'Un código de barras tiene entre 8 y 14 cifras.',
        ]);

        try {
            $results = $this->images->search($data['source'], trim($data['q']));
        } catch (ImageProviderUnavailable $exception) {
            return response()->json(['results' => [], 'error' => $exception->getMessage()]);
        }

        return response()->json([
            'results' => array_map(fn (ImageCandidate $candidate) => $candidate->toArray(), $results),
            'error' => null,
        ]);
    }

    public function store(Request $request, Ingredient $ingredient): RedirectResponse
    {
        Gate::authorize('update', $ingredient);
        abort_unless((bool) config('services.ingredient_images.enabled'), 404);

        $data = $request->validate([
            'provider' => ['required', Rule::in([CommonsImages::NAME, OpenFoodFactsImages::NAME])],
            'reference' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->images->attach($ingredient, $data['provider'], $data['reference']);
        } catch (ImageProviderUnavailable|InvalidArgumentException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Imagen guardada para «{$ingredient->name}»."]);

        return back();
    }

    public function destroy(Ingredient $ingredient): RedirectResponse
    {
        Gate::authorize('update', $ingredient);

        $this->images->remove($ingredient);
        Inertia::flash('toast', ['type' => 'success', 'message' => "«{$ingredient->name}» vuelve a mostrar el icono de su categoría."]);

        return back();
    }

    /**
     * The local copy, only for members of the household. The URL carries a version, so the
     * browser may cache it for a long time.
     */
    public function show(Ingredient $ingredient): StreamedResponse
    {
        Gate::authorize('view', $ingredient);

        $disk = Storage::disk(IngredientImages::DISK);
        abort_if($ingredient->image_path === null || ! $disk->exists($ingredient->image_path), 404);

        return $disk->response($ingredient->image_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
