<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin or Inventory can add categories.']);
    exit;
}

$categoryName = trim((string)($_POST['category_name'] ?? ''));
if ($categoryName === '') {
    echo json_encode(['status' => 'error', 'message' => 'Category name is required.']);
    exit;
}

function category_code_from_name($name) {
    $code = strtolower(trim((string)$name));
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);
    $code = trim($code, '_');
    return $code !== '' ? $code : 'category';
}

$categoryFile = dirname(__DIR__) . '/data/item_categories.json';
$categoryDir = dirname($categoryFile);

if (!is_dir($categoryDir) && !mkdir($categoryDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to create category storage.']);
    exit;
}

$defaultCategories = [
    ['code' => 'fab', 'name' => 'Fabric'],
    ['code' => 'threads', 'name' => 'Threads'],
    ['code' => 'acc', 'name' => 'Accessories']
];

$categories = $defaultCategories;
if (is_readable($categoryFile)) {
    $decoded = json_decode((string)file_get_contents($categoryFile), true);
    if (is_array($decoded)) {
        $categories = $decoded;
    }
}

foreach ($categories as $category) {
    if (strcasecmp($category['name'] ?? '', $categoryName) === 0) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Category already exists.',
            'category' => [
                'code' => $category['code'] ?? category_code_from_name($categoryName),
                'name' => $category['name'] ?? $categoryName
            ]
        ]);
        exit;
    }
}

$baseCode = category_code_from_name($categoryName);
$code = $baseCode;
$existingCodes = array_map(function($category) {
    return strtolower((string)($category['code'] ?? ''));
}, $categories);
$counter = 2;
while (in_array(strtolower($code), $existingCodes, true)) {
    $code = $baseCode . '_' . $counter;
    $counter++;
}

$newCategory = ['code' => $code, 'name' => $categoryName];
$categories[] = $newCategory;
usort($categories, function($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$json = json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($categoryFile, $json . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to save category.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Category saved.',
    'category' => $newCategory
]);
