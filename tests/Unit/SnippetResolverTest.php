<?php

declare(strict_types=1);

use App\Guides\InvalidGuideException;
use App\Guides\SnippetResolver;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/snippets-'.uniqid();
    mkdir($this->root.'/app', 0777, true);
    mkdir($this->root.'/storage');
    file_put_contents($this->root.'/.env', 'APP_KEY=secret');
    file_put_contents($this->root.'/storage/x.php', "<?php\n");
    file_put_contents($this->root.'/app/small.php', "<?php\n\$a = '<b>&</b>';\n");
    file_put_contents($this->root.'/app/big.php', implode("\n", array_fill(0, 81, '// line')));
    file_put_contents($this->root.'/app/anchored.php', "<?php\n// guide:start core\n\$one = 1;\n\$two = 2;\n// guide:end core\n\$three = 3;\n");
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->root)));

it('AC-3 resolves a PHP symbol through reflection', function (): void {
    $resolver = new SnippetResolver(dirname(__DIR__, 2));
    $snippet = $resolver->resolve(SnippetResolver::class.'::resolve');

    expect($snippet['file'])->toBe('app/Guides/SnippetResolver.php')
        ->and($snippet['code'])->toContain('public function resolve')
        ->and($snippet['endLine'])->toBeGreaterThan($snippet['startLine']);
});

it('AC-3 starts a symbol snippet at its docblock and attributes', function (): void {
    $class = 'GuideDocblockFixture'.uniqid();
    file_put_contents($this->root.'/app/Documented.php', "<?php\n\nfinal class {$class}\n{\n    /**\n     * Explains the method.\n     */\n    #[\\Deprecated]\n    public function run(): int\n    {\n        return 1;\n    }\n}\n");
    require $this->root.'/app/Documented.php';

    $snippet = (new SnippetResolver($this->root))->resolve($class.'::run');

    expect($snippet['startLine'])->toBe(5)
        ->and($snippet['code'])->toStartWith("    /**\n     * Explains the method.")
        ->and($snippet['code'])->toContain('#[\\Deprecated]');
});

it('AC-3 resolves an anchored range even after the lines above it move', function (): void {
    $resolver = new SnippetResolver($this->root);
    $first = $resolver->resolve('app/anchored.php#core');
    file_put_contents($this->root.'/app/anchored.php', "<?php\n// new\n// new\n// guide:start core\n\$one = 1;\n\$two = 2;\n// guide:end core\n");
    $second = $resolver->resolve('app/anchored.php#core');

    expect($first['startLine'])->toBe(3)->and($first['endLine'])->toBe(4)
        ->and($first['code'])->toBe("\$one = 1;\n\$two = 2;")
        ->and($second['startLine'])->toBe(5)
        ->and($second['code'])->toBe($first['code']);
});

it('AC-3 refuses an anchor that occurs more than once in a file', function (string $body): void {
    file_put_contents($this->root.'/app/dup.php', $body);

    (new SnippetResolver($this->root))->resolve('app/dup.php#core');
})->with([
    'duplicated start and end' => ["<?php\n// guide:start core\n\$a = 1;\n// guide:end core\n// guide:start core\n\$b = 2;\n// guide:end core\n"],
    'duplicated start only' => ["<?php\n// guide:start core\n// guide:start core\n\$a = 1;\n// guide:end core\n"],
    'duplicated end only' => ["<?php\n// guide:start core\n\$a = 1;\n// guide:end core\n// guide:end core\n"],
])->throws(InvalidGuideException::class, 'exactly once');

it('AC-3 resolves a whole file of 80 lines or fewer and refuses a longer one', function (): void {
    $resolver = new SnippetResolver($this->root);

    expect($resolver->resolve('app/small.php')['startLine'])->toBe(1);
    $resolver->resolve('app/big.php');
})->throws(InvalidGuideException::class, 'more than 80 lines');

it('AC-3 refuses an unknown anchor or symbol', function (string $reference): void {
    (new SnippetResolver($this->root))->resolve($reference);
})->with(['app/anchored.php#nope', 'App\Missing\Thing'])->throws(InvalidGuideException::class);

it('NFR-3 refuses disallowed paths from the project root', function (string $reference): void {
    (new SnippetResolver($this->root))->resolve($reference);
})->with(['.env', '../.env', 'app/../.env', 'storage/x.php', 'app/../storage/x.php'])->throws(InvalidGuideException::class);

it('NFR-3 escapes HTML in the returned code', function (): void {
    $snippet = (new SnippetResolver($this->root))->resolve('app/small.php');

    expect($snippet['code'])->toContain('&lt;b&gt;&amp;&lt;/b&gt;')->not->toContain('<b>');
});
