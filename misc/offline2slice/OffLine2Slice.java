import java.applet.*;
import java.awt.*;
import java.io.*;

public class OffLine2Slice extends Applet implements Runnable {

//  values goes from parametr
    private String message = "default message";

//  set font
	private Font   font;
	private String font_jmeno = "Helvetica";
	private int    font_styl = Font.PLAIN;
	private int    font_velikost = 12;
	
  private String           filename;		// name of manipulated file is set in store function
	private String           filenameAppletParameter="ecn.txt";	// "default" file for storing (if not set as parameter to applet)
	private int              sleep_time=1000;
	private Thread           runner;
  private RandomAccessFile raf;
  private byte[]           outBuffer;
	private boolean          buffer_changed = false;
	private boolean          want_read = false;
	private boolean          skip_info = false;
	private boolean          fileReadComplete = false;
	private boolean          fileReadCompleteHM = false;
	private boolean			 want_how_many = false;
	private boolean			 delete_it = false;
	private String           readString;
	private String           err = "";
	private boolean          running = true;
	private long		     how_many = 0;


    public void init () {
        super.init();

        message = getParameter("msg");
		filenameAppletParameter = getParameter("filename");		
       // file to store - used, if no parameter providede to store function
		font = new Font( font_jmeno, font_styl, font_velikost );
    }

    public void start() {
      if (runner == null) {
        runner = new Thread(this);
        runner.start();
      }
    }
 
    public void stop() {
    	running = false;    // stops the  thread (runner.stop() is deprecated)
    }

    public void paint(Graphics g) {
		g.setFont(font);
        g.drawString(message, 50, 25);
    }

   public void run() {
	 // loop until stop method is invoked (it sets running=false)
     while (running) {
	   if (buffer_changed) {
	     err = "";
	     buffer_changed = false;
		 
         try {			// write outBuffer at the end of file
           raf = new RandomAccessFile(filename, "rw");
		   		   
		// the number of forms is stored in the begining of file
		   if ( raf.length() == 0 ) raf.writeLong(1); // new file -> first (one) form
		   else {	// we are adding new form to file
		     long tempLong = raf.readLong();
			 tempLong++;
			 raf.seek(0);
			 raf.writeLong( tempLong );
		   }
           raf.seek( raf.length() );
           raf.write(outBuffer,0,outBuffer.length);
         }
         catch (SecurityException se) { err += se.getMessage(); }
         catch (IOException ioe) { err += ioe.getMessage(); }
         finally {
           if (raf != null)
             try { raf.close(); }
             catch (IOException ioe2) { err += ioe2.getMessage(); }
         }
         if (err != "") { /* message=err; repaint(); */ }	// Javascript error
	   }
	   if (want_how_many) {
	      err = "";
	      want_how_many = false;
		  how_many = 0;
		  filename = filenameAppletParameter;
		  try {
		  	   File ef = new File( filename );
			   if ( ef!=null ) {
			      ef = null;
			      raf = new RandomAccessFile( filename, "r");
			      raf.seek(0);
			      how_many = raf.readLong();  // IOException throws this line
			   }
		  }
		  catch ( FileNotFoundException fnfe ) { err += "fnfe:"+fnfe.getMessage(); }
	      catch ( IOException ioe ) { err += "ioe:"+ioe.getMessage(); }
	      catch ( SecurityException se ) { err += "se:"+se.getMessage(); }	   
		  catch ( NullPointerException npe ) { err += "npe:"+npe.getMessage(); }
	      finally { if (raf != null) {
	   		      	 	try { raf.close(); }
			    		catch (IOException ioe2) { err += "ioe2:"+ioe2.getMessage(); } 
			     	}
	      			fileReadCompleteHM = true; // tells JS, that all is read
		  }	
	   }
	   if (want_read) {
	      want_read = false;
		  err = "";
		  
	      byte[] inBuffer = new byte[0];
          try {
		  		// RandomAccessFile do not throw FileNotFoundException
		  		File ef = new File(filename);
				if (ef==null) throw new FileNotFoundException("Soubor " + filename + " neexistuje.");
				else if (!ef.exists()) throw new FileNotFoundException("Soubor " + filename + " neexistuje.");
				ef = null;
								
		  		raf = new RandomAccessFile(filename, "r");

        // sizeof(long) is 8 in Java
        if( skip_info ) {
          inBuffer = new byte[ (int)raf.length() - 8 ];
					raf.seek( 8 );
        } else {
 	   	    inBuffer = new byte[ (int)raf.length() ];
   				raf.seek( 0 );
        }
			  raf.read( inBuffer, 0, (int)raf.length() ); 
		  }
		  catch ( FileNotFoundException fnfe ) { err += "fnfe:"+fnfe.getMessage(); }
	      catch ( IOException ioe3 ) { err += "ioe:"+ioe3.getMessage(); }
	      catch ( SecurityException se ) { err += "se:"+se.getMessage(); }	   
	      finally { if (raf != null) {
	   		      	 	try { raf.close(); }
			    		catch (IOException ioe4) { err += "ioe:"+ioe4.getMessage(); } 
			     	}
		  			readString = new String( inBuffer );
	      			fileReadComplete = true; // tells JS, that all is read
		  }	
	   }
	   
	   if (delete_it) {
	      File ef;
	      err = "";
	      delete_it = false;
		  filename = filenameAppletParameter;
		  try {
		  	  ef = new File(filename);		 // File.delete returns true, if file is sucessfully deleted. Else false
		  	  if ( (ef==null) || (!ef.exists()) || (!ef.canWrite()) || (!ef.delete()) ) err = "File not exist or it is not possible to delete it.";
		  }
	      catch ( SecurityException se ) { err += "se:"+se.getMessage(); }	   
		  catch ( NullPointerException npe ) { err += "npe:"+npe.getMessage(); }
		  finally {
		  		  ef = null;
		  } 
	   } 
       try { Thread.sleep(sleep_time); }
        catch (InterruptedException e) { }
     }
   }

	public void store(String file, String s) {
		filename = file;
		outBuffer = s.getBytes();
		buffer_changed = true;  // az po tom, co jsem ho opravdu zmenil
	}							// applet se dozvi, ze ma zapisovat
	
	public void store(String s) {
		this.store(filenameAppletParameter, s);
	}
	
	// Tells Javascript if already read
	public boolean retrieved() {
	   return fileReadComplete;
	}	
	
	// returns error string. If the string is empty, no error ocured.
  // If Javascript will be checking for Errors, applet could not to do
  // anything with them (the title should be always the same)
	public String retrieveError() {
	 	return err;
	}
	
	// When Javascript knows, that data are allready read, it chacks boo variable
	// - true -> common file - we should encode it to wddx packet
	// - false -> do not encode it, when store in wddx packet
	public String readValue(boolean boo) {
	   fileReadComplete = false;
	   if (boo) return Base64.encode(readString);
	   else     return               readString;
	}
	
	// JS says: "I want to read file"
	// Used for <INPUT TYPE="file">
	// the data JS adds to do wddxPacketu.
	public void retrieve(String file) {
		filename = file;
		want_read = true;
		skip_info = false;
	}
	
	// If JS do not says, what file to read, the file from applet parameter is 
  // taken - used for sending forms content to serever
	public void retrieve() {
		skip_info = true;
		filename = filenameAppletParameter;
		want_read = true;
	}
	
	// JS wants to know how many forms are stored in file
	public void howMany() {
	    want_how_many = true;
		fileReadCompleteHM = false;
	}
	
	// JS chachks if allready read (number of forms in file, which wasn't send to 
  // server)
	public boolean retrievedHM() {
	   return fileReadCompleteHM;
	}	
	
	public int retrieveHM() {
	   return (int)how_many;
	}
	
	// JS wants to delete file
	public void deleteFile() {
	   delete_it = true;
	}

}

/**
 * Provides static methods for encoding and decoding strings of base 64
 * characters, such as those used for HTTP basic authorization.
 *
 * No warranty -- you may use this code in your own projects so long as you
 * preserve this message and the author tag below.
 *
 * @author <A HREF="mailto:wes@cacas.org">Wes Biggs</A>
 */
class Base64 {
  private static final String alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

  private static final int b64val(char c) {
    int b = alphabet.indexOf(c);
    return (b == -1) ? 0 : b;
  }

  private static final char b64char(int i) {
    return ((i >= 0) && (i < 64)) ? alphabet.charAt(i) : '=';
  }

  /**
   * Creates a base 64 encoding of the input text.
   *
   * @param f_text the String to be encoded.
   */
  public static final String encode(String f_text) {
    StringBuffer output = new StringBuffer();
    // read 3 chars, make 4
    int l = f_text.length();
    int a,b,c,d;
    for (int i = 0; i < l;) {
      b = c = d = 0;
      a = (int) f_text.charAt(i++);
      output.append(b64char(a >> 2));
      if (i < l) { 
	b = (int) f_text.charAt(i++);
	output.append(b64char((b >> 4) | ((a << 4) & 63)));
	
	if (i < l) {
	  c = (int) f_text.charAt(i++);
	  output.append(b64char((c >> 6) | ((b << 2) & 63)));
	  output.append(b64char(c & 63));
	} else { 
	  output.append(b64char((b << 2) & 63)); 
	  output.append('=');
	}
      } else {
	output.append(b64char((a << 4) & 63));
	output.append("==");
      }
    }
    return output.toString();
  }

  /**
   * Decodes a base 64 encoding of a string.
   *
   * @param f_text the String to be decoded.
   */

  public static final String decode(String f_text) {
    StringBuffer output = new StringBuffer();
    for (int i = 0; (i + 3) < f_text.length();) {
      int a = b64val(f_text.charAt(i++));
      int b = b64val(f_text.charAt(i++));
      int c = b64val(f_text.charAt(i++));
      int d = b64val(f_text.charAt(i++));

      a = ((a << 2) & 255) | (b >> 4);
      b = ((b << 4) & 255) | (c >> 2);
      c = ((c << 6) & 255) | d;
      
      output.append((char) a);
      if (b != 0) output.append((char) b);
      if (c != 0) output.append((char) c);
    }
    return output.toString();
  }
}
