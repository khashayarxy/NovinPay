<?php

namespace Tests\Feature\Admin;

use App\Support\ArticleContent;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ArticleContentLinksTest extends TestCase
{
    public static function invalidLinks(): array
    {
        return [
            'empty label' => ['[](https://example.com)'],
            'blank label' => ['[   ](https://example.com)'],
            'long label' => ['['.str_repeat('a', 201).'](https://example.com)'],
            'empty URL' => ['[label]()'],
            'long URL' => ['[label](https://example.com/'.str_repeat('a', 2029).')'],
            'leading space' => ['[label]( https://example.com)'],
            'trailing space' => ['[label](https://example.com )'],
            'internal space' => ['[label](https://example.com/a b)'],
            'tab' => ["[label](https://example.com/\tpath)"],
            'unicode space' => ["[label](https://example.com/\u{00a0}path)"],
            'missing close' => ['[label](https://example.com'],
            'missing URL opener' => ['[label]https://example.com)'],
            'nested label' => ['[outer[inner]](https://example.com)'],
            'nested URL' => ['[label](https://example.com/a(b))'],
            'hidden inside emphasis' => ['**[label](javascript:alert)**'],
            'unsafe scheme' => ['[label](javascript:alert)'],
            'protocol relative' => ['[label](//example.com)'],
            'credentials' => ['[label](https://user:pass@example.com)'],
            'newline URL' => ["[label](https://example.com/a\nb)"],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_or_unrenderable_links_are_explicitly_rejected(string $source): void
    {
        $this->expectException(ValidationException::class);
        ArticleContent::parse($source);
    }

    public static function validLinks(): array
    {
        return [
            ['label', 'http://example.com/path'],
            ['عنوان فارسی', 'https://example.com/path?q=a&next=%20#section'],
            ['label', 'HTTPS://example.com/path'],
            [str_repeat('a', 200), 'https://example.com/'.str_repeat('a', 2028)],
        ];
    }

    #[DataProvider('validLinks')]
    public function test_every_valid_link_is_a_rendered_escaped_anchor(string $label, string $url): void
    {
        $blocks = ArticleContent::parse('before ['.$label.']('.$url.') after');
        $this->assertSame(['type' => 'link', 'text' => $label, 'url' => $url], $blocks[0]['inlines'][1]);
        $html = $this->blade('<x-article-content :blocks="$blocks" />', compact('blocks'));
        $html->assertSee('href="'.e($url).'"', false)->assertSee(e($label), false);
    }

    public function test_multiple_links_and_emphasis_use_the_same_tokenizer(): void
    {
        $nodes = ArticleContent::parse('**bold** [one](http://example.com) *em* [two](https://example.org)')[0]['inlines'];
        $this->assertSame(['strong', 'text', 'link', 'text', 'em', 'text', 'link'], array_column($nodes, 'type'));
        $this->assertSame('http://example.com', $nodes[2]['url']);
        $this->assertSame('https://example.org', $nodes[6]['url']);
    }
}
