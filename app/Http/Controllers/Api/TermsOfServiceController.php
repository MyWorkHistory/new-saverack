<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TermsOfServiceUpdateRequest;
use App\Models\TermsOfService;
use App\Services\TermsOfServiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsOfServiceController extends Controller
{
    /** @var TermsOfServiceService */
    private $terms;

    public function __construct(TermsOfServiceService $terms)
    {
        $this->terms = $terms;
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TermsOfService::class);

        return response()->json($this->terms->toGlobalArray($this->terms->globalDocument()));
    }

    public function update(TermsOfServiceUpdateRequest $request): JsonResponse
    {
        $doc = $this->terms->updateGlobal(
            (string) $request->validated()['body'],
            $request->user()
        );

        return response()->json($this->terms->toGlobalArray($doc));
    }
}
