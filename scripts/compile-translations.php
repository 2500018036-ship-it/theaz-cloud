<?php
// Rebuild the browser catalog after changing App.php or UI.php in either locale.
$root = dirname(__DIR__);
$flatten = static function (array $tree, string $prefix = '') use (&$flatten): array {
    $result = [];
    foreach ($tree as $key => $value) {
        if (is_array($value)) $result += $flatten($value, $prefix . $key . '.');
        else $result[$prefix . $key] = (string) $value;
    }
    return $result;
};
$catalog = ['en' => [], 'id' => []];
foreach (['UI', 'App'] as $file) {
    $english = $flatten(include $root . '/app/Language/en/' . $file . '.php');
    $indonesian = $flatten(include $root . '/app/Language/id/' . $file . '.php');
    foreach ($english as $key => $value) {
        $catalog['en'][$value] = $value;
        $catalog['id'][$value] = $indonesian[$key] ?? $value;
    }
}
file_put_contents($root . '/public/assets/js/translations.js', 'window.CloudTranslations=' . json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR) . ";\n");
echo "Browser translation catalog rebuilt.\n";
