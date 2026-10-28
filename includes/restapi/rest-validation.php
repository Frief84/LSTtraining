<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Gemeinsame, strikt ablehnende Eingabevalidierung fuer schreibende REST-APIs.
 * Die Funktionen geben nur kanonische Werte zurueck und werfen bei jeder
 * Abweichung eine InvalidArgumentException. Datenbankabfragen muessen
 * zusaetzlich weiterhin gebundene Parameter verwenden.
 */

function lsttraining_rest_is_list(array $value): bool {
    $index = 0;
    foreach ($value as $key => $_item) {
        if ($key !== $index) {
            return false;
        }
        $index++;
    }
    return true;
}

function lsttraining_rest_string_length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function lsttraining_rest_assert_safe_string(string $field, $value, int $max, bool $multiline = false): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' muss eine Zeichenkette sein.');
    }
    if (strlen($value) > max(4096, $max * 4)) {
        throw new InvalidArgumentException($field . ' ist zu lang.');
    }
    $checked = wp_check_invalid_utf8($value, true);
    if ($checked !== $value) {
        throw new InvalidArgumentException($field . ' enthaelt ungueltiges UTF-8.');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException($field . ' enthaelt unzulaessige Steuerzeichen.');
    }
    if (!$multiline && preg_match('/[\r\n]/', $value)) {
        throw new InvalidArgumentException($field . ' darf keinen Zeilenumbruch enthalten.');
    }
    if (preg_match('/[<>]|(?:javascript|vbscript)\s*:|data\s*:\s*(?:text\/html|image\/svg\+xml)|\bon[a-z]+\s*=/iu', $value)) {
        throw new InvalidArgumentException($field . ' enthaelt nicht erlaubte Code- oder Markupbestandteile.');
    }

    $normalized = $multiline ? sanitize_textarea_field($value) : sanitize_text_field($value);
    $normalized = trim($normalized);
    if (lsttraining_rest_string_length($normalized) > $max) {
        throw new InvalidArgumentException($field . ' ist zu lang.');
    }
    return $normalized;
}

function lsttraining_rest_strict_integer(string $field, $value, ?int $min = null, ?int $max = null): int {
    if (is_bool($value) || (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value)))) {
        throw new InvalidArgumentException($field . ' muss eine Ganzzahl sein.');
    }
    if (is_string($value)) {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            throw new InvalidArgumentException($field . ' liegt ausserhalb des Ganzzahlbereichs.');
        }
        $normalized = (int) $validated;
    } else {
        $normalized = $value;
    }
    if ($min !== null && $normalized < $min) {
        throw new InvalidArgumentException($field . ' ist zu klein.');
    }
    if ($max !== null && $normalized > $max) {
        throw new InvalidArgumentException($field . ' ist zu gross.');
    }
    return $normalized;
}

function lsttraining_rest_strict_number(string $field, $value, ?float $min = null, ?float $max = null): float {
    $numeric_string = is_string($value) && preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value);
    if (is_bool($value) || (!is_int($value) && !is_float($value) && !$numeric_string)) {
        throw new InvalidArgumentException($field . ' muss eine Zahl sein.');
    }
    $normalized = (float) $value;
    if (!is_finite($normalized)) {
        throw new InvalidArgumentException($field . ' muss eine endliche Zahl sein.');
    }
    if ($min !== null && $normalized < $min) {
        throw new InvalidArgumentException($field . ' ist zu klein.');
    }
    if ($max !== null && $normalized > $max) {
        throw new InvalidArgumentException($field . ' ist zu gross.');
    }
    return $normalized;
}

function lsttraining_rest_strict_boolean(string $field, $value): bool {
    if (!is_bool($value)) {
        throw new InvalidArgumentException($field . ' muss ein JSON-Boolean true oder false sein.');
    }
    return $value;
}

function lsttraining_rest_id_list(string $field, $value, int $max_items = 500): array {
    if (!is_array($value) || !lsttraining_rest_is_list($value)) {
        throw new InvalidArgumentException($field . ' muss eine JSON-Liste sein.');
    }
    if (count($value) > $max_items) {
        throw new InvalidArgumentException($field . ' enthaelt zu viele Eintraege.');
    }
    $ids = [];
    foreach ($value as $item) {
        $id = lsttraining_rest_strict_integer($field, $item, 1);
        $ids[$id] = true;
    }
    return array_map('intval', array_keys($ids));
}

function lsttraining_rest_json_input(string $field, $value, int $max_bytes = 5242880): array {
    if (is_string($value)) {
        if (strlen($value) > $max_bytes) {
            throw new InvalidArgumentException($field . ' ist zu gross.');
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException($field . ' enthaelt ungueltiges JSON.');
        }
        return $decoded;
    }
    if (!is_array($value)) {
        throw new InvalidArgumentException($field . ' muss ein JSON-Objekt oder eine JSON-Liste sein.');
    }
    return $value;
}

function lsttraining_rest_json_object(WP_REST_Request $request, array $allowed_fields, int $max_bytes = 5242880): array {
    $raw = (string) $request->get_body();
    if ($raw === '' || strlen($raw) > $max_bytes) {
        throw new InvalidArgumentException($raw === '' ? 'Ein JSON-Objekt ist erforderlich.' : 'Der JSON-Body ist zu gross.');
    }
    $trimmed = ltrim($raw);
    if ($trimmed === '' || $trimmed[0] !== '{') {
        throw new InvalidArgumentException('Der JSON-Body muss ein Objekt sein.');
    }
    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Der JSON-Body ist ungueltig.');
    }
    $unknown = array_values(array_diff(array_keys($payload), $allowed_fields));
    if ($unknown) {
        foreach ($unknown as $field) {
            if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]*$/D', $field)) {
                throw new InvalidArgumentException('Der JSON-Body enthaelt einen ungueltigen Feldnamen.');
            }
        }
        throw new InvalidArgumentException('Unbekannte Felder: ' . implode(', ', $unknown) . '.');
    }
    return $payload;
}

function lsttraining_rest_coordinate_pair(string $field, $value): string {
    $value = lsttraining_rest_assert_safe_string($field, $value, 50);
    if (!preg_match('/^(-?(?:\d+(?:\.\d*)?|\.\d+))\s*,\s*(-?(?:\d+(?:\.\d*)?|\.\d+))$/D', $value, $match)) {
        throw new InvalidArgumentException($field . ' muss das Format "Breitengrad, Laengengrad" verwenden.');
    }
    $lat = lsttraining_rest_strict_number($field, $match[1], -90, 90);
    $lon = lsttraining_rest_strict_number($field, $match[2], -180, 180);
    return rtrim(rtrim(sprintf('%.8F', $lat), '0'), '.') . ', ' . rtrim(rtrim(sprintf('%.8F', $lon), '0'), '.');
}

function lsttraining_rest_identifier(string $field, $value, int $max = 50): string {
    $value = lsttraining_rest_assert_safe_string($field, $value, $max);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value)) {
        throw new InvalidArgumentException($field . ' darf nur Buchstaben, Zahlen, Punkt, Doppelpunkt, Unterstrich und Bindestrich enthalten.');
    }
    return $value;
}

function lsttraining_rest_image_reference(string $field, $value, int $max = 255): string {
    $value = lsttraining_rest_assert_safe_string($field, $value, $max);
    if ($value === '') {
        return '';
    }
    if (strpos($value, '\\') !== false || strpos($value, "\0") !== false || preg_match('#(?:^|/)\.\.(?:/|$)#', $value)) {
        throw new InvalidArgumentException($field . ' enthaelt einen unzulaessigen Pfad.');
    }

    $is_absolute_url = preg_match('#^https?://#i', $value) === 1;
    if ($is_absolute_url) {
        $parts = wp_parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException($field . ' enthaelt keine erlaubte HTTP(S)-URL.');
        }
        $normalized = esc_url_raw($value, ['http', 'https']);
        if ($normalized === '') {
            throw new InvalidArgumentException($field . ' enthaelt keine gueltige URL.');
        }
        $path = (string) ($parts['path'] ?? '');
    } else {
        if (strpos($value, '//') === 0 || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) || !preg_match('#^/?[A-Za-z0-9._~/%+-]+$#D', $value)) {
            throw new InvalidArgumentException($field . ' enthaelt keinen erlaubten relativen Bildpfad.');
        }
        $normalized = $value;
        $path = $value;
    }

    $extension = strtolower((string) pathinfo((string) wp_parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true)) {
        throw new InvalidArgumentException($field . ' muss auf eine unterstuetzte Bilddatei verweisen.');
    }
    return $normalized;
}

function lsttraining_rest_trusted_image_reference(string $field, string $value, int $max = 255): string {
    $reference = lsttraining_rest_image_reference($field, $value, $max);
    if ($reference === '') {
        return '';
    }

    $plugin_root = realpath(LSTTRAINING_PATH);
    if (!preg_match('#^https?://#i', $reference) && !str_starts_with($reference, '/')) {
        foreach ([LSTTRAINING_PATH . $reference, LSTTRAINING_PATH . 'img/wachen/' . $reference] as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && $plugin_root !== false && str_starts_with($resolved, $plugin_root . DIRECTORY_SEPARATOR) && is_file($resolved)) {
                return $reference;
            }
        }
    }

    $uploads = wp_upload_dir();
    $safe_url_root = is_array($uploads) && !empty($uploads['baseurl'])
        ? trailingslashit((string) $uploads['baseurl']) . 'lsttraining-api-images/'
        : '';
    $safe_path_root = is_array($uploads) && !empty($uploads['basedir'])
        ? trailingslashit((string) $uploads['basedir']) . 'lsttraining-api-images'
        : '';
    if ($safe_url_root !== '' && str_starts_with($reference, $safe_url_root)) {
        $relative = rawurldecode(substr($reference, strlen($safe_url_root)));
        if ($relative !== '' && basename($relative) === $relative && !str_contains($relative, "\0")) {
            $resolved_root = realpath($safe_path_root);
            $resolved = realpath(trailingslashit($safe_path_root) . $relative);
            if ($resolved_root !== false && $resolved !== false && str_starts_with($resolved, $resolved_root . DIRECTORY_SEPARATOR) && is_file($resolved)) {
                return $reference;
            }
        }
    }

    throw new InvalidArgumentException($field . ' muss Bilddaten mitsenden oder auf ein vertrauenswuerdiges lokales Bild verweisen.');
}

function lsttraining_rest_svg_dimension(string $field, string $value): float {
    $value = trim($value);
    if (!preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px)?$/D', $value)) {
        throw new InvalidArgumentException($field . ' enthaelt eine ungueltige SVG-Abmessung.');
    }
    return lsttraining_rest_strict_number($field, preg_replace('/px$/', '', $value), 0.01, 4096);
}

function lsttraining_rest_sanitize_svg(string $field, string $binary): array {
    if (strlen($binary) > 2097152) {
        throw new InvalidArgumentException($field . ' darf als SVG hoechstens 2 MiB gross sein.');
    }
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
        throw new InvalidArgumentException($field . ' kann auf diesem Server nicht sicher als SVG bereinigt werden.');
    }
    if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $binary)) {
        throw new InvalidArgumentException($field . ' enthaelt nicht erlaubte XML-Deklarationen.');
    }

    $previous_errors = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadXML($binary, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);
    $root = $document->documentElement;
    if (!$loaded || !$root instanceof DOMElement || strtolower($root->localName) !== 'svg' || $document->doctype !== null) {
        throw new InvalidArgumentException($field . ' enthaelt kein gueltiges SVG-Dokument.');
    }
    $namespace = (string) $root->namespaceURI;
    if ($namespace !== '' && $namespace !== 'http://www.w3.org/2000/svg') {
        throw new InvalidArgumentException($field . ' verwendet einen ungueltigen SVG-Namensraum.');
    }

    $allowed_elements = array_fill_keys([
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'marker', 'title', 'desc',
    ], true);
    $allowed_attributes = array_fill_keys([
        'id', 'class', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'width', 'height', 'viewbox', 'preserveaspectratio', 'd', 'points', 'transform',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'opacity', 'display', 'visibility', 'font-family', 'font-size', 'font-weight',
        'text-anchor', 'dominant-baseline', 'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'clip-path', 'mask',
        'marker-start', 'marker-mid', 'marker-end', 'markerwidth', 'markerheight',
        'refx', 'refy', 'orient', 'version', 'xmlns',
    ], true);

    $xpath = new DOMXPath($document);
    $nodes = [];
    foreach ($xpath->query('//*') ?: [] as $node) {
        $nodes[] = $node;
    }
    if (!$nodes || count($nodes) > 5000) {
        throw new InvalidArgumentException($field . ' enthaelt zu viele SVG-Elemente.');
    }
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement || (!$node->parentNode && $node !== $root)) {
            continue;
        }
        $element_name = strtolower($node->localName);
        if (!isset($allowed_elements[$element_name])) {
            if ($node === $root) {
                throw new InvalidArgumentException($field . ' enthaelt kein erlaubtes SVG-Wurzelelement.');
            }
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
            continue;
        }

        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[] = $attribute;
        }
        if (count($attributes) > 64) {
            throw new InvalidArgumentException($field . ' enthaelt zu viele SVG-Attribute.');
        }
        foreach ($attributes as $attribute) {
            $attribute_name = strtolower($attribute->localName ?: $attribute->name);
            $attribute_value = trim((string) $attribute->value);
            $is_namespace = strtolower((string) $attribute->prefix) === 'xmlns' || strtolower($attribute->name) === 'xmlns';
            $unsafe = (!$is_namespace && !isset($allowed_attributes[$attribute_name]))
                || str_starts_with($attribute_name, 'on')
                || strlen($attribute_value) > 16384
                || (!$is_namespace && preg_match('/[\x00-\x1F\x7F<>]|(?:javascript|vbscript|data|https?)\s*:|\/\//iu', $attribute_value));
            if (!$unsafe && stripos($attribute_value, 'url(') !== false && !preg_match('/^url\(#[A-Za-z_][A-Za-z0-9_.:-]*\)$/D', $attribute_value)) {
                $unsafe = true;
            }
            if (!$unsafe && in_array($attribute_name, ['d', 'points', 'transform', 'gradienttransform'], true) && !preg_match('/^[0-9eE+.,\sMmLlHhVvCcSsQqTtAaZz()\-]*$/D', $attribute_value)) {
                $unsafe = true;
            }
            if (!$unsafe && in_array($attribute_name, ['id', 'class'], true) && !preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/D', $attribute_value)) {
                $unsafe = true;
            }
            if ($unsafe) {
                $node->removeAttributeNode($attribute);
            }
        }
    }

    $removable_nodes = [];
    foreach ($xpath->query('//comment() | //processing-instruction()') ?: [] as $node) {
        $removable_nodes[] = $node;
    }
    foreach ($removable_nodes as $node) {
        if ($node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }

    foreach ($xpath->query('//text()') ?: [] as $text_node) {
        $parent_name = $text_node->parentNode instanceof DOMElement ? strtolower($text_node->parentNode->localName) : '';
        if (!in_array($parent_name, ['text', 'tspan', 'title', 'desc'], true)) {
            if (trim((string) $text_node->nodeValue) !== '' && $text_node->parentNode) {
                $text_node->parentNode->removeChild($text_node);
            }
            continue;
        }
        try {
            $text_node->nodeValue = lsttraining_rest_assert_safe_string($field . '.text', (string) $text_node->nodeValue, 2000, true);
        } catch (InvalidArgumentException $e) {
            $text_node->nodeValue = '';
        }
    }

    $width = $root->hasAttribute('width') ? lsttraining_rest_svg_dimension($field . '.width', $root->getAttribute('width')) : 0.0;
    $height = $root->hasAttribute('height') ? lsttraining_rest_svg_dimension($field . '.height', $root->getAttribute('height')) : 0.0;
    if (($width <= 0 || $height <= 0) && $root->hasAttribute('viewBox')) {
        $view_box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        if (count($view_box) === 4) {
            $width = lsttraining_rest_strict_number($field . '.viewBox.width', $view_box[2], 0.01, 4096);
            $height = lsttraining_rest_strict_number($field . '.viewBox.height', $view_box[3], 0.01, 4096);
        }
    }
    if ($width <= 0 || $height <= 0 || ($width * $height) > 12000000) {
        throw new InvalidArgumentException($field . ' benoetigt gueltige SVG-Abmessungen bis 4096 x 4096 Pixel.');
    }

    $sanitized = $document->saveXML($root);
    if (!is_string($sanitized) || $sanitized === '' || preg_match('/<(?:script|foreignObject|iframe|object|embed|image|style|animate|set|use)\b|\bon[a-z]+\s*=|(?:javascript|vbscript|data)\s*:/iu', $sanitized)) {
        throw new InvalidArgumentException($field . ' konnte nicht vollstaendig von aktivem SVG-Code bereinigt werden.');
    }
    return ['binary' => $sanitized, 'width' => $width, 'height' => $height];
}

function lsttraining_rest_image_input(string $field, $value, int $max_reference_length = 255) {
    if (is_string($value)) {
        return lsttraining_rest_trusted_image_reference($field, $value, $max_reference_length);
    }
    if (!is_array($value) || lsttraining_rest_is_list($value)) {
        throw new InvalidArgumentException($field . ' muss eine Bildreferenz oder ein Bilddaten-Objekt sein.');
    }
    $unknown = array_diff(array_keys($value), ['filename', 'mime_type', 'data_base64']);
    if ($unknown || !isset($value['filename'], $value['mime_type'], $value['data_base64'])) {
        throw new InvalidArgumentException($field . ' erlaubt fuer Bilddaten nur filename, mime_type und data_base64.');
    }

    $filename = lsttraining_rest_assert_safe_string($field . '.filename', $value['filename'], 180);
    $declared_mime = lsttraining_rest_assert_safe_string($field . '.mime_type', $value['mime_type'], 32);
    $base64 = $value['data_base64'];
    if (!is_string($base64) || $base64 === '' || strlen($base64) > 13981016 || strlen($base64) % 4 !== 0 || !preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $base64)) {
        throw new InvalidArgumentException($field . '.data_base64 enthaelt keine gueltigen oder zu grosse Base64-Bilddaten.');
    }
    $binary = base64_decode($base64, true);
    if (!is_string($binary) || $binary === '' || strlen($binary) > 10485760) {
        throw new InvalidArgumentException($field . ' darf entpackt hoechstens 10 MiB gross sein.');
    }

    if ($declared_mime === 'image/svg+xml') {
        $sanitized_svg = lsttraining_rest_sanitize_svg($field, $binary);
        $basename = (string) pathinfo($filename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $basename) ?: 'image';
        $basename = trim($basename, '-_') ?: 'image';
        return [
            '_lsttraining_sanitized_image' => true,
            'filename' => substr($basename, 0, 120) . '.svg',
            'mime_type' => 'image/svg+xml',
            'width' => $sanitized_svg['width'],
            'height' => $sanitized_svg['height'],
            'binary' => $sanitized_svg['binary'],
        ];
    }

    $image_info = @getimagesizefromstring($binary);
    if (!is_array($image_info) || empty($image_info[0]) || empty($image_info[1]) || empty($image_info['mime'])) {
        throw new InvalidArgumentException($field . ' enthaelt keine erkennbare Rastergrafik.');
    }
    $actual_mime = strtolower((string) $image_info['mime']);
    $allowed = [
        'image/png' => ['extension' => 'png', 'encoder' => 'imagepng'],
        'image/jpeg' => ['extension' => 'jpg', 'encoder' => 'imagejpeg'],
        'image/gif' => ['extension' => 'gif', 'encoder' => 'imagegif'],
        'image/webp' => ['extension' => 'webp', 'encoder' => 'imagewebp'],
    ];
    if (!isset($allowed[$actual_mime]) || $declared_mime !== $actual_mime) {
        throw new InvalidArgumentException($field . ' erlaubt nur korrekt deklarierte PNG-, JPEG-, GIF- oder WebP-Bilder.');
    }
    $width = (int) $image_info[0];
    $height = (int) $image_info[1];
    if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || ($width * $height) > 12000000) {
        throw new InvalidArgumentException($field . ' ueberschreitet die erlaubten Bildabmessungen.');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists($allowed[$actual_mime]['encoder'])) {
        throw new InvalidArgumentException($field . ' kann auf diesem Server nicht sicher neu codiert werden.');
    }

    $image = @imagecreatefromstring($binary);
    if ($image === false) {
        throw new InvalidArgumentException($field . ' konnte nicht vollstaendig decodiert werden.');
    }
    if (function_exists('imagealphablending')) {
        imagealphablending($image, true);
    }
    if (function_exists('imagesavealpha')) {
        imagesavealpha($image, true);
    }

    ob_start();
    if ($actual_mime === 'image/jpeg') {
        $encoded = @imagejpeg($image, null, 90);
    } elseif ($actual_mime === 'image/png') {
        $encoded = @imagepng($image, null, 6);
    } elseif ($actual_mime === 'image/webp') {
        $encoded = @imagewebp($image, null, 90);
    } else {
        $encoded = @imagegif($image);
    }
    $sanitized_binary = ob_get_clean();
    imagedestroy($image);

    if (!$encoded || !is_string($sanitized_binary) || $sanitized_binary === '') {
        throw new InvalidArgumentException($field . ' konnte nicht sicher neu codiert werden.');
    }
    $sanitized_info = @getimagesizefromstring($sanitized_binary);
    if (!is_array($sanitized_info) || strtolower((string) ($sanitized_info['mime'] ?? '')) !== $actual_mime) {
        throw new InvalidArgumentException($field . ' hat die Sicherheits-Neucodierung nicht bestanden.');
    }

    $basename = (string) pathinfo($filename, PATHINFO_FILENAME);
    $basename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $basename) ?: 'image';
    $basename = trim($basename, '-_');
    if ($basename === '') {
        $basename = 'image';
    }
    return [
        '_lsttraining_sanitized_image' => true,
        'filename' => substr($basename, 0, 120) . '.' . $allowed[$actual_mime]['extension'],
        'mime_type' => $actual_mime,
        'width' => $width,
        'height' => $height,
        'binary' => $sanitized_binary,
    ];
}

function lsttraining_rest_store_sanitized_image(string $field, array $prepared): array {
    if (empty($prepared['_lsttraining_sanitized_image']) || !isset($prepared['filename'], $prepared['binary'], $prepared['mime_type'])) {
        throw new InvalidArgumentException($field . ' enthaelt kein vorbereitetes Bild.');
    }
    $uploads = wp_upload_dir();
    if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
        throw new RuntimeException('Das WordPress-Uploadverzeichnis ist nicht verfuegbar.');
    }
    $directory = trailingslashit((string) $uploads['basedir']) . 'lsttraining-api-images';
    $base_url = trailingslashit((string) $uploads['baseurl']) . 'lsttraining-api-images';
    if (!wp_mkdir_p($directory)) {
        throw new RuntimeException('Das sichere Bildverzeichnis konnte nicht angelegt werden.');
    }
    $filename = wp_unique_filename($directory, sanitize_file_name((string) $prepared['filename']));
    $path = trailingslashit($directory) . $filename;
    $written = file_put_contents($path, (string) $prepared['binary'], LOCK_EX);
    if ($written === false || $written !== strlen((string) $prepared['binary'])) {
        if (is_file($path)) {
            wp_delete_file($path);
        }
        throw new RuntimeException('Das sicherheitsbereinigte Bild konnte nicht gespeichert werden.');
    }
    @chmod($path, 0644);
    $check = @getimagesize($path);
    $valid_saved_file = (string) $prepared['mime_type'] === 'image/svg+xml'
        ? (lsttraining_rest_sanitize_svg($field, (string) file_get_contents($path))['binary'] === (string) $prepared['binary'])
        : (is_array($check) && strtolower((string) ($check['mime'] ?? '')) === (string) $prepared['mime_type']);
    if (!$valid_saved_file) {
        wp_delete_file($path);
        throw new RuntimeException('Das gespeicherte Bild hat die Abschlusspruefung nicht bestanden.');
    }
    return [
        'reference' => esc_url_raw(trailingslashit($base_url) . rawurlencode($filename), ['http', 'https']),
        'path' => $path,
    ];
}

function lsttraining_rest_safe_json_value(string $field, $value, int $depth = 0) {
    if ($depth > 5) {
        throw new InvalidArgumentException($field . ' ist zu tief verschachtelt.');
    }
    if ($value === null || is_bool($value) || is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        if (!is_finite($value)) {
            throw new InvalidArgumentException($field . ' enthaelt eine ungueltige Zahl.');
        }
        return $value;
    }
    if (is_string($value)) {
        return lsttraining_rest_assert_safe_string($field, $value, 1000, true);
    }
    if (!is_array($value) || count($value) > 200) {
        throw new InvalidArgumentException($field . ' enthaelt eine ungueltige oder zu grosse JSON-Struktur.');
    }
    $normalized = [];
    foreach ($value as $key => $item) {
        if (!is_int($key)) {
            $key = lsttraining_rest_assert_safe_string($field . '.key', (string) $key, 100);
        }
        $normalized[$key] = lsttraining_rest_safe_json_value($field, $item, $depth + 1);
    }
    return $normalized;
}

function lsttraining_rest_geojson_position(string $field, $value, int &$coordinate_count): array {
    if (!is_array($value) || !lsttraining_rest_is_list($value) || count($value) < 2 || count($value) > 3) {
        throw new InvalidArgumentException($field . ' enthaelt eine ungueltige Koordinate.');
    }
    $coordinate_count++;
    if ($coordinate_count > 100000) {
        throw new InvalidArgumentException($field . ' enthaelt zu viele Koordinaten.');
    }
    $position = [
        lsttraining_rest_strict_number($field . '.longitude', $value[0], -180, 180),
        lsttraining_rest_strict_number($field . '.latitude', $value[1], -90, 90),
    ];
    if (isset($value[2])) {
        $position[] = lsttraining_rest_strict_number($field . '.altitude', $value[2], -20000, 100000);
    }
    return $position;
}

function lsttraining_rest_geojson_polygon(string $field, $coordinates, int &$coordinate_count): array {
    if (!is_array($coordinates) || !lsttraining_rest_is_list($coordinates) || !$coordinates || count($coordinates) > 1000) {
        throw new InvalidArgumentException($field . ' enthaelt keine gueltigen Polygonringe.');
    }
    $rings = [];
    foreach ($coordinates as $ring_index => $ring) {
        if (!is_array($ring) || !lsttraining_rest_is_list($ring) || count($ring) < 4) {
            throw new InvalidArgumentException($field . ' enthaelt einen zu kurzen Polygonring.');
        }
        $normalized_ring = [];
        foreach ($ring as $position) {
            $normalized_ring[] = lsttraining_rest_geojson_position($field, $position, $coordinate_count);
        }
        $first = $normalized_ring[0];
        $last = $normalized_ring[count($normalized_ring) - 1];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            throw new InvalidArgumentException($field . ' enthaelt einen nicht geschlossenen Polygonring.');
        }
        $rings[] = $normalized_ring;
    }
    return $rings;
}

function lsttraining_rest_geojson_geometry(string $field, $geometry, int &$coordinate_count): array {
    if (!is_array($geometry) || lsttraining_rest_is_list($geometry)) {
        throw new InvalidArgumentException($field . ' enthaelt keine gueltige Geometrie.');
    }
    $unknown = array_diff(array_keys($geometry), ['type', 'coordinates', 'bbox']);
    if ($unknown) {
        throw new InvalidArgumentException($field . ' enthaelt unbekannte Geometriefelder.');
    }
    $type = $geometry['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['Polygon', 'MultiPolygon'], true)) {
        throw new InvalidArgumentException($field . ' erlaubt nur Polygon oder MultiPolygon.');
    }
    $coordinates = $geometry['coordinates'] ?? null;
    if ($type === 'Polygon') {
        return ['type' => 'Polygon', 'coordinates' => lsttraining_rest_geojson_polygon($field, $coordinates, $coordinate_count)];
    }
    if (!is_array($coordinates) || !lsttraining_rest_is_list($coordinates) || !$coordinates || count($coordinates) > 1000) {
        throw new InvalidArgumentException($field . ' enthaelt kein gueltiges MultiPolygon.');
    }
    $polygons = [];
    foreach ($coordinates as $polygon) {
        $polygons[] = lsttraining_rest_geojson_polygon($field, $polygon, $coordinate_count);
    }
    return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
}

function lsttraining_rest_geojson_feature(string $field, $feature, int &$coordinate_count): array {
    if (!is_array($feature) || lsttraining_rest_is_list($feature) || ($feature['type'] ?? null) !== 'Feature') {
        throw new InvalidArgumentException($field . ' enthaelt kein gueltiges Feature.');
    }
    $unknown = array_diff(array_keys($feature), ['type', 'geometry', 'properties', 'id', 'bbox']);
    if ($unknown) {
        throw new InvalidArgumentException($field . ' enthaelt unbekannte Feature-Felder.');
    }
    $normalized = [
        'type' => 'Feature',
        'geometry' => lsttraining_rest_geojson_geometry($field . '.geometry', $feature['geometry'] ?? null, $coordinate_count),
        'properties' => null,
    ];
    if (isset($feature['properties'])) {
        if (!is_array($feature['properties']) || ($feature['properties'] && lsttraining_rest_is_list($feature['properties']))) {
            throw new InvalidArgumentException($field . '.properties muss ein JSON-Objekt sein.');
        }
        $normalized['properties'] = lsttraining_rest_safe_json_value($field . '.properties', $feature['properties']);
    }
    if (array_key_exists('id', $feature)) {
        if (is_int($feature['id'])) {
            $normalized['id'] = $feature['id'];
        } else {
            $normalized['id'] = lsttraining_rest_assert_safe_string($field . '.id', $feature['id'], 100);
        }
    }
    return $normalized;
}

function lsttraining_rest_geojson(string $field, $value): array {
    $decoded = lsttraining_rest_json_input($field, $value);
    if (lsttraining_rest_is_list($decoded)) {
        throw new InvalidArgumentException($field . ' muss ein GeoJSON-Objekt sein.');
    }
    $coordinate_count = 0;
    $type = $decoded['type'] ?? null;
    if ($type === 'FeatureCollection') {
        $unknown = array_diff(array_keys($decoded), ['type', 'features', 'bbox', 'name']);
        if ($unknown || !isset($decoded['features']) || !is_array($decoded['features']) || !lsttraining_rest_is_list($decoded['features']) || !$decoded['features'] || count($decoded['features']) > 5000) {
            throw new InvalidArgumentException($field . ' enthaelt keine gueltige FeatureCollection.');
        }
        $features = [];
        foreach ($decoded['features'] as $index => $feature) {
            $features[] = lsttraining_rest_geojson_feature($field . '.features[' . $index . ']', $feature, $coordinate_count);
        }
        $normalized = ['type' => 'FeatureCollection', 'features' => $features];
        if (isset($decoded['name'])) {
            $normalized['name'] = lsttraining_rest_assert_safe_string($field . '.name', $decoded['name'], 255);
        }
        return $normalized;
    }
    if ($type === 'Feature') {
        return lsttraining_rest_geojson_feature($field, $decoded, $coordinate_count);
    }
    if (in_array($type, ['Polygon', 'MultiPolygon'], true)) {
        return lsttraining_rest_geojson_geometry($field, $decoded, $coordinate_count);
    }
    throw new InvalidArgumentException($field . ' erlaubt nur FeatureCollection, Feature, Polygon oder MultiPolygon.');
}

function lsttraining_rest_department_codes(): array {
    static $codes = null;
    if ($codes !== null) {
        return $codes;
    }
    $codes = [];
    $path = LSTTRAINING_PATH . 'data/departments.json';
    $decoded = is_readable($path) ? json_decode((string) file_get_contents($path), true) : [];
    foreach (is_array($decoded) ? array_keys($decoded) : [] as $code) {
        $codes[strtoupper((string) $code)] = true;
    }
    return $codes;
}

function lsttraining_rest_department_code(string $field, $value): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' enthaelt einen ungueltigen Fachbereichscode.');
    }
    $code = strtoupper(lsttraining_rest_assert_safe_string($field, $value, 16));
    $allowed_codes = lsttraining_rest_department_codes();
    if (!preg_match('/^[A-Z0-9_]+$/D', $code) || !isset($allowed_codes[$code])) {
        throw new InvalidArgumentException($field . ' enthaelt einen unbekannten Fachbereichscode.');
    }
    return $code;
}

function lsttraining_rest_department_location(string $field, $value): array {
    if (!is_array($value) || lsttraining_rest_is_list($value)) {
        throw new InvalidArgumentException($field . ' muss ein Koordinatenobjekt sein.');
    }
    $unknown = array_diff(array_keys($value), ['Lat', 'Long']);
    if ($unknown || !array_key_exists('Lat', $value) || !array_key_exists('Long', $value)) {
        throw new InvalidArgumentException($field . ' erlaubt nur Lat und Long.');
    }
    return [
        'Lat' => lsttraining_rest_strict_number($field . '.Lat', $value['Lat'], -90, 90),
        'Long' => lsttraining_rest_strict_number($field . '.Long', $value['Long'], -180, 180),
    ];
}

function lsttraining_rest_departments(string $field, $value): array {
    $decoded = lsttraining_rest_json_input($field, $value, 262144);
    if (count($decoded) > 100) {
        throw new InvalidArgumentException($field . ' enthaelt zu viele Fachbereiche.');
    }
    $items = [];
    $seen = [];
    $append = static function (string $code, ?array $location) use (&$items, &$seen): void {
        if (isset($seen[$code])) {
            throw new InvalidArgumentException('departments enthaelt doppelte Fachbereichscodes.');
        }
        $seen[$code] = true;
        $items[] = $location === null ? $code : [$code => $location];
    };

    if (!lsttraining_rest_is_list($decoded)) {
        foreach ($decoded as $code => $location) {
            $normalized_code = lsttraining_rest_department_code($field, (string) $code);
            $append($normalized_code, lsttraining_rest_department_location($field . '.' . $normalized_code, $location));
        }
        return $items;
    }

    foreach ($decoded as $index => $item) {
        if (is_string($item)) {
            $append(lsttraining_rest_department_code($field . '[' . $index . ']', $item), null);
            continue;
        }
        if (!is_array($item) || lsttraining_rest_is_list($item) || count($item) !== 1) {
            throw new InvalidArgumentException($field . ' enthaelt einen ungueltigen Fachbereichseintrag.');
        }
        $code = (string) array_key_first($item);
        $normalized_code = lsttraining_rest_department_code($field . '[' . $index . ']', $code);
        $append($normalized_code, lsttraining_rest_department_location($field . '[' . $index . '].' . $normalized_code, $item[$code]));
    }
    return $items;
}

function lsttraining_rest_signal_lights(string $field, $value): ?array {
    $decoded = lsttraining_rest_json_input($field, $value, 65536);
    if (lsttraining_rest_is_list($decoded)) {
        $lights = $decoded;
    } else {
        $unknown = array_diff(array_keys($decoded), ['version', 'lights']);
        if ($unknown || !isset($decoded['lights']) || !is_array($decoded['lights']) || !lsttraining_rest_is_list($decoded['lights'])) {
            throw new InvalidArgumentException($field . ' enthaelt keine gueltige Signallicht-Konfiguration.');
        }
        if (array_key_exists('version', $decoded) && lsttraining_rest_strict_integer($field . '.version', $decoded['version'], 1, 1) !== 1) {
            throw new InvalidArgumentException($field . ' verwendet eine unbekannte Version.');
        }
        $lights = $decoded['lights'];
    }
    if (count($lights) > 64) {
        throw new InvalidArgumentException($field . ' enthaelt zu viele Signallichter.');
    }
    $normalized = [];
    foreach ($lights as $index => $light) {
        if (!is_array($light) || lsttraining_rest_is_list($light)) {
            throw new InvalidArgumentException($field . ' enthaelt einen ungueltigen Lichtpunkt.');
        }
        $unknown = array_diff(array_keys($light), ['x', 'y', 'type', 'interval', 'phase', 'size']);
        if ($unknown || !array_key_exists('x', $light) || !array_key_exists('y', $light)) {
            throw new InvalidArgumentException($field . ' enthaelt ungueltige Lichtpunkt-Felder.');
        }
        $type = array_key_exists('type', $light) ? $light['type'] : 'beacon';
        if (!is_string($type) || !in_array($type, ['beacon', 'strobe', 'bar', 'glow'], true)) {
            throw new InvalidArgumentException($field . '[' . $index . '].type ist ungueltig.');
        }
        $normalized[] = [
            'x' => lsttraining_rest_strict_number($field . '[' . $index . '].x', $light['x'], 0, 1),
            'y' => lsttraining_rest_strict_number($field . '[' . $index . '].y', $light['y'], 0, 1),
            'type' => $type,
            'interval' => array_key_exists('interval', $light) ? lsttraining_rest_strict_integer($field . '[' . $index . '].interval', $light['interval'], 120, 2000) : 420,
            'phase' => array_key_exists('phase', $light) ? lsttraining_rest_strict_integer($field . '[' . $index . '].phase', $light['phase'], 0, 5000) : 0,
            'size' => array_key_exists('size', $light) ? lsttraining_rest_strict_number($field . '[' . $index . '].size', $light['size'], 0.4, 2.5) : 1.0,
        ];
    }
    return $normalized ? ['version' => 1, 'lights' => $normalized] : null;
}
