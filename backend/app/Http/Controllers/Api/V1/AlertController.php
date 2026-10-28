<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\Alert;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Catalog\ProductVariant;
use App\Http\Controllers\Controller;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    private const array MODELS = ['product' => Product::class, 'variant' => ProductVariant::class, 'group' => ProductGroup::class];

    public function index(Request $request): JsonResponse
    {
        $alerts = Alert::where('user_id', $request->user()->id)->latest()->get();

        return response()->json($alerts->map(fn (Alert $a) => $this->present($a)));
    }

    /** Creates or updates the shopper's alert for one target (one alert per target). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(Alert::TARGETS)],
            'id' => ['required', 'integer', 'min:1'],
            'max_unit_price' => ['nullable', 'numeric', 'gt:0'],
            'min_discount' => ['nullable', 'integer', 'between:1,90'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'integer', Rule::in([1, 3, 5, 10, 20, 50])],
            'place' => ['nullable', 'string', 'max:120'],
        ]);
        (self::MODELS[$data['type']])::query()->findOrFail($data['id']);

        $alert = Alert::updateOrCreate(
            ['user_id' => $request->user()->id, 'target_type' => $data['type'], 'target_id' => $data['id']],
            [
                'max_unit_price' => $data['max_unit_price'] ?? null,
                'min_discount' => $data['min_discount'] ?? null,
                'location' => isset($data['lat']) ? Point::makeGeodetic((float) $data['lat'], (float) $data['lng']) : null,
                'radius_km' => isset($data['lat']) ? ($data['radius_km'] ?? 10) : null,
                'place' => $data['place'] ?? null,
                'is_active' => true,
            ],
        );

        return response()->json($this->present($alert), $alert->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Alert $alert): JsonResponse
    {
        abort_unless($alert->user_id === $request->user()->id, 404);
        $alert->delete();

        return response()->json(['deleted' => true]);
    }

    private function present(Alert $alert): array
    {
        $target = (self::MODELS[$alert->target_type])::query()->with($alert->target_type === 'variant' ? ['product', 'optionValues'] : [])->find($alert->target_id);

        return [
            'id' => $alert->id,
            'type' => $alert->target_type,
            'target_id' => $alert->target_id,
            'name' => match (true) {
                $target instanceof ProductVariant => $target->label(),
                $target instanceof ProductGroup => "Any {$target->name}",
                default => $target?->name,
            },
            'max_unit_price' => $alert->max_unit_price !== null ? (float) $alert->max_unit_price : null,
            'min_discount' => $alert->min_discount,
            'place' => $alert->place,
            'radius_km' => $alert->radius_km,
            'last_notified_at' => $alert->last_notified_at?->toIso8601String(),
        ];
    }
}
