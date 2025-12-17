<?php
// debug_env.php

// 1. Gather all variables
// specific 'getenv()' call gets system-level envs that might not be in $_SERVER/$_ENV in some FPM setups
$all_vars = array_merge($_SERVER, $_ENV, getenv());
ksort($all_vars); // Sort alphabetically by key

// 2. Helper to mask secrets
function maskValue($key, $value) {
    $sensitive_terms = ['TOKEN', 'KEY', 'PASSWORD', 'SECRET', 'AUTH', 'CREDENTIAL'];
    
    foreach ($sensitive_terms as $term) {
        if (stripos($key, $term) !== false) {
            // Show first 4 chars, mask the rest
            $len = strlen($value);
            if ($len > 8) {
                return substr($value, 0, 4) . str_repeat('*', 12) . " (Masked)";
            }
            return "******** (Masked)";
        }
    }
    return htmlspecialchars($value);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Environment Variables</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 20px; background: #f4f4f4; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { border-bottom: 2px solid #eee; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #ddd; word-break: break-all; }
        th { background-color: #f8f9fa; font-weight: 600; color: #333; width: 30%; }
        tr:hover { background-color: #f1f1f1; }
        .empty { color: #999; font-style: italic; }
        .badge { background: #e0e0e0; padding: 2px 6px; border-radius: 4px; font-size: 11px; margin-right: 5px; }
    </style>
</head>
<body>

<div class="container">
    <h1>Environment Variables</h1>
    <p>Below is a list of all detected <code>$_SERVER</code>, <code>$_ENV</code>, and system variables.</p>

    <table>
        <thead>
            <tr>
                <th>Key Name</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_vars as $key => $value): ?>
                <tr>
                    <td>
                        <span class="badge">ENV</span> 
                        <?php echo htmlspecialchars($key); ?>
                    </td>
                    <td>
                        <?php 
                        if (empty($value) && $value !== '0') {
                            echo '<span class="empty">empty</span>';
                        } else {
                            echo maskValue($key, $value); 
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>