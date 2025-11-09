<?php
/**
 * Automated Script to Add Deprecation Notices
 * 
 * This script adds @deprecated notices to all legacy functions
 * 
 * Usage: php add-deprecation-notices.php
 */

$files_to_process = [
    'includes/rosters.php' => 'InterSoccer\\ReportsRosters\\UI\\Pages',
    'includes/reports.php' => 'InterSoccer\\ReportsRosters\\Reports',
    'includes/roster-data.php' => 'InterSoccer\\ReportsRosters\\Data\\Repositories\\RosterRepository',
    'includes/roster-details.php' => 'InterSoccer\\ReportsRosters\\UI\\Pages',
    'includes/roster-export.php' => 'InterSoccer\\ReportsRosters\\Export',
    'includes/reports-ajax.php' => 'InterSoccer\\ReportsRosters\\Ajax\\AjaxHandler',
    'includes/reports-data.php' => 'InterSoccer\\ReportsRosters\\Reports',
    'includes/reports-export.php' => 'InterSoccer\\ReportsRosters\\Export',
    'includes/reports-ui.php' => 'InterSoccer\\ReportsRosters\\UI',
    'includes/event-reports.php' => 'InterSoccer\\ReportsRosters\\Reports',
    'includes/utils.php' => 'InterSoccer\\ReportsRosters\\Utils',
    'includes/woocommerce-orders.php' => 'InterSoccer\\ReportsRosters\\WooCommerce',
    'includes/placeholder-rosters.php' => 'InterSoccer\\ReportsRosters\\Data\\Repositories',
    'includes/reporting-discounts.php' => 'InterSoccer\\ReportsRosters\\WooCommerce\\DiscountCalculator',
];

$total_functions = 0;
$total_updated = 0;

foreach ($files_to_process as $file => $oop_namespace) {
    if (!file_exists($file)) {
        echo "Skipping $file - not found\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    $new_lines = [];
    $updated_in_file = 0;
    
    for ($i = 0; $i < count($lines); $i++) {
        // Check if this is a function definition
        if (preg_match('/^function intersoccer_(\w+)\s*\(/', $lines[$i], $matches)) {
            // Check if previous line already has @deprecated
            $has_deprecation = false;
            for ($j = $i - 1; $j >= max(0, $i - 5); $j--) {
                if (strpos($lines[$j], '@deprecated') !== false) {
                    $has_deprecation = true;
                    break;
                }
            }
            
            if (!$has_deprecation) {
                // Add deprecation notice before the function
                $function_name = $matches[1];
                
                // Add doc block with deprecation
                $new_lines[] = "/**";
                $new_lines[] = " * @deprecated 2.0.0 Use {$oop_namespace}";
                $new_lines[] = " */";
                $updated_in_file++;
                $total_updated++;
            }
            
            $total_functions++;
        }
        
        $new_lines[] = $lines[$i];
    }
    
    // Write back if changes were made
    if ($updated_in_file > 0) {
        file_put_contents($file, implode("\n", $new_lines));
        echo "✅ $file: Added $updated_in_file deprecation notices\n";
    } else {
        echo "⏭️  $file: No changes needed\n";
    }
}

echo "\n";
echo "========================================\n";
echo "  DEPRECATION NOTICES ADDED\n";
echo "========================================\n";
echo "Total functions found: $total_functions\n";
echo "Deprecation notices added: $total_updated\n";
echo "========================================\n";

