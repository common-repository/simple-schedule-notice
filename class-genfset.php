<?php
/*
  Description: Generates html for a form.
  Author: E.Kamiya
 */

  const cRoBgcolor = '#F0FFF0';         //background color of ReadOnly field.

class GenFset_Class {
  /*** sample html generated :
   * <fieldset class="<?$fldclass?>">
   *   <legend>Password / Confirm</legend>
   *   <input type="password" size="10" name="LoginPass" value="" style="ime-mode:disabled;"/>
   *   <input type="password" size="10" name="ConfirmPass" value="" style="ime-mode:disabled;"/>
   * </fieldset>
   */

  private $legend, $fields, $fldsiz, $seq, $stat;
  private $tagstack, $tagcnt, $gerrcnt, $classdef, $browsername;

  function __construct( $siz = 80, $classdef = array() ) {
    // $classdef .. [ 'classid'=>'classname', ... ]
    //   where classid's are :
    //     fieldset ... class applied to <fieldset>
    //     ul       ... class applied to <ul>
    //mb_internal_encoding("UTF-8");
    $this->fldsiz      = $siz;
    $this->tagstack    = array();
    $this->tagcnt      = 0;
    $this->stat        = False;
    $this->classdef    = $classdef;
    $this->browsername = null;
  }

  //** user callable functions **
  function genFset( $leg, $nam, $val, $uop = null ) {
    $this->open( $leg );
    $this->input( $nam, $val, $uop );
    $this->close();
  }

  function open( $leg = '' ) {
    //tag <fieldset> will not be generated if legend is not specified.
    $this->legend = $leg;
    $this->fields = array();
    $this->seq    = 0;
    $this->stat   = True;
  }

  function hidden( $nam ) {
    $this->fields[] = '<input type="hidden" name="' . $nam . '" value="<?php echo $' . $nam . '; ?>"/>';
  }

  function input( $nam, $val = null, $uop = null ) {
    //$uop ...
    //'im'=>input mode     kana, email, etc. (may not work on many browsers)
    //'rj'=>justified right False or True
    //'ro'=>readonly       False or True
    //'st'=>style          array( 'keyword: value', ...)
    //'sz'=>item width     ($this->fldsiz)
    //'ty'=>type           text, textarea, number, date, range, etc.
    //'ph'=>place holder
    $opt = array_merge( array('ty' => 'text', 'sz' => $this->fldsiz ), $uop );
    if ( 'textarea' == $opt[ 'ty' ] ) {
      $genstr = '<textarea name="' . $nam . '" rows="8" cols="' . $opt[ 'sz' ] . '"';
      $stylestr = '';
      switch ( $this->get_browsername() ) {
        case 'MSIE':
        case 'Trident':
        case 'Edge':
          $stylestr .= $this->con( $stylestr, ' ' ) . 'width:' . (integer)$opt[ 'sz' ]*0.6 . 'em;';
          break;
        default:
          $stylestr .= $this->con( $stylestr, ' ' ) . 'resize:both;';
          break;
      }
      if ( !empty( $stylestr )) {
        $genstr .= ' style="' . $stylestr . '"';
      }
      $genstr .= '>' . $val . '</textarea>';
    } else {
      $genstr = '<input type="' . $opt[ 'ty' ] . '" name="' . $nam . '"';
      $stylestr = '';
      foreach ( $opt as $opkey => $opval ) {
        switch ( $opkey ) {
          case 'ty':
            break;
          case 'im':
            $genstr .= '  inputmode="' . $opval . '"';
            break;
          case 'ph':
            $genstr .= ' placeholder="' . (string) $opval . '"';
            break;
          case 'rj':
            $stylestr .= 'text-align:right;';
            break;
          case 'ro':
            //$str .= ' readonly data-theme="'.Theme(UPD, ALT).'"'; // for jQmobile
            $genstr .= ' readonly';
            $stylestr .= 'background-color:' . cRoBgcolor . ';';
            break;
          case 'st':
            foreach ( $opval as $stval ) {
              $stylestr .= $this->con( $stylestr, ' ' ) . $this->strterm( $stval, ';' );
            }
            break;
          case 'sz':
            if ( $opt[ 'ty' ] <> 'hidden' ) {
              $genstr .= ' size="' . (string) $opval . '"';
            }
            break;
          default:
            $genstr .= $this->con( $genstr, ' ' ) . $opkey . '="' . $opval . '"';
            break;
        }
      }
      if ( $stylestr <> '' ) {
        $genstr .= ' style="' . $stylestr . '"';
      }
      if ( !empty( $val ) ) {
        $genstr .= ' value="' . $val . '"';
      }
      $genstr .= '/>';
    }
    $this->fields[] = $genstr;
  }

  function l_input( $lbl, $nam, $val = null, $uop = null ) {
    //labeled input field
    $this->fields[] = '<label>' . $lbl;
    $this->input( $nam, $val, $uop );
    $this->fields[] = '</label>';
  }

  function checkbox( $nam, $lab, $chk = False, $uop = null ) {
    $opt = array( 'ro' => False );
    if ( !is_null( $uop ) ) {
      $opt = array_merge( $opt, $uop );
    }
    $id = $nam . '_' . strval( $this->seq++ );
    //$str  = '<input type="checkbox" name="'.$nam.'" id="'.$id.'"'.($chk ? ' checked' : '').' data-inline="true"';
    $str = '<label><input type="checkbox" name="' . $nam . '" id="' . $id . '"' . ($chk ? ' checked' : '')
             . ' style="margin-right:0.5em;"';
    if ( $opt[ 'ro' ] ) {
      $str .= '" disabled';
    }
    $str .= '>' . $lab . '</label>';
    $this->fields[] = $str;
  }

  function radio( $nam, $lab, $chk = False, $opt = array() ) {
  //generate radoi group;
  //assumes inline only at this version.
  // $lab     : an array of radios as [ 'value'=>'LabelString', ... ]
    foreach ( $lab as $rbkey => $label ) {
      $lbsty = 'margin-right: 1.5em;';   //for inline
      $rbstr  = '<input type="radio" name="' . $nam . '"';
      $rbstr .= ' id="' . $nam . '_' . $rbkey . '"';
      $rbstr .= ' value="' . $rbkey . '"';
      foreach ( $opt as $optkey => $optval ) {
        switch ( $optkey ) {
          case 'ro':
            if ( True == $optval ) {
              $rbstr .= ' disabled';
              $lbsty .= 'background-color:' . cRoBgcolor . ';';
            }
            break;
          default:
            $rbstr .= $this->con( $rbstr, ' ' ) . $optkey . '="' . $optval . '"';
            break;
        }
      }
      if ( $rbkey == $chk )  { $rbstr .= ' checked'; }
      $rbstr .=  '>';
      $this->fields[]
        = '<label' . ( empty( $lbsty ) ? '' : ' style="' . $lbsty . '"') . '>' . $rbstr . $label . '</label>';
    }
  }

  function tag( $tag, $param='', $pushstack = True ) {
  // do not enclose $tag with < >
    $ret = $tag;
    if ( ! empty( $param )) {
      $ret .= ' ' . $param;
    }
    if ( $pushstack ) {
      $this->tagstack[ $this->tagcnt++ ] = $tag;
    }
    $this->fields[] = $this->capsul( $ret, '<>' );
  }

  function c_tag( $n = 1 ) {
  //close tag
    for ( $ix = 0; $ix < $n; $ix++ ) {
      if ( $this->tagcnt < 1 ) {
        $this->fields[] = 'error : tag stack empty.';
      } else {
        $this->fields[] = $this->capsul( $this->tagstack[ --$this->tagcnt ], array( '</', '>' ));
      }
    }
  }

  function plaintext( $str ) {
    $this->fields[] = $str;
  }

  function select( $nam, $options, $selectedid = 0, $uop = array() ) {
    $native = ( '-' == substr( $nam, 0, 1 ) );
    if ( $native ) {
      $nam = mb_substr( $nam, 1 );
    }
    $opt = array_merge( array('ro' => False), $uop );
    $str = '<select id="' . $nam . '" name="' . $nam . '" data-native-menu="' . ($native ? 'ture' : 'false') . '">';
    $ro  = $opt[ 'ro' ];
    $this->fields[] = $str;
    foreach ( $options as $optval => $optlabel ) {
      $this->fields[] = '<option value="' . $optval . '"'
        . ($selectedid == $optval ? ' selected' : ($ro ? 'disabled' : '')) . '>' . $optlabel . '</option>';
    }
    $this->fields[] = '</select>';
  }

  function remarks( $cat, $msg ) {
    if ( !empty( $msg ) ) {
      $str = 
        '<div' . $this->get_classstr( $cat ) . '>' .
        $this->get_liststr( $msg ) .
        '</div>';
      $this->fields[] = $str;
    }
  }

  function close() {
    if ( !empty( $this->legend ) ) {
      echo '<fieldset' . $this->get_classstr( 'fieldset' ) . '" data-role="controlgroup">';
      echo '<legend>' . $this->legend . '</legend>';
    }
    foreach ( $this->fields as $field ) {
      echo $field;
    }
    if ( !empty( $this->legend ) ) {
      echo '</fieldset>';
    }
    echo PHP_EOL;
    $this->legend = '';
    $this->fields = array();
    $this->stat   = False;
  }

  function errorcount( $reset = False ) {
    if ( $reset ) {
      $this->gerrcnt = 0;
    }
    return $this->gerrcnt;
  }

  //**** service methods ****
  private function get_liststr( $msg, $gtag='ul', $ltag='li' ) {
    //Returns <ul> list from simple array $msg.
    $ret = '';
    if ( ! empty( $msg )) {
      if ( is_array( $msg )) {
        $g_opn = $this->capsul( $gtag, '<>' );    $g_cls = $this->capsul( '/' . $gtag, '<>' );
        $l_opn = $this->capsul( $ltag, '<>' );    $l_cls = $this->capsul( '/' . $ltag, '<>' );
        $ret = $g_opn . $l_opn . implode( $l_cls . $l_opn, $msg ) . $l_cls . $g_cls;
      } else {
        $ret = $msg;
      }
    }
    return $ret;
  }

  private function con( $base, $conchar = ' and ', $default = '' ) {
    //Returns concatination string.
    return (empty( $base ) ? $default : $conchar);
  }

  private function strterm( $str, $term ) {
    //ensure the string is terminated by $term.
    $siz = mb_strlen( $str );
    if ( $term == mb_substr( $str, -$siz )) {
      return $str;
    } else {
      return $str . $term;
    }
  }

  private function capsul( $str, $sep="'" ) {
    if ( is_array( $sep )) {
      $sep_h = $sep[ 0 ];
      $sep_f = $sep[ 1 ];
    } else {
      $siz = strlen( $sep );
      if ( 2 <= $siz ) {
        $sep_h = substr( $sep, 0, $siz - 1 );
        $sep_f = substr( $sep, -1, 1 );
      } else {      //expects 1 normally
        $sep_h = $sep_f = $sep;
      }
    }
    $siz = strlen( $sep_h );
    return ( $sep_h <> substr( $str, 0, $siz ) ? $sep_h . $str . $sep_f : $str );
  }

  private function get_classstr( $key ) {
    return ( array_key_exists( $key, $this->classdef) ? ' class="' . $this->classdef[ $key ] . '"' : '' );
  }

  private function get_browsername() {
    if ( empty( $this->browsername )) {
      $browserlist = array( 'Edge', 'MSIE', 'Trident', 'Chrome' );  //順序に注意
      $this->browsername = 'unknown';
      foreach ( $browserlist as $key ) {
        $pos = strpos ( $_SERVER['HTTP_USER_AGENT'], $key );
        if ( ! ( False===$pos )) {
          $this->browsername = $key;
          break;
        }
      }
    }
    return $this->browsername;
  }

}
