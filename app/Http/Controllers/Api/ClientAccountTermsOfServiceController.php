<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientAccountTermsOfServiceUpdateRequest;
use App\Models\ClientAccount;
use App\Services\TermsOfServiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAccountTermsOfServiceController extends Controller
{
    /** @var TermsOfServiceService */
    private $terms;

    public function __construct(TermsOfServiceService $terms)
    {
        $this->terms = $terms;
    }

    public function show(Request $request, ClientAccount $clientAccount): JsonResponse
    {
        $this->authorize('view', $clientAccount);

        return response()->json($this->terms->toAccountArray($clientAccount));
    }

    public function update(ClientAccountTermsOfServiceUpdateRequest $request, ClientAccount $clientAccount): JsonResponse
    {
        $this->authorize('update', $clientAccount);

        $account = $this->terms->updateAccount(
            $clientAccount,
            (string) $request->validated()['body']
        );

        return response()->json($this->terms->toAccountArray($account));
    }
}
