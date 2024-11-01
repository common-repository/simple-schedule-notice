<?php
/* 
Description: called when uninstall "Schedule Notice".
Author: E.Kamiya
 */
include_once 'definitions.php';

if (defined('WP_UNINSTALL_PLUGIN')) {
  before_uninstall( cPluginID );
}

function before_uninstall( $plugin_id ) {
  delete_option( $plugin_id );
  $post_value = array(
    'numberposts'   => -1,        
    'post_type'     => $plugin_id,
    'post_status'   => 'any',     
  );
  $posts = get_posts( $post_value );
  foreach ($posts as $post) {
    wp_delete_post($post->ID, true);
  }
}
