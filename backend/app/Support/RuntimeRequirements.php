<?php

namespace App\Support;

class RuntimeRequirements
{
    // PHP's 21 October 2026 critical RCE security release floors.
    // https://www.php.net/archive/2026.php
    // Recheck upstream advisories at every release; this is not an evergreen security guarantee.
    public static function patchedPhp(string $version): bool
    {
        foreach (['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'] as $branch => $minimum) {
            if (str_starts_with($version, $branch.'.')) {
                return version_compare($version, $minimum, '>=');
            }
        }

        return false; // An unreviewed new branch needs compatibility/security verification first.
    }
}
