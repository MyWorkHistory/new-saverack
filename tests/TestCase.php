<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWithPhoto(string $url, array $payload): TestResponse
    {
        return $this->post($url, array_merge($payload, [
            'photo' => UploadedFile::fake()->image('return.jpg'),
        ]), [
            'Accept' => 'application/json',
        ]);
    }
}
