<?php
/**
 * Export GC leads data to CSV on the local sites
 */

// Hook to add a menu item
add_action('admin_menu', 'gris_gc_leads_exporter_menu');

function gris_gc_leads_exporter_menu() {
    $current_blog_id = get_current_blog_id();
    if ( is_multisite() && $current_blog_id !== 1 ) {
       add_menu_page('GC Leads Exporter', 'GC Leads Exporter', 'read_private_gc_leads', 'gc-leads-exporter', 'gris_gc_leads_exporter_page');
    }
}

// The page content
function gris_gc_leads_exporter_page() {
    ?>
    <h1>GC Leads Exporter</h1>
    <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" target="_blank" >
        Start Date: <input type="date" name="start_date" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" />
        End Date: <input type="date" name="end_date" value="<?php echo date('Y-m-d'); ?>" />
        <input type="hidden" name="action" value="gc_export_leads" />
        <input type="submit" name="export" value="Export" />
    </form>
    <?php
}

// Function to handle the export
add_action('wp_ajax_gc_export_leads', 'gris_gc_export_leads');

// Function to handle the export
function gris_gc_export_leads() {
    if( empty( $_POST['start_date'] ) || empty( $_POST['end_date'] ) ){
        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename="gc_leads.csv";');
        echo 'Please select start and end date';
        exit;
        return;
    }

    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $args = [
        'post_type'      => 'gc_lead',
        'posts_per_page' => -1,
        'date_query'     => [
            [
                'after'     => $start_date,
                'before'    => $end_date,
                'inclusive' => true,
            ],
        ],
    ];
    $query = new WP_Query($args);
    $all_data = [];
    $headers = ['Post Date', 'Entry ID', 'Form ID']; // Initialize headers with fixed fields

    // First, collect all headers and data
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $form_data = get_post_meta(get_the_ID(), 'form_data', true);
            $data = gris_parse_form_data($form_data);

            // Fetch additional metadata
            $entry_id = get_post_meta(get_the_ID(), 'entry_id', true);
            $form_id = get_post_meta(get_the_ID(), 'form_id', true);
            $post_date = get_the_date('Y-m-d', get_the_ID());

            // Append new fields to each data entry
            $data['Post Date'] = $post_date;
            $data['Entry ID'] = $entry_id;
            $data['Form ID'] = $form_id;

            $all_data[] = $data;
            // Update headers by merging current data keys with existing headers
            $headers = array_unique(array_merge($headers, array_keys($data)));
        }
    }

    // Now generate CSV lines using all collected headers
    $csv_lines[] = implode(',', array_map('gris_escape_csv', $headers)); // Add headers as the first line of CSV

    foreach ($all_data as $data) {
        $row = array_map(function($header) use ($data) {
            return isset($data[$header]) ? gris_escape_csv($data[$header]) : '';
        }, $headers);
        $csv_lines[] = implode(',', $row);
    }

    // Output the CSV file
    header('Content-Type: application/csv');
    header('Content-Disposition: attachment; filename="gc_leads.csv";');
    echo implode("\n", $csv_lines);
    exit;
}

// Helper function to escape CSV fields
function gris_escape_csv($data) {
    return '"' . str_replace('"', '""', $data) . '"';
}

// Parse form data function remains the same
function gris_parse_form_data($form_data) {
    $lines = explode("\n", $form_data);
    $result = [];
    foreach ($lines as $line) {
        if (strpos($line, ': ') !== false) { // Check if there is a colon
            list($key, $value) = explode(': ', $line, 2);
            $key_lower = strtolower($key);
            $result[trim($key_lower)] = trim($value);
        }
    }
    return $result;
}

