<?php 

require_once ( 'vendor/autoload.php' );

/*
Plugin Name: Elit Facebook Counts (alpha)
Plugin URI:  https://github.com/pjsinco/elit-facebook-counts
Description: Get counts of Facebook likes, shares and comments for each post.
Version:     0.0.1
Author:      Patrick Sinco
Author URI:  http://github.com/pjsinco
License:     GPL2
*/

// if this file is called directly, abort
if (!defined('WPINC')) {
    die;
}

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('elit_fb_logger');
$logger->pushHandler(
    new StreamHandler(plugin_dir_path(__FILE__) . 'log.txt'),
    Logger::DEBUG
);
$logger->addInfo('hiya: ' . time());

define('FB_URL', "http://api.facebook.com/restserver.php?method=links." . 
    "getStats&urls=");

/**
 * Get all id, title and date of all published posts
 *
 * @return array of post IDs
 * @author PJ
 */
function elit_fb_get_posts()
{
    global $wpdb;
    //$table = $wpdb->prefix . '_posts';
    $q = "
        SELECT id, post_name, post_date
        FROM {$wpdb->prefix}posts
        WHERE post_status = 'publish'
          AND post_type = 'post'
        ORDER BY id
    ";
    
    $results = $wpdb->get_results($q);

    return $results;
}


/**
 * Add a menu page for our plugin to the admin menu.
 *
 */
function elit_fb_counts_menu() {

    add_options_page(
        'Facebook Counts',
        'Facebook Counts',
        'manage_options',
        'facebook-counts',
        'elit_fb_counts_options_page'
    );

}
add_action('admin_menu' , 'elit_fb_counts_menu');

function elit_fb_counts_options_page() {

    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permission to access this page.');
    }

    require('includes/options-page-wrapper.php');

}


function elit_fb_activation() {

    if (!wp_next_scheduled('elit_fb_cron_hook')) {
        wp_schedule_event(time(), 'hourly', 'elit_fb_cron_hook');
    }

}
register_activation_hook(__FILE__, 'elit_fb_activation');


function elit_fb_process_posts() {

    $logger->addInfo('Starting elit_fb_process_posts: ' . time());

    $posts = elit_fb_get_posts();
    $logger->addInfo("\tHave posts? " . !empty($posts));

    if ($posts) {
        foreach ($posts as $post) {
            $logger->addInfo("\t\tProcessing $post->id");
            $stats = array();
            $url = FB_URL . urlencode(get_permalink($post->id));
            $info = wp_remote_get($url);
            $xml = simplexml_load_string($info['body']);
            $stats['elit_fb_shares'] = (string) $xml->link_stat->share_count;
            $stats['elit_fb_likes'] = (string) $xml->link_stat->like_count;
            $stats['elit_fb_comments'] = (string) $xml->link_stat->comment_count;
    
            update_post_meta($post->id, 'elit_fb', serialize($stats));
        }
    }

}
add_action('elit_fb_cron_hook' , 'elit_fb_process_posts');
