<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class SbiText
{
    public static function clean(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return '';
            }
            self::cleanChildren($body);
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $document->saveHTML($child);
            }

            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function cleanChildren(DOMNode $parent): void
    {
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'a'];
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (! in_array($tag, $allowed, true)) {
                    $parent->replaceChild($parent->ownerDocument->createTextNode($child->textContent), $child);

                    continue;
                }
                $href = $tag === 'a' ? $child->getAttribute('href') : '';
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $child->removeAttribute($attribute->name);
                }
                if ($tag === 'a' && filter_var($href, FILTER_VALIDATE_URL)
                    && in_array(strtolower(parse_url($href, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true)) {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                self::cleanChildren($child);
            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                $parent->removeChild($child);
            }
        }
    }
}
