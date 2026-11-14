<?php

declare(strict_types=1);

use App\Support\PlaygroundErrorRedactor;
use Tests\TestCase;

uses(TestCase::class);

it('removes urls, ip addresses, host:port pairs and ports from an error text', function (string $text, array $absent): void {
    $clean = PlaygroundErrorRedactor::clean($text);

    foreach ($absent as $secret) {
        expect($clean)->not->toContain($secret);
    }
})->with([
    'a url' => ['cURL error 7 for https://api.example.com/v1/messages?x=1 failed', ['https://', 'api.example.com']],
    'an ipv4 with a port' => ['Connection refused at 10.0.0.5:11434', ['10.0.0.5', '11434']],
    'a bare ipv4' => ['no route to 192.168.1.20', ['192.168.1.20']],
    'a bracketed ipv6' => ['connect to [::1]:11434 failed', ['[::1]', '11434']],
    'an ipv6' => ['connect to fe80:0:0:0:1:2:3:4 failed', ['fe80:0:0:0:1:2:3:4']],
    'a service name with a port' => ['could not reach ollama:11434 or db:5432', ['ollama:11434', 'db:5432', '11434', '5432']],
    'curl wording' => ['Failed to connect to my-box port 11434 after 3 ms: Connection refused', ['my-box', '11434']],
    'resolve wording' => ['Could not resolve host: llm.internal.example', ['llm.internal.example']],
    'a dotted host name' => ['proxy gpu-box.lan.example answered 502', ['gpu-box.lan.example']],
    'localhost' => ['localhost refused the connection', ['localhost']],
]);

it('removes the host of every configured provider url, a single-label one too', function (): void {
    config(['ai.providers.ollama.url' => 'http://inference-node:11434']);

    expect(PlaygroundErrorRedactor::clean('inference-node did not answer'))->not->toContain('inference-node');
});

it('keeps keys redacted as before and leaves model names and the error class readable', function (): void {
    config(['ai.providers.anthropic.key' => 'sk-ant-secret-0123456789']);

    $clean = PlaygroundErrorRedactor::clean("model 'qwen3.5:9b' failed: cURL error 28: Operation timed out; key sk-ant-secret-0123456789; gemma4:12b llama3.2:3b");

    expect($clean)->toContain('qwen3.5:9b')->toContain('gemma4:12b')->toContain('llama3.2:3b')->toContain('cURL error 28')->toContain('Operation timed out')
        ->not->toContain('sk-ant-secret');
});
