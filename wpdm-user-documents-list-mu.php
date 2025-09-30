<?php
/*
Plugin Name: WPDM User Documents List Shortcode (MU)
Description: Provides a [wpdm_user_documents_list] shortcode for displaying user-accessible WPDM documents with category filtering, as a must-use plugin.
Author: Your Name
Version: 1.0
*/

function wpdm_user_documents_list_shortcode($atts) {
    if (!is_user_logged_in()) {
        wp_redirect(site_url('/wp-downloads-login/'));
        exit;
    }

    ob_start();

    // Enqueue DataTables assets
    wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', ['jquery'], null, true);
    wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css');

    // Query all WPDM packages
    $args = array(
        'post_type'      => 'wpdmpro',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    );
    $packages = get_posts($args);

    $rows = '';
    // We'll collect category IDs that come from accessible packages
    $cats_from_packages = [];
    // Tag set (commented out for now)
    // $tags_set = [];

    foreach ($packages as $package) {
        $package_id = $package->ID;

        if (!function_exists('wpdm_user_has_access') || !wpdm_user_has_access($package_id)) {
            continue;
        }

        // Categories (wpdmcategory) for this package
        $terms = get_the_terms($package_id, 'wpdmcategory');
        $term_names = [];
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $term_names[] = $term->name;
                $cats_from_packages[$term->term_id] = $term->term_id;
            }
        }
        $category_str = $term_names ? implode(', ', $term_names) : '-';

        /*
        // Tags (wpdmtag) - commented out
        $tag_terms = get_the_terms($package_id, 'wpdmtag');
        $tag_names = [];
        if (!is_wp_error($tag_terms) && !empty($tag_terms)) {
            foreach ($tag_terms as $tag) {
                $tag_names[] = $tag->name;
                $tags_set[$tag->name] = true;
            }
        }
        $tag_str = $tag_names ? implode(', ', $tag_names) : '-';
        */

        // Publish Date
        $publish_date = get_the_date('Y-m-d', $package_id);

        // Download button
        $download_link = do_shortcode("[wpdm_package id='{$package_id}' template='link-template-button']");

        // Table row (Category column enabled)
        $rows .= '<tr>';
        $rows .= '<td>' . esc_html($package->post_title) . '</td>';
        $rows .= '<td>' . esc_html($category_str) . '</td>'; // Category column enabled
        // $rows .= '<td>' . esc_html($tag_str) . '</td>'; // Tag column commented out
        $rows .= '<td>' . esc_html($publish_date) . '</td>';
        $rows .= '<td>' . $download_link . '</td>';
        $rows .= '</tr>';
    }

    // ---------- Build user-accessible categories ----------
    $current_user = wp_get_current_user();
    $user_id      = $current_user->ID;
    $user_roles   = (array) $current_user->roles;

    // Get all categories (do NOT hide empty so we can inspect access meta)
    $all_terms = get_terms(array(
        'taxonomy'   => 'wpdmcategory',
        'hide_empty' => false,
    ));

    // Map terms by ID for quick lookup
    $terms_by_id = array();
    foreach ($all_terms as $t) {
        $terms_by_id[$t->term_id] = $t;
    }

    // Categories explicitly allowed either by category access meta or because they appear on accessible packages
    $user_category_ids = [];

    // 1) Add categories that appear on accessible packages
    foreach ($cats_from_packages as $tid) {
        $user_category_ids[$tid] = $tid;
    }

    // 2) Add categories that are allowed by category-level access (role or user)
    foreach ($all_terms as $term) {
        $access = get_term_meta($term->term_id, '__wpdm_access', true);

        // Normalize access to an array
        if (!is_array($access)) {
            if ($access === '' || $access === false || $access === null) {
                $access = array();
            } else {
                // sometimes it's a single string, convert to array
                $access = (array) $access;
            }
        }

        // If empty => public category
        if (empty($access)) {
            $user_category_ids[$term->term_id] = $term->term_id;
            continue;
        }

        // Role-based check
        if (!empty(array_intersect($user_roles, $access))) {
            $user_category_ids[$term->term_id] = $term->term_id;
            continue;
        }

        // User-based check: WPDM stores user entries as 'U:123' — check strict match
        if (in_array('U:' . $user_id, $access, true)) {
            $user_category_ids[$term->term_id] = $term->term_id;
            continue;
        }

        // Some installs may store numeric user IDs or other variants — check numeric user ID as fallback
        if (in_array((string)$user_id, $access, true) || in_array($user_id, $access, true)) {
            $user_category_ids[$term->term_id] = $term->term_id;
            continue;
        }
    }

    // If no categories found, prepare an empty array
    if (empty($user_category_ids)) {
        $user_category_ids = array();
    }

    // ---------- Build parent -> children map for hierarchical rendering ----------
    $children_map = array();
    foreach ($all_terms as $term) {
        $parent = (int) $term->parent;
        if (!isset($children_map[$parent])) $children_map[$parent] = array();
        $children_map[$parent][] = $term;
    }

    // Helper: check if a term or any descendant is in $user_category_ids
    if (!function_exists('wpdm_term_has_relevant_descendant')) {
        function wpdm_term_has_relevant_descendant($term_id, $children_map, $user_category_ids) {
            // direct hit
            if (isset($user_category_ids[$term_id])) return true;
            if (empty($children_map[$term_id])) return false;
            foreach ($children_map[$term_id] as $child) {
                if (isset($user_category_ids[$child->term_id])) return true;
                if (wpdm_term_has_relevant_descendant($child->term_id, $children_map, $user_category_ids)) return true;
            }
            return false;
        }
    }

    // Render category options recursively.
    if (!function_exists('wpdm_render_user_category_options')) {
        function wpdm_render_user_category_options($parent_id, $children_map, $user_category_ids, $prefix = '') {
            if (empty($children_map[$parent_id])) return;
            foreach ($children_map[$parent_id] as $cat) {
                $has_relevant = wpdm_term_has_relevant_descendant($cat->term_id, $children_map, $user_category_ids);
                if (!$has_relevant) {
                    // neither this category nor descendants are relevant — skip
                    continue;
                }

                // If this category itself is selectable (in user_category_ids) show as option.
                if (isset($user_category_ids[$cat->term_id])) {
                    echo '<option value="' . esc_attr($cat->name) . '">' . esc_html($prefix . $cat->name) . '</option>';
                    // render children (they might be selectable or further groups)
                    wpdm_render_user_category_options($cat->term_id, $children_map, $user_category_ids, $prefix . '— ');
                } else {
                    // Category not selectable but has relevant descendants:
                    // Render as disabled label, then render its children
                    echo '<option disabled>' . esc_html($prefix . $cat->name) . '</option>';
                    wpdm_render_user_category_options($cat->term_id, $children_map, $user_category_ids, $prefix . '— ');
                }
            }
        }
    }

    // ---------- Output filters ----------

    // Category filter (hierarchical, only relevant categories)
    echo '<label for="category-filter">Filter by Category:</label> ';
    echo '<select id="category-filter" style="margin-right:15px; margin-bottom:10px;">';
    echo '<option value="">All Categories</option>';
    // Start from parent = 0
    wpdm_render_user_category_options(0, $children_map, $user_category_ids, '');
    echo '</select>';

    /*
    // Tag filter (commented out)
    ksort($tags_set);
    echo '<label for="tag-filter">Filter by Tag:</label> ';
    echo '<select id="tag-filter" style="margin-right:15px; margin-bottom:10px;"><option value="">All Tags</option>';
    foreach ($tags_set as $tag => $_) {
        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
    }
    echo '</select>';
    */

    // Search box
    echo '<input type="text" id="doc-search" placeholder="Search documents..." style="padding:5px;">';

    // Table markup
    echo '<table id="wpdm-documents" class="display" style="margin-top:10px;"><thead><tr>
        <th>Title</th>
        <th>Category</th>
        <th>Date</th>
        <th>Download</th>
    </tr></thead><tbody>';
    echo $rows;
    echo '</tbody></table>';

    // DataTables JS
    ?>
    <script>
    jQuery(document).ready(function($) {
        var table = $('#wpdm-documents').DataTable({
            dom: 'tip',
            pageLength: 100,
            order: [[2, 'desc']] // Default sort by Date (index 2)
        });

        $('#doc-search').on('keyup', function () {
            table.search(this.value).draw();
        });

        // Category filter logic (column 1 = Category)
        $('#category-filter').on('change', function () {
            var selected = $(this).val();
            if (selected) {
                table.column(1).search(selected, true, false).draw();
            } else {
                table.column(1).search('').draw();
            }
        });

        /*
        // Tag filter logic (commented out)
        $('#tag-filter').on('change', function () {
            let selected = $(this).val();
            if (selected) {
                table.column(2).search(selected, true, false).draw();
            } else {
                table.column(2).search('').draw();
            }
        });
        */
    });
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode('wpdm_user_documents_list', 'wpdm_user_documents_list_shortcode');
