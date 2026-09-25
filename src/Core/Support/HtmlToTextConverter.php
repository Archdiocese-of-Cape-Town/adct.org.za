<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Support;

final class HtmlToTextConverter
{
    private const BLOCK_TAGS = [
        'address',
        'article',
        'blockquote',
        'div',
        'footer',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'header',
        'hr',
        'main',
        'ol',
        'p',
        'section',
        'ul',
    ];

    public function convert(string $html): string
    {
        $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;
        $html = preg_replace(
            '~<(script|style|head|noscript|template|svg|iframe|object)\b[^>]*>.*?</\1\s*>~is',
            ' ',
            $html
        ) ?? $html;

        $tableDepth = 0;
        $rowHasCell = false;
        $text = preg_replace_callback(
            '~<![^>]*>|<\?[^>]*\?>|</?[a-z][^>]*>~is',
            static function (array $matches) use (&$tableDepth, &$rowHasCell): string {
                $tag = $matches[0];

                if (! preg_match('/^<\s*(\/?)\s*([a-z][a-z0-9]*)\b([^>]*)>/is', $tag, $parts)) {
                    return '';
                }

                $closing = $parts[1] === '/';
                $name = strtolower($parts[2]);
                $attributes = $parts[3] ?? '';

                if ($name === 'table') {
                    if ($closing) {
                        $tableDepth = max(0, $tableDepth - 1);

                        return "\n";
                    }

                    ++$tableDepth;

                    return "\n";
                }

                if ($name === 'tr' && $tableDepth > 0) {
                    if ($closing) {
                        $rowHasCell = false;

                        return "\n";
                    }

                    $rowHasCell = false;

                    return '';
                }

                if (($name === 'td' || $name === 'th') && $tableDepth > 0) {
                    if ($closing) {
                        return '';
                    }

                    if ($rowHasCell) {
                        return ' | ';
                    }

                    $rowHasCell = true;

                    return '';
                }

                if ($name === 'br' || $name === 'hr') {
                    return "\n";
                }

                if ($name === 'li') {
                    return $closing ? '' : "\n- ";
                }

                if (in_array($name, self::BLOCK_TAGS, true)) {
                    return "\n";
                }

                if ($name === 'img' && preg_match('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $alt)) {
                    $value = $alt[1] !== '' ? $alt[1] : ($alt[2] !== '' ? $alt[2] : ($alt[3] ?? ''));

                    return $value !== '' ? ' ' . $value . ' ' : '';
                }

                return '';
            },
            $html
        );

        $text = html_entity_decode(
            is_string($text) ? $text : $html,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t\f\x0B]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\R */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{2,}/u', "\n", $text) ?? $text;

        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = preg_replace('/\s*\|\s*/u', ' | ', trim($line)) ?? trim($line);
            $line = preg_replace('/^(?:\|\s*)+|(?:\s*\|)+$/u', '', $line) ?? $line;
            $lines[] = trim($line);
        }

        return trim(implode("\n", $lines));
    }
}
