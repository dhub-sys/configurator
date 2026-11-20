<?php
// php tests/render_preview.php <out-dir>  — γράφει HTML προεπισκοπήσεις των έτοιμων προτύπων με δείγμα δεδομένων.
define('_JEXEC', 1);
$base = __DIR__ . '/../components/com_koinoxrista/src/Helper/';
foreach (['Fmt', 'Calc', 'Render', 'Layouts'] as $c) require $base . $c . '.php';

use Koinoxrista\Component\Koinoxrista\Site\Helper\Layouts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Render;

$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
$css = file_get_contents(__DIR__ . '/../media/css/print.css');
$sample = Layouts::sample();
// Περισσότερες ιδιοκτησίες για έλεγχο σελιδοποίησης.
for ($i = 0; $i < 20; $i++) {
    $u = $sample['units'][$i % 10];
    $u['unit.code'] = 'X' . ($i + 1);
    $sample['units'][] = $u;
}
$cases = [
    'sheet-full' => ['sheet', Layouts::sheet(), ['mode' => 'full']],
    'sheet-data' => ['sheet', Layouts::sheet(), ['mode' => 'data', 'grid' => true]],
    'sheet-form' => ['sheet', Layouts::sheet(), ['mode' => 'form']],
    'notice-full' => ['notice', Layouts::notice(), ['mode' => 'full', 'draft' => true]],
];
foreach ($cases as $name => [$type, $layout, $opt]) {
    $layout = Layouts::sanitize(json_decode(json_encode($layout), true));
    $html = '<!doctype html><meta charset="utf-8"><style>body{margin:0;background:#e5e7eb}' . $css . '</style><div class="kx-pages">'
        . Render::document($type, $layout, $sample, $opt) . '</div>';
    file_put_contents("$out/$name.html", $html);
    echo "$out/$name.html\n";
}
