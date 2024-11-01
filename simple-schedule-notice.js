/**
 * This file is part of the Simple Schedule Notice plugin and is released under the same license.
 * Author: E.Kamiya
 */

function bcolor( elem ) {
  if ( elem.disabled ) {
    elem.value = '';
    elem.style.backgroundColor = '#F0FFF0';
  } else {
    elem.style.backgroundColor = 'white';
  }
}

function checked( obj, nam, cnt ) {
  result = -1;
  for (i=0; i<cnt; i++) {
    if (obj[ nam ][i].checked) {
      result = i;
      break;
    }
  }
  return result;
}

function cy_ctl( ) {
  fObj = document.getElementById("form_dtl");
  if ( fObj != null ) {
    Chk = checked( fObj, "cycle", 4 );
    elem = fObj["YR"];    elem.disabled = (0 < Chk);   bcolor( elem );
    elem = fObj["MO"];    elem.disabled = (1 < Chk);   bcolor( elem );
    elem = fObj["DA"];    elem.disabled = (2 < Chk);   bcolor( elem );
  }
}

window.onload = function(){
  cy_ctl( );
}
