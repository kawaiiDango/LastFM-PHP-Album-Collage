<?php

include('curl.php');

class CurlException extends Exception{
  public function __construct($message, $code = 0, Exception $previous = null) {
    parent::__construct($message, $code, $previous);
  }

  public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
  }
}

class Utils {
  public static function getJson($url, $curl, $test=false)
  {
    /*
      Method for downloading JSON from LastFM using cURL.
      Must set User-Agent, as per LastFM's API policy.
    */
    $curl->setUrl($url)
         ->setType('GET');
    $curl->send();
    $response = $curl->getBody();

    if($response == false || $curl->getStatusCode() != 200)
    {
      if(!$test)
        imagepng(Utils::errorImage($curl->getStatusCode()));
      throw new CurlException('Error: '.$curl->getStatusCode());
    }
    return ($response);
  }
  //Tested
  function getImages($coverUrls, $max)
  {

    /*
      This method uses parallel cURL's to speed up downloads.
    */
    //Create array to hold cURL's
    $chs = array();
    //Boolean to note if the downloads are still progressing.
    $running = null;
    $mhandler = curl_multi_init();
    $counter = 0;
    $coverUrls = array_slice($coverUrls, 0, $max);
    foreach($coverUrls as $url)
    {
      $chs[$counter] = curl_init($url['url']);
      curl_setopt($chs[$counter], CURLOPT_RETURNTRANSFER, true);
      curl_setopt($chs[$counter], CURLOPT_USERAGENT, 'www.paddez.com/lastfm/');
      curl_setopt($chs[$counter], CURLOPT_CONNECTTIMEOUT, 20);
      curl_setopt($chs[$counter], CURLOPT_TIMEOUT, 120);
      curl_multi_add_handle($mhandler, $chs[$counter]);
      $counter++;
    }
    do
    {
      curl_multi_exec($mhandler, $running);
      curl_multi_select($mhandler);
    } while($running > 0);

    $counter = 0;
    $images = array();
    foreach($chs as $ch)
    {
      $images[$counter]['data'] = curl_multi_getcontent($ch);
      
      $images[$counter] += $coverUrls[$counter];
      curl_multi_remove_handle($mhandler, $ch);
      $counter++;
    }
    curl_multi_close($mhandler);
    
    return $images;
  }

  function createCollage($covers, $quality ,$totalSize, $cols, $rows, $albumInfo, $playcount)
  {
    switch ($quality)
    {
      case 0:
        $pixels = 34;
        break;
      case 1:
        $pixels = 64;
        break;
      case 2:
        $pixels = 126;
        break;
      case 3:
        $pixels = 300;
        break;
    }

    //Create blank image
    $canvas = imagecreatetruecolor($pixels * $cols, $pixels * $rows);
    //Set black colour.
    $backgroundColor = imagecolorallocate($canvas, 0, 0, 0);
    //Fill with black
    imagefill($canvas, 0, 0, $backgroundColor);
    //Note where cursor is.
    $coords['x'] = 0;
    $coords['y'] = 0;

    $counter = 1;
    //Grab images with cURL method.
    $images = Utils::getImages($covers, $rows * $cols);

    //For each image returned, create image object and write text
    foreach($images as $rawdata)
    {
      
      $image = imagecreatefromstring($rawdata['data']);
      if($albumInfo || $playcount)
      {

        $font = "resources/NotoSansCJK-Regular.ttc";
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $offset = 0;
        if($albumInfo && $playcount)
        {
          if (isset($rawdata['artist']))
            $this->imagettfstroketext($image, 10, 0, 5, 20 + ($offset++ * 15), $white, $black, $font, $rawdata['artist'], 2);
          if(isset($rawdata['album']))
            $this->imagettfstroketext($image, 10, 0, 5, 20 + ($offset++ * 15), $white, $black, $font, $rawdata['album'], 2);
          $this->imagettfstroketext($image, 10, 0, 5, 20 + ($offset++ * 15), $white, $black, $font, $rawdata['playcount']." plays", 2);
        }
        elseif($albumInfo)
        {
          if (isset($rawdata['artist']))
            $this->imagettfstroketext($image, 10, 0, 5, 20 + ($offset++ * 15), $white, $black, $font, $rawdata['artist'], 2);
          if(isset($rawdata['album']))
            $this->imagettfstroketext($image, 10, 0, 5, 20 + ($offset++ * 15), $white, $black, $font, $rawdata['album'], 2);
        }
        elseif($playcount)
        {
          $this->imagettfstroketext($image, 10, 0, 5, 20, $white, $black, $font, $rawdata['playcount']." plays", 2);
        }
      }

      imagecopy($canvas, $image, $coords['x'], $coords['y'], 0, 0, $pixels, $pixels);

      //Increase X coords each time
      $coords['x'] += $pixels;
      //If we've hit the side of the image, move down and reset x position.
      if($counter % $cols == 0)
      {
        $coords['y'] += $pixels;
        $coords['x'] = 0;
      }

      $counter++;

    }
    return $canvas;
  }

  function imagettfstroketext(&$image, $size, $angle, $xSize, $ySize, &$textcolor, &$strokecolor, $fontfile, $text, $pixels)
  {
    /*
      Function to add shadow to text.
    */
    for($c1 = ($xSize-abs($pixels)); $c1 <= ($xSize+abs($pixels)); $c1++){
      for($c2 = ($ySize-abs($pixels)); $c2 <= ($ySize+abs($pixels)); $c2++){
        imagettftext($image, $size, $angle, $c1, $c2, $strokecolor, $fontfile, $text);
      }
    }
    return imagettftext($image, $size, $angle, $xSize, $ySize, $textcolor, $fontfile, $text);
  }

  function getArt($albums, $quality)
  {
    global $request;
    /*
       0 = Low (34)
       1 = Medium (64s)
       2 = Large (126)
       3 = xlarge (300)
     */
    $i = 0;
    $artUrl = null;

    foreach($albums as $album)
    {
      $url = $album->{'image'}[$quality]->{'#text'};

      if(strpos($url, 'noimage') != false || strlen($url) < 5)
      {
        error_log('No album art for - '.$album->{'artist'}->{'name'}.' - '.$album->{'name'});
        continue;
      }

      $artUrl[$i]['artist'] = $album->{'artist'}->{'name'};
      $artUrl[$i]['album'] = $album->{'name'};
      $artUrl[$i]['mbid'] = $album->{'mbid'};
      $artUrl[$i]['playcount'] = $album->{'playcount'};
      $artUrl[$i]['url'] = $url;
      $artUrl[$i]['user'] = $request['user'];
      $i++;
    }

    return $artUrl;
  }

  function getArtArtist($html, $quality)
  {
    global $request;
    /*
       0 = Low (34)
       1 = Medium (64s)
       2 = Large (126)
       3 = xlarge (300)
     */
    $i = 0;
    $artUrl = null;

    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $dom->preserveWhiteSpace = false;
    $finder = new DomXPath($dom);
    $classname="chartlist-image";
    $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $classname ')]");
    foreach($nodes as $imageNode) {
      $imgUrl = $imageNode->firstChild->nextSibling->firstChild->nextSibling->getAttribute("src");
      if($quality > 2)
        $imgUrl = str_replace("avatar70s", "avatar300s", $imgUrl);
      else if($quality > 1)
        $imgUrl = str_replace("avatar70s", "avatar170s", $imgUrl);
      
      $nameNode = $imageNode->nextSibling->nextSibling;
      $artistName = trim($nameNode->textContent);

      $countNode = $nameNode->nextSibling->nextSibling->nextSibling->nextSibling;
      $playCount = explode(" ", trim($countNode->textContent))[0];

      if(strpos($imgUrl, "2a96cbd8b46e442fc41c2b86b821562f")){
        error_log('No album art for - ' . $artistName);
        continue;
      }
      $artUrl[$i]['artist'] = $artistName;
      $artUrl[$i]['mbid'] = "";
      $artUrl[$i]['playcount'] = $playCount;
      $artUrl[$i]['url'] = $imgUrl;
      $artUrl[$i]['user'] = $request['user'];
      $i++;
    }

    return $artUrl;
  }
  static function getAlbums($json)
  {
    if(is_object($json->{'topalbums'}))
      return $json->{'topalbums'}->{'album'};
    if(is_object($json->{'topartists'}))
      return $json->{'topartists'}->{'artist'};
    return null;
  }

  static function getArtists($json)
  {
    if(is_object($json->{'topartists'}))
      return $json->{'topartists'}->{'artist'};
    return null;
  }
  static function errorImage($message)
  {
    $xSize = 500;
    $ySize = 50;
    $font = "resources/OpenSans-Regular.ttf";

    $image = imagecreatetruecolor($xSize, $ySize);
    $background = imagecolorallocate($image, 0xF0, 0xF0, 0xF0);
    $foreground = imagecolorallocate($image, 0x00, 0x00, 0x00);
    imagefill($image, 0, 0, $background);
    imagettftext($image, 20, 0, 45, 20, $foreground, $font ,$message);

    return $image;
  }
}
?>
