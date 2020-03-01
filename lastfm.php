<?php
/*

    Last.fm Album Collage
    Runs on Elastic Beanstalk

    David Kernan

    Version 0.5 = 26/06/2010
    Version 0.6 = 17/9/2010
    Version 0.7 = 10/6/2011
    Version 0.9 = 02/8/2011
    Version 1.0 = 26/10/2014
    Version 1.1 = 28/10/2014
    Version 1.2 = 10/02/2015
    Version 1.3 = 18/07/2015
    Version 1.5 = 20/08/2015

    0.5
        Minor Bugfixes

    0.6
        Invalid Headers being sent, corrected.
        Cache timeout increased to 10 minutes.
    0.7
        Removed invalid images showing up in Result, will now only show the albums tha have a cover art in the Last.fm database
    0.9
        Updated Webpage to include loading
        Included Higher Definition Collages

    1.0
        Elastic Beanstalk Support
        Amazon S3 Support
        Total code refiguration to make a bit more sense

    1.1
        Implemented Composer for managing dependancies.

    1.2
        Album information captions (Artist, Album)

    1.3
        Hangul & Japanese Support
        Play count included

 */
//Grabs the query included in the URL.
include('config.inc.php');
//include('vendor/autoload.php');
include('lib/Utils.php');
/*
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;

use Doctrine\Common\Cache\FilesystemCache;
use Guzzle\Cache\DoctrineCacheAdapter;
*/
mb_internal_encoding("UTF-8");

$utils = new Utils();

/*
if(!isset($config))
{
  //if not defined, use Environment variables
  $config['bucket'] = getenv("bucket");
  $config['api_key'] = getenv("api_key");
}

//Cache AWS's temporary role credentials
$cache = new DoctrineCacheAdapter(new FilesystemCache('/tmp/cache'));
$s3 = S3Client::factory(array(
      'credentials.cache' => $cache,
      'region' => 'eu-west-1'));
*/
//Get localhost
$url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$url = substr($url, strpos($url, '?')+1);
//Parses the $vars and assigns the values as in the URL. $name and $period expected here.
parse_str($url, $request);
$request['user'] = trim($request['user']);

$limit = $request['cols'] * $request['rows'] + 15;
//$bucket = $config['bucket'];

//If Configuration isn't defined, throw and error and exit
/*
if(empty($config['bucket']) && empty($config['api_key']))
{
  error_log("Configuration not defined, check environment variables or config.inc.php");
  die();
}

//S3 Key.
$key = 'images/'.$request['user'].'-'.$request['period'].'.jpg';
*/
//Define Lastfm API calls


$validUser = "http://ws.audioscrobbler.com/2.0/?method=user.getinfo&user=".$request['user']."&api_key=".$config['api_key']."&format=json";

$infoJson = json_decode($utils->getJson($validUser, new Curl()));

//If an error is thrown, generate an error image and exit
if(isset($infoJson->{"error"}))
{
  header("Content-Type: image/png");
  error_log($infoJson->{"message"}." - ".$request['user']);
  imagepng(errorImage($infoJson->{"message"}));
  /*
  $sns = SnsClient::factory(array(
        'credentials.cache' => $cache,
        'region' => 'eu-west-1'));
  $sns->publish(array(
        'TopicArn' => 'arn:aws:sns:eu-west-1:346795263809:LastFM-Errors',
        'Message' => $infoJson->{"message"}." - ".$request['user'],
        'Subject' => "Lastfm Error: ".$infoJson->{"error"}
        ));
        */
  return;
}

if (isset($request['artist']) && $request['artist'] == 1){
  $internalDurations = array("overall" => "ALL", "7day" => "LAST_7_DAYS", "1month" => "LAST_30_DAYS", "3month" => "LAST_90_DAYS", "6month" => "LAST_180_DAYS", "12month" => "LAST_365_DAYS");

  $lastfmApi = "https://www.last.fm/user/".$request['user']."/library/artists?date_preset=".$internalDurations[$request['period']];
}
else
  $lastfmApi = "http://ws.audioscrobbler.com/2.0/?method=user.gettopalbums&user=".$request['user']."&period=".$request['period']."&api_key=".$config['api_key']."&limit=$limit&format=json";


//Get User's albums and generate a MD5 hash based on this
$json = $utils->getJson($lastfmApi, new Curl());
if ($limit>50){
    $marker = 'Artists Scrobbled';
    $markerPos = strpos($json, $marker);
    $marker = 'metadata-display">';
    $markerPos = strpos($json, $marker, $markerPos+strlen($marker));
    if ($markerPos){
        $artistCount = substr($json, $markerPos+strlen($marker), 
            strpos($json,"<",$markerPos+strlen($marker)) - $markerPos-strlen($marker));
        $artistCount = (int)str_replace(",", "", $artistCount);

        for ($i=2; $i<=3 && ($i-1)*50<=$artistCount; $i++){
            $json .= $utils->getJson($lastfmApi."&page=".$i, new Curl());
        }
    }
}

/*
$sns = SnsClient::factory(array(
    'credentials.cache' => $cache,
    'region' => 'eu-west-1'));
$sns->publish(array(
    'TopicArn' => 'arn:aws:sns:eu-west-1:346795263809:LastFM-API-CAlls',
    'Message' => $json,
    'Subject' => $user."s JSON API Call"
));
*/
$jsonhash = md5($json);

//Cache based on user set variables and JSON hash
$filename = "images/".$request['user'].$request['period'].$request['rows'].$request['cols'].$request['info'].$request['playcount'].$jsonhash;

//if a previous file exists - request is cached, serve from cache and exit
if(file_exists($filename))
{
  header("Content-Type: image/jpeg");
  error_log("Serving from cache - ".$filename);
  echo file_get_contents($filename);
  exit;
}

//otherwise carry on and getAlbums from LastFM.
if (isset($request['artist']) && $request['artist'] == 1){
    $covers = $utils->getArtArtist($json, 3);
} else {
    $albums = $utils->getAlbums(json_decode($json));
    $covers = $utils->getArt($albums, 3);
}
//Pass the Albums to getArt to download the art into a $covers array

//From the covers array, create a collage while passing user variables required
$image = $utils->createCollage($covers, 3, 0, $request['cols'], $request['rows'], isset($request['info']) && $request['info'] == 1, isset($request['playcount']) && $request['playcount'] == 1);

// Output HTTP ContentType header.
header("Content-Type: image/jpeg");
//Output image on stdout.
imagejpeg($image, null, 88);
//Save image to local filesystem as cache
// imagejpeg($image, $filename, 100);
//After output, save image to S3 for static content
/*
$result = $s3->putObject(array(
      'Bucket'      => $bucket,
      'Key'        => strtolower($key),
      'SourceFile'    => $filename,
      'ACL'        => 'public-read',
      'ContentType'    => 'image/jpeg',
      'CacheControl'  =>  'max-age=16400'
      ));
*/
//delete file
//unlink($filename);
//Free resources
imagedestroy($image);
?>
