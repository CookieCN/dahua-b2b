<?php
declare(strict_types=1);

const PRODUCT_DETAILS_MAX_BYTES = 100000;
const HEAD_CODE_MAX_BYTES = 20000;

function clean_product_details(mixed $value, array $assets): string {
    if (!is_string($value) || strlen($value) > PRODUCT_DETAILS_MAX_BYTES || str_contains($value, "\0")) {
        throw new ApiError('产品详情最多支持 100 KB 的 HTML。');
    }
    if (trim($value) === '') { return ''; }
    $document = new DOMDocument();
    $parsed = $document->loadHTML(
        '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $value . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    $body = $document->getElementsByTagName('body')->item(0);
    if (!$parsed || $body === null) { return ''; }
    $visible = false;
    $html = product_detail_markup($body, array_fill_keys($assets, true), $visible);
    if (strlen($html) > PRODUCT_DETAILS_MAX_BYTES) { throw new ApiError('产品详情最多支持 100 KB 的 HTML。'); }
    return $visible ? trim($html) : '';
}

/** Rebuild only article markup and attributes from the parsed tree. */
function product_detail_markup(DOMNode $parent, array $assets, bool &$visible): string {
    static $tags = [
        'p'=>true, 'div'=>true, 'span'=>true, 'h2'=>true, 'h3'=>true, 'h4'=>true, 'h5'=>true, 'h6'=>true,
        'strong'=>true, 'b'=>true, 'em'=>true, 'i'=>true, 'u'=>true, 's'=>true, 'del'=>true, 'sub'=>true, 'sup'=>true,
        'ul'=>true, 'ol'=>true, 'li'=>true, 'blockquote'=>true, 'pre'=>true, 'code'=>true, 'a'=>true,
        'figure'=>true, 'figcaption'=>true, 'table'=>true, 'caption'=>true, 'thead'=>true, 'tbody'=>true,
        'tfoot'=>true, 'tr'=>true, 'th'=>true, 'td'=>true,
    ];
    static $discard = [
        'script'=>true, 'style'=>true, 'iframe'=>true, 'object'=>true, 'embed'=>true, 'template'=>true,
        'svg'=>true, 'math'=>true, 'noscript'=>true, 'textarea'=>true, 'select'=>true, 'button'=>true, 'title'=>true,
    ];
    $html = '';
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMText) {
            $visible = $visible || preg_match('/[^\s\p{Z}\p{Cf}]/u', $child->data) === 1;
            $html .= esc($child->data);
        }
        if (!$child instanceof DOMElement) { continue; }
        $tag = $child->tagName;
        if (isset($discard[$tag])) { continue; }
        if ($tag === 'br' || $tag === 'hr') {
            $html .= '<' . $tag . '>';
            continue;
        }
        if ($tag === 'img') {
            $src = $child->getAttribute('src');
            if (isset($assets[$src]) && preg_match('~^/(?:hero\.webp|uploads/[a-f0-9]{32}\.(?:jpg|png|webp|gif))$~D', $src)) {
                $html .= '<img src="' . esc($src) . '" alt="' . esc($child->getAttribute('alt')) . '" loading="lazy">';
                $visible = true;
            }
            continue;
        }
        $content = product_detail_markup($child, $assets, $visible);
        // Editor image wrappers should not add paragraph gaps between long-image slices.
        if (in_array($tag, ['p', 'div', 'span', 'figure'], true)
            && str_contains($content, '<img ')
            && preg_match('~^(?:<img [^>]*>|\s|\p{Z}|\p{Cf}|<br>)+$~uD', $content)) {
            preg_match_all('~<img [^>]*>~', $content, $images);
            $html .= implode('', $images[0]);
            continue;
        }
        if (!isset($tags[$tag])) {
            $html .= $content;
            continue;
        }
        $attributes = '';
        if ($tag === 'a') {
            $href = trim($child->getAttribute('href'));
            if (!preg_match('/[\x00-\x20\x7f\\\\]/', $href) && preg_match('~^(?:https?://[^/]|mailto:[^/]|tel:\+?[0-9]|/(?!/)|#)~i', $href)) {
                $attributes = ' href="' . esc($href) . '"';
            }
        }
        if ($tag === 'td' || $tag === 'th') {
            foreach (['rowspan', 'colspan'] as $name) {
                $span = $child->getAttribute($name);
                if (preg_match('/^(?:[1-9]|[1-4][0-9]|50)$/D', $span)) { $attributes .= ' ' . $name . '="' . $span . '"'; }
            }
        }
        $html .= '<' . $tag . $attributes . '>' . $content . '</' . $tag . '>';
    }
    // Empty paragraphs inserted between two image blocks also create visible seams.
    return preg_replace(
        '~(<img [^>]*>)(?:\s|\p{Z}|\p{Cf}|<br>|<(p|div|span|figure)>(?:\s|\p{Z}|\p{Cf}|<br>)*</\2>)+(?=<img )~u',
        '$1', $html
    );
}
