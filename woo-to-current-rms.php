<?php
/**
 * Plugin Name: Woo to Current RMS Toolkit
 * Description: Full-featured plugin to sync WooCommerce orders to Current RMS with admin settings, product mapping, and logging.
 * Version: 2.0
 * Author: dwmedia / Daniel Wilkinson
 */

if (!defined('ABSPATH')) exit;

// --- SETTINGS PAGE ---

add_action('admin_menu', function() {
    add_menu_page('Woo to RMS Toolkit', 'Woo → RMS', 'manage_options', 'woo_rms_toolkit', 'woo_rms_toolkit_settings_page');
    add_submenu_page('woo_rms_toolkit', 'Settings', 'Settings', 'manage_options', 'woo_rms_toolkit', 'woo_rms_toolkit_settings_page');
    add_submenu_page('woo_rms_toolkit', 'Product Mapping', 'Product Mapping', 'manage_options', 'woo_rms_toolkit_mapping', 'woo_rms_toolkit_mapping_page');
    add_submenu_page('woo_rms_toolkit', 'Debug Log', 'Debug Log', 'manage_options', 'woo_rms_toolkit_debug', 'woo_rms_toolkit_debug_page');
});

function woo_rms_toolkit_settings_page() {
    if (isset($_POST['woo_rms_settings_nonce']) && wp_verify_nonce($_POST['woo_rms_settings_nonce'], 'woo_rms_save_settings')) {
        update_option('woo_rms_token', sanitize_text_field($_POST['woo_rms_token']));
        update_option('woo_rms_subdomain', sanitize_text_field($_POST['woo_rms_subdomain']));
        update_option('woo_rms_org_id', intval($_POST['woo_rms_org_id']));
        update_option('woo_rms_owner_id', intval($_POST['woo_rms_owner_id']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $token = get_option('woo_rms_token', '');
    $sub = get_option('woo_rms_subdomain', '');
    $org = get_option('woo_rms_org_id', 14);
    $owner = get_option('woo_rms_owner_id', 1);
    ?>
    <div class="wrap">
        <h1>Woo to RMS Settings</h1>
        <form method="post">
            <?php wp_nonce_field('woo_rms_save_settings', 'woo_rms_settings_nonce'); ?>
            <table class="form-table">
                <tr><th><label>API Token</label></th><td><input type="text" name="woo_rms_token" value="<?php echo esc_attr($token); ?>" size="50" /></td></tr>
                <tr><th><label>Subdomain</label></th><td><input type="text" name="woo_rms_subdomain" value="<?php echo esc_attr($sub); ?>" /></td></tr>
                <tr><th><label>Fallback Org ID</label></th><td><input type="number" name="woo_rms_org_id" value="<?php echo esc_attr($org); ?>" /></td></tr>
                <tr><th><label>Owner ID</label></th><td><input type="number" name="woo_rms_owner_id" value="<?php echo esc_attr($owner); ?>" /></td></tr>
            </table>
            <p><input type="submit" class="button-primary" value="Save Settings" /></p>
        </form>
    </div>
    <?php
}

function woo_rms_toolkit_mapping_page() {
    if (isset($_POST['woo_rms_mapping_nonce']) && wp_verify_nonce($_POST['woo_rms_mapping_nonce'], 'woo_rms_save_mapping')) {
        $mapping = [];
        foreach ($_POST['woo_ids'] as $i => $woo_id) {
            if (!empty($woo_id) && !empty($_POST['rms_ids'][$i])) {
                $mapping[intval($woo_id)] = sanitize_text_field($_POST['rms_ids'][$i]);
            }
        }
        update_option('wc_to_rms_product_map', $mapping);
        echo '<div class="updated"><p>Product mappings saved.</p></div>';
    }

    $map = get_option('wc_to_rms_product_map', []);
    ?>
    <div class="wrap">
        <h1>Product Mapping</h1>
        <form method="post">
            <?php wp_nonce_field('woo_rms_save_mapping', 'woo_rms_mapping_nonce'); ?>
            <table class="widefat">
                <thead><tr><th>Woo Product ID</th><th>RMS Product ID</th></tr></thead>
                <tbody>
                <?php foreach ($map as $woo_id => $rms_id): ?>
                    <tr>
                        <td><input type="number" name="woo_ids[]" value="<?php echo esc_attr($woo_id); ?>" /></td>
                        <td><input type="text" name="rms_ids[]" value="<?php echo esc_attr($rms_id); ?>" /></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <tr>
                        <td><input type="number" name="woo_ids[]" /></td>
                        <td><input type="text" name="rms_ids[]" /></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <p><input type="submit" class="button-primary" value="Save Mapping" /></p>
        </form>
    </div>
    <?php
}

function woo_rms_toolkit_debug_page() {
    $log_path = WP_CONTENT_DIR . '/debug.log';
    echo '<div class="wrap"><h1>Debug Log</h1><textarea style="width:100%;height:500px;">';
    if (file_exists($log_path)) {
        echo esc_textarea(file_get_contents($log_path));
    } else {
        echo 'No debug log found.';
    }
    echo '</textarea></div>';
}

// --- ORDER HANDLING ---

add_action('woocommerce_thankyou', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $log = function($msg) use ($order_id) {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[{$timestamp}] Order {$order_id}: {$msg}
", 3, WP_CONTENT_DIR . '/debug.log');
    };

    $log("🔁 Hook triggered");

    $token = get_option('woo_rms_token');
    $subdomain = get_option('woo_rms_subdomain');
    $org_id = get_option('woo_rms_org_id', 14);
    $owner_id = get_option('woo_rms_owner_id', 1);

    if (!$token || !$subdomain) {
        $log("❌ Missing token or subdomain.");
        return;
    }

    $headers = [
        'X-AUTH-TOKEN' => $token,
        'X-SUBDOMAIN' => $subdomain,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
    ];

    $items = [];
    foreach ($order->get_items() as $item) {
        $woo_id = $item->get_product_id();
        $rms_id = dw_safe_rms_get_product_map($woo_id);
        if ($rms_id) {
            $log("✅ Mapped Woo ID {$woo_id} to RMS ID {$rms_id}");
            $items[] = [
                'product_id' => $rms_id,
                'quantity' => $item->get_quantity()
            ];
        }
    }

    if (empty($items)) {
        $log("🛑 No valid items found. Aborting.");
        return;
    }

    $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . ' - Order #' . $order_id;
    $payload = [
        'opportunity' => [
            'name' => $name,
            'subject' => $name,
            'description' => 'Auto-created from WooCommerce',
            'organization_id' => strval($org_id),
            'starts_at' => date('Y-m-d'),
            'ends_at' => date('Y-m-d', strtotime('+1 day')),
            'owned_by' => intval($owner_id),
            'participants_attributes' => [['member_id' => intval($owner_id)]],
            'opportunity_items_attributes' => $items
        ]
    ];

    $log("📤 Payload ready: " . json_encode($payload));

    $response = wp_remote_post("https://api.current-rms.com/api/v1/opportunities", [
        'headers' => $headers,
        'body' => json_encode($payload)
    ]);

    $log("✅ API response: " . wp_remote_retrieve_body($response));
});

function dw_safe_rms_get_product_map($woo_id) {
    $map = get_option('wc_to_rms_product_map', []);
    return isset($map[$woo_id]) ? $map[$woo_id] : null;
}
