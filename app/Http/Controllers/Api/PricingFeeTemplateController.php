<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PricingFeeTemplateStoreRequest;
use App\Http\Requests\PricingFeeTemplateUpdateRequest;
use App\Models\PricingFeeTemplate;
use App\Services\PricingFeeTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PricingFeeTemplateController extends Controller
{
    /** @var PricingFeeTemplateService */
    private $templates;

    public function __construct(PricingFeeTemplateService $templates)
    {
        $this->templates = $templates;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PricingFeeTemplate::class);

        return response()->json($this->templates->list($request->only(['search', 'category'])));
    }

    public function store(PricingFeeTemplateStoreRequest $request): JsonResponse
    {
        $this->authorize('create', PricingFeeTemplate::class);

        $template = $this->templates->create(
            $request->validated(),
            $request->file('icon')
        );

        return response()->json($this->templates->toArray($template), 201);
    }

    public function show(PricingFeeTemplate $pricingFeeTemplate): JsonResponse
    {
        $this->authorize('view', $pricingFeeTemplate);

        return response()->json($this->templates->toArray($pricingFeeTemplate));
    }

    public function update(PricingFeeTemplateUpdateRequest $request, PricingFeeTemplate $pricingFeeTemplate): JsonResponse
    {
        $this->authorize('update', $pricingFeeTemplate);

        $template = $this->templates->update(
            $pricingFeeTemplate,
            $request->validated(),
            $request->file('icon'),
            $request->boolean('remove_icon')
        );

        return response()->json($this->templates->toArray($template));
    }

    public function destroy(PricingFeeTemplate $pricingFeeTemplate): JsonResponse
    {
        $this->authorize('delete', $pricingFeeTemplate);

        try {
            $this->templates->delete($pricingFeeTemplate);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }
}
