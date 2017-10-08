<?php
error_reporting(E_ALL);
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 'On');

ini_set('xdebug.remote_mode', 'req');
//ini_set('xdebug.remote_mode', 'jit');

header("Cache-Control: no-cache, must-revalidate"); //HTTP 1.1
header("Pragma: no-cache"); //HTTP 1.0
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

/* 
MIT-Lizenz
Copyright (c) 2017, Bodo Baitis 
Hiermit wird unentgeltlich jeder Person, die eine Kopie der Software und der zugehörigen Dokumentationen (die "Software") erhält, 
die Erlaubnis erteilt, sie uneingeschränkt zu nutzen, inklusive und ohne Ausnahme mit dem Recht, sie zu verwenden, 
zu kopieren, zu verändern, zusammenzufügen, zu veröffentlichen, zu verbreiten, zu unterlizenzieren und/oder zu verkaufen, 
und Personen, denen diese Software überlassen wird, diese Rechte zu verschaffen, unter den folgenden Bedingungen:

Der obige Urheberrechtsvermerk und dieser Erlaubnisvermerk sind in allen Kopien oder Teilkopien der Software beizulegen.

DIE SOFTWARE WIRD OHNE JEDE AUSDRÜCKLICHE ODER IMPLIZIERTE GARANTIE BEREITGESTELLT, EINSCHLIEẞLICH DER GARANTIE ZUR BENUTZUNG 
FÜR DEN VORGESEHENEN ODER EINEM BESTIMMTEN ZWECK SOWIE JEGLICHER RECHTSVERLETZUNG, JEDOCH NICHT DARAUF BESCHRÄNKT. 
IN KEINEM FALL SIND DIE AUTOREN ODER COPYRIGHTINHABER FÜR JEGLICHEN SCHADEN ODER SONSTIGE ANSPRÜCHE HAFTBAR ZU MACHEN, 
OB INFOLGE DER ERFÜLLUNG EINES VERTRAGES, EINES DELIKTES ODER ANDERS IM ZUSAMMENHANG MIT DER SOFTWARE 
ODER SONSTIGER VERWENDUNG DER SOFTWARE ENTSTANDEN.

Content
-------
08.10.2017, Bodo Baitis
   simple Web-App to access the Sunricher SR1009FAWI / LK35 wifi LED controller via WebService (led.php)
   To persist the last known value, the current folder 
   must be writeable by the apache web server.
   A file led.json will be stored in the current folder.
   A second empty file sync.bin must exist and is used for synchronized socket access.

/led.php?cmd=on                 = controller on
/led.php?cmd=off                = controller off  
/led.php?cmd=q                  = query current values as JSON  
/led.php?cmd=1                  =  1 - 8  -> recall stored value
/led.php?cmd=s1                 = s1 - s8 -> store current value
/led.php?rgbw=ffffffff          = RRGGBBWW
/led.php?r=128&g=128&b=128&w=0  = RGBW   0-255
/led.php?d=1                    = dimmen 1-8

cmd=q - will return the last known values as json object
$led->state  0/1
$led->r 
$led->g 
$led->b 
$led->w 
$led->d 
*/

class VAL {
  public $r = 0;
  public $g = 0;
  public $b = 0;
  public $w = 0;
  public $d = 0;
};

class DAT {
  public $state; // current state
  public $led;   // last known values
  public $s;     // storage array[0...7] with tracked values  
 
  public function __construct()
    {
    $val = new VAL();
    $this->state = '0';
    $this->led = $val;
    $this->s = array($val,$val,$val,$val,$val,$val,$val,$val);
    }
};

class LED
  {
  private $_MyIp;           // is determined automatically $_SERVER["SERVER_ADDR"]
  private $_LedIp;          // is determined automatically via broadcast
  private $_WiFiPort;       // 48899
  private $_LedPort;        // 8899
  private $_LedSig;         // magic last 3 byte of mac-adress
  private $_Zone;           // "\x01\x02" "02" -> hier Zone 1

  public function __construct()
    {
    $this->_MyIp = $_SERVER["SERVER_ADDR"];
    $i = strrpos($this->_MyIp, '.');
    if ($i)
      $this->_LedIp = substr($this->_MyIp, 0, $i+1) . "255"; // start with broadcast, should check MASK here...
    else
      $this->_LedIp = "255.255.255.255";
      
    $this->_LedSig = ""; // 3 magic byte
    $this->_WiFiPort = 48899;
    $this->_LedPort = 8899;
    $this->_Zone = "\x01\x00";
    //                    ++---- Zone: 8Bit == 8 Zones
    }

  // search the controller via Broadcast Address, and UDP query the IP-Address and 3 magic bytes    
  private function getWifiBridge()
    {
    $_WiFisock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ( !$_WiFisock)
      die(socket_strerror(socket_last_error()));
      
    if (!socket_set_option($_WiFisock, SOL_SOCKET, SO_REUSEADDR, 1))
      die(socket_strerror(socket_last_error()));
      
    if (!socket_bind($_WiFisock, $this->_MyIp, $this->_WiFiPort))
      {
      socket_close($_WiFisock);
      die(socket_strerror(socket_last_error()));
      }

    socket_set_option($_WiFisock, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>1,'usec'=>0));
    socket_clear_error();

    // "AT+Q\r" send first to leave wifi control mode

    $msg = "HF-A11ASSISTHREAD";
    
    socket_set_option($_WiFisock, SOL_SOCKET, SO_BROADCAST, 1);
    if (!socket_sendto($_WiFisock, $msg, strlen($msg), 0, $this->_LedIp, $this->_WiFiPort))
      {
      socket_close($_WiFisock);
      die(socket_strerror(socket_last_error()));
      }

    usleep(50000); // 50ms delay
      
    while(true)
      {
      $buffer = null;
      $adr = '';
      $prt = 0;

      $erg = socket_recvfrom($_WiFisock, $buffer, 512, 0, $adr, $prt); // we receive our own broadcast herer first

      if ($erg===false)
        {
        //  no bytes available, socket closed
        socket_close($_WiFisock);
        die("LED Controller not found");
        }
      elseif ($erg===0)
        {
        // socket closed
        efree($buffer);
        socket_close($_WiFisock);
        die(socket_strerror(socket_last_error()));
        }
      else // 192.168.178.74,F0FE6B319886,HF-LPB100
        {  //                         +-+---------------- magic signature bytes
        $erg = false;
        $arr = explode(",", $buffer);
        if (count($arr)==3 && strlen($arr[1])==12) // && $arr[2]=='HF-LPB100' 
          {
          $this->_LedIp = $arr[0];                 // controller IP address
          $this->_LedSig = substr($arr[1], 9, 3);  // 3 signature bytes (last 3 byte of controller MAC)
          socket_close($_WiFisock);
          return true;
          }
        }
      }
      
    socket_close($_WiFisock);
    return false;
    }

  // hier nur die 2 Light-Command-Bytes
  public function sendCommand($Cmd1, $Cmd2)
    {
    if (Empty($this->_LedSig) && !$this->getWifiBridge())      
      return false;

    // calculate checksum
    $msg1 = $this->_Zone.$Cmd1;
    
    $c = strlen($msg1);
    $cs = 0;
    for ($i=0; $i<$c; $i++)
      $cs += ord($msg1[$i]);
    $msg1 = "\x55".$this->_LedSig.$msg1.chr($cs)."\xaa\xaa";
    
    if (!Empty($Cmd2))
      {
      $msg2 = $this->_Zone.$Cmd2;
      $c = strlen($msg2);
      $cs = 0;
      for ($i=0; $i<$c; $i++)
        $cs += ord($msg2[$i]);
      $msg2 = "\x55".$this->_LedSig.$msg2.chr($cs)."\xaa\xaa";
      $msg1 .= $msg2;
      }
      
    try 
      {
      $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
      socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, array('sec'=>1,'usec'=>0));
      socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>1,'usec'=>0));
  
      if (!socket_connect($sock, $this->_LedIp, $this->_LedPort))
        die(socket_strerror(socket_last_error()));
 
  
      socket_write($sock, $msg1, strlen($msg1));
      }
    catch (Exception $e)
      {
      }
        
    usleep(50000); // delay b4 next command
    
    return true;
    }
    
  //-----------------------------------------------------------------
  public function SendRGBW($R, $G, $B, $W)
    {
    $this->sendCommand("\x08\x48".chr($R), "");
    $this->sendCommand("\x08\x49".chr($G), "");
    $this->sendCommand("\x08\x4a".chr($B), "");
    $this->sendCommand("\x08\x4b".chr($W), "");
    }
  
  //-----------------------------------------------------------------
  public function SendD($D)
    {
    if ($D>=1 && $D<=8) // dimmen
      $this->sendCommand("\x08\x23".chr($D), "");
    }
        
  }; // end-of-class
  
//-----------------------------------------------------------------
// persist last known values
// $dat->state  - on/off  1/0
// $dat->led->r - red     0...255
// $dat->led->g - green   0...255
// $dat->led->b - blue    0...255
// $dat->led->w - white   0...255
// $dat->led->d - density 1...8
function LoadLastValues()
  {
  // initialze old values file, if missing yet
  if (!file_exists('led.json'))
    {
    // empty rgbwd value structure
    $dat = new DAT();
    file_put_contents('led.json', json_encode($dat));
    }
    
  // read old values from json file
  for ($i=0; $i<5; $i++)
    {
    $val = json_decode(file_get_contents('led.json'));
    if (!Empty($val))
      return $val;
    }
  return null;  
  }
  
function SaveValues($dat)
  {
  file_put_contents('led.json', json_encode($dat));
  }
  
//-----------------------------------------------------------------
class LED_Command {
    public $idx;
    public $cmd1;
    public $cmd2;
    public function __construct($ix, $c1, $c2)
      {
      $this->idx = $ix;
      $this->cmd1 = $c1;
      $this->cmd2 = $c2;
      }
  }; // end of class

function LED_Commands()
  {
  return array(
  'on'  => new LED_Command(-1, "\x02\x12\xab", ""),
  'off' => new LED_Command(-2, "\x02\x12\xa9", ""),
  
  // select preset
  '1'  => new LED_Command(0, "\x02\x14\xb0", "\x02\x0a\x91"),
  '2'  => new LED_Command(1, "\x02\x14\xb0", "\x02\x0b\x94"),
  '3'  => new LED_Command(2, "\x02\x14\xb0", "\x02\x0c\x97"),
  '4'  => new LED_Command(3, "\x02\x14\xb0", "\x02\x0d\x9a"),
  '5'  => new LED_Command(4, "\x02\x14\xb0", "\x02\x0e\x9d"),
  '6'  => new LED_Command(5, "\x02\x14\xb0", "\x02\x0f\xa0"),
  '7'  => new LED_Command(6, "\x02\x14\xb0", "\x02\x10\xa3"),
  '8'  => new LED_Command(7, "\x02\x14\xb0", "\x02\x11\xa6"),
  
  // store preset
  's1'  => new LED_Command(8,  "\x02\x14\xb1", "\x02\x0a\x91"),
  's2'  => new LED_Command(9,  "\x02\x14\xb1", "\x02\x0b\x94"),
  's3'  => new LED_Command(10, "\x02\x14\xb1", "\x02\x0c\x97"),
  's4'  => new LED_Command(11, "\x02\x14\xb1", "\x02\x0d\x9a"),
  's5'  => new LED_Command(12, "\x02\x14\xb1", "\x02\x0e\x9d"),
  's6'  => new LED_Command(13, "\x02\x14\xb1", "\x02\x0f\xa0"),
  's7'  => new LED_Command(14, "\x02\x14\xb1", "\x02\x10\xa3"),
  's8'  => new LED_Command(15, "\x02\x14\xb1", "\x02\x11\xa6"),
  );
}

//-----------------------------------------------------------------
// simple do command without persitance of last values
function LED_DoCommand($cmd)
  {
  $lst = LED_Commands();
  if (!array_key_exists($cmd, $lst))
    return false;

  $p = $lst[$cmd];
  if (!IsSet($led)) $led = new LED();
  $led->sendCommand($p->cmd1, $p->cmd2);
  return true;
  }
  
//-----------------------------------------------------------------
function CheckArg($arg, &$val)
  {
  if (!IsSet($_REQUEST[$arg]))
    return false;
  
  $val = $_REQUEST[$arg];
  return true;
  }

//-----------------------------------------------------------------
// query last known values
function QueryValues()
  {
  if (Empty($_REQUEST['cmd']) || $_REQUEST['cmd']!='q')
    return false;
  
  // return the current values
  $dat = LoadLastValues();
  $led2 = $dat->led;
  $led2->state = $dat->state; // give back current state too
  $json = json_encode($led2);
  header('Content-Type: application/json');
  echo $json;
  return true;
  }
  
//-----------------------------------------------------------------
function HandleApi()
  {
  if (QueryValues()) // query last known values?
    return;
    
  $dat = LoadLastValues();
  
  // replace with new values
  if (IsSet($_REQUEST['rgbw'])) // rgbw=ffffffff
    {
    $val = $_REQUEST['rgbw'];
    $val = str_pad($val, 8, '0', STR_PAD_LEFT);
    $dat->led->r = hexdec(substr($val, 0, 2));
    $dat->led->g = hexdec(substr($val, 2, 2));
    $dat->led->b = hexdec(substr($val, 4, 2));
    $dat->led->w = hexdec(substr($val, 6, 2));
    $led = new LED();
    }
    
  // check single values
  if (CheckArg('r', $dat->led->r) && !IsSet($led)) $led = new LED();
  if (CheckArg('g', $dat->led->g) && !IsSet($led)) $led = new LED();
  if (CheckArg('b', $dat->led->b) && !IsSet($led)) $led = new LED();
  if (CheckArg('w', $dat->led->w) && !IsSet($led)) $led = new LED();
  
  // if we got one of them, send command to controller
  if (IsSet($led))
    $led->SendRGBW($dat->led->r, $dat->led->g, $dat->led->b, $dat->led->w); // r=128,g=128,b=128[,w=0][,d=8]
    
  // check density and send separately  
  if (CheckArg('d', $dat->led->d) && $dat->led->d>=1 && $dat->led->d<=8) // optional
    {
    if (!IsSet($led)) $led = new LED();
    $led->SendD($dat->led->d);
    }
    
  // check s1-s8 and on/off commands
  if (!Empty($_REQUEST['cmd'])) //cmd=on, cmd=off, cmd=1, cmd=8, cmd=s1, cmd=s8
    {
    $cmd = $_REQUEST['cmd'];
    $lst = LED_Commands();
    
    if (array_key_exists($cmd, $lst))
      {
      $p = $lst[$cmd];
      if (!IsSet($led)) $led = new LED();
      $led->sendCommand($p->cmd1, $p->cmd2);
      $dat->state = $cmd=='off' ? '0' : '1'; // only off can turn off the controller
                                             // all other commands turn it on
      // s1-s8 
      if ($p->idx>=0 && $p->idx<8)
        $dat->led = $dat->s[$p->idx];   // switch to stored values
      elseif ($p->idx>=8 && $p->idx<16)
        $dat->s[$p->idx-8] = $dat->led; // store current values as s1-s8
      }
    }
  
  // if something has been changed, persist it  
  if (IsSet($led))  
    file_put_contents('led.json', json_encode($dat));
  }
 
/////////////////////////////////////////////////////////////////
// === main entry point ===
// lock mutal access via file 
$Sync = fopen('sync.bin', 'r+');
if ($Sync) {
  if (flock($Sync, LOCK_EX)) {
    try {  
      HandleApi();
    }
    catch(Excptiion $e) {
    }
    flock($Sync, LOCK_UN);
  }
  fclose($Sync);
}
?>