<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    /** @var EmailTemplateService */
    private $templates;

    public function __construct(EmailTemplateService $templates)
    {
        $this->templates = $templates;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmailTemplate::class);

        $grouped = filter_var($request->query('grouped', true), FILTER_VALIDATE_BOOLEAN);

        if ($grouped) {
            return response()->json([
                'categories' => EmailTemplate::CATEGORIES,
                'category_labels' => EmailTemplate::CATEGORY_LABELS,
                'groups' => $this->templates->groupedTemplates(),
            ]);
        }

        $category = $request->query('category');
        $category = is_string($category) ? $category : null;

        return response()->json([
            'categories' => EmailTemplate::CATEGORIES,
            'category_labels' => EmailTemplate::CATEGORY_LABELS,
            'data' => $this->templates->listTemplates($category),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', EmailTemplate::class);

        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(EmailTemplate::CATEGORIES)],
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:512'],
            'body' => ['nullable', 'string'],
        ]);

        $template = $this->templates->create($validated);

        return response()->json($this->templates->toArray($template), 201);
    }

    public function show(EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('view', $emailTemplate);

        return response()->json($this->templates->toArray($emailTemplate));
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('update', $emailTemplate);

        $validated = $request->validate([
            'category' => ['sometimes', 'string', Rule::in(EmailTemplate::CATEGORIES)],
            'name' => ['sometimes', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:512'],
            'body' => ['nullable', 'string'],
        ]);

        $template = $this->templates->update($emailTemplate, $validated);

        return response()->json($this->templates->toArray($template));
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('delete', $emailTemplate);

        $this->templates->delete($emailTemplate);

        return response()->json(['ok' => true]);
    }
}
