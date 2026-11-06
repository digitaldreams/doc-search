<?php

namespace App\Tests\Docs;

use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;

/** Deterministic stand-in for the embedding model: one axis per word, so shared words mean similar vectors. */
final class FakeEmbeddings
{
    /** @var list<string|array<string>> every input the "model" received */
    public static array $inputs = [];

    public static function vector(string $text): array
    {
        $v = array_fill(0, 768, 0.0);
        preg_match_all('/\w+/', strtolower($text), $m);
        foreach ($m[0] as $w) {
            if (!\in_array($w, ['title', 'text', 'task', 'search', 'result', 'query', 'none'], true)) {
                ++$v[crc32($w) % 768];
            }
        }
        // a text with no real word still has to be a valid unit vector
        if (0.0 === array_sum($v)) {
            $v[0] = 1.0;
        }

        return $v;
    }

    public static function platform(): \Symfony\AI\Platform\PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static function ($model, $input) {
            self::$inputs[] = $input;

            return new VectorResult(array_map(static fn ($t) => new Vector(self::vector($t)), (array) $input));
        });
    }
}
