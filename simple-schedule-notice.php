<?php
/*
  Plugin Name: Simple Schedule Notice
  Plugin URI: http://tenjinyama.wp.xdomain.jp/category/simpleschedulenotice
  Description: Sends notice mail of scheduled tasks on the date specified.
  Author: E.Kamiya
  Author URI: http://tenjinyama.wp.xdomain.jp/
  Text Domain: smpl_shcd_notice
  Domain Path: /languages
  Version: 1.0.8
 */
include_once 'definitions.php';

class Simple_Schedulenotice_Class {

  var $selfurl;
  var $module_title, $menu_title;
  var $sys_timezone, $wp_timezone, $msg, $system_error;
  var $actionhooks;

  //constructor
  public function __construct() {
    //add_action( 'plugins_loaded', array( $this, 'simschntc_plugins_loaded' ));
    add_action( 'admin_menu', array($this, 'simschntc_admin_menu' ));
    add_action( 'admin_enqueue_scripts', array($this, 'simschntc_enqueue_styledef' ));
    register_activation_hook( __FILE__, array($this, 'simschntc_activation' ));
    register_deactivation_hook( __FILE__, array($this, 'simschntc_deactivation' ));
    //setup events
    $this->actionhooks = array(
      cActionHook           => 'simschntc_process_event',
      cActionHook_immediate => 'simschntc_process_event',
      cActionHook_test      => 'simschntc_process_testevent'
    );
    foreach ( $this->actionhooks as $hook => $action ) {
      add_action( $hook, array( $this, $action ));
    }
    //omit appending posttype on mail title
    add_filter( 'private_title_format', array($this, 'simschntc_title_format' ));
    //set system error handler .. debuging only
    //set_error_handler([$this, 'strict_error_handler']);
    //set language
    load_plugin_textdomain( cPluginID, false, dirname( plugin_basename( __FILE__ )) . '/languages' );
    //initialize variables
    $this->module_title = __( 'Simple Schedule Notice', cPluginID );
    $this->menu_title   = __( 'Schedule Notice', cPluginID );
    $this->sys_timezone = new DateTimeZone( date_default_timezone_get() );      //system timezone
    $this->wp_timezone  = new DateTimeZone( get_option( 'timezone_string' ));  //wordpress timezone
    $this->system_error = array();
  }

  //register admin menu
  public function simschntc_admin_menu() {
    add_management_page(                // add into "Tools" menu
      $this->module_title,              //page_title
      $this->menu_title,                //menu_title
      'administrator',                  //capability
      cPluginID,                        //menu_slug
      array($this, 'simschntc_process_admin_page_request' )
    );
  }

  //enqueue style definition
  public function simschntc_enqueue_styledef( $hook ) {
    //apply css & js on admin screen
    if( false === strpos( $hook, cPluginID )) { return; }
    wp_enqueue_style ( cPluginID . '_cs', plugins_url( 'simple-schedule-notice.css', __FILE__ ));
    wp_enqueue_script( cPluginID . '_js', plugins_url( 'simple-schedule-notice.js' , __FILE__ ));
  }

  //process plugin activation
  public function simschntc_activation() {
    $this->schedule_event();    //schedule action
  }

  //process plugin de-activation
  public function simschntc_deactivation() {
    $this->unschedule_event();    //remove action
  }

  //process events
  public function simschntc_process_event() {
    $this->scan_notifications();
  }

  public function simschntc_process_testevent() {
    $this->scan_notifications( True );
  }

  //process plugin removal
  public function simschntc_title_format( $format ) {
    //do not append post status on the title
    $format = '%s';   //in order that my NetBeans does not report "variable not used" error.
    return $format;
  }

  //process page request ...
  public function simschntc_process_admin_page_request() {
    $this->system_error = array();       //clear error messages.
    $dir = $this->get_direction(
            'action',
            array( 'edit', 'delete', 'mailtest', 'insert', 'update', 'discard' ),
            'list' );
    $this->selfurl = $_SERVER[ "REQUEST_URI" ];
    $this->msg = null;
    $suppress_list = False;
    switch ( $dir ) {
      case 'list':
        break;
      case 'options':
        $baseinfo = $this->get_baseinfo( 'post' );
        $err = $this->check_baseinput( $baseinfo );
        if( empty( $err )) {
          $this->update_baseinfo( $baseinfo );
        }
        $suppress_list = $this->gen_setting_page( $baseinfo, $err );
        break;
      case 'new':             //show detail screen
        $post_id = 0;
        list( $post_info, $post_meta_info ) = $this->get_defaultdata();
        $suppress_list = $this->gen_detail_page( $post_id, $post_info, $post_meta_info );
        break;
      case 'edit':
        $post_id = $this->PVal( $dir, 0 );
        list( $post_info, $post_meta_info ) = $this->get_dbdata( $post_id );
        $suppress_list = $this->gen_detail_page( $post_id, $post_info, $post_meta_info );
        break;
      case 'delete':
        $post_id = $this->PVal( $dir, 0 );
        wp_delete_post( $post_id, True );        // relating postmetas are removed by the system
        break;
      case 'mailtest':
        $post_id = $this->PVal( $dir, 0 );
        $this->scan_notifications_test( $post_id );
        break;
      case 'insert':
      case 'update':
        $post_id = ('insert' == $dir ? 0 : $this->PVal( 'POSTID', 0 ));
        list( $post_info, $post_meta_info ) = $this->get_postdata();
        $err = $this->check_detailinput( $post_info, $post_meta_info );
        if( !empty( $err )) {
          $suppress_list = $this->gen_detail_page( $post_id, $post_info, $post_meta_info, $err );
        } else {
          $this->update_schedule( $post_id, $post_info, $post_meta_info );
        }
        break;
      case 'immediate':
        $this->schedule_event( cActionHook_immediate );
        break;
      case 'resched':
        $this->unschedule_event();  //fall through
      case 'sched':
        $this->schedule_event();
        break;
      case 'discard':
        $this->unschedule_event( $this->PVal( 'discard', '' ));
        break;
      case 'test':
        $this->schedule_event( cActionHook_test );
        break;
      default :
        echo '<div>error .. wrong request ： ' . $_SERVER[ 'QUERY_STRING' ] . '</div>';
        break;
    }
    if( !$suppress_list ) {
      $this->gen_setting_page();
    }
  }

  //generate base info screen
  private function gen_setting_page( $baseinfo = null, $err = array() ) {
    global $post, $authordata;     //setup_postdata requires "global $post" is used

    $this->gen_article_hdr( 'shdntc-general' );
    if( empty( $baseinfo )) {
      $baseinfo = $this->get_baseinfo( 'db' );
    }
    $args = array(
      'post_type'      => cPluginID,
      'post_status'    => 'private',
      'posts_per_page' => 999,
    );
    $schedules = get_posts( $args );

    require_once 'class-genfset.php';
    $fset = new GenFset_Class( 60, array( 'fieldset'=>'shdntc-fldset', 'info'=>'shdntc-remarks', 'err'=>'shdntc-errors'  ));
    ?>
    <form id="form_bas" action="<?php echo $this->selfurl; ?>" method="post">
      <section class="shdntc-admblock">
        <h3><?php _e( 'General Settings', cPluginID ); ?></h3>
        <?php
        $fset->open( __( 'Destinations', cPluginID ));
        $fid = 'default_mailaddr1';
        $fset->l_input( '1 ) ', $fid, $baseinfo[ $fid ], array('im' => 'email', 'ph' => __( 'Enter destination 1.', cPluginID )));
        $fset->plaintext( '<br />' );
        $fset->remarks( 'err', $this->AVal($fid, $err));
        $fid = 'default_mailaddr2';
        $fset->l_input( '2 ) ', $fid, $baseinfo[ $fid ], array('im' => 'email', 'ph' => __( 'Enter destination 2.', cPluginID )));
        $fset->remarks( 'err', $this->AVal($fid, $err));
        $fset->close();
        $fset->open( __( 'Notification Time', cPluginID ));
        $fid = 'notify_time';
        $fset->tag( 'div', 'style=display: inline-block;' );
          $fset->input( $fid, $baseinfo[ $fid ], array( 'sz'=>10, 'ph' => __( 'Enter notification time.', cPluginID )) );
          $fset->tag( 'span', 'style=margin-left: 1em;' );
          $fset->plaintext( '( 00:00:00 .. 23:59:59 )' );
          $fset->c_tag();
        $fset->c_tag();
        $fset->remarks( 'err', $this->AVal($fid, $err));
        $fset->close();
        ?>
        <br/>
        <button type="submit" name="action" value="options"><?php _e( 'Save', cPluginID ); ?></button>
      </section>
      <div style="margin-top: 16px;margin-bottom: 32px"><hr width="90%" /></div>
      <section class="shdntc-admblock">
        <h3><?php _e( 'Notification List', cPluginID ); ?></h3>
        <?php if( $schedules ) : ?>
          <table class="shdntc-list">
            <thead><tr>
                <th><?php _e( 'Title', cPluginID ); ?></th>
                <th><?php _e( 'Edit', cPluginID ); ?></th>
                <th><?php _e( 'Delete', cPluginID ); ?></th>
                <th><?php _e( 'Test', cPluginID ); ?></th>
                <th><?php _e( 'Author', cPluginID ); ?></th>
                <th><?php _e( 'Year', cPluginID ); ?></th>
                <th><?php _e( 'Month', cPluginID ); ?></th>
                <th><?php _e( 'Day', cPluginID ); ?></th>
                <th><?php _e( 'Last Processed', cPluginID ); ?></th>
              </tr></thead>
            <tbody>
              <?php
              foreach ( $schedules as $post ) :
                setup_postdata( $post );
                list($post_info, $post_meta_info) = $this->get_dbdata();
                list($yr, $mo, $da) = sscanf( $post_meta_info[ 'date' ], '%04d-%02d-%02d' );
                $cyclbl = $this->get_cycle( $yr, $mo, $da, 'L' );
                ?>
                <tr>
                  <td><strong><?php echo $post_info[ 'post_title' ]; ?></strong></td>
                  <td><button type="submit" name="edit"     value="<?php the_ID(); ?>"><?php _e( 'Edit', cPluginID ); ?></button></td>
                  <td><button type="submit" name="delete"   value="<?php the_ID(); ?>" onclick="return confirm(<?php echo $this->Quot($this->get_msgtext( 'cfds' )); ?>);" ><?php _e( 'Delete', cPluginID ); ?></button></td>
                  <td><button type="submit" name="mailtest" value="<?php the_ID(); ?>"><?php _e( 'Test', cPluginID ); ?></button></td>
                  <td><?php the_author(); ?></td>
                  <td><?php echo $cyclbl; ?></td>
                  <td><?php echo (0 == $mo ? '' : $mo); ?></td>
                  <td><?php echo (0 == $da ? '' : $da); ?></td>
                  <td><?php echo $post_meta_info[ 'processed' ]; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <P><?php _e( 'No notification task.', cPluginID ); ?></P>
        <?php endif; ?>
        <?php
        if( !empty( $this->msg )) {
          $fset->open();
          $fset->remarks( 'info', $this->msg );
          $fset->close();
        }
        ?>
        <br />
        <button type="submit" name="action" value="new"><?php _e( 'Add New', cPluginID ); ?></button>
      </section>
      <section class="shdntc-admblock">
        <div style="margin-top: 16px;margin-bottom: 32px"><hr width="90%" /></div>
        <h3><?php _e( 'Next Actions Registered', cPluginID ); ?></h3>
        <div>
          <table class="shdntc-list">
            <tbody>
              <?php
              $shedcnt = 0;
              foreach ( array_keys( $this->actionhooks ) as $hook ) {
                $shcdinfo = wp_next_scheduled( $hook );
                if( $shcdinfo ) {
                  $shedcnt++;
                  $msg = $this->get_msgtext( 'cfdh' );
                  $schd = wp_get_schedule( $hook );
                  echo
                    '<tr>'
                  . '<td>' . $hook . '</td>'
                  . '<td>at ' . $this->get_wp_timestr( $shcdinfo ) . '</td>'
                  . '<td>cycle : ' . (empty( $schd ) ? 'one time' : $schd) . '</td>'
                  . '<td><button type="submit" name="discard" value="' . $hook . '" onclick="return confirm(' . $this->Quot( $msg ) . ')">' . __( 'Discard', cPluginID ) . '</button></td>'
                  . '</tr>';
                }
              }
              if( 0 == $shedcnt ) {
                echo '<tr><td>' . __( 'No action is registered', cPluginID ) . '</td></tr>';
              }
              ?>
            </tbody>
          </table>
        </div>
        <br />
        <div style="display:inline;">
        <?php if( 0 == $shedcnt ): ?>
          <button type="submit" name="action" value="sched"><?php _e( 'Set Normal Action', cPluginID ); ?></button>
        <?php else: ?>
          <button type="submit" name="action" value="resched"><?php _e( 'Reset Normal Action', cPluginID ); ?></button>
        <?php endif; ?>
          &nbsp;&nbsp;&nbsp;&nbsp;
          <button type="submit" name="action" value="immediate"><?php _e( 'Add Immediate Action', cPluginID ); ?></button>
        </div>
      </section>
      <section class="shdntc-admblock">
        <div style="margin-top: 16px;margin-bottom: 32px"><hr width="90%" /></div>
        <h3><?php _e( 'System Information', cPluginID ); ?></h3>
        <div>
          <table class="shdntc-list">
            <tbody>
              <tr>
                <td><?php _e( 'PHP Time', cPluginID ); ?></td>
                <td><?php echo date( "Y-m-d H:i:s" ); ?></td>
                <td><?php echo $this->sys_timezone->getName(); ?></td>
              </tr>
              <tr>
                <td><?php _e( 'WP time', cPluginID ); ?></td>
                <td><?php   $wdt = new DateTime( 'now', $this->wp_timezone );
                             echo $wdt->format( "Y-m-d H:i:s" ); ?></td>
                <td><?php echo $this->wp_timezone->getName(); ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        <br />
        <button type="submit" name="action" value="test"><?php _e( 'Add Test Action', cPluginID ); ?></button>
        &nbsp;&nbsp;&nbsp;&nbsp;<span style="color:olive;"><?php _e( '* All notifications are processed. Processing date is not updated.', cPluginID ); ?></span>
      </section>
    </form>
    <?php
    $this->gen_article_ftr();
    return True;
  }

  //generate detail screen
  private function gen_detail_page( $post_id, $post_info, $post_meta_info, $err = array() ) {
    $this->gen_article_hdr( 'shdntc-detail' );

    require_once 'class-genfset.php';
    $fset = new GenFset_Class( 60, array( 'fieldset'=>'shdntc-fldset', 'info'=>'shdntc-remarks', 'err'=>'shdntc-errors'  ));
    ?>
    <h3><?php _e( 'Notification Settings', cPluginID ); ?></h3>
    <form id="form_dtl" action="<?php echo $this->selfurl; ?>" method="post">
      <?php
      echo '<input type="hidden" name="POSTID" value=' . (string) $post_id . '>';
      echo '<input type="hidden" name="post_author" value=' . $this->AVal( 'post_author', $post_info, '0' ) . '>';
      $fset->open( __( 'Title', cPluginID ));
      $fset->input( 'post_title', $this->AVal( 'post_title', $post_info ), array( 'im' => 'kana' ));
      $fset->remarks( 'err', $this->AVal('post_title', $err));
      $fset->close();
      list($yr, $mo, $da) = sscanf( $this->AVal( 'date', $post_meta_info ), '%04d-%02d-%02d' );
      $cycnam = $this->get_cycle($yr, $mo, $da, 'n');
      $fset->open( __( 'Notification Cycle', cPluginID ));
      $fset->tag( 'p', 'style=margin: 0.5em 0em;' );
      $fset->radio('cycle', $this->cycledef(), $cycnam, array( 'onClick'=>'cy_ctl()' ));
      $fset->c_tag();
      $fset->tag( 'p', 'style=margin: 0.5em 0em;' );
      $fset->tag( 'span', 'style=margin-right: 2em;' );
      $fset->plaintext( __( 'Year :', cPluginID ) . '&nbsp;' );
      $fset->input( 'YR', $yr, array('im' => 'numeric', 'sz' => 4 ));
      $fset->c_tag();
      $fset->tag( 'span', 'style=margin-right: 2em;' );
      $fset->plaintext( __( 'Month :', cPluginID ) . '&nbsp;' );
      $fset->input( 'MO', $mo, array('im' => 'numeric', 'sz' => 2 ));
      $fset->c_tag();
      $fset->tag( 'span' );
      $fset->plaintext( __( 'Day :', cPluginID ) . '&nbsp;' );
      $fset->input( 'DA', $da, array('im' => 'numeric', 'sz' => 2 ));
      $fset->c_tag();
      $fset->c_tag();
      $fset->remarks( 'info', $this->get_msgtext( 'ifdt' ));
      $fset->remarks( 'err', $this->AVal('date', $err));
      $fset->close();
      $fset->open( __( 'Common Destinations', cPluginID ));
      $fset->tag( 'span', 'class="checkbox_layout"' );
      $fid = 'suppress_default1';
      $fset->checkbox( $fid, __( 'Do not send to destination 1', cPluginID ), $this->AVal( $fid, $post_meta_info, False ));
      $fset->plaintext( '<br/>' );
      $fset->remarks( 'err', $this->AVal($fid, $err));
      $fid = 'suppress_default2';
      $fset->checkbox( $fid, __( 'Do not send to destination 2', cPluginID ), $this->AVal( $fid, $post_meta_info, False ));
      $fset->remarks( 'err', $this->AVal($fid, $err));
      $fset->c_tag();
      $fset->close();
      $fset->open( __( 'Additional Destinations', cPluginID ));
      $fid = 'mailaddr1';
      $fset->l_input( '1 )', $fid, $this->AVal( $fid, $post_meta_info ), array('im' => 'email', 'ph' => __( 'Mail address of additional destination 1', cPluginID )) );
      $fset->remarks( 'err', $this->AVal($fid, $err));
      $fset->plaintext( '<br />' );
      $fid = 'mailaddr2';
      $fset->l_input( '2 )', $fid, $this->AVal( $fid, $post_meta_info ), array('im' => 'email', 'ph' => __( 'Mail address of additional destination 2', cPluginID )) );
      $fset->remarks( 'err', $this->AVal($fid, $err));
      $fset->close();
      $fset->open( __( 'Text', cPluginID ));
      $fid = 'post_content';
      $fset->input( $fid, $this->AVal( 'post_content', $post_info ), array('im' => 'kana', 'ty' => 'textarea' ));
      $fset->remarks( 'err', $this->AVal($fid, $err));
      $fset->close();
      $fset->open( __( 'Last Processed Date', cPluginID ));
      $fset->tag( 'span', 'style=display: inline; vertical-align: middle;' );
      $fid = 'processed';
      $fset->input( $fid, $this->AVal( $fid, $post_meta_info ), array('ro' => True, 'sz' => 16, 'st'=>array('margin-right: 2em')) );
      $fset->checkbox( 'clearproc', __( 'Clear Last Processed Date', cPluginID ));
      $fset->c_tag();
      $fset->close();
      ?>
      <br />
      <div style="display: inline;">
        <button type="submit" name="list" value=""><?php _e( 'Cancel', cPluginID ); ?></button>
        <span>&nbsp;&nbsp;</span>
        <?php if( empty( $post_id )): ?>
          <button type="submit" name="insert" value="" class="list-cell-m"><?php _e( 'Add', cPluginID ); ?></button>
        <?php else: ?>
          <button type="submit" name="update" value=""><?php _e( 'Update', cPluginID ); ?></button>
        <?php endif; ?>
      </div>
      <?php
      ?>
    </form>
    <?php
    $this->gen_article_ftr();
    return True;
  }

  //system error handler
  public function strict_error_handler($errno, $errstr, $errfile, $errline) {
    $this->system_error[] = sprintf('%04d', $errno) . ' ' . $errstr . ' at : ' . $errfile . ' / ' . (string)$errline;
  }

  //** Domestic Library **
  private function scan_notifications( $testmode = False ) {
    global $post;

    $base_info = $this->get_baseinfo( 'db' );
    //get current date in wp timuzone
    $curtime = new DateTime( 'now', $this->wp_timezone );
    $today_str = $curtime->format( "Y-m-d" );   // 'yyyy-mm-dd'
    $today_sys = strtotime( $today_str );
    $today_arr = explode( "-", $today_str );
    $args = array(
      'post_type'      => cPluginID,
      'post_status'    => 'private',
      'posts_per_page' => 100,
    );
    $schedules = get_posts( $args );
    foreach ( $schedules as $post ) {
      setup_postdata( $post );
      $post_id = get_the_ID();
      list($post_info, $post_meta_info) = $this->get_dbdata();
      $schdday_str = $post_meta_info[ 'date' ];
      list($yr, $mo, $da) = sscanf( $schdday_str, '%04d-%02d-%02d' );
      if( 0 == $da ) { $da = $today_arr[ 2 ]; }
      if( 0 == $mo ) { $mo = $today_arr[ 1 ]; }
      if( 0 == $yr ) { $yr = $today_arr[ 0 ]; }
      $schdday_str = sprintf( '%04d-%02d-%02d', $yr, $mo, $da );
      $schdday_sys = strtotime( $schdday_str );
      //＊＊ ロジックを再確認のこと ＊＊
      if( $testmode or ( $schdday_sys <= $today_sys )) {   //already met
        $lastprc_str = $post_meta_info[ 'processed' ];
        $lastprc_sys = strtotime( $lastprc_str );
        if( $testmode or ( $lastprc_sys < $schdday_sys )) {  //not processed yet
          $this->process_notification( $post_id, $base_info, $post_info, $post_meta_info, ($testmode ? '' : $today_str ));
        }
      }
    }
  }

  private function scan_notifications_test( $post_id ) {
    //send test mail
    global $post;

    $base_info = $this->get_baseinfo( 'db' );
    $post = get_post( $post_id );
    setup_postdata( $post );
    list($post_info, $post_meta_info) = $this->get_dbdata();
    $this->process_notification( $post_id, $base_info, $post_info, $post_meta_info, null );
  }

  private function process_notification( $post_id, $base_info, $post_info, $post_meta_info, $procdatestr = '' ) {
    //send single mail
    // $procdatestr .. process date; empty if test
    $result = False;
    $post_title   = html_entity_decode( $this->AVal( 'post_title', $post_info ));
    $post_content = html_entity_decode( $this->AVal( 'post_content', $post_info ));
    $maillist     = array();
    if( !$post_meta_info[ 'suppress_default1' ] ) {
      $this->array_append( $maillist, $base_info[ 'default_mailaddr1' ] );
    }
    if( !$post_meta_info[ 'suppress_default2' ] ) {
      $this->array_append( $maillist, $base_info[ 'default_mailaddr2' ] );
    }
    $this->array_append( $maillist, $post_meta_info[ 'mailaddr1' ] );
    $this->array_append( $maillist, $post_meta_info[ 'mailaddr2' ] );
    $failed = array();
    $title_prefix = (empty( $procdatestr ) ? __( 'Test Transmission:', cPluginID ) : '');
    foreach ( $maillist as $mailaddr ) {
      //ek20210305    1 line(s) replaced ..
      //$result = wp_mail( $mailaddr, $title_prefix . $post_title, $post_content );
			$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );   // Get the site domain
			if( 'www.' === substr( $sitename, 0, 4 )) {
				$sitename = substr( $sitename, 4 );
			}
			if( False === strpos( $sitename, '.' )) {
				$sitename .= '.jp';
			}
      $post_header  = [ 'From: schedule-notifice <schedule-notifice@' . $sitename . '>' ];
      $result = wp_mail( $mailaddr, $title_prefix . $post_title, $post_content, $post_header );
      //^^^^^^^^
      if( !$result ) {
        $failed[] = $mailaddr;
      }
    }
    if( empty( $failed )) {
      $this->msg[ $post_id ] = $post_title . ' .. ' . __( 'Test transmission was processed.', cPluginID );
      //update last process date
      if( !empty( $procdatestr )) {
        $post_meta_info[ 'processed' ] = $procdatestr;
        update_post_meta( $post_id, cPluginID, $post_meta_info );
      }
    } else {
      $this->msg[ $post_id ] = '<span style="color: red;">' . $post_title . ' .. ' .  __( 'Test transmission failed.', cPluginID ) . '</span><br />'
              . implode ( ', ' , $failed );
    }
  }

  private function get_baseinfo( $src = null ) {
    switch ( $src ) {
      case 'db':
        $baseinfo = get_option( cPluginID, null );
        if( empty($baseinfo)) {
          $baseinfo = array( 'default_mailaddr1' => null, 'default_mailaddr2' => null, 'notify_time'=>'07:00:00' );
        }
        break;
      case 'post':
        $baseinfo = array( 'default_mailaddr1' => null, 'default_mailaddr2' => null, 'notify_time'=>null );
        foreach ( array_keys( $baseinfo ) as $key ) {
          $baseinfo[ $key ] = $this->PVal( $key );
        }
        break;
      default:
        $baseinfo = array();
        $this->system_error[] = '"get_baseinfo" requires proper data source.';
    }
    return $baseinfo;
  }

  private function get_defaultdata() {
    $current_user = wp_get_current_user();
    $post_info = array(
      'post_title'     => '',                     
      'post_content'   => '',                     
      'post_author'    => $current_user->id,      
      'post_type'      => cPluginID,              
      'post_status'    => 'private',              
      'ping_status'    => 'closed',               
      'comment_status' => 'closed',               
    );
    $post_meta_info = array(
      'suppress_default1' => False,
      'suppress_default2' => False,
      'date'              => '0000-00-00',
      'mailaddr1'         => '',
      'mailaddr2'         => '',
      'processed'         => $this->get_wp_timestr( time(), 'Y-m-d' ),
    );
    return array( $post_info, $post_meta_info );
  }

  private function get_postdata() {
    $post_info = array(
      'post_title'     => $this->PVal( 'post_title', '' ),      
      'post_content'   => $this->PVal( 'post_content', '' ),    
      'post_author'    => $this->PVal( 'post_author', 0 ),      
      'post_type'      => cPluginID,              
      'post_status'    => 'private',              
      'ping_status'    => 'closed',               
      'comment_status' => 'closed',               
    );
    $yr = $this->PVal( 'YR', null );
    $mo = $this->PVal( 'MO', null );
    $da = $this->PVal( 'DA', null );
    $post_meta_info = array(
      'suppress_default1' => $this->PVal( 'suppress_default1', False ),
      'suppress_default2' => $this->PVal( 'suppress_default2', False ),
      'date'              => sprintf( '%04d-%02d-%02d', $yr, $mo, $da ),
      'mailaddr1'         => $this->PVal( 'mailaddr1' ),
      'mailaddr2'         => $this->PVal( 'mailaddr2' ),
      'processed'         => ($this->PVal( 'clearproc', False ) ? '' : $this->PVal( 'processed' )),
    );
    return array( $post_info, $post_meta_info );
  }

  private function get_dbdata( $post_id = null ) {
    //returns post data of $post_id
    // when $post_id is null, assumes "setup_postdata" is already done and only post_meta is retrieved
    global $post;

    if( empty( $post_id )) {
      $post_id = get_the_ID();
    } else {
      $post = get_post( $post_id );
      if( !is_null( $post )) {
        setup_postdata( $post );
      }
    }
    if( !is_null( $post )) {
      $post_info = array(
        'post_title'     => get_the_title(),          
        'post_content'   => get_the_content(),        
        'post_author'    => get_the_author(),         
        'post_type'      => cPluginID,                
        'post_status'    => $post->post_status,       
        'ping_status'    => $post->ping_status,       
        'comment_status' => $post->comment_status,    
      );
      $post_meta_info = get_post_meta( $post_id, cPluginID, True );
    }
    return array( $post_info, $post_meta_info );
  }

  private function update_baseinfo( $baseinfo ) {
    update_option( cPluginID, $baseinfo, False );
  }

  private function update_schedule( $post_id, $post_info, $post_meta_info ) {
    if( empty( $post_id )) {
      $post_id = wp_insert_post( $post_info );
    } else {
      $post_info[ 'ID' ] = $post_id;
      $post_id = wp_update_post( $post_info );
    }
    if( $post_id ) {
      update_post_meta( $post_id, cPluginID, $post_meta_info );
    } else {      
      //failed
      $this->system_error[] = '"wp_update_post" failed.';
    }
  }

  private function gen_article_hdr( $id=null ) {
    echo '<article' . ( empty( $id ) ? '' : ' id="' . $id . '"') . '><h1>' . $this->module_title . '</h1>';
    // gen_articleftr must be called to close <article>
  }

  private function gen_article_ftr() {
    //show error messages.
    if( !empty( $this->system_error )) : ?>
      <section>
        <div style="margin-top: 16px;margin-bottom: 32px"><hr width="90%" /></div>
        <h3>System Messages</h3>
        <div class="error">
          <ul>
            <?php foreach ($this->system_error as $message) {
              echo '<li>' . $message . '</li>';
             } ?>
          </ul>
        </div>
      </section>
      <?php
    endif;
    // close article
    echo '</article>';
  }

  private function AVal( $key, $array = array(), $default = null, $escape = False ) {
// Returns $array[ $key ] value converted to the type of $default,
//  or $default value if array value is not defined.
// if the type of $default is boolean, either 'true', 'on' or 'yes' returns TRUE.
    $ret = $default;
    if( isset( $array[ $key ] )) {
      $ret = $array[ $key ];
      $defaulttype = gettype( $default );  // boolean, integer, double, string, array, object, resource, null, unknown type
      switch ( $defaulttype ) {
        case 'boolean': //settype as boolean sets  FALSE to TRUE.
          switch ( gettype( $ret )) {
            case 'string':
              if( '' == $ret ) {
                $ret = False;
              } else {
                $valstr = strtolower( $ret );
                $ret = in_array( $valstr, array('true', 'on', 'yes' ));        //TRUE string found.
                if( !$ret ) {
                  $ret = (!in_array( $valstr, array('false', 'off', 'no' )));    //not(FALSE string found)
                  if( $ret ) {
                    $this->system_error[] = "--error in AVal : string '" . $valstr . "' encountered for a boolean param.";
                  }
                }
              }
              break;
            case 'integer':
              $ret = ($ret <> 0);
              break;
            case 'boolean':
              break;
            default:
              $this->system_error[] = "--error in AVal : type '" . gettype( $ret ) . "' not supported for a boolean param.";
              break;
          }
          break;
        case 'integer':
        case 'double' :
          settype( $ret, $defaulttype );
          break;
        case 'string':
          if( $escape ) {
            $ret = sanitize_text_field( $ret );
          }
          break;
        default:
          break;
      }
    }
    return $ret;
  }

  private function PVal( $nam, $default = null ) {
    return $this->AVal( $nam, $_POST, $default, True ); //escape=True
  }

  private function array_append( &$arr, $memb ) {
    // Add new member to the array, if $memb is not empty.
    // Returns nothing.
    if( !empty( $memb )) {
      if( is_array( $memb )) {
        foreach ( $memb as $elem ) {
          $arr[] = $elem;
        }
      } else {
        $arr[] = $memb;
      }
    }
  }

  private function get_direction( $simplekey, $valkeylist, $default = False ) {
    //Returns ：
    // 1) the value of $_POST[ $simplekey ] if the key exists.
    // 2) the word in simple array $valkeylist which is found in $_REQUEST,
    //    remind that [Key=>''] is given in $_REQUEST when value part is not specified.
    // 3) $default
    $dir = $default;
    if( isset( $_POST[ $simplekey ] )) {
      $dir = $_POST[ $simplekey ];
    } else {
      foreach ( array_keys( $_REQUEST ) as $key ) {
        if( in_array( $key, $valkeylist )) {
          $dir = $key;
          break;
        }
      }
    }
    return $dir;
  }

  private function check_baseinput( $baseinfo ) {
    //check base data fields
    $err = array();
    foreach ( $baseinfo as $key => $value ) {
      switch ( $key ) {
        case 'default_mailaddr1':
        case 'default_mailaddr2':
          if( !( empty( $value ) or is_email( $value ))) {
            $err += array( $key => array(  $this->get_msgtext( 'maer' )) );
          }
          break;
        case 'notify_time':
          list( $hour, $minu, $seco ) = sscanf( $value, '%02d:%02d:%02d' );
          $errntf = 0;
          if( is_null($hour) || ( 23 < $hour )) { $errntf++; }
          if( is_null($minu) || ( 59 < $minu )) { $errntf++; }
          if( is_null($seco) || ( 59 < $seco )) { $errntf++; }
          if( $errntf > 0 ) {
            $err += array( $key => array( __( 'Time specification is incorrect.', cPluginID )));
          }
          break;
        default:
          break;
      }
    }
    return $err;
  }

  private function check_detailinput( $post_info, $post_meta_info ) {
    //check detail screen fields
    $err = array();
    if( empty( $post_info[ 'post_title' ] )) {
      $err += array( 'post_title' => array( __( 'Please specify the title.', cPluginID )) );
    }
    $errdate = array();
    list($yr, $mo, $da) = sscanf( $post_meta_info[ 'date' ], '%04d-%02d-%02d' );
    if( ($da < 0) or ( 31 < $da)) {
      $errdate += array( __( 'Day value is out of range.', cPluginID ));
    }
    if( ($mo < 0) or ( 12 < $mo)) {
      $errdate += array( __( 'Month value is out of range.', cPluginID ));
    }
    if( (0 <> $yr) && (0 == $mo)) {
      $errdate += array( __( 'Month can not be omitted when year is specified. ', cPluginID ));
    }
    if( !empty( $errdate )) {
      $err += array( 'date' => $errdate );
    }
    $mailaddr1 = $post_meta_info[ 'mailaddr1' ];
    if( !( empty( $mailaddr1 ) or is_email( $mailaddr1 ))) {
      $err += array( 'mailaddr1' => array( $this->get_msgtext( 'maer' )) );
    }
    $mailaddr2 = $post_meta_info[ 'mailaddr2' ];
    if( !( empty( $mailaddr2 ) or is_email( $mailaddr2 ))) {
      $err += array( 'mailaddr2' => array( $this->get_msgtext( 'maer' )) );
    }
    if(  $post_info[ 'suppress_default1' ] and
          $post_info[ 'suppress_default2' ] and
          empty( $post_info[ 'mailaddr1' ] ) and
          empty( $post_info[ 'mailaddr2' ] )) {
      $err += array( 'suppress_default1' => __( 'At least one destination is required.', cPluginID ));
    }
    if( empty( $post_info[ 'post_content' ] )) {
      $err += array( 'post_content' => array(__( 'Please specify the text.', cPluginID )) );
    }
    return $err;
  }

  private function schedule_event( $action=null ) {
    switch ( $action ) {
      case cActionHook_immediate:
      case cActionHook_test:
        //activates once at once
        wp_schedule_single_event( $this->get_sys_timestamp( '+1 min' ), $action );
        break;
      default:
        //normal action; activates at the specified hour every day
        $base_info = $this->get_baseinfo( 'db' );
        $notifytime = $base_info[ 'notify_time' ];
        $systimestamp = $this->get_sys_timestamp( '+1 day', $notifytime );
        wp_schedule_event( $systimestamp, 'daily', cActionHook );
        break;
    }
  }

  private function unschedule_event( $hook = null) {
    //delete events
    if( $hook ) {
      wp_clear_scheduled_hook( $hook );
    } else {
      foreach ( array_keys( $this->actionhooks ) as $hook ) {
        $shcdinfo = wp_next_scheduled( $hook );
        if( $shcdinfo ) {
          wp_clear_scheduled_hook( $hook );
        }
      }
    }
  }

  private function get_sys_timestamp( $datemod = '', $timestr = '' ) {
    // Returns php timestamp
    //  $datemod  .. acceptable datetime modification. (ex. '+1 month'
    //  $timestr  .. ex. '07:00:00'
    $datetime = new DateTime( 'now', $this->wp_timezone );
    if( !empty( $datemod )) {
      $datetime->modify( $datemod );
    }
    if( !empty( $timestr )) {
      if( $this->wp_timezone->getLocation() <> $this->sys_timezone->getLocation() ) {
        $datetimestr = $datetime->format( 'Y-m-d' ) . ' ' . $timestr;
        unset( $datetime );
        $datetime = new DateTime( $datetimestr, $this->wp_timezone );
      }
    }
    $datetime->setTimeZone( $this->sys_timezone );
    return $datetime->getTimestamp();
  }

  private function get_wp_timestr( $sys_timestamp, $format = 'Y-m-d H:i:s' ) {
    // Convert system timestamp to wp time and Returns string value in specified format.
    $datetime = new DateTime( date( 'Y-m-d H:i:s', $sys_timestamp ), $this->sys_timezone );
    $datetime->setTimezone( $this->wp_timezone );
    return $datetime->format( $format );
  }

  private function cycledef() {
    return array(
      'day'     => __( 'Date',      cPluginID ),
      'yearly'  => __( 'Yearly',    cPluginID ),
      'monthly' => __( 'Monthly',   cPluginID ),
      'daily'   => __( 'Daily',     cPluginID ),
    );
  }

  private function get_cycle( $yr, $mo, $da, $mod='n' ) {
  //decode cycle value, and returns key, label, or Year value according to $mod
    $cycid = (0 == $da ? 3 : (0 == $mo ? 2 : (0 == $yr ? 1 : 0)));
    $cycle = array_slice( $this->cycledef(), $cycid, 1, true);
    switch ( $mod ) {
      case 'n' :   //name
        $ret = key( $cycle );
        break;
      case 'l':   //label
        $ret = current( $cycle );
        break;
      case 'L':   //label; 'day' returns year value
        if( 0==$cycid ) {
          $ret = $yr;
        } else {
          $ret = current( $cycle );
        }
        break;
      default:
        $ret = $cycle;
        break;
    }
    return $ret;
  }

  private function get_msgtext( $mid ) {
    //returns a massage specified by $mid. the massage would be ..
    //  array, used more than once, or any message you like to put here.
    switch ( $mid ) {
      case 'cfdh':
        $msg = __( 'Are you shure to discard this action?', cPluginID );
        break;
      case 'cfds':
        $msg = __( 'Are you shure to delete this notification?', cPluginID );
        break;
      case 'ifdt':
        $msg = array(
          __( 'Note that the task is not processed every month when day "31" is specified.', cPluginID ) ,
        );
        break;
      case 'maer':
        $msg = __( 'Not acceptable as mail address.', cPluginID );
        break;
      default :
        $msg = '( massage ID ' . $mid . ' is not defined. )';
        break;
    }
    return $msg;
  }

  private function Quot( $str, $sep="'" ) {
    return $sep . $str . $sep;
  }
}

new Simple_Schedulenotice_Class();
