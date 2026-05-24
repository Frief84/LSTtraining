<?php
if (!defined('ABSPATH')) {
    exit;
}

function lsttraining_pick_random_anrufer_name(PDO $pdo, ?string $genderKey = null): array
{
    $allowed = ['male', 'female', 'neutral'];

    $sql = "
        SELECT id, gender_key, first_name, last_name
        FROM anrufer_name_pool
        WHERE enabled = 1
    ";
    $params = [];

    if ($genderKey !== null && $genderKey !== '' && in_array($genderKey, $allowed, true)) {
        $sql .= " AND gender_key = ? ";
        $params[] = $genderKey;
    }

    $sql .= " ORDER BY RAND() LIMIT 1 ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && is_array($row)) {
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'gender_key' => (string)($row['gender_key'] ?? 'neutral'),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
        ];
    }

    return [
        'id' => null,
        'gender_key' => 'neutral',
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
    ];
}

function lsttraining_build_anrufer_name_tokens(array $nameRow): array
{
    $genderKey = trim((string)($nameRow['gender_key'] ?? 'neutral'));
    $firstName = trim((string)($nameRow['first_name'] ?? ''));
    $lastName  = trim((string)($nameRow['last_name'] ?? ''));

    $title = '';
    if ($genderKey === 'male') {
        $title = 'Herr';
    } elseif ($genderKey === 'female') {
        $title = 'Frau';
    }

    $fullName = trim($firstName . ' ' . $lastName);
    $formalName = trim($title . ' ' . $lastName);
    $titleLastName = $formalName;

    if ($fullName === '') {
        $fullName = $formalName !== '' ? $formalName : 'Unbekannt';
    }

    return [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'full_name' => $fullName,
        'formal_name' => $formalName,
        'title_last_name' => $titleLastName,
        'gender_key' => $genderKey,
    ];
}

function lsttraining_fill_anrufer_placeholders(string $text, array $tokens): string
{
    $map = [
        '{first_name}'      => (string)($tokens['first_name'] ?? ''),
        '{last_name}'       => (string)($tokens['last_name'] ?? ''),
        '{full_name}'       => (string)($tokens['full_name'] ?? ''),
        '{formal_name}'     => (string)($tokens['formal_name'] ?? ''),
        '{title_last_name}' => (string)($tokens['title_last_name'] ?? ''),
        '{address_full}'    => (string)($tokens['address_full'] ?? ''),
        '{poi_name}'        => (string)($tokens['poi_name'] ?? ''),
        '{company_name}'    => (string)($tokens['company_name'] ?? ''),
        '{problem}'         => (string)($tokens['problem'] ?? ''),
        '{observation}'     => (string)($tokens['observation'] ?? ''),
        '{extra}'           => (string)($tokens['extra'] ?? ''),
        '{greeting}'        => (string)($tokens['greeting'] ?? ''),
        '{person}'          => (string)($tokens['person'] ?? ''),
        '{location}'        => (string)($tokens['location'] ?? ''),
    ];

    $result = strtr($text, $map);
    $result = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $matches) use ($tokens): string {
        $key = (string) ($matches[1] ?? '');
        return array_key_exists($key, $tokens) ? (string) $tokens[$key] : $matches[0];
    }, $result);
    $result = preg_replace('/\s+/', ' ', $result);
    return trim((string)$result);
}

function lsttraining_build_anrufer_tokens(PDO $pdo, ?string $genderKey = null, array $context = []): array
{
    $nameRow = lsttraining_pick_random_anrufer_name($pdo, $genderKey);
    $nameTokens = lsttraining_build_anrufer_name_tokens($nameRow);

    return array_merge($nameTokens, $context, [
        'address_full' => (string)($context['address_full'] ?? ''),
        'poi_name' => (string)($context['poi_name'] ?? ''),
        'company_name' => (string)($context['company_name'] ?? ''),
        'problem' => (string)($context['problem'] ?? ''),
        'observation' => (string)($context['observation'] ?? ''),
        'extra' => (string)($context['extra'] ?? ''),
    ]);
}
