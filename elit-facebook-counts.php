<?php 

require_once ( 'vendor/autoload.php' );

/*
Plugin Name: Elit Facebook Counts (alpha)
Plugin URI:  https://github.com/pjsinco/elit-facebook-counts
Description: Get counts of Facebook likes, shares and comments for each post.
Version:     0.0.2
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

    elit_fb_process_posts();

    if (!wp_next_scheduled('elit_fb_cron_hook')) {
        wp_schedule_event(time(), 'hourly', 'elit_fb_cron_hook');
    }

}
register_activation_hook(__FILE__, 'elit_fb_activation');


function elit_fb_process_posts() {

     $logger = new Logger('elit_fb_logger');
     $logger->pushHandler(
         new StreamHandler(plugin_dir_path(__FILE__) . 'log.txt'),
         Logger::DEBUG
     );

    $logger->addInfo('Starting elit_fb_process_posts: ' . time());

    $posts = elit_fb_get_posts();
    $logger->addInfo("\tHave posts? " . !empty($posts));

    $report = array();

    if ($posts) {
        foreach ($posts as $post) {
            $logger->addInfo("\t\tProcessing $post->id");
            $newStats = array();
            $url = FB_URL . urlencode(get_permalink($post->id));
            $info = wp_remote_get($url);
            $xml = simplexml_load_string($info['body']);
            $newStats['elit_fb_shares'] = (string) $xml->link_stat->share_count;
            $newStats['elit_fb_likes'] = (string) $xml->link_stat->like_count;
            $newStats['elit_fb_comments'] = (string) $xml->link_stat->comment_count;

            $current = get_post_meta($post->id, 'elit_fb', true);

            $currentStats = unserialize($current);

            if  (!empty($current) && ($newStats['elit_fb_shares'] != $currentStats['elit_fb_shares'] ||
                $newStats['elit_fb_likes'] != $currentStats['elit_fb_likes'] ||
                $newStats['elit_fb_comments'] != $currentStats['elit_fb_comments'])) {

                $logger->addInfo("\t\tPushing item to report ...");

                $reportItem = array();
                $reportItem['title'] = get_the_title($post->id);
                $reportItem['id'] = $post->id;

                if ($newStats['elit_fb_shares'] != $currentStats['elit_fb_shares']) {
                    $reportItem['new_share_count'] = 
                        (int) $newStats['elit_fb_shares'] - 
                            (int) $currentStats['elit_fb_shares'];
                } 
        
                if ($newStats['elit_fb_likes'] != $currentStats['elit_fb_likes']) {
                    $reportItem['new_like_count'] =
                        (int) $newStats['elit_fb_likes'] - 
                              (int) $currentStats['elit_fb_likes'];
                } 

                if ($newStats['elit_fb_comments'] != 
                        $currentStats['elit_fb_comments']) {
                    $reportItem['new_comment_count'] = 
                        (int) $newStats['elit_fb_comments'] - 
                            (int) $currentStats['elit_fb_comments'];
                }

                array_push($report, $reportItem);
            }

    
            update_post_meta($post->id, 'elit_fb', serialize($newStats));
        }

        $emailBody = '';
        $emailBody .= PHP_EOL;
        $emailBody .= "* * * * *                        * * * * *" . PHP_EOL;
        $emailBody .= "* * * * * *      B E T A       * * * * * *" . PHP_EOL;
        $emailBody .= "* * * * *                        * * * * *" . PHP_EOL . PHP_EOL;
        $emailBody = 'Activity since yesterday ...';
        $emailBody .= PHP_EOL;

        foreach ($report as $item) {
          $newStatsArray = get_post_meta($item['id'], 'elit_fb', true);
          $newStats = unserialize($newStatsArray);
          $emailBody .= '------------------------------------------' . PHP_EOL;
          $emailBody .= '                U P D A T E               ' . PHP_EOL;
          $emailBody .= '------------------------------------------' . PHP_EOL;
          $emailBody .= PHP_EOL;
          $emailBody .= $item['title'] . PHP_EOL;
          if (isset($item['new_like_count'])) {
              $emailBody .= 
                "New likes:    " . $item['new_like_count'] . PHP_EOL;
          }
          if (isset($item['new_share_count'])) {
              $emailBody .= 
                "New shares:   " . $item['new_share_count'] . PHP_EOL;
          }
          if (isset($item['new_comment_count'])) {
              $emailBody .= 
                "New comments: " . $item['new_comment_count'] . PHP_EOL;
          }
          $emailBody .= PHP_EOL;
          $emailBody .= 'NEW TOTALS FOR POST' . PHP_EOL;
          $emailBody .= "Likes:    " . $newStats['elit_fb_likes'] . PHP_EOL;
          $emailBody .= "Shares:   " . $newStats['elit_fb_shares'] . PHP_EOL;
          $emailBody .= "Comments: " . $newStats['elit_fb_comments'] . PHP_EOL;
          $emailBody .= PHP_EOL . PHP_EOL . PHP_EOL;
        }

        if (!empty($report)) {
            $mailed = wp_mail(
                'psinco@osteopathic.org', 
                'Facebook Activity', 
                $emailBody, 
                'Content-Type: text/plain'
            );
        }

        $logger->addInfo('Mailed? ' . ($mailed != null ? 'Yes' : 'No'));
        
    }

}
add_action('elit_fb_cron_hook' , 'elit_fb_process_posts');
