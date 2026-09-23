<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Support\Text;

/**
 * Konverter HTML -> Markdown murni PHP (tanpa dependency).
 *
 * Dipakai sebagai parser cadangan bila endpoint /parse menolak input web
 * (mis. EMBED_WEB_PAGE=false di service). Hasilnya tetap Markdown yang rapi
 * supaya tahap chunking dan /embed tidak perlu tahu siapa yang mem-parsing.
 */
final class HtmlToMarkdown
{
    /** Elemen yang isinya tidak pernah relevan untuk pencarian. */
    private const DROP_TAGS = [
        'script', 'style', 'noscript', 'svg', 'canvas', 'iframe', 'template',
        'form', 'button', 'input', 'select', 'textarea', 'option',
    ];

    /** Elemen navigasi/iklan yang dibuang supaya teks utama bersih. */
    private const NOISE_TAGS = ['nav', 'footer', 'aside', 'dialog', 'menu'];

    /** Elemen tingkat blok yang memicu pemisahan paragraf markdown. */
    private const BLOCK_TAGS = [
        'p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside',
        'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre', 'table',
        'thead', 'tbody', 'tr', 'hr', 'address', 'details', 'summary',
    ];

    private const NOISE_ATTRIBUTE_PATTERN = '/(^|[^a-z])(cookie|banner|sidebar|navbar|breadcrumb|social|share|advert|ads|popup|modal|subscribe|newsletter)([^a-z]|$)/i';

    private bool $stripNavigation;

    public function __construct(bool $stripNavigation = true)
    {
        $this->stripNavigation = $stripNavigation;
    }

    /**
     * @return array{title: string, markdown: string}
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            return ['title' => '', 'markdown' => ''];
        }

        $dom = $this->load($html);
        if ($dom === null) {
            return ['title' => '', 'markdown' => ''];
        }

        $title = $this->documentTitle($dom);
        $this->stripNoise($dom);

        $root = $this->pickRoot($dom);
        if ($root === null) {
            return ['title' => $title, 'markdown' => ''];
        }

        $blocks = [];
        $this->renderBlocks($root, $blocks);

        $markdown = Text::normalizeWhitespace(implode("\n\n", $this->compact($blocks)));

        return ['title' => $title, 'markdown' => $markdown];
    }

    private function load(string $html): ?\DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return null;
        }

        // Buang processing instruction "<?xml ...>" yang disisipkan di atas.
        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                $dom->removeChild($child);
                break;
            }
        }

        return $dom;
    }

    private function documentTitle(\DOMDocument $dom): string
    {
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $text = $this->collapse($titles->item(0)?->textContent ?? '');
            if ($text !== '') {
                return $text;
            }
        }

        foreach ($dom->getElementsByTagName('h1') as $h1) {
            $text = $this->collapse($h1->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        foreach ($dom->getElementsByTagName('meta') as $node) {
            $property = strtolower($node->getAttribute('property'));
            $name = strtolower($node->getAttribute('name'));

            if (!in_array($property, ['og:title', 'twitter:title'], true) && $name !== 'title') {
                continue;
            }

            $content = $this->collapse($node->getAttribute('content'));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    private function stripNoise(\DOMDocument $dom): void
    {
        $tags = self::DROP_TAGS;
        if ($this->stripNavigation) {
            $tags = array_merge($tags, self::NOISE_TAGS);
        }

        foreach ($tags as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        if (!$this->stripNavigation) {
            return;
        }

        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $element) {
            $label = trim($element->getAttribute('id') . ' ' . $element->getAttribute('class'));
            if ($label === '' || preg_match(self::NOISE_ATTRIBUTE_PATTERN, $label) !== 1) {
                continue;
            }

            // Jangan buang container besar yang mungkin memuat seluruh isi.
            if (mb_strlen($this->collapse($element->textContent)) < 200) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    /**
     * Pilih container isi utama: elemen terdalam yang tetap memuat minimal
     * 60% teks halaman (heuristik sederhana yang tahan terhadap beragam tema
     * situs pemerintah).
     */
    private function pickRoot(\DOMDocument $dom): ?\DOMElement
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return $dom->documentElement;
        }

        $bodyLength = mb_strlen($this->collapse($body->textContent));
        if ($bodyLength < 400) {
            return $body;
        }

        $threshold = (int) floor($bodyLength * 0.6);
        $best = $body;
        $bestLength = $bodyLength;

        foreach ($body->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->nodeName);
            if (in_array($tag, ['html', 'body', 'script', 'style'], true)) {
                continue;
            }

            $length = mb_strlen($this->collapse($element->textContent));
            if ($length < $threshold || $length >= $bestLength) {
                continue;
            }

            $best = $element;
            $bestLength = $length;
        }

        return $best;
    }

    /**
     * @param list<string> $blocks
     *
     * @return list<string>
     */
    private function compact(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block !== '') {
                $out[] = $block;
            }
        }

        return $out;
    }

    private function collapse(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $decoded) ?? $decoded);
    }

    /**
     * Telusuri DOM dan hasilkan blok-blok markdown.
     *
     * Teks di dalam elemen inline dikumpulkan lebih dulu, lalu di-flush
     * sebagai satu paragraf setiap kali bertemu elemen tingkat blok.
     *
     * @param list<string> $blocks
     */
    private function renderBlocks(\DOMNode $node, array &$blocks, int $listDepth = 0): void
    {
        $pending = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $pending .= $child->nodeValue ?? '';
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (!$this->isBlock($tag)) {
                $pending .= $this->inline($child);
                continue;
            }

            $this->flush($blocks, $pending);
            $pending = '';

            switch ($tag) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $text = $this->collapse($child->textContent);
                    if ($text !== '') {
                        $blocks[] = str_repeat('#', (int) substr($tag, 1)) . ' ' . $text;
                    }
                    break;

                case 'ul':
                case 'ol':
                    $this->renderList($child, $blocks, $listDepth, $tag === 'ol');
                    break;

                case 'li':
                case 'dt':
                case 'dd':
                case 'figcaption':
                case 'summary':
                case 'address':
                    $text = $this->collapse($child->textContent);
                    if ($text !== '') {
                        $blocks[] = $text;
                    }
                    break;

                case 'blockquote':
                    $inner = [];
                    $this->renderBlocks($child, $inner, $listDepth);
                    foreach ($this->compact($inner) as $line) {
                        $blocks[] = '> ' . str_replace("\n", "\n> ", $line);
                    }
                    break;

                case 'pre':
                    $code = rtrim((string) $child->textContent);
                    if (trim($code) !== '') {
                        $blocks[] = "```\n" . $code . "\n```";
                    }
                    break;

                case 'table':
                    $table = $this->renderTable($child);
                    if ($table !== '') {
                        $blocks[] = $table;
                    }
                    break;

                case 'hr':
                    $blocks[] = '---';
                    break;

                default:
                    // div, section, article, main, header, p, figure, details, ...
                    $this->renderBlocks($child, $blocks, $listDepth);
            }
        }

        $this->flush($blocks, $pending);
    }

    /**
     * @param list<string> $blocks
     */
    private function flush(array &$blocks, string $text): void
    {
        $text = $this->collapse($text);
        if ($text !== '') {
            $blocks[] = $text;
        }
    }

    private function isBlock(string $tag): bool
    {
        return in_array($tag, self::BLOCK_TAGS, true) || in_array($tag, self::DROP_TAGS, true);
    }

    /**
     * @param list<string> $blocks
     */
    private function renderList(\DOMElement $list, array &$blocks, int $depth, bool $ordered): void
    {
        $indent = str_repeat('  ', max(0, $depth));
        $index = 1;

        foreach ($list->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $inline = '';
            $nested = [];

            foreach ($child->childNodes as $liChild) {
                if ($liChild instanceof \DOMText) {
                    $inline .= $liChild->nodeValue ?? '';
                    continue;
                }

                if (!$liChild instanceof \DOMElement) {
                    continue;
                }

                $tag = strtolower($liChild->nodeName);
                if ($tag === 'ul' || $tag === 'ol') {
                    $nested[] = $liChild;
                    continue;
                }

                $inline .= $this->inline($liChild);
            }

            $marker = $ordered ? $index . '. ' : '- ';
            $text = $this->collapse($inline);

            if ($text !== '') {
                $blocks[] = $indent . $marker . $text;
            }

            foreach ($nested as $subList) {
                $this->renderList($subList, $blocks, $depth + 1, strtolower($subList->nodeName) === 'ol');
            }

            $index++;
        }
    }

    /**
     * Render elemen inline menjadi teks markdown.
     */
    private function inline(\DOMElement $element): string
    {
        $tag = strtolower($element->nodeName);

        switch ($tag) {
            case 'br':
                return "\n";

            case 'img':
                $src = trim($element->getAttribute('src'));
                if ($src === '') {
                    return '';
                }

                return '![' . $this->collapse($element->getAttribute('alt')) . '](' . $src . ')';

            case 'a':
                $text = $this->inlineText($element);
                if ($text === '') {
                    return '';
                }

                $href = trim($element->getAttribute('href'));
                if ($href === '' || str_starts_with($href, '#') || stripos($href, 'javascript:') === 0) {
                    return $text;
                }

                return '[' . $text . '](' . $href . ')';

            case 'strong':
            case 'b':
                $text = $this->inlineText($element);

                return $text === '' ? '' : '**' . $text . '**';

            case 'em':
            case 'i':
                $text = $this->inlineText($element);

                return $text === '' ? '' : '*' . $text . '*';

            case 'code':
                $text = $this->inlineText($element);

                return $text === '' ? '' : '`' . $text . '`';

            case 'del':
            case 's':
            case 'strike':
                $text = $this->inlineText($element);

                return $text === '' ? '' : '~~' . $text . '~~';

            default:
                return $this->inlineText($element);
        }
    }

    /**
     * Teks gabungan dari sebuah elemen inline (termasuk anak inline-nya).
     */
    private function inlineText(\DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->nodeValue ?? '';
                continue;
            }

            if ($child instanceof \DOMElement) {
                $text .= $this->inline($child);
            }
        }

        return $this->collapse($text);
    }

    /**
     * Render tabel HTML menjadi tabel markdown gaya pipe.
     */
    private function renderTable(\DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            $hasHeaderCell = false;

            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof \DOMElement) {
                    continue;
                }

                $tag = strtolower($cell->nodeName);
                if ($tag !== 'td' && $tag !== 'th') {
                    continue;
                }

                if ($tag === 'th') {
                    $hasHeaderCell = true;
                }

                $cells[] = str_replace('|', '\\|', $this->inlineText($cell));
            }

            if ($cells === []) {
                continue;
            }

            $rows[] = ['cells' => $cells, 'header' => $hasHeaderCell];
        }

        if ($rows === []) {
            return '';
        }

        $columnCount = 0;
        foreach ($rows as $row) {
            $columnCount = max($columnCount, count($row['cells']));
        }

        $lines = [];
        foreach ($rows as $index => $row) {
            $cells = $row['cells'];
            while (count($cells) < $columnCount) {
                $cells[] = '';
            }

            $lines[] = '| ' . implode(' | ', $cells) . ' |';

            if ($index === 0) {
                $lines[] = '|' . str_repeat(' --- |', $columnCount);
            }
        }

        return implode("\n", $lines);
    }
}
