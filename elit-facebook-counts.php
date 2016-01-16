<?php 

/*
Plugin Name: Elit Facebook Counts (beta)
Plugin URI:  https://github.com/pjsinco/elit-facebook-counts
Description: Get counts of Facebook likes, shares and comments for each post.
Version:     0.0.3
Author:      Patrick Sinco
Author URI:  http://github.com/pjsinco
License:     GPL2
*/

// if this file is called directly, abort
if (!defined('WPINC')) {
    die;
}

require_once( 'vendor/autoload.php' );
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Carbon\Carbon;

/**
 * Send an HTML email
 *
 */
function elit_format_html_email($report_items = array())
{

  $template = elit_get_email_templater($logger);

  $email = $template->render(
    'email.html',
    array( 
      'report' => $report_items, 
      'date' => elit_report_date(),
    )
  );

  return $email;
}

/**
 * Set up Twig templates
 *
 */
function elit_get_email_templater()
{

  require_once( plugin_dir_path(__FILE__) . 'vendor/twig/twig/lib/Twig/Autoloader.php');

  Twig_Autoloader::register();

  $loader = new Twig_Loader_Filesystem(plugin_dir_path(__FILE__) . 'includes');

  $twig = new Twig_Environment($loader);

  return $twig;

}


if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}


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
    add_management_page(
        'Facebook Counts',
        'Facebook Counts',
        'manage_options',
        'facebook-counts',
        'elit_fb_counts_management_page'
    );
}
add_action('admin_menu' , 'elit_fb_counts_menu');

function elit_fb_admin_header() {
    $page = (isset($_GET['page'])) ? esc_attr($_GET['page']) : false;
    if (!$page) {
        return;
    }
    echo '<style type="text/css">';
    echo '.wp-list-table .column-post { width: 40%; }';
    echo '.wp-list-table .column-post_date { width: 12%; }';
    echo '.wp-list-table .column-elit_fb_likes { width: 10%; }';
    echo '.wp-list-table .column-elit_fb_shares { width: 10%; }';
    echo '.wp-list-table .column-elit_fb_comments { width: 10%; }';
}
add_action('admin_head', 'elit_fb_admin_header');


function elit_fb_counts_management_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permission to access this page.');
    }
    require('includes/options-page-wrapper.php');
}
function elit_fb_make_csv() {
  $id_list = elit_fb_get_posts();
  
  echo sprintf('%s,%s,%s,%s,%s,%s', 'link', 'shares', 'likes', 'comments', 'last_updated', 'id');
  echo  PHP_EOL;
  
  // print each data row and increment count
  foreach ($id_list as $id) {
    $stats_raw = get_post_meta($id->id, 'elit_fb', true);
    $stats = unserialize($stats_raw);
    echo sprintf('%s,%s,%s,%s,%s,%s', 
      get_permalink($id->id),
      $stats['elit_fb_shares'],
      $stats['elit_fb_likes'],
      $stats['elit_fb_comments'],
      '2015-08-10',
      $id->id
    ); 
    echo  PHP_EOL;
    
  }
}
function elit_fb_activation() {
    elit_fb_process_posts();
    if (!wp_next_scheduled('elit_fb_cron_hook')) {
        wp_schedule_event(time(), 'daily', 'elit_fb_cron_hook');
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
                // remove wptexturize for a sec so we don't get any curly 
                // quotes, etc.
                remove_filter('the_title', 'wptexturize');
                $reportItem['title'] = 
                  wp_kses_decode_entities(get_the_title($post->id));
                add_filter('the_title', 'wptexturize');
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
                $logger->addInfo('reportItem: ' . var_export($reportItem, true));
            }
    
            update_post_meta($post->id, 'elit_fb', serialize($newStats));
        }

        $logger->addInfo("\t\tProcessing $post->id");
        /**
         * Add new totals to each report item
         *
         */
        for ($i = 0; $i < count($report); $i++) {
          $newStatsArray = get_post_meta($report[$i]['id'], 'elit_fb', true);
          $newStats = unserialize($newStatsArray);
          $report[$i]['new_stats'] = $newStats;
        }
        
        $logger->addInfo('Report to send: ' . var_export($report, true));

        $email = elit_format_html_email($report, $logger);

        $logger->addInfo('Email to send: ' . $email);

        $emailBody = '';
        $emailBody .= PHP_EOL;
//        $emailBody .= "* * * * *                        * * * * *" . PHP_EOL;
//        $emailBody .= "* * * * * *      B E T A       * * * * * *" . PHP_EOL;
//        $emailBody .= "* * * * *                        * * * * *" . PHP_EOL . PHP_EOL;
        $emailBody .= 'Activity since yesterday ...' . PHP_EOL;
        $emailBody .= PHP_EOL;
        foreach ($report as $item) {
          $newStatsArray = get_post_meta($item['id'], 'elit_fb', true);
          $newStats = unserialize($newStatsArray);
          $emailBody .= '---------------' . PHP_EOL;
          $emailBody .= '  U P D A T E  ' . PHP_EOL;
          $emailBody .= '---------------' . PHP_EOL;
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

        //if (!empty($report)) {
            //$html = file_get_contents(
                //plugin_dir_path(__FILE__) .  'includes/test-email-2.php'
            //);
            //$mailed = elit_email_report($emailBody);
            //$mailed = elit_email_report($html);
            $mailed = elit_email_report($email);
        //}

        $logger->addInfo('Mailed? ' . ($mailed != null ? 'Yes' : 'No'));
        
    }
}
add_action('elit_fb_cron_hook' , 'elit_fb_process_posts');

function elit_set_html_content_type()
{
    return 'text/html';
}

function elit_report_date()
{
    return Carbon::yesterday()->format('F j, Y');
}

function elit_email_report($body)
{
    add_filter('wp_mail_content_type', 'elit_set_html_content_type');

    $mailed = wp_mail(
        array( 
            'psinco@osteopathic.org',
            //'bjohnson@osteopathic.org',
        ),
        "The DO: Facebookkeeping for " . elit_report_date(), 
        $body
        //'Content-Type: text/plain'
    );

    remove_filter('wp_mail_content_type', 'elit_set_html_content_type');
}

class Elit_List_Table extends WP_List_Table
{
    // 'link', 'shares', 'likes', 'comments', 'last_updated', 'id'

    var $example_data = array(
        
        array(
            'ID' => 164291,
            'post' => 'Excelling under pressure: DOs share their insights, strategies',
            'post_date' => '2014-01-03 16:21:10',
            'elit_fb_shares' => 3,
            'elit_fb_likes' => 4,
            'elit_fb_comments' => 0,
        ),
        array(
            'ID' => 164293,
            'post' => 'Retirement: What life after medicine looks like for 4 DOs',
            'post_date' => '2014-01-07 11:29:56',
            'elit_fb_shares' => 0,
            'elit_fb_likes' => 1,
            'elit_fb_comments' => 0,
        ),

    );

    public function __construct()
    {
        global $status, $page;

        parent::__construct(array(
            'singular' => 'wp_list_text_link',
            'plural' => 'wp_list_text_links',
            'ajax' => false,
        ));
        
    }
    
    function column_default($item, $column_name) {
        switch($column_name) {
            case 'post':
                return '<a href="' . home_url('?p=') . $item['ID'] . '">' . $item[$column_name] . '</a>';
            case 'id':
                return $item[$column_name];
//            case 'post_date':
//                return $item['post_date'];
//            case 'elit_fb_likes':
//                return (int) $item['elit_fb_likes'];
            case 'elit_fb_shares':
                return $item[$column_name];
            case 'elit_fb_comments':
                return $item[$column_name];
            default: 
                return print_r($item, true);
        }
    }

    function column_elit_fb_likes($item) {
      return $item['elit_fb_likes'];
    }

    function column_post_date($item) {
      return date('M. j, Y', strtotime($item['post_date']));
    }
    
    function get_columns() {

        $columns = array(
            'id'               => 'ID',
            'post'             => 'Post',
            'post_date'        => 'Date posted',
            'elit_fb_likes'    => 'Likes',
            'elit_fb_shares'   => 'Shares',
            'elit_fb_comments' => 'Comments',
        );

        return $columns;
    }
      
    function get_sortable_columns() {
        $sortable_columns = array(
            'post_date' => array('post_date', true),
            'elit_fb_likes' => array('elit_fb_likes', false),
            'elit_fb_shares' => array('elit_fb_shares', false),
            'elit_fb_comments' => array('elit_fb_comments', false),
        );

        return $sortable_columns;
    }

    function prepare_items() {
        global $wpdb;
        $per_page = 20;

        $columns = $this->get_columns();
        $hidden = array('id');
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        //$data = $this->example_data;
        // DB query goes here
        //SELECT p.ID, p.post_title, DATE_FORMAT(p.post_date, '%b %e, %Y') as post_date, pm.meta_value
        $q = "
            SELECT p.ID, p.post_title, p.post_date, pm.meta_value
            FROM {$wpdb->prefix}posts p INNER JOIN {$wpdb->prefix}postmeta pm
                ON p.id = pm.post_id
            WHERE
                p.post_type = 'post'
                AND p.post_status = 'publish'
                AND pm.meta_key = 'elit_fb'
            ORDER BY p.post_date DESC
        ";
        $results = $wpdb->get_results($q);

        $data = array();
        
        for ($i = 0; $i < count($results); $i++) {
            $stats = unserialize(unserialize($results[$i]->meta_value));
            array_push($data, array(
                'ID' => $results[$i]->ID,
                'post' => $results[$i]->post_title,
                'post_date' => $results[$i]->post_date,
                'elit_fb_likes' =>  (int) $stats['elit_fb_likes'],
                'elit_fb_shares' =>  (int) $stats['elit_fb_shares'],
                'elit_fb_comments' =>  (int) $stats['elit_fb_comments'],
            ));
        }

        function usort_reorder($a,$b){
            //If no sort, default to post_date
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'post_date'; 
            //If no order, default to desc
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc'; 
            if (gettype($a[$orderby]) == 'string' && gettype($b[$orderby]) == 'string') {
                $result = strcmp($a[$orderby], $b[$orderby]); //Determine sort order
            } else {
                $result = $a[$orderby] - $b[$orderby]; //Determine sort order
            }
            return ($order==='asc') ? $result : -$result; //Send final sort direction to usort
        }

        usort($data, 'usort_reorder');


        $current_page = $this->get_pagenum();
        $total_items = count($data);
        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);
        $this->items = $data;

        $this->set_pagination_args( 
            array(
                //WE have to calculate the total number of items
                'total_items' => $total_items,
                //WE have to determine how many items to show on a page
                'per_page'    => $per_page,
                //WE have to calculate the total number of pages
                'total_pages' => ceil($total_items/$per_page)
            ) 
        );
    }
}
