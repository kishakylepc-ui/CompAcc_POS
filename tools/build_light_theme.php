<?php

declare(strict_types=1);

/**
 * CompAcc POS - Light Theme Builder
 *
 * Purpose:
 * - Read every stylesheet in public/assets/css (and the <style> blocks in
 *   public/inventory/index.php), find each color, and write the light-mode
 *   version of it to public/assets/css/theme-light.css.
 * - The dark stylesheets are only READ, never changed, so dark mode stays
 *   exactly as it is. theme-light.css only applies when <html> has
 *   data-theme="light" (the sun / moon button in the top bar).
 *
 * Kept exactly as they are in light mode: product photos and their white
 * backgrounds, QR code previews, logos, color swatches, the receipt paper
 * and everything printed (the whole file is screen-only).
 *
 * Run it again after changing colors in any stylesheet (then bump the
 * ?v= of theme-light.css in app/views/partials/header.php, login.php and
 * pos/receipt.php):
 *   C:\php\php.exe tools\build_light_theme.php
 *
 * Never edit theme-light.css by hand: edit the dark stylesheet, or the
 * MANUAL_RULES section at the bottom of this file, then run it again.
 */

const CSS_DIR = __DIR__ . '/../public/assets/css';
const OUTPUT = CSS_DIR . '/theme-light.css';
const LIGHT = 'html[data-theme="light"]';

/* Near-black used for text, borders and overlays in light mode. */
const INK = [17, 19, 24];


/*
|--------------------------------------------------------------------------
| SOURCES (in the order pages load them)
|--------------------------------------------------------------------------
*/

$sources = [];

foreach (['app.css', 'confirmation-modal.css', 'layout.css', 'ui.css'] as $shared) {
    $sources[$shared] = file_get_contents(CSS_DIR . '/' . $shared);
}

foreach (glob(CSS_DIR . '/*.css') as $path) {
    $name = basename($path);

    if (!isset($sources[$name]) && $name !== 'theme-light.css') {
        $sources[$name] = file_get_contents($path);
    }
}

$inventoryPage = file_get_contents(__DIR__ . '/../public/inventory/index.php');
preg_match_all('/<style>(.*?)<\/style>/s', $inventoryPage, $inlineStyles);
$sources['inventory/index.php (inline)'] = implode("\n", $inlineStyles[1]);


/*
|--------------------------------------------------------------------------
| WHAT STAYS UNCHANGED
|--------------------------------------------------------------------------
*/

/* Photos, QR previews, logos and swatches keep their real colors. */
const KEEP_SELECTOR = '/(product-card-photo|cart-item-photo|sale-confirm-item-photo|receipt-product-photo|color-photo-preview|payment-qr-preview|qr-preview|logo-image|login-logo|swatch|color-dot|\bimg\b)/i';

/* Receipt page: only the page around the paper, the buttons above it and
   the Void window follow the theme. The paper itself stays as printed. */
const RECEIPT_THEMED = '/^(html|body)\b|receipt-page|receipt-actions?\b|void-modal/';


/*
|--------------------------------------------------------------------------
| COLOR HELPERS
|--------------------------------------------------------------------------
*/

function parseColor(string $text): ?array
{
    $text = strtolower(trim($text));

    if ($text === 'white') {
        return [255, 255, 255, 1.0];
    }

    if ($text === 'black') {
        return [0, 0, 0, 1.0];
    }

    if (preg_match('/^#([0-9a-f]{3,8})$/', $text, $m)) {
        $hex = $m[1];

        if (strlen($hex) === 3 || strlen($hex) === 4) {
            $hex = implode('', array_map(static fn ($c) => $c . $c, str_split($hex)));
        }

        if (strlen($hex) !== 6 && strlen($hex) !== 8) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0
        ];
    }

    if (preg_match('/^rgba?\((.*)\)$/s', $text, $m)) {
        $parts = preg_split('/[\s,\/]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) < 3) {
            return null;
        }

        $alpha = $parts[3] ?? '1';
        $alpha = str_ends_with($alpha, '%') ? (float) $alpha / 100 : (float) $alpha;

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2], $alpha];
    }

    return null;
}

function formatColor(array $c): string
{
    [$r, $g, $b, $a] = $c;
    $r = (int) round(max(0, min(255, $r)));
    $g = (int) round(max(0, min(255, $g)));
    $b = (int) round(max(0, min(255, $b)));
    $a = round(max(0, min(1, $a)), 3);

    if ($a >= 1) {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    return "rgba($r, $g, $b, " . rtrim(rtrim(number_format($a, 3, '.', ''), '0'), '.') . ')';
}

function toHsl(array $c): array
{
    [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;

    if ($max === $min) {
        return [0.0, 0.0, $l];
    }

    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

    $h = match ($max) {
        $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
        $g => (($b - $r) / $d) + 2,
        default => (($r - $g) / $d) + 4
    };

    return [$h / 6, $s, $l];
}

function fromHsl(float $h, float $s, float $l, float $a): array
{
    $l = max(0, min(1, $l));
    $s = max(0, min(1, $s));

    if ($s == 0.0) {
        return [$l * 255, $l * 255, $l * 255, $a];
    }

    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;

    $channel = static function (float $t) use ($p, $q): float {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    };

    return [$channel($h + 1 / 3) * 255, $channel($h) * 255, $channel($h - 1 / 3) * 255, $a];
}

function isNeutral(array $c): bool
{
    return (max($c[0], $c[1], $c[2]) - min($c[0], $c[1], $c[2])) <= 26;
}


/*
| Readability: WCAG contrast of a (possibly see-through) text color on the
| slightly grey light background most text sits on.
*/
const READABLE = 5.0;
/* Colored text often sits on a tint of its own color, so it aims higher. */
const READABLE_COLORED = 6.0;
const LIGHT_BACKGROUND = [238, 240, 242];

function luminance(array $c): float
{
    $channel = static function (float $v): float {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel($c[0]) + 0.7152 * $channel($c[1]) + 0.0722 * $channel($c[2]);
}

function contrastOnLight(array $c): float
{
    $bg = LIGHT_BACKGROUND;
    $shown = [
        $c[0] * $c[3] + $bg[0] * (1 - $c[3]),
        $c[1] * $c[3] + $bg[1] * (1 - $c[3]),
        $c[2] * $c[3] + $bg[2] * (1 - $c[3])
    ];
    [$a, $b] = [luminance($shown), luminance($bg)];

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/* Darken a text color (same hue) until it is readable on a light background. */
function readableText(float $h, float $s, float $l, float $a, float $target = READABLE): array
{
    $color = fromHsl($h, $s, $l, $a);

    while (contrastOnLight($color) < $target && $l > 0.12) {
        $l -= 0.01;
        $color = fromHsl($h, $s, $l, $a);
    }

    return $color;
}


/*
|--------------------------------------------------------------------------
| THE LIGHT VERSION OF ONE COLOR
|--------------------------------------------------------------------------
| role: text | border | surface | shadow
*/

function lightColor(array $c, string $role, bool $backdrop = false): array
{
    [$h, $s, $l] = toHsl($c);
    $a = $c[3];

    if ($role === 'shadow') {
        /* Dark shadows stay dark but softer; light glows stay as they are. */
        if ($l < 0.5) {
            return [$c[0], $c[1], $c[2], max(0.02, $a * 0.45)];
        }

        return isNeutral($c) ? $c : [$c[0], $c[1], $c[2], $a * 0.6];
    }

    if (isNeutral($c)) {

        if ($role === 'text' && $a >= 1 && $l > 0.3 && $l <= 0.5) {
            /* Mid-grey muted text becomes readable dark grey. */
            return readableText($h, $s * 0.5, min($l, 0.42), 1.0);
        }

        if ($l > 0.5) {
            /* White-ish text, borders and overlays become ink. */
            if ($role === 'text') {
                if ($a >= 1) {
                    return readableText($h, $s * 0.5, max(0.07, min(0.42, 1 - $l)), 1.0);
                }

                /* Faint text gets deeper so it stays readable on white,
                   while brighter text stays darker than faint text. */
                $alpha = min(1, 0.5 + 0.5 * $a);

                while (contrastOnLight([...INK, $alpha]) < READABLE && $alpha < 1) {
                    $alpha = min(1, $alpha + 0.01);
                }

                return [...INK, $alpha];
            }

            if ($role === 'border') {
                return [...INK, $a >= 1 ? 1.0 : min(1, $a * 1.35)];
            }

            /* surface */
            if ($a >= 1) {
                return fromHsl($h, $s * 0.5, max(0.07, 1 - $l), 1.0);
            }

            return [...INK, $a];
        }

        /* Dark colors. */
        if ($role === 'surface') {
            if ($l < 0.02 && $a < 1) {
                /* Black see-through layers: window backdrops stay a (lighter)
                   dim; anything else (input fields, wells) becomes white. */
                return $backdrop
                    ? [15, 17, 22, $a * 0.55]
                    : [255, 255, 255, min(1, 0.55 + $a)];
            }

            /* Darker surface -> page grey, lighter surface -> white. */
            return fromHsl($h, $s * 0.4, max(0.9, min(1, 0.955 + ($l - 0.02) * 1.5)), $a);
        }

        /* Dark text / borders (used on light buttons) become light; faint
           ones get stronger, as on white, so they stay readable on the
           now-dark button. */
        $alpha = ($role === 'text' && $a < 1) ? min(1, 0.5 + 0.5 * $a) : $a;

        return fromHsl($h, $s * 0.5, 1 - $l, $alpha);
    }

    /* Colored. */
    if ($role === 'surface') {
        if ($a <= 0.4 || $l >= 0.25) {
            return $c;
        }

        /* Very dark colored panels become a pale tint of the same hue. */
        return fromHsl($h, $s * 0.6, 0.92, $a);
    }

    if ($role === 'text') {
        /* Gold, green, red, orange text made for dark backgrounds gets
           deeper (same hue) until it is readable on a light background. */
        $alpha = $a >= 0.5 ? 1.0 : 0.5 + 0.5 * $a;

        return readableText($h, min(1, $s * 1.05), min($l, 0.45), $alpha, READABLE_COLORED);
    }

    if ($l > 0.45) {
        /* Colored borders: a little deeper so they still show on white. */
        return fromHsl($h, $s, 0.45, min(1, $a * 1.2));
    }

    return $c;
}

function roleFor(string $property): string
{
    $p = strtolower($property);

    if (str_starts_with($p, '--')) {
        return match (true) {
            (bool) preg_match('/text|ink|-fg\b/', $p) => 'text',
            (bool) preg_match('/soft|tint|bg|surface|card|panel|background|backdrop|overlay|hover|glass/', $p) => 'surface',
            (bool) preg_match('/shadow|glow/', $p) => 'shadow',
            (bool) preg_match('/border|line|divider|outline|ring/', $p) => 'border',
            default => 'text'
        };
    }

    return match (true) {
        str_contains($p, 'shadow') || $p === 'filter' => 'shadow',
        str_starts_with($p, 'background') => 'surface',
        str_starts_with($p, 'border') || str_starts_with($p, 'outline') || str_starts_with($p, 'column-rule') => 'border',
        default => 'text'
    };
}

const COLOR_PROPERTY = '/^(--.*|color|background(-color|-image)?|border(-(top|right|bottom|left))?(-color)?|outline(-color)?|box-shadow|text-shadow|caret-color|fill|stroke|filter|text-decoration(-color)?|column-rule(-color)?|-webkit-text-fill-color|accent-color)$/i';

const COLOR_TOKEN = '/#[0-9a-fA-F]{3,8}\b|rgba?\([^()]*\)|\b(white|black)\b/';

function lightValue(string $property, string $value, string $selector = ''): string
{
    $role = roleFor($property);
    $backdrop = (bool) preg_match('/backdrop|overlay|scrim|-dim\b|shade/i', $selector);

    return preg_replace_callback(COLOR_TOKEN, static function (array $m) use ($role, $backdrop): string {
        $color = parseColor($m[0]);

        return $color === null ? $m[0] : formatColor(lightColor($color, $role, $backdrop));
    }, $value);
}


/*
|--------------------------------------------------------------------------
| SMALL CSS READER
|--------------------------------------------------------------------------
*/

function splitTopLevel(string $text, string $separator): array
{
    $parts = [];
    $depth = 0;
    $quote = null;
    $current = '';

    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $ch = $text[$i];

        if ($quote !== null) {
            if ($ch === $quote && $text[$i - 1] !== '\\') {
                $quote = null;
            }
        } elseif ($ch === '"' || $ch === "'") {
            $quote = $ch;
        } elseif ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        } elseif ($ch === $separator && $depth === 0) {
            $parts[] = $current;
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $parts[] = $current;

    return $parts;
}

/* Returns a list of ['rule', selector, body] / ['at', prelude, children]. */
function parseCss(string $css): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $pos = 0;

    return parseBlock($css, $pos);
}

function parseBlock(string $css, int &$pos): array
{
    $items = [];
    $n = strlen($css);
    $start = $pos;

    while ($pos < $n) {
        $ch = $css[$pos];

        if ($ch === '}') {
            $pos++;
            return $items;
        }

        if ($ch === ';') {
            /* @import / @charset statements end here. */
            $pos++;
            $start = $pos;
            continue;
        }

        if ($ch === '{') {
            $prelude = trim(substr($css, $start, $pos - $start));
            $pos++;

            if (str_starts_with($prelude, '@media') || str_starts_with($prelude, '@supports')) {
                $items[] = ['at', $prelude, parseBlock($css, $pos)];
            } elseif (str_starts_with($prelude, '@')) {
                skipBlock($css, $pos); /* @keyframes, @font-face, @page ... */
            } else {
                $bodyStart = $pos;
                skipBlock($css, $pos);
                $items[] = ['rule', $prelude, substr($css, $bodyStart, $pos - $bodyStart - 1)];
            }

            $start = $pos;
            continue;
        }

        $pos++;
    }

    return $items;
}

function skipBlock(string $css, int &$pos): void
{
    $depth = 1;

    while ($pos < strlen($css) && $depth > 0) {
        if ($css[$pos] === '{') $depth++;
        if ($css[$pos] === '}') $depth--;
        $pos++;
    }
}

function lightSelector(string $selectorList): string
{
    $out = [];

    foreach (splitTopLevel($selectorList, ',') as $selector) {
        $selector = trim(preg_replace('/\s+/', ' ', $selector));

        if ($selector === '') {
            continue;
        }

        if (str_starts_with($selector, ':root')) {
            $out[] = LIGHT . substr($selector, 5);
        } elseif (preg_match('/^html(?![\w-])/', $selector)) {
            $out[] = LIGHT . substr($selector, 4);
        } else {
            $out[] = LIGHT . ' ' . $selector;
        }
    }

    return implode(",\n", $out);
}


/*
|--------------------------------------------------------------------------
| BUILD
|--------------------------------------------------------------------------
*/

$stats = ['rules' => 0, 'declarations' => 0];

function buildItems(array $items, string $file, int $indent, array &$stats): string
{
    $pad = str_repeat('    ', $indent);
    $out = '';

    foreach ($items as $item) {

        if ($item[0] === 'at') {
            if (str_contains($item[1], 'print')) {
                continue; /* printouts never change */
            }

            $inner = buildItems($item[2], $file, $indent + 1, $stats);

            if ($inner !== '') {
                $out .= "{$pad}{$item[1]} {\n{$inner}{$pad}}\n\n";
            }

            continue;
        }

        [, $selector, $body] = $item;

        /*
        | Every color line of every rule is written again in light mode, even
        | when its value stays the same (photos, receipt paper, var(--token),
        | transparent, none). That keeps the dark cascade: a "selected" or
        | "active" rule still beats the plain rule, because both now carry
        | the same html[data-theme="light"] prefix.
        */
        $keepAsIs =
            preg_match(KEEP_SELECTOR, $selector) ||
            ($file === 'receipt.css' && !preg_match(RECEIPT_THEMED, $selector));

        $declarations = [];
        $pairs = [];

        foreach (splitTopLevel($body, ';') as $declaration) {
            $colon = strpos($declaration, ':');

            if ($colon !== false) {
                $pairs[] = [trim(substr($declaration, 0, $colon)), trim(substr($declaration, $colon + 1))];
            }
        }

        /* A solid colored background (red / gold / green button) keeps its
           color in light mode, so the text on it keeps its color too. */
        $keepsColoredBackground = false;

        foreach ($pairs as [$property, $value]) {
            if (!preg_match('/^background(-color)?$/i', $property)) {
                continue;
            }

            preg_match_all(COLOR_TOKEN, $value, $found);

            foreach ($found[0] as $token) {
                $color = parseColor($token);

                if ($color !== null && !isNeutral($color) && $color[3] > 0.4 && toHsl($color)[2] >= 0.25) {
                    $keepsColoredBackground = true;
                }
            }
        }

        foreach ($pairs as [$property, $value]) {

            if ($keepsColoredBackground && preg_match('/^(color|fill|stroke)$/i', $property)) {
                continue;
            }

            if (!preg_match(COLOR_PROPERTY, $property)) {
                continue;
            }

            $hasColor = (bool) preg_match(COLOR_TOKEN, $value);

            /* Custom properties without a color (sizes, spacing) are not colors. */
            if (str_starts_with($property, '--') && !$hasColor) {
                continue;
            }

            $important = (bool) preg_match('/!\s*important\s*$/i', $value);
            $value = trim(preg_replace('/!\s*important\s*$/i', '', $value));
            $value = preg_replace('/\s+/', ' ', $value);

            /* The receipt page keeps its dark body text (it sits on white paper). */
            $receiptBodyText =
                $file === 'receipt.css' &&
                preg_match('/^body\b/', $selector) &&
                strtolower($property) === 'color';

            $light = ($keepAsIs || $receiptBodyText || !$hasColor)
                ? $value
                : lightValue($property, $value, $selector);

            $declarations[] = "{$pad}    {$property}: {$light}" . ($important ? ' !important' : '') . ';';
        }

        if ($declarations === []) {
            continue;
        }

        $stats['rules']++;
        $stats['declarations'] += count($declarations);

        $prefixed = str_replace("\n", "\n{$pad}", lightSelector($selector));
        $out .= "{$pad}{$prefixed} {\n" . implode("\n", $declarations) . "\n{$pad}}\n\n";
    }

    return $out;
}

/*
| MANUAL_RULES: hand-written light-mode details the builder cannot guess.
*/
const MANUAL_RULES = <<<'CSS'
    /* Native controls (date pickers, scrollbars, dropdowns) follow the theme. */
    html[data-theme="light"] {
        color-scheme: light;
    }

    /* The UA logo is white on black and drawn with a "screen" blend (black
       disappears). On light backgrounds: invert it and "multiply" (white
       disappears) so it shows as a black UA. */
    html[data-theme="light"] .sidebar-logo-image,
    html[data-theme="light"] .login-logo img {
        filter: invert(1);
        mix-blend-mode: multiply;
    }

    /* The dark photo behind every page gets a light wash instead. */
    html[data-theme="light"] .main-area {
        background:
            linear-gradient(rgba(244, 245, 247, .94), rgba(244, 245, 247, .97)),
            url('/assets/images/UA_bg.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
    }
CSS;

$generated = '';

foreach ($sources as $file => $css) {
    $block = buildItems(parseCss($css), $file, 1, $stats);

    if ($block !== '') {
        $generated .= "    /* ---- {$file} ---- */\n\n{$block}";
    }
}

$header = <<<'TXT'
/* =========================================================
   UA POS - LIGHT THEME (GENERATED FILE - DO NOT EDIT)

   Made by tools/build_light_theme.php from the dark stylesheets.
   Applies only when <html data-theme="light"> (top bar button),
   and only on screen: printouts keep their normal colors.
   To change light mode: edit the dark CSS or MANUAL_RULES in
   the tool, then run  C:\php\php.exe tools\build_light_theme.php
========================================================= */

@media screen {


TXT;

$output = $header . $generated . "    /* ---- manual rules (tools/build_light_theme.php) ---- */\n\n" . MANUAL_RULES . "\n\n}\n";

file_put_contents(OUTPUT, $output);

echo 'Light theme written: public/assets/css/theme-light.css' . PHP_EOL;
echo "{$stats['rules']} rules, {$stats['declarations']} color declarations, from " . count($sources) . ' sources.' . PHP_EOL;
