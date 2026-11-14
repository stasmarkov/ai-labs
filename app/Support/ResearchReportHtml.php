<?php

declare(strict_types=1);

namespace App\Support;

use App\Ai\ResearchCitations;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * The report of a research run as HTML the page may insert: the text comes from a model that read docs text, so it is
 * untrusted. Raw HTML is escaped, an image is replaced by its alt text (no request leaves the reader's browser), and a
 * link keeps only its text unless it is the anchor of a numbered source on the same page.
 */
final class ResearchReportHtml
{
    private const CONFIG = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    private const SOURCE_ANCHOR = '/^#research-source-\d+$/';

    /**
     * @param  list<int>  $knownSources  the source numbers a `[n]` marker may link to
     */
    public static function render(string $markdown, array $knownSources): string
    {
        $converter = new GithubFlavoredMarkdownConverter(self::CONFIG);
        $environment = $converter->getEnvironment();

        $document = (new MarkdownParser($environment))->parse(ResearchCitations::link($markdown, $knownSources));

        $replaced = [];

        foreach ($document->iterator() as $node) {
            if ($node instanceof Image || ($node instanceof Link && preg_match(self::SOURCE_ANCHOR, $node->getUrl()) !== 1)) {
                $replaced[] = $node;
            }
        }

        foreach ($replaced as $node) {
            self::unwrap($node);
        }

        return trim((string) (new HtmlRenderer($environment))->renderDocument($document));
    }

    /**
     * Puts the children of the node, its link text or alt text, in its place.
     */
    private static function unwrap(Node $node): void
    {
        foreach (iterator_to_array($node->children(), false) as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }
}
