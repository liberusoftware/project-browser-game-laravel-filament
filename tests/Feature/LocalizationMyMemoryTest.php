<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Liberu\Foundation\Localization\MyMemory\MyMemoryServiceProvider;
use Liberu\Foundation\Localization\MyMemory\TranslationService;
use Liberu\Localization\Contracts\TranslationProvider;
use Liberu\Localization\Contracts\TranslationProviderRegistry;

it('registers the adapter with the translation provider registry', function () {
    $registry = Mockery::mock(TranslationProviderRegistry::class);
    $registry->shouldReceive('register')->once()->with(Mockery::type(TranslationProvider::class));
    app()->instance(TranslationProviderRegistry::class, $registry);

    (new MyMemoryServiceProvider(app()))->boot();
});

it('translates and caches successful responses while retaining non-string batch values', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => 'Hola']])]);
    $service = new TranslationService();

    expect($service->name())->toBe('mymemory')
        ->and($service->translate('Hello', 'es'))->toBe('Hola')
        ->and($service->translate('Hello', 'es'))->toBe('Hola')
        ->and($service->translateBatch(['heading' => 'Hello', 'nested' => [2 => 'World', 'count' => 3, 'empty' => []]], 'es'))
        ->toBe(['heading' => 'Hola', 'nested' => [2 => 'Hola', 'count' => 3, 'empty' => []]]);
    Http::assertSentCount(2);
});

it('returns the source text for same language and malformed cached translations', function () {
    Http::preventStrayRequests();
    Http::fake();
    Cache::put('translation:en:es:'.md5('Hello'), ['invalid' => true]);

    $service = new TranslationService();
    expect($service->translate('Hello', 'en'))->toBe('Hello')
        ->and($service->translate('Hello', 'es'))->toBe('Hello')
        ->and($service->getSupportedLanguages())->toBe(TranslationService::SUPPORTED_LANGUAGES)
        ->and($service->supportedLanguages())->toBe(TranslationService::SUPPORTED_LANGUAGES)
        ->and($service->isLanguageSupported('fr'))->toBeTrue()
        ->and($service->isLanguageSupported('xx'))->toBeFalse();
    Http::assertNothingSent();
});

it('keeps the original text and logs an API connection failure', function () {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('offline'));
    Log::spy();

    expect((new TranslationService())->translate('Hello', 'es'))->toBe('Hello');
    Log::shouldHaveReceived('error')->once()->with('Translation failed', Mockery::on(fn (array $context): bool => $context['error'] === 'offline'));
});

it('does not cache failed or unusable API responses', function (array $payload, int $status) {
    Http::preventStrayRequests();
    Http::fake(['api.mymemory.translated.net/*' => Http::response($payload, $status)]);

    expect((new TranslationService())->translate('Hello', 'es'))->toBe('Hello')
        ->and(Cache::has('translation:en:es:'.md5('Hello')))->toBeFalse();
})->with([
    'server failure' => [[], 500],
    'missing translation' => [['responseData' => []], 200],
    'empty translation' => [['responseData' => ['translatedText' => '']], 200],
    'invalid translation type' => [['responseData' => ['translatedText' => 42]], 200],
]);
