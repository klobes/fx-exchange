<?php

namespace Botble\EmironFx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * config/permissions.php: struktura e vlefshme (0.7.3: thellësia ≤ 4, përndryshe System → Roles jep 500)
 * dhe përputhja me përdorimin real (çdo leje e përdorur ekziston; asnjë leje e vdekur).
 *
 * "Përdorim" = `'permission' => 'fx.x'` (route), `fx_can('fx.x')` (controller/pamje) ose
 * lista e lejeve të një zëri menyje `['fx.x']]` (EmironFxServiceProvider).
 */
class PermissionsConfigTest extends TestCase
{
    private string $root;

    /** @var array<int, array{name:string, flag:string, parent_flag?:string}> */
    private array $permissions;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->permissions = require $this->root . '/config/permissions.php';
    }

    /** @return array<string,string|null> flag => prindi */
    private function parents(): array
    {
        $out = [];

        foreach ($this->permissions as $p) {
            $out[$p['flag']] = $p['parent_flag'] ?? null;
        }

        return $out;
    }

    /** @return array<string,string> flag => një skedar ku përdoret */
    private function usedFlags(): array
    {
        $used = [];
        $patterns = [
            "/'permission'\s*=>\s*'(fx(?:\.[a-z0-9_\-]+)+)'/",
            "/fx_can\(\s*'(fx(?:\.[a-z0-9_\-]+)+)'\s*\)/",
            "/\['(fx(?:\.[a-z0-9_\-]+)+)'\]\]/",
        ];

        foreach (['src', 'routes', 'resources/views', 'helpers'] as $dir) {
            $path = $this->root . '/' . $dir;

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $code, $m)) {
                        foreach ($m[1] as $flag) {
                            $used[$flag] = $file->getPathname();
                        }
                    }
                }
            }
        }

        return $used;
    }

    public function test_flags_are_unique_and_named(): void
    {
        $flags = array_column($this->permissions, 'flag');

        $this->assertSame(array_values(array_unique($flags)), $flags, 'Flamuj të dublikuar');

        foreach ($this->permissions as $p) {
            $this->assertNotSame('', trim($p['name']), "Leja {$p['flag']} s'ka emër");
            $this->assertMatchesRegularExpression('/^fx(\.[a-z0-9_\-]+)*$/', $p['flag']);
        }
    }

    public function test_parents_exist_and_are_defined_before_children(): void
    {
        $seen = [];

        foreach ($this->permissions as $p) {
            if (isset($p['parent_flag'])) {
                $this->assertContains($p['parent_flag'], $seen, "Prindi {$p['parent_flag']} i {$p['flag']} duhet të jetë përcaktuar para tij");
            }

            $seen[] = $p['flag'];
        }
    }

    public function test_depth_never_exceeds_four_levels(): void
    {
        $parents = $this->parents();

        foreach (array_keys($parents) as $flag) {
            $depth = 1;
            $cursor = $flag;

            while (($parents[$cursor] ?? null) !== null) {
                $depth++;
                $cursor = $parents[$cursor];
                $this->assertLessThan(10, $depth, "Cikël te {$flag}");
            }

            $this->assertLessThanOrEqual(4, $depth, "{$flag} ka thellësi {$depth}: pamja e roleve të Botble-s ka gabim nga niveli 5");
        }
    }

    public function test_every_permission_used_in_routes_code_and_menu_is_declared(): void
    {
        $declared = array_keys($this->parents());
        $missing = [];

        foreach ($this->usedFlags() as $flag => $file) {
            if (! in_array($flag, $declared, true)) {
                $missing[] = $flag . ' (' . basename($file) . ')';
            }
        }

        $this->assertSame([], $missing, "Leje të përdorura por të padeklaruara te permissions.php:\n" . implode("\n", $missing));
    }

    public function test_no_dead_permissions(): void
    {
        $used = array_keys($this->usedFlags());
        $dead = [];

        foreach (array_keys($this->parents()) as $flag) {
            if ($flag === 'fx' || str_starts_with($flag, 'fx.group.')) {
                continue; // prindër vizualë
            }

            if (! in_array($flag, $used, true)) {
                $dead[] = $flag;
            }
        }

        $this->assertSame([], $dead, 'Leje të deklaruara që s\'përdoren askund: ' . implode(', ', $dead));
    }

    public function test_dashboard_blocks_require_their_own_permissions(): void
    {
        $controller = (string) file_get_contents($this->root . '/src/Http/Controllers/FxDashboardController.php');

        // 0.7.1: paneli s'duhet të shfaqë arkat/financat e të tjerëve vetëm me fx.dashboard.index.
        foreach (['fx.pnl.index', 'fx.sessions.index', 'fx.bank.index'] as $flag) {
            $this->assertStringContainsString("fx_can('{$flag}')", $controller, "Paneli duhet të kontrollojë {$flag}");
        }
    }
}
