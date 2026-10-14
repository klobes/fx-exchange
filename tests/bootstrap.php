<?php

/*
 * Bootstrap i testeve të Emiron FX.
 *
 *  - ngarkon autoload-in e projektit Botble (platform/plugins/emiron-fx/tests → rrënja = 4 nivele sipër);
 *  - regjistron një autoloader rezervë për Botble\EmironFx\ (src/) dhe Botble\EmironFx\Tests\ (tests/),
 *    që testet Unit të punojnë edhe pa nisur aplikacionin (klasat pa varësi nga Laravel/DB).
 */

$pluginRoot = dirname(__DIR__);
$candidates = [
    dirname($pluginRoot, 3) . '/vendor/autoload.php', // .../platform/plugins/emiron-fx → rrënja e projektit
    $pluginRoot . '/vendor/autoload.php',
];

foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $maps = [
        'Botble\\EmironFx\\Tests\\' => $pluginRoot . '/tests/',
        'Botble\\EmironFx\\' => $pluginRoot . '/src/',
    ];

    foreach ($maps as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
