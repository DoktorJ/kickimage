<?php

// Font to use for text (regular and bold)
$FONT = 'TitilliumWeb-Regular.ttf';
$FONT_B = 'TitilliumWeb-SemiBold.ttf';

// Image cache age (seconds)
$i_age = 300;
// JSON cache age (seconds)
$c_age = 1200;

////////////////////////////////////////////////////////////////////////////////
// Output an error message if something bad happened
// Outputs as an image to be embed-friendly
// Usage: err("Your message here");
//
function err($msg) {
  global $FONT;
  $im = imagecreatetruecolor(400, 30);
  $tx = imagecolorallocatealpha($im, 255,0,0, 127);
  $bk = imagecolorallocatealpha($im, 0,0,0, 0);
  $wt = imagecolorallocatealpha($im, 255,255,255, 0);
  $rd = imagecolorallocatealpha($im, 255,0,0, 0);
  imagefill($im, 1,1, $tx);
  $dims = imagettfbbox(12, 0, $FONT, $msg);
  $t = round(28 - (($dims[1] - $dims[7]) / 2));
  $l = round(200 - (($dims[2] - $dims[0]) / 2));
  imagettftext($im, 12, 0, $l+1,$t+1, $bk, $FONT, $msg);
  imagettftext($im, 12, 0, $l,$t,     $wt, $FONT, $msg);
  imagerectangle($im, 0,0, 399,29, $rd);
  imagealphablending($im, false);
  imagesavealpha($im, true);
  imagepng($im);
  imagedestroy($im);
  exit();
}
//
////////////////////////////////////////////////////////////////////////////////

header("Content-Type: image/png");

// Check for search terms
if (!isset($_GET['term'])) {
  err('No Project Specified!');
}

// Validate search terms
$q = $_GET['term'];
if (!preg_match('/^[a-z0-9\-\ \_\.\+]+$/i', $q)) {
  err('Invalid project name "' . $q . '"!');
}

// Generate cache file names
$pfile = preg_replace('/[. \+]/', '', $q);
if (strlen($pfile) > 64) $pfile = substr($pfile, 0, 64);
$cfile = './cache/' . $pfile . '.json';
$ifile = './cache/' . $pfile . '.png';

$data = '';
$cfx = 'empty';

// If a cached image exists and is fresh, just show it and quit it
if (file_exists($ifile) && !isset($_GET['fresh'])) {
  if ((time() - filemtime($ifile)) < $i_age) {
    $f = fopen($ifile,'r');
    fpassthru($f);
    fclose($f);
    exit();
  }
}

// If the cached image is stale but JSON is still fresh, load cached JSON
if (file_exists($cfile)) {
  if ((time() - filemtime($cfile)) < $c_age) {
    $data = file_get_contents($cfile);
    $cfx = 'fresh';
  } else {
    $cfx = 'stale';
  }
}
$cfx .= " (${cfile})";

////////////////////////////////////////////////////////////////////////////////
// If cached JSON wasn't loaded, fetch the Kickstarter page
//
if ($data == '') {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, "https://www.kickstarter.com/projects/search.json?search=&term=${q}");
  // Pretend to be Chrome so KS doesn't barf!
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Pragma: no-cache',
    'Accept-Language: en-US,en;q=0.8',
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.116 Safari/537.36',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Cache-Control: no-cache',
    'Connection: keep-alive'
  ));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $data = curl_exec($ch);
  curl_close($ch);
  file_put_contents($cfile, $data);
}
//
////////////////////////////////////////////////////////////////////////////////

// A valid Kickstarter response should be a decent length
if (strlen($data) < 50) {
  err('Invalid response from Kickstarter!');
}

// Verify that KS actually found something with the search terms
$json = json_decode($data, true);
if ($json['total_hits'] == '0') {
  err('Project not found!');
}

// Verify that KS found only one project
// NOTE: This will also happen with unsuccessfully-completed campaigns!
// TODO: Research why, and try to capture this
if ($json['total_hits'] > 1) {
  err('Ambiguous project name, try again!');
}

// Get title
$proj = $json['projects'][0];
$title = preg_replace('/^([^-]+)-.*$/','$1',$proj['name']);
$title = preg_replace('/^([^(]+) \(.*$/','$1',$title);
$title = trim($title);

// Create image and allocate colors for text
$im = imagecreatetruecolor(512, 120);
$whiteout = imagecolorallocatealpha($im, 255,255,255, 16);
$ksgreen =  imagecolorallocatealpha($im,  43,222,115,  0);
$wt = imagecolorallocatealpha($im,    255,255,255, 0);
$bk = imagecolorallocatealpha($im,    0,0,0,       0);
$ovrbk = imagecolorallocatealpha($im, 0,0,0,      95);

// Get the background image and apply it
$bgim = $proj['photo']['1024x768'];
$dot = strpos($bgim,'original.') + 9;
$que = strpos($bgim,'?');
$bgext = substr($bgim,$dot, $que - $dot);
if ($bgext == 'png')
  $bg = imagecreatefrompng($bgim);
else if ($bgext == 'jpg')
  $bg = imagecreatefromjpeg($bgim);
imagecopyresampled($im, $bg, 0,-132, 0,0, 512,384, 1024,768);
imagedestroy($bg);

// Fade out the background and make a border
imagefilledrectangle($im, 0,0, 511,119, $whiteout);
imagerectangle($im, 0,0, 511,119, $ksgreen);
imagerectangle($im, 1,1, 510,118, $ksgreen);

// Add text to the image -- the meat and potatoes!
$y = 26;
$dims = imagettfbbox(17, 0, $FONT_B, $title);
$l = round(256 - (($dims[2] - $dims[0]) / 2));
imagettftext($im, 17, 0, $l+1,$y+1, $wt, $FONT_B, $title);
imagettftext($im, 17, 0, $l,$y, $bk, $FONT_B, $title);

////////////////////////////////////////////////////////////////////////////////
// Process the project description, split by sentences
//
$y += 20;
$ba = explode('.', $proj['blurb']);
$sz = 10.5;
$c = count($ba);
for ($i = 0; $i < $c; $i++)
  if (trim($ba[$i]) == '') unset($ba[$i]);
$c = min([count($ba),2]);
for ($i = 0; $i < $c; $i++) {
  $bline = preg_replace('~\s+~',' ',trim($ba[$i]));
  if ($bline == '') continue;
// Wrap line, truncate if necessary
  if (strlen($bline) > 80) {
    $sp = strpos($bline, ' ', 75);
    if ($c == 1) {
      $ba[1] = substr($bline, ++$sp);
      $bline = substr($bline,0,$sp) . '.';
      $c++;
    } else {
      $bline = substr($bline,0,$sp);
      $lc = preg_replace('/[,.!?\-;:()\[\]]/','',substr($bline,-1));
      $bline = substr($bline,0,-1) . $lc . '...';
    }
  } else if (!preg_match('/[!?.]/',substr($bline,-1)))
    $bline .= '.';
  $dims = imagettfbbox($sz, 0, $FONT, $bline);
  $l = round(256 - (($dims[2] - $dims[0]) / 2));
  imagettftext($im, $sz, 0, $l+1,$y+1, $wt, $FONT, $bline);
  imagettftext($im, $sz, 0, $l,$y, $bk, $FONT, $bline);
  $y += 15;
}
if ($c == 1) $y += 15;
//
////////////////////////////////////////////////////////////////////////////////

// Magic conversion of K and M to prevent excessively long numbers
$y += 10;
$pledge = $proj['pledged'];
if ($pledge >= 100000000)
  $pledge = floor($pledge / 1000000) . 'M';
else if ($pledge >= 10000000)
  $pledge = round($pledge / 1000000, 1) . 'M';
else if ($pledge >= 1000000)
  $pledge = round($pledge / 1000000, 2) . 'M';
else if ($pledge >= 100000)
  $pledge = round($pledge / 1000, 1) . 'K';
$goal = $proj['goal'];
$cs = $proj['currency_symbol'];

// Output pledge/goal text
$rem = "${cs}${pledge}  ";
$rtxt = 'pledged toward';
$goal = "  ${cs}${goal}  ";
$gtxt = 'goal';
$dims = imagettfbbox(14, 0, $FONT_B, $rem);
$rem_w = $dims[2] - $dims[0];
$dims = imagettfbbox(14, 0, $FONT, $rtxt);
$rtxt_w = $dims[2] - $dims[0];
$dims = imagettfbbox(14, 0, $FONT_B, $goal);
$goal_w = $dims[2] - $dims[0];
$dims = imagettfbbox(14, 0, $FONT, $gtxt);
$gtxt_w = $dims[2] - $dims[0];
$twidth = $rem_w + $rtxt_w + $goal_w + $gtxt_w;
$pos = 256 - round($twidth / 2);
imagettftext($im, 14, 0, $pos+1,$y+1, $bk,      $FONT_B, $rem);
imagettftext($im, 14, 0, $pos,$y,     $ksgreen, $FONT_B, $rem);
$pos += $rem_w;
imagettftext($im, 14, 0, $pos,$y,     $bk,      $FONT, $rtxt);
$pos += $rtxt_w;
imagettftext($im, 14, 0, $pos+1,$y+1, $bk,      $FONT_B, $goal);
imagettftext($im, 14, 0, $pos,$y,     $ksgreen, $FONT_B, $goal);
$pos += $goal_w;
imagettftext($im, 14, 0, $pos,$y,     $bk,      $FONT, $gtxt);

$y += 23;

////////////////////////////////////////////////////////////////////////////////
// Calculate time remaining (or if campaign is over)
//
$ctime = time();

// If campaign is over, indicate how long ago it succeeded/failed
if ($ctime > $proj['deadline']) {
  $day = floor(($ctime - $proj['deadline']) / 86400);
  $plural = ($day == 1) ? 'day' : 'days';
  if ($pledge >= $goal)
    $ftxt = "Funded ${day} ${plural} ago!";
// Due to issues with JSON for unsuccessful campaigns, this bit probably won't happen
  else
    $ftxt = "Campaign ended ${day} ${plural} ago.";
  $dims = imagettfbbox(14, 0, $FONT_B,   $ftxt);
  $twidth = $dims[2] - $dims[0];
  $pos = 256 - round($twidth / 2);
  imagettftext($im, 14, 0, $pos+1,$y+1, $bk, $FONT_B, $ftxt);
  if ($pledge >= $goal)
    imagettftext($im, 14, 0, $pos,$y, $ksgreen, $FONT_B, $ftxt);
// Calculate and indicate how long until campaign ends
} else {
  $sec = $proj['deadline'] - $ctime;
  $min = round($sec / 60);
  $hr = round($sec / 3600);
  $day = floor($hr / 24);
  $hr %= 24;

  $mtxt = '';
  if ($day == 0) {
    $dtxt = '';
    if ($min != 0) $mtxt = "${min} minute";
    if ($min > 1) $mtxt .= 's';
  } else if ($day == 1)
    $dtxt = '1 day ';
  else
    $dtxt = "${day} days ";

  if ($hr == 0)
    $htxt = '';
  else if ($hr == 1)
    $htxt = ($day > 0) ? ', 1 hour' : '1 hour';
  else
    $htxt = ($day > 0) ? ", ${hr} hours" : "${hr} hours";

  if ($hr > 0 && $mtxt != '') $htxt .= ', ';
  if ($mtxt != '') $htxt .= $mtxt;
  $togo = $htxt . ' to go!';
  $dims = imagettfbbox(14, 0, $FONT_B, $dtxt);
  $dtxt_w = $dims[2] - $dims[0];
  $dims = imagettfbbox(14, 0, $FONT,   $togo);
  $togo_w = $dims[2] - $dims[0];
  $twidth = $dtxt_w + $togo_w;
  $pos = 256 - round($twidth / 2);

  imagettftext($im, 14, 0, $pos,$y, $bk, $FONT_B, $dtxt);
  $pos += $dtxt_w;
  imagettftext($im, 14, 0, $pos,$y, $bk, $FONT, $togo);
}

$y += 10;
//
////////////////////////////////////////////////////////////////////////////////

// Indicate when image was last generated (see image caching above)
$date = 'Updated ' . gmstrftime('%H:%M:%S') . ' UTC';
$dims = imagettfbbox(7, 0, $FONT, $date);
$date_w = $dims[2] - $dims[0];
$x = 501 - $date_w;
$y = 111;
imagettftext($im, 7, 0, $x, $y, $ovrbk, $FONT, $date);

// Put it all together and output the image!
imagealphablending($im, false);
imagesavealpha($im, true);
imagepng($im,$ifile);
imagepng($im);
imagedestroy($im);
