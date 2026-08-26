<?php

namespace App\Services;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmailTemplateService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(?string $category = null): array
    {
        $query = EmailTemplate::query()->orderBy('category')->orderBy('name')->orderBy('id');
        if ($category !== null && $category !== '' && $category !== 'all') {
            $query->where('category', $category);
        }

        return $query->get()->map(function (EmailTemplate $row) {
            return $this->toArray($row);
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupedTemplates(): array
    {
        $byCategory = [];
        foreach (EmailTemplate::CATEGORIES as $category) {
            $byCategory[$category] = [];
        }

        foreach ($this->listTemplates() as $row) {
            $cat = (string) ($row['category'] ?? '');
            if (! array_key_exists($cat, $byCategory)) {
                $byCategory[$cat] = [];
            }
            $byCategory[$cat][] = $row;
        }

        $groups = [];
        foreach (EmailTemplate::CATEGORIES as $category) {
            $items = $byCategory[$category] ?? [];
            $groups[] = [
                'category' => $category,
                'category_label' => EmailTemplate::categoryLabel($category),
                'count' => count($items),
                'templates' => $items,
            ];
        }

        return $groups;
    }

    /**
     * @param  array{category: string, name: string, subject?: string|null, body?: string|null}  $data
     */
    public function create(array $data): EmailTemplate
    {
        $payload = $this->normalizePayload($data, true);

        return EmailTemplate::query()->create($payload);
    }

    /**
     * @param  array{category?: string, name?: string, subject?: string|null, body?: string|null}  $data
     */
    public function update(EmailTemplate $template, array $data): EmailTemplate
    {
        $payload = $this->normalizePayload($data, false);
        $template->fill($payload);
        $template->save();

        return $template->fresh();
    }

    public function delete(EmailTemplate $template): void
    {
        DB::transaction(function () use ($template) {
            $template->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(EmailTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'category' => (string) $template->category,
            'category_label' => EmailTemplate::categoryLabel((string) $template->category),
            'name' => (string) $template->name,
            'subject' => $template->subject !== null ? (string) $template->subject : null,
            'body' => $template->body !== null ? (string) $template->body : null,
            'status' => 'ready',
            'status_label' => 'Ready',
            'last_sent_at' => null,
            'created_at' => optional($template->created_at)->toIso8601String(),
            'updated_at' => optional($template->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data, bool $creating): array
    {
        $out = [];

        if ($creating || array_key_exists('category', $data)) {
            $category = strtolower(trim((string) ($data['category'] ?? '')));
            if (! EmailTemplate::isValidCategory($category)) {
                throw ValidationException::withMessages([
                    'category' => ['Select a valid category.'],
                ]);
            }
            $out['category'] = $category;
        }

        if ($creating || array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Template name is required.'],
                ]);
            }
            $out['name'] = mb_substr($name, 0, 255);
        }

        if ($creating || array_key_exists('subject', $data) || array_key_exists('description', $data)) {
            $raw = array_key_exists('subject', $data) ? $data['subject'] : ($data['description'] ?? null);
            $subject = isset($raw) ? trim((string) $raw) : '';
            $out['subject'] = $subject !== '' ? mb_substr($subject, 0, 512) : null;
        }

        if ($creating || array_key_exists('body', $data)) {
            $body = isset($data['body']) ? (string) $data['body'] : '';
            $out['body'] = $body !== '' ? $body : null;
        }

        return $out;
    }
}
