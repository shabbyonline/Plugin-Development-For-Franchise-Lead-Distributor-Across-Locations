/*
Plugin Name: Franchise Lead Distributor
Description: Distributes Forms submissions to hundreds of subsites(locations) based on service area zip codes, and manages custom roles and capabilities. Runs on the main site of a multisite network.
Version: 2.0
Author: Shabbir Aftab
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Ensure we're in a multisite environment
if (!is_multisite()) {
    return;
}
include_once( 'gc_leads_exporter.php' );

add_action('gform_after_submission', 'send_contact_us_form_email', 10, 2);

function send_contact_us_form_email($entry, $form) {
    // Check if the form has the CSS class "custom-contact-form"
    if (strpos($form['cssClass'], 'custom-contact-form') === false) {
        return;
    }

    // Initialize $zipCode as empty
    $zipCode = '';

    // Look for the "Zip Code" field in the form
    foreach ($form['fields'] as $field) {
        $label = $field->label ?? '';
        $admin_label = $field->adminLabel ?? ''; 
        if ( stripos($label, 'Zip Code') !== false ||
        stripos($admin_label, 'Zip Code') !== false) {
            $potentialZip = rgar($entry, $field->id); // Get the value of the current field
            if (!empty($potentialZip)) {
                $zipCode = $potentialZip; // Assign the first non-empty value
                break; // Stop the loop once we find a valid zip code
            }
        }
    }

    // If ZIP code is still empty, log an error and stop
    if (empty($zipCode)) {
        error_log('No ZIP code found in form submission.');
        return;
    }

    // Get location details based on ZIP code
    $location = gris_gc_lead_get_location_by_zip($zipCode);
    GFCommon::log_debug( 'gform_is_value_match: => ' . print_r( $location, 1 ) ); 
    
    // Define default lead email forms.noreply@griswoldcare.com and Creatio email creatio.leads@griswoldcare.com
    $default_lead_email = 'forms.noreply@griswoldcare.com';
    $creatio_email = 'creatio.leads@griswoldcare.com';
    
    $formData = array_reduce(array_keys($entry), function ($carry, $field_id) use ($entry, $form) {
        $field = GFFormsModel::get_field($form, $field_id);

        if ($field) {
            // Use admin label if available; otherwise, use label
            $label = !empty($field->adminLabel) ? $field->adminLabel : $field->label;
            $value = '';
            $requiredFields = ['Campaign', 'Campaign ID', 'Source', 'Medium', 'First URL', 'Content'];
            if ($field->type === 'consent') {
                foreach ($field->inputs as $input) {
                    if ($input['id'] == $field_id) {
                        // Check if the input has the 'hidden' class and skip it
                        if (isset($input['class']) && strpos($input['class'], 'hidden') !== false) {
                            continue; // Skip this input if it has the 'hidden' class
                        }
                    
                        // Skip if the label is 'Description'
                        if ($input['label'] == 'Description') {
                            continue; // Skip the description field
                        }
                        $label .= '';
                        // $label .= '  ' . $input['label']; // Append the label to the final output
                        
                    
                        // Determine if the consent was given (checked) or not
                        if ($input['label'] === 'Consent') {
                            // Check if the consent checkbox is checked or not
                            $consentState = !empty($entry[$field_id]) && $entry[$field_id] === '1' ? 'Checked' : 'Not Checked';
                            // Get the label of the consent field
                            $consentLabel = $field->label;

                            // Get the checkbox label (consent text) from the field object
                            $consentText = isset($field['checkboxLabel']) ? strip_tags($field['checkboxLabel']) : '';
                    
                            // Prepare the consent state and text output
                            $value = " " . $consentState; // Consent state (Checked or Not Checked)
                    
                            GFCommon::log_debug('Consent Field Main LabelValue: ' . $value);
                            // If there is consent text, add it to the value
                            if ($consentState === 'Checked') {
                                if (!empty($consentText)) {
                                    if ($consentLabel === 'SMS Consent') {
                                        $value .= "\nSMS Consent - Text Field: " . $consentText; // Add SMS-specific consent text
                                    } else {
                                        $value .= "\nConsent - Text Field: " . $consentText; // Add general consent text
                                    }
                                    // $value .= "\nConsent - Text: " . $consentText; // Add the consent text (with HTML stripped)
                                }
                            }else{
                                if ($consentLabel === 'SMS Consent') {
                                    $value .= "\nSMS Consent - Text Field: " . ''; // Add SMS-specific consent text
                                } else {
                                    $value .= "\nConsent - Text Field: " . ''; // Add general consent text
                                }
                            }
                        } else {
                            // For other fields, just use the entry value
                            // $value = $entry[$field_id];
                        }
                    
                        break; // Exit the loop once a valid input is processed
                    }
                    
                    
                }
            }                
            else {
                // Handle all other fields
                $value = is_array($entry[$field_id]) 
                    ? implode(', ', $entry[$field_id]) 
                    : $entry[$field_id];
    
                // Remove HTML from the value
                $value = strip_tags($value);
            }
            if (in_array($label, $requiredFields)) {
                if (empty($value)) {
                    $value = ''; // Set default value for empty required fields
                }
                $carry .= $label . ': ' . $value . "\n";
            } elseif (!empty($value)) {
                // Skip empty fields unless they are in the required fields list
                $carry .= $label . ': ' . $value . "\n";
            }
            // Skip empty fields
            // if (!empty($value)) {
            //     $carry .= $label . ': ' . $value . "\n";
            // }
        }
        
    
        return $carry;
    }, '');
    // Add source URL to the email content
    $formData .= "Source URL: " . $entry['source_url'] . "\n";
    $current_site_id = get_current_blog_id();
    if (get_current_blog_id() == 138) {
        $source_url = rgar($entry, 'source_url');
        error_log('Source URL : ' .print_r($source_url, true));     
        $location_id = '';
        $source_post_id = 0;

        if (!empty($source_url)) {
            $clean_url = strtok($source_url, '?'); // Strip query parameters
            $source_post_id = url_to_postid($clean_url);
            error_log('Clean URL: ' . $clean_url);
            error_log('Source Post ID: ' . print_r($source_post_id, true));

            if ($source_post_id) {
                $location_id = get_post_meta($source_post_id, '_location_id_meta', true);
                error_log('Fetched Location ID from source URL: ' . $location_id);
            } else {
                error_log('No post ID found for source URL: ' . $source_url);
            }
        } else {
            error_log('source_url is empty or not present in entry.');
        }
        $location_lead_email = '';
        $location_site_name = 'Promo';
    
        if ($location_id) {
            switch_to_blog(1);
            $location_lead_email = get_post_meta($location_id, 'store_locator_rs_lead_email', true);
            $site_id = get_post_meta($location_id, 'store_locator_rs_site_id', true);
    
            if ($site_id) {
                $blog_details = get_blog_details($site_id);
                if ($blog_details && isset($blog_details->blogname)) {
                    $location_site_name = $blog_details->blogname;
                }
            }
            restore_current_blog();
        }
    
        // Prepare post
        $lead_post = [
            'post_title'    => 'Lead - ' . $form['title'] . ' - ' . $form['id'] . ' - ' . $entry['id'] . ' - ' . $location_site_name . ' Location Found',
            // 'post_title'    => 'Lead - ' . $form['title'] . ' - ' . $form['id'] . ' - ' . $entry['id'] . ' - Promo Location Found',
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_type'     => 'gc_lead',
        ];
    
        $lead_post_id = wp_insert_post($lead_post);
    
        if (!is_wp_error($lead_post_id)) {
            // $formData .= "\n\nLocation: Promo";
            $formData .= "\n\nLocation: " . $location_site_name;
            update_post_meta($lead_post_id, 'form_data', $formData);
            update_post_meta($lead_post_id, 'entry_id', $entry['id']);
            update_post_meta($lead_post_id, 'form_id', $form['id']);
            update_post_meta($lead_post_id, 'form_name', $form['title']);
        } else {
            error_log("Failed to create gc_lead post on Site 138 for Form Entry ID: " . $entry['id']);
        }
        
        // Send emails 
        //. ' - ' . $entry['id'] . ' - Location ' . $location_site_name
        $subject = 'New Lead: ' . $form['title'];
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $message = $formData;
        $email_addresses = [];

        // Add location email(s) if any
        if (!empty($location_lead_email)) {
            $email_addresses = array_merge($email_addresses, array_map('trim', explode(',', $location_lead_email)));
        }
        
        // Always add default and creatio email
        $email_addresses[] = $default_lead_email;
        $email_addresses[] = $creatio_email;
        
        // Remove duplicates just in case
        $email_addresses = array_unique($email_addresses);
        error_log('All Emails : ' .print_r($email_addresses, true));
        // Send to all
        foreach ($email_addresses as $email) {
            wp_mail($email, $subject, $message, $headers);
        }
        // if (!empty($location_lead_email)) {
        //     // Support multiple comma-separated emails
        //     $email_addresses = array_map('trim', explode(',', $location_lead_email));
        //     foreach ($email_addresses as $email) {
        //         wp_mail($email, $subject, $message, $headers);
        //     }
        // } else {
        //     wp_mail($default_lead_email, $subject, $message, $headers);
        //     wp_mail($creatio_email, $subject, $message, $headers);
        // }
         // Clear session value
        // unset($_SESSION['location_meta_id']);
        return;
    }
        
    // Process location and email logic
    if (!empty($location)) {
        $subsite_id =$location['site_id'];
        error_log('Subsite ID from location: ' . $subsite_id);
    
        if (!empty($subsite_id) && is_multisite()) {
            // Switch to subsite
            switch_to_blog($subsite_id);
            error_log('Switched to Subsite ID: ' . get_current_blog_id());
    
            // Create post on the corresponding subsite
            $lead_post = [
                'post_title'    => 'Lead - ' . $form['title'] . ' - ' . $form['id'] . ' - ' . $entry['id'],
                'post_content'  => '',
                'post_status'   => 'publish',
                'post_type'     => 'gc_lead',
            ];
    
            $lead_post_id = wp_insert_post($lead_post);
    
            if (!is_wp_error($lead_post_id)) {
                if(!empty($location['name'])){
                    $formData .="Location: " . $location['name'];
                }
                $formData .= "\n\nEmail Sent to: " . $location['email'];
                update_post_meta($lead_post_id, 'form_data', $formData);
                update_post_meta($lead_post_id, 'entry_id', $entry['id']);
                update_post_meta($lead_post_id, 'form_id', $form['id']);
                update_post_meta($lead_post_id, 'form_name', $form['title']);
            } else {
                error_log('Failed to create gc_lead post on Subsite ID: ' . $subsite_id);
            }
    
            restore_current_blog(); // Restore original site context
            error_log('Restored to Main Site: ' . get_current_blog_id());
        }
    
        // Get subsite name
        $subsite_details = get_blog_details($subsite_id);
        $subsite_name = $subsite_details ? $subsite_details->blogname : 'Unknown Subsite';
    
        // Switch back to main site explicitly
        if (is_multisite()) {
            switch_to_blog(get_network()->site_id);
        }
    
        // Create post on the main site
        $lead_post_main = [
            'post_title'    => 'Lead - #' . $entry['id'] . ' From ' . $subsite_name . ' - ' . $form['title'],
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_type'     => 'gc_lead',
        ];
    
        $lead_post_main_id = wp_insert_post($lead_post_main);
    
        if (!is_wp_error($lead_post_main_id)) {
            update_post_meta($lead_post_main_id, 'form_data', $formData);
            update_post_meta($lead_post_main_id, 'entry_id', $entry['id']);
            update_post_meta($lead_post_main_id, 'form_id', $form['id']);
            update_post_meta($lead_post_main_id, 'form_name', $form['title']);
            update_post_meta($lead_post_main_id, 'subsite_id', $subsite_id);
            update_post_meta($lead_post_main_id, 'subsite_name', $subsite_name);
        } else {
            error_log('Failed to create gc_lead post on Main Site for Form Entry ID: ' . $entry['id']);
        }
    
        // Restore original blog context
        if (is_multisite()) {
            restore_current_blog();
            error_log('Final Restored Blog ID: ' . get_current_blog_id());
        }
    
        // Prepare and send emails
        $lead_email = $location['email'];
        // $location_name = $location['name'];
        $subject = 'New Lead: ' . $form['title'] . ' - ' . $entry['id'];
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        // $message = $formData . "\nLocation: " . $location_name;
        $message = $formData;
    
        if (!empty($lead_email)) {
            wp_mail($lead_email, $subject, $message, $headers);
        } else {
            wp_mail($default_lead_email, $subject, $message, $headers);
        }
    
        wp_mail($creatio_email, $subject, $message, $headers);
    
    }
    else {
        // Switch to the main site explicitly
        
        if (is_multisite()) {
            switch_to_blog(get_network()->site_id);
            error_log('Switched to Main Site for No Location Found case: ' . get_current_blog_id());
        }
    
        // No location found, create post only on the main site
        $lead_post_main = [
            'post_title'    => 'Lead - ' . $form['title'] . ' - ' . $form['id'] . ' - ' . $entry['id'] . ' - No Location Found',
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_type'     => 'gc_lead',
        ];
    
        $lead_post_main_id = wp_insert_post($lead_post_main);
    
        if (!is_wp_error($lead_post_main_id)) {
            $formData .= "Location: Unknown";
            $formData .= "\n\nEmail Sent to: " . $default_lead_email . " and " . $creatio_email;
            update_post_meta($lead_post_main_id, 'form_data', $formData);
            update_post_meta($lead_post_main_id, 'entry_id', $entry['id']);
            update_post_meta($lead_post_main_id, 'form_id', $form['id']);
            update_post_meta($lead_post_main_id, 'form_name', $form['title']);
        } else {
            error_log("Failed to create gc_lead post on Main Site for Form Entry ID: " . $entry['id']);
        }
    
        // Send email to notify no location was found
        $subject = 'New Lead: ' . $form['title'] . ' - ' . $entry['id'] . ' - No Location Found';
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $message = $formData;
        // $message = $formData . "\nLocation: Unknown\n";
    
        wp_mail($default_lead_email, $subject, $message, $headers);
        wp_mail($creatio_email, $subject, $message, $headers);
    
        // Restore original blog context
        if (is_multisite()) {
            restore_current_blog();
            error_log('Restored to Original Blog after No Location Found: ' . get_current_blog_id());
        }
    }
    
    // Restore the original blog context if necessary
    if (is_multisite()) {
        restore_current_blog();
        error_log('Final Restored Blog ID: ' . get_current_blog_id());
    }
}



function gris_gc_lead_get_location_by_zip( $zipcode ) {
    $location = false;

    // Query the current site's locations first
    $args = [
        'post_type' => 'locations',
        'tax_query' => [
            [
                'taxonomy' => 'location_service_area',
                'field'    => 'name',
                'terms'    => $zipcode,
            ],
        ],
        'posts_per_page' => -1, // Retrieve all posts with the matching ZIP
    ];

    // Query for current site locations
    $loc_query = new WP_Query($args);
    $current_site_id = get_current_blog_id();
    $switched = false;

    // If no location is found on the current site, switch to the main site
    if (!$loc_query->have_posts() && $current_site_id !== 1) {
        switch_to_blog(1);
        $switched = true;
        $loc_query = new WP_Query($args); // Query for the main site
    }

    // Loop through the query results
    if ($loc_query->have_posts()) {
        while ($loc_query->have_posts()) {
            $loc_query->the_post();
            $location_id = get_the_ID();
            $location_site_id = get_post_meta($location_id, 'store_locator_rs_site_id', true);

            // If the site_id matches the current site, set the location
            if ($location_site_id == $current_site_id || $current_site_id === 1) {
                $location = [
                    'id'    => $location_id,
                    'name'  => get_post_meta($location_id, 'store_locator_rs_name', true),
                    'email' => get_post_meta($location_id, 'store_locator_rs_lead_email', true),
                    'site_id' => $location_site_id,
                ];

                // Fallback to alternative email if no lead email is set
                if (empty($location['email'])) {
                    $location['email'] = get_post_meta($location_id, 'store_locator_rs_email', true);
                }

                break; // Exit the loop once the match is found
            }
        }
    }

    // Restore the current blog if it was switched
    if ($switched) {
        restore_current_blog();
    }

    // Reset the post data
    wp_reset_postdata();

    return $location; // Return the location or false if no match is found
}

function gris_gc_lead_network_activate($network_wide) {
    global $wpdb;

    if ($network_wide) {
        $original_blog_id = get_current_blog_id();
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");

        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            gris_gc_lead_add_role_caps();
            restore_current_blog();
        }

        switch_to_blog($original_blog_id);
    } else {
        gris_gc_lead_add_role_caps();
    }
}
register_activation_hook(__FILE__, 'gris_gc_lead_network_activate');

function gris_gc_lead_add_role_caps() {

    $caps = [

        'edit_gc_leads',
        'edit_others_gc_leads',
        'edit_published_gc_leads',
        'edit_private_gc_leads',
        'delete_gc_leads',
        'delete_others_gc_leads',
        'delete_published_gc_leads',
        'delete_private_gc_leads',
        'publish_gc_leads',
        'read_private_gc_leads',

        // team members post type capabilities
        'edit_team_members',
        'edit_others_team_members',
        'edit_published_team_members',
        'edit_private_team_members',
        'delete_team_members',
        'delete_others_team_members',
        'delete_published_team_members',
        'delete_private_team_members',
        'publish_team_members',
        'read_private_team_members',

        // Service post type capabilities
        'edit_services',
        'edit_others_services',
        'edit_published_services',
        'edit_private_services',
        'delete_services',
        'delete_others_services',
        'delete_published_services',
        'delete_private_services',
        'publish_services',
        'read_private_services',
    ];

    $roles = ['administrator', 'editor'];
    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }
    // Remove the existing 'franchisee_admin' role to reset its capabilities
    $fran_role = get_role('franchisee_admin');
    if( $fran_role ) {
        remove_role('franchisee_admin');
    }

    add_role('franchisee_admin', 'Franchisee Admin', [
        'read' => true,
        'upload_files' => true,

        // Blog post capabilities
        'edit_posts' => true,
        'edit_others_posts' => true,
        'edit_published_posts' => true,
        'edit_private_posts' => true,
        'delete_posts' => true,
        'delete_others_posts' => true,
        'delete_published_posts' => true,
        'delete_private_posts' => true,
        'publish_posts' => true,
        'read_private_posts' => true,

        'manage_categories' => true,
        'edit_terms' => true,
        'delete_terms' => true,
        'assign_terms' => true,

        'edit_gc_leads'=> true,
        'edit_others_gc_leads'=> true,
        'edit_published_gc_leads'=> true,
        'edit_private_gc_leads'=> true,
        'delete_gc_leads'=> false,
        'delete_others_gc_leads'=> false,
        'delete_published_gc_leads'=> false,
        'delete_private_gc_leads'=> false,
        'publish_gc_leads'=> true,
        'read_private_gc_leads'=> true,

        // team members post type capabilities
        'edit_team_members'=> true,
        'edit_others_team_members'=> true,
        'edit_published_team_members'=> true,
        'edit_private_team_members'=> true,
        'delete_team_members'=> true,
        'delete_others_team_members'=> true,
        'delete_published_team_members'=> true,
        'delete_private_team_members'=> true,
        'publish_team_members'=> true,
        'read_private_team_members'=> true,

        // Page capabilities
        'edit_pages' => false, // Able to add a new page
        'edit_published_pages' => false, // Able to edit pages that they have created that are already published
        'publish_pages' => false, // Not able to publish a page - must request a review
        'delete_pages' => false, // Assuming you don't want them to delete pages either
        'edit_others_pages' => false, // Not able to edit published pages that were not created by them

    ]);
}
register_deactivation_hook(__FILE__, 'gris_gc_lead_network_deactivate');
function gris_gc_lead_network_deactivate($network_deactivating) {
    global $wpdb;

    if (is_multisite() && $network_deactivating) {
        // Get all blog ids
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            remove_role('franchisee_admin');
            restore_current_blog();
        }
    } else {
        // Single site deactivation
        remove_role('franchisee_admin');
    }
}

add_action('init', 'gris_register_gc_lead_post_type');
function gris_register_gc_lead_post_type() {
    // Do not register the custom post type on the main site
    // if (get_current_blog_id() == 1) {
    //     return;
    // }

    $labels = array(
        'name'                  => _x('GC Leads name', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('GC Lead', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('GC Leads', 'text_domain'),
        'name_admin_bar'        => __('GC Lead', 'text_domain'),
        'archives'              => __('Lead Archives', 'text_domain'),
        'attributes'            => __('Lead Attributes', 'text_domain'),
        'parent_item_colon'     => __('Parent Lead:', 'text_domain'),
        'all_items'             => __('All Leads', 'text_domain'),
        'add_new_item'          => __('Add New Lead', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'new_item'              => __('New Lead', 'text_domain'),
        'edit_item'             => __('Edit Lead', 'text_domain'),
        'update_item'           => __('Update Lead', 'text_domain'),
        'view_item'             => __('View Lead', 'text_domain'),
        'view_items'            => __('View Leads', 'text_domain'),
        'search_items'          => __('Search Lead', 'text_domain'),
        'not_found'             => __('Not found', 'text_domain'),
        'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
        'featured_image'        => __('Featured Image', 'text_domain'),
        'set_featured_image'    => __('Set featured image', 'text_domain'),
        'remove_featured_image' => __('Remove featured image', 'text_domain'),
        'use_featured_image'    => __('Use as featured image', 'text_domain'),
        'insert_into_item'      => __('Insert into lead', 'text_domain'),
        'uploaded_to_this_item' => __('Uploaded to this lead', 'text_domain'),
        'items_list'            => __('Leads list', 'text_domain'),
        'items_list_navigation' => __('Leads list navigation', 'text_domain'),
        'filter_items_list'     => __('Filter leads list', 'text_domain'),
    );
    $capabilities = array(
        'edit_posts' => 'edit_gc_leads',
        'edit_others_posts' => 'edit_others_gc_leads',
        'edit_published_posts' => 'edit_published_gc_leads',
        'edit_private_posts' => 'edit_private_gc_leads',
        'delete_posts' => 'delete_gc_leads',
        'delete_others_posts' => 'delete_others_gc_leads',
        'delete_published_posts' => 'delete_published_gc_leads',
        'delete_private_posts' => 'delete_private_gc_leads',
        'publish_posts' => 'publish_gc_leads',
        'read_private_posts' => 'read_private_gc_leads',
    );



    $args = array(
        'label'               => __('gc_lead', 'text_domain'),
        'description'         => __('GC Lead Custom Post Type', 'text_domain'),
        'labels'              => $labels,
        'supports'              => array( 'title', 'thumbnail'),
        'hierarchical'          => false,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 6,
        'menu_icon'             => 'dashicons-email',
        'show_in_admin_bar'     => true,
        'can_export'            => true,
        'has_archive'           => false,
        'publicly_queryable'    => false,
        'capability_type' => 'gc_lead',
        'map_meta_cap' => true, // Important for mapping custom capabilities
        'capabilities' => $capabilities,
        'show_in_rest'          => false,
        'public'                => false,
        'exclude_from_search'   => true,
        'show_in_nav_menus'     => true,
    );
    register_post_type('gc_lead', $args);
}

add_action('cmb2_admin_init', 'gris_register_gc_lead_metabox');
function gris_register_gc_lead_metabox() {
    // Setup metabox
    $cmb = new_cmb2_box(array(
        'id'            => 'lead_metabox',
        'title'         => __('Lead Details', 'text_domain'),
        'object_types'  => array('gc_lead'), // Post type
        'context'       => 'normal',
        'priority'      => 'high',
        'show_names'    => true,
    ));

    // Define fields without the prefix
    $cmb->add_field(array(
        'name' => __('Entry ID', 'text_domain'),
        'id'   => 'entry_id',
        'type' => 'text',
        'attributes' => array('readonly' => 'readonly'),
    ));

    $cmb->add_field(array(
        'name' => __('Form ID', 'text_domain'),
        'id'   => 'form_id',
        'type' => 'text',
        'attributes' => array('readonly' => 'readonly'),
    ));

    $cmb->add_field(array(
        'name' => __('Form Name', 'text_domain'),
        'id'   => 'form_name',
        'type' => 'text',
        'attributes' => array('readonly' => 'readonly'),
    ));

    $cmb->add_field(array(
        'name' => __('Form Data', 'text_domain'),
        'id'   => 'form_data',
        'type' => 'textarea',
        'attributes' => array('readonly' => 'readonly'),
    ));
}


// Ensure roles and capabilities setup correctly when new sites are created in the network
add_action('wpmu_new_blog', 'gris_gc_lead_new_site_setup', 10, 6);
function gris_gc_lead_new_site_setup($blog_id, $user_id, $domain, $path, $site_id, $meta) {
    switch_to_blog($blog_id);
    gris_gc_lead_add_role_caps();
    restore_current_blog();
}


add_action('init', 'gris_check_location_service_area_taxonomy');
// Register the location_service_area taxonomy if it doesn't exist
function gris_check_location_service_area_taxonomy() {
    if( taxonomy_exists('location_service_area') ){
        return;
    }

    $args = array(
        'label'        => 'Location Service Areas',
        'public'       => false, // Not publicly queryable
        'hierarchical' => false, // Assuming ZIP codes don't have a hierarchy
        'show_ui'      => true, // Show in admin UI for management
        'query_var'    => false, // Not available for public query
        'rewrite'      => false, // No URL rewrite for this taxonomy
    );

    register_taxonomy('location_service_area', 'locations', $args);
}

add_action('admin_menu', 'gris_hide_add_new_gc_lead_menu', 99);
function gris_hide_add_new_gc_lead_menu() {
    remove_submenu_page('edit.php?post_type=gc_lead', 'post-new.php?post_type=gc_lead');
}

// Optional: Restrict direct access to the "Add New" screen
add_action('admin_init', 'gris_restrict_gc_lead_add_new_access');
function gris_restrict_gc_lead_add_new_access() {
    if (isset($_GET['post_type']) && $_GET['post_type'] === 'gc_lead' && isset($_GET['action']) && $_GET['action'] === 'create') {
        wp_redirect(admin_url('edit.php?post_type=gc_lead'));
        exit;
    }
}
add_action('add_meta_boxes', function () {
    if (get_current_blog_id() != 138) return;

    // add_meta_box('wp_page_number_meta', 'Page Number Field', 'wp_number_meta_box_callback', 'page', 'side');
    add_meta_box('wp_location_meta', 'Location ID Field', 'wp_location_meta_box_callback', 'page', 'side');
});



function wp_location_meta_box_callback($post) {
    $value = get_post_meta($post->ID, '_location_id_meta', true);
    wp_nonce_field('wp_save_location_meta_box', 'wp_location_meta_box_nonce');
    echo '<label for="location_id_meta">Enter Location ID:</label>';
    echo '<input type="number" name="location_id_meta" id="location_id_meta" value="' . esc_attr($value) . '" style="width:100%;" />';
}

add_action('save_post_page', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_page', $post_id)) return;

    if (isset($_POST['wp_location_meta_box_nonce']) && wp_verify_nonce($_POST['wp_location_meta_box_nonce'], 'wp_save_location_meta_box')) {
        update_post_meta($post_id, '_location_id_meta', intval($_POST['location_id_meta']));
    }
});
