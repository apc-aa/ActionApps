// Javascript functions used to send some info to a server by the way of img.src and to wait for the response
// I used and modified some code from Vlad B., see
// http://www.c4home.com/cf/vlad_code_sample/use_gif_to_talk_to_cfm/testpage.html

var glob_timeid = 0;

/*
Wait4Response(delay --- maximum time you wish to wait (in mili)
              inc   --- time delay in seconds between consequent checks for ACK signal
  		      ack   --- special ACK string (signal) to look for in cookie returned by the server
			  func  --- piece of code you wish to excecute upon successful server response	
*/	
/*		
function Wait4Response(delay, inc, ack, func, timeOutFunc) 
{	   
    // is ACK string present in the cookie?
    // if not, then sleep for some time and check back later...
    // otherwise, YEAH! we done it!

    if (document.cookie.indexOf(ack) == -1) {
       if (delay > 0) {
    		delay = delay - inc;
    		window.status = "waiting for response... time left " + delay + " mili seconds";						
    
    		if (glob_timeid) clearTimeout(glob_timeid);
    		glob_timeid = 
    		
    	} else {
            glob_timeid = 0;
            eval(timeOutFunc);
    		return 0;   // failed!
    	}
    } else {	
    	if (glob_timeid) clearTimeout(glob_timeid);
        glob_timeid = 0;
    	eval(func);    // excecute designated function...
    	return 1;      // successfull!
    }
}
*/						
 
/*  Provides live checkbox - a checkbox-like image. 
    If you click on it, a request is send to the AA live_update.php3 script,
    which changes the value of the field and the JavaScript changes the image.

    A pause of 1 second is needed because a new request would stop the old one.
*/
  
function LiveCheckbox (formname, varname, imageDir, script, 
    alt_on, alt_off, alt_2on, alt_2off, alt_failed,
    msg2on, msg2off, msg_wait) {
    
    // create a random acknowledgement string - helps both to recognize the answer
    // with cookies and to avoid browser caching of the image
    var ack = "ACK" + Math.random()*1000000;
    if (script.search (/\?/) > -1) 
         script += "&";
    else script += "?";
    script += "ack=" + ack;
    
    // if another live checkbox is in process, wait
    if (glob_timeid) {
        alert (msg_wait);
        return false;
    }
    
    // params for the UpdateLiveCheckbox function
    updateParams = ",'"+formname+"','"+varname+"','"+imageDir+"','"+alt_on+"','"+alt_off+"','"+alt_failed+"')";
    
    var myImage = document.forms[formname][varname];
    s = myImage.src;
    s = s.substr (s.search (/cb_/));
    
    switch (s) {
        case "cb_2off.gif":
            alert (msg2off); break;
        case "cb_2on.gif":
            alert (msg2on); break;
        case "cb_on.gif": 
            myImage.src = imageDir + "cb_2off.gif";
            myImage.alt = alt_2off;
            var i = new Image (1,1);
			i.src = script + "&" + varname + "=off";
            glob_timeid = setTimeout("UpdateLiveCheckbox ('off'" + updateParams, 1000);
            /*
            Wait4Response (10000, 500, ack, 
                "UpdateLiveCheckbox ('off'" + updateParams,
                "UpdateLiveCheckbox ('failed'"+updateParams);
            */
            break;
        case "cb_off.gif":
            myImage.src = imageDir + "cb_2on.gif";
            myImage.alt = alt_2on;
            var i = new Image (1,1);
			i.src = script + "&" + varname + "=on";
            glob_timeid = setTimeout("UpdateLiveCheckbox ('on'" + updateParams, 1000);
            break;
    }
    
    return true;
}

function UpdateLiveCheckbox (state, formname, varname, imageDir, alt_on, alt_off, alt_failed) {
    var myImage = document.forms[formname][varname];
    myImage.src = imageDir + "cb_" + state + ".gif";
    switch (state) {
        case "on": myImage.alt = alt_on; break;
        case "off": myImage.alt = alt_off; break;
        case "failed": myImage.alt = alt_failed; break;
    }
    glob_timeid = 0;
}
