<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class PhotoEncoderComposeTest extends TestCase
{
    private const string COMPOSE_PATH = '.ddev/docker-compose.photo-encoder.yaml';

    /**
     * @return array<string, mixed>
     */
    private function service(): array
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2).'/'.self::COMPOSE_PATH);

        $this->assertIsArray($compose['services']['photo-encoder'] ?? null);

        return $compose['services']['photo-encoder'];
    }

    public function test_ac1_image_is_pinned_by_digest(): void
    {
        $this->assertMatchesRegularExpression(
            '#^michaelf34/infinity(:[\w.-]+)?@sha256:[0-9a-f]{64}$#',
            $this->service()['image'],
        );
    }

    public function test_ac1_weights_live_in_a_named_volume_that_survives_restarts(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2).'/'.self::COMPOSE_PATH);
        $mounts = collect($this->service()['volumes'] ?? []);

        $named = $mounts->first(fn (string $mount): bool => str_starts_with($mount, 'photo-encoder-models:'));

        $this->assertNotNull($named);
        $this->assertArrayHasKey('photo-encoder-models', $compose['volumes']);
        $this->assertSame('/models', explode(':', $named)[1]);
        $this->assertSame('/models', $this->service()['environment']['HF_HOME'] ?? null);
    }

    public function test_nfr2_memory_is_capped_at_two_gigabytes(): void
    {
        $service = $this->service();

        $this->assertSame('2g', $service['mem_limit']);
        $this->assertSame('2g', $service['memswap_limit']);
    }

    public function test_ac1_health_check_probes_the_health_endpoint_on_the_encoder_port(): void
    {
        $health = $this->service()['healthcheck'];

        $this->assertStringContainsString('http://localhost:7997/health', implode(' ', $health['test']));
        $this->assertArrayHasKey('interval', $health);
        $this->assertArrayHasKey('retries', $health);
        $this->assertArrayHasKey('start_period', $health);
        $this->assertContains(7997, $this->service()['expose']);
    }

    public function test_ac1_serves_the_512_dimension_clip_model_on_cpu(): void
    {
        $command = $this->service()['command'];

        $this->assertStringContainsString('openai/clip-vit-base-patch32', $command);
        $this->assertStringContainsString('--device cpu', $command);
        $this->assertStringContainsString('--port 7997', $command);
    }

    public function test_ac7_serves_every_available_model_within_the_memory_cap(): void
    {
        $command = $this->service()['command'];

        foreach (['openai/clip-vit-base-patch32', 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K'] as $model) {
            $this->assertStringContainsString("--model-id {$model}", $command);
        }
        $this->assertSame('2g', $this->service()['mem_limit']);
    }

    public function test_ac1_is_discoverable_by_ddev(): void
    {
        $service = $this->service();

        $this->assertSame('${DDEV_SITENAME}', $service['labels']['com.ddev.site-name']);
        $this->assertSame('${DDEV_APPROOT}', $service['labels']['com.ddev.approot']);
        $this->assertSame('photo-encoder', $service['hostname']);
    }

    public function test_mnc5_ddev_config_is_untouched(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Yaml::parseFile($root.'/.ddev/config.yaml');

        $this->assertSame('nginx-fpm', $config['webserver_type']);
        $this->assertStringNotContainsString('photo-encoder', file_get_contents($root.'/.ddev/config.yaml'));

        exec('git -C '.escapeshellarg($root).' diff --quiet HEAD -- .ddev/config.yaml', $output, $status);
        $this->assertSame(0, $status, '.ddev/config.yaml differs from HEAD');
    }

    public function test_models_are_pinned_to_commits_and_run_no_remote_code(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/.ddev/docker-compose.photo-encoder.yaml');

        $this->assertMatchesRegularExpression('#--model-id openai/clip-vit-base-patch32 --revision [0-9a-f]{40} --no-trust-remote-code#', $compose);
        $this->assertMatchesRegularExpression('#--model-id laion/CLIP-ViT-B-32-laion2B-s34B-b79K --revision [0-9a-f]{40} --no-trust-remote-code#', $compose);
        $this->assertStringNotContainsString('--trust-remote-code ', $compose);
    }
}
