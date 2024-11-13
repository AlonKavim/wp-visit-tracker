<?php
/*
Plugin Name: Advanced Visit Tracker
Description: Tracks website visits with detailed metrics, including page views, referring URLs, user agent statistics, and peak visit times.
Version: 1.0
Author: AlonKaivm
*/

if (!defined('ABSPATH')) exit;

global $wpdb;
define('AVT_TABLE_NAME', $wpdb->prefix . 'advanced_visit_tracker');

// Function to create the table on the 'init' hook
function avt_create_table() {
    global $wpdb;
    $table_name = AVT_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(100) NOT NULL,
        user_agent TEXT NOT NULL,
        visit_time DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
        page_visited VARCHAR(255) NOT NULL,
        referrer VARCHAR(255),
        is_returning TINYINT(1) DEFAULT 0,
        is_banned TINYINT(1) DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('init', 'avt_create_table');  // Make sure this line is present to create the table

// Ban visitors with banned IP addresses
add_action('wp', function() {
    global $wpdb;
    $table_name = AVT_TABLE_NAME;
    $ip_address = sanitize_text_field($_SERVER['REMOTE_ADDR']);

    // Check if IP is banned
    $is_banned = $wpdb->get_var($wpdb->prepare("SELECT is_banned FROM $table_name WHERE ip_address = %s LIMIT 1", $ip_address));

    if ($is_banned) {
        status_header(403);
        wp_die('Access Denied: Your IP address has been banned.', 'Access Denied', ['response' => 403]);
    }
});

// Log each visit
add_action('wp', function() {
    if (is_user_logged_in()) return;

    global $wpdb;
    $table_name = AVT_TABLE_NAME;

    // Ensure table exists before logging
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        error_log("Table $table_name does not exist.");
        return;
    }

    $ip_address = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
    $page_visited = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/');
    $referrer = sanitize_text_field($_SERVER['HTTP_REFERER'] ?? 'Direct');

    // Check if IP is returning (seen before)
    $is_returning = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE ip_address = %s", $ip_address)) > 0 ? 1 : 0;

    // Insert visit data
    $wpdb->insert(
        $table_name,
        [
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'visit_time' => current_time('mysql'),
            'page_visited' => $page_visited,
            'referrer' => $referrer,
            'is_returning' => $is_returning,
            'is_banned' => 0
        ]
    );
});

// Add admin menu
add_action('admin_menu', function() {
    add_menu_page('Visit Tracker', 'Visit Tracker', 'manage_options', 'visit-tracker', 'avt_display_admin_page', 'dashicons-chart-bar', 20);
    add_submenu_page('visit-tracker', 'Settings', 'Settings', 'manage_options', 'visit-tracker-settings', 'avt_display_settings_page');
});

// Display the main admin page with metrics
function avt_display_admin_page() {
    global $wpdb;
    $table_name = AVT_TABLE_NAME;

    // Handle ban/unban actions securely with nonces
    if (isset($_GET['action']) && ($_GET['action'] === 'ban' || $_GET['action'] === 'unban') && isset($_GET['ip'])) {
        $ip = sanitize_text_field($_GET['ip']);
        $nonce = $_GET['_wpnonce'];

        if (wp_verify_nonce($nonce, 'ban_unban_ip_' . $ip)) {
            if ($_GET['action'] === 'ban') {
                $wpdb->update($table_name, ['is_banned' => 1], ['ip_address' => $ip]);
            } elseif ($_GET['action'] === 'unban') {
                $wpdb->update($table_name, ['is_banned' => 0], ['ip_address' => $ip]);
            }
            // Redirect to the same page to prevent duplicate actions on refresh
            wp_redirect(admin_url('admin.php?page=visit-tracker'));
            exit;
        }
    }

    // Fetching metrics
    $total_visits = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $unique_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip_address) FROM $table_name");
    $new_visitors = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_returning = 0");
    $returning_visitors = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_returning = 1");

    echo "<div class='wrap'><h1>Visit Tracker</h1>";
    echo "<p><strong>Total Visits:</strong> " . esc_html($total_visits) . " | <strong>Unique IPs Visited:</strong> " . esc_html($unique_ips) . "</p>";
    echo "<p><strong>New Visitors:</strong> " . esc_html($new_visitors) . " | <strong>Returning Visitors:</strong> " . esc_html($returning_visitors) . "</p>";

    // Top Referrers
    $top_referrers = $wpdb->get_results("SELECT referrer, COUNT(*) AS ref_count FROM $table_name GROUP BY referrer ORDER BY ref_count DESC LIMIT 5");
    echo "<h2>Top Referrers</h2><ul>";
    foreach ($top_referrers as $ref) {
        echo "<li>" . esc_html($ref->referrer) . ": " . esc_html($ref->ref_count) . " visits</li>";
    }
    echo "</ul>";

    // Page Views per Page
    $top_pages = $wpdb->get_results("SELECT page_visited, COUNT(*) AS page_count FROM $table_name GROUP BY page_visited ORDER BY page_count DESC LIMIT 5");
    echo "<h2>Top Pages by Views</h2><ul>";
    foreach ($top_pages as $page) {
        echo "<li>" . esc_html($page->page_visited) . ": " . esc_html($page->page_count) . " views</li>";
    }
    echo "</ul>";

    // Visit Logs Table with pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    $visits = $wpdb->get_results($wpdb->prepare(
        "SELECT ip_address, page_visited, MAX(visit_time) AS last_visit, COUNT(*) as visit_count, is_banned 
        FROM $table_name GROUP BY ip_address, page_visited ORDER BY last_visit DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));

    echo '<h2>Visit Logs</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Last Visit</th><th>IP Address</th><th>Page Visited</th><th>Visit Count</th><th>Status</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    foreach ($visits as $visit) {
        $status = $visit->is_banned ? 'Banned' : 'Active';
        $action = $visit->is_banned ? 'unban' : 'ban';
        $nonce = wp_create_nonce('ban_unban_ip_' . $visit->ip_address);
        $action_url = admin_url("admin.php?page=visit-tracker&action=$action&ip={$visit->ip_address}&_wpnonce=$nonce");

        $action_button = "<a href='$action_url' class='button'>" . ucfirst($action) . "</a>";

        echo "<tr>
            <td>" . esc_html($visit->last_visit) . "</td>
            <td>" . esc_html($visit->ip_address) . "</td>
            <td>" . esc_html($visit->page_visited) . "</td>
            <td>" . esc_html($visit->visit_count) . "</td>
            <td>" . esc_html($status) . "</td>
            <td>$action_button</td>
        </tr>";
    }
    echo '</tbody></table>';

    // Pagination
    $total_records = $wpdb->get_var("SELECT COUNT(DISTINCT ip_address, page_visited) FROM $table_name");
    $total_pages = ceil($total_records / $per_page);

    echo '<div class="pagination">';
    for ($i = 1; $i <= $total_pages; $i++) {
        $class = ($i == $current_page) ? 'current' : '';
        echo "<a href='?page=visit-tracker&paged=$i' class='$class button'>$i</a> ";
    }
    echo '</div>';
}


// Settings page for clearing data and managing bans
function avt_display_settings_page() {
    global $wpdb;
    $table_name = AVT_TABLE_NAME;

    // Handle settings actions
    if (isset($_POST['clear_all_data'])) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo "<div class='updated'><p>All data cleared successfully.</p></div>";
    } elseif (isset($_POST['clear_unbanned_data'])) {
        $wpdb->query("DELETE FROM $table_name WHERE is_banned = 0");
        echo "<div class='updated'><p>Unbanned data cleared successfully.</p></div>";
    } elseif (isset($_POST['unban_all'])) {
        $wpdb->update($table_name, ['is_banned' => 0], ['is_banned' => 1]);
        echo "<div class='updated'><p>All IPs unbanned successfully.</p></div>";
    } elseif (isset($_POST['clear_referrer_data'])) {
        $wpdb->query("UPDATE $table_name SET referrer = NULL");
        echo "<div class='updated'><p>Referrer data cleared successfully.</p></div>";
    } elseif (isset($_POST['clear_page_views'])) {
        $wpdb->query("UPDATE $table_name SET page_visited = NULL");
        echo "<div class='updated'><p>Page view data cleared successfully.</p></div>";
    } elseif (isset($_POST['clear_returning_visitors'])) {
        $wpdb->query("UPDATE $table_name SET is_returning = 0");
        echo "<div class='updated'><p>Returning visitor data cleared successfully.</p></div>";
    }

    // Settings form with buttons for each action
    echo "<div class='wrap'><h1>Visit Tracker Settings</h1>";
    echo "<form method='post'>";
    echo "<p><input type='submit' name='clear_all_data' class='button button-danger' value='Clear All Data' onclick='return confirm(\"Are you sure you want to clear all data?\");'></p>";
    echo "<p><input type='submit' name='clear_unbanned_data' class='button button-danger' value='Clear Only Unbanned Data' onclick='return confirm(\"Are you sure you want to clear only unbanned data?\");'></p>";
    echo "<p><input type='submit' name='unban_all' class='button button-primary' value='Unban All IPs'></p>";
    echo "<hr />";
    echo "<p><input type='submit' name='clear_referrer_data' class='button button-secondary' value='Clear Referrer Data' onclick='return confirm(\"Are you sure you want to clear all referrer data?\");'></p>";
    echo "<p><input type='submit' name='clear_page_views' class='button button-secondary' value='Clear Page Views Data' onclick='return confirm(\"Are you sure you want to clear all page view data?\");'></p>";
    echo "<p><input type='submit' name='clear_returning_visitors' class='button button-secondary' value='Clear Returning Visitor Data' onclick='return confirm(\"Are you sure you want to reset returning visitor data?\");'></p>";
    echo "</form>";
    echo "</div>";
}
