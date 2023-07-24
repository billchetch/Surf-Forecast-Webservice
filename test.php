<?php
require_once('_include.php');

use chetch\api\APIMakeRequest as APIMakeRequest;
use \chetch\Config as Config;

//init logger
$router = null;

function normalDistribution($x, $sd = 1){
	$a = (1.0 / ($sd*sqrt(2*M_PI))); 
	$b = exp(-1*($x*$x) / (2*$sd*$sd));
	return $a*$b;
}

function directionQuality($actualDirection, $bestDirection, $spread = 4){
	$normalisedDirection = abs($bestDirection - $actualDirection);
	if($normalisedDirection > 180)$normalisedDirection = 360 - $normalisedDirection;
	$normalisedDirection = 3.5*$normalisedDirection / 180.0; //bring to within 0, 4

	$normalisedDirection = pow($normalisedDirection, 1.5) / $spread;
	$scaleToOne = sqrt(2*M_PI);
	return $scaleToOne*normalDistribution($normalisedDirection); //, $sd);
}

function ceilingQuality($val, $ceiling, $spread = 4){
	if($val <= 0)return 0;
	if($val >= $ceiling)return 1;

	$normalisedVal = 4.0*($ceiling - $val)/$ceiling;
	$normalisedVal = $normalisedVal*$normalisedVal / $spread;

	$scaleToOne = sqrt(2*M_PI);
	return $scaleToOne*normalDistribution($normalisedVal); 
}

function floorQuality($val, $floor, $max, $spread = 4){
	if($val <= $floor)return 1;
	if($val >= $max)return 0;

	$normalisedVal = ($val - $floor)*4.0/$max;
	$normalisedVal = $normalisedVal*$normalisedVal / $spread;
	$scaleToOne = sqrt(2*M_PI);
	return $scaleToOne*normalDistribution($normalisedVal); 
}


function windQuality($windDirection, $windSpeed, $bestDirection, $windWindow = 4, $windSpeedThreshold = 5, $maxWindspeed = 50){
	$directionQuality = directionQuality($windDirection, $bestDirection, $windWindow);
	$windQuality = floorQuality($windSpeed, $windSpeedThreshold, $maxWindspeed, 1.25*$directionQuality);
	return $windQuality;
}

function swellQuality($swellDirection, $swellPeriod, $swellHeight, $bestDirection, $swellWindow = 1, $periodCeiling = 16, $heightCeiling = 2.5){
	$directionQuality = directionQuality($swellDirection, $bestDirection, $swellWindow);
	$xp = 2;
	$ceiling = pow($periodCeiling, $xp)*$heightCeiling;
	$whq = ceilingQuality(pow($swellPeriod, $xp)*$swellHeight*$directionQuality, $ceiling, 3.5*$swellPeriod/$periodCeiling);
	return $whq;
}

function conditionsRating($swellQuality, $windQuality, $scale = 5){
	 return round($scale*$swellQuality*$windQuality);
}


function download($url, $payload, $encoding){
	//retrieve data
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url); 
	curl_setopt($ch, CURLOPT_HEADER, false); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	if($encoding)curl_setopt($ch, CURLOPT_ENCODING, $this->get('encoding'));
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, Config::get('CURLOPT_CONNECTTIMEOUT',30));
	curl_setopt($ch, CURLOPT_TIMEOUT, Config::get('CURLOPT_TIMEOUT',30));
		
	if(!empty($payload)){
		curl_setopt($ch, CURLOPT_POST, 1);
		//echo $this->payload; die;
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	}
		
	$data = curl_exec($ch); 
	$error = curl_error($ch);
	$errno = curl_errno($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);
				
    //store stuff if it's any good
    if($data && $errno == 0 && $info['http_code'] < 400)
	{
        return $data;
    } else {
        throw new Exception($error);
    }
}

function getForecastDateAndTime($datestr, $timezone = null, $round2hour = true, $asArray = true){
	if(is_numeric($datestr))$datestr = date('Y-m-d H:i:s', $datestr);
	$dt = new DateTime($datestr);
	if($timezone)$dt->setTimezone(new DateTimeZone($timezone));
	$date = $dt->format('Y-m-d');
	$time = null;
	if($round2hour){
		$hour = $dt->format('H');
		$time = str_pad($hour, 2, '0', STR_PAD_LEFT).':00:00';
	} else {
		$time = $dt->format('H:i:s');
	}
	if($asArray){
		return array('date'=>$date, 'time'=>$time);
	} else {
		return $date.' '.$time;
	}
}

try{
	$lf = "\n";
	$spotId = "640a69004eb375bdb39e4cb3";
	$forecasts = array();
	//note: don't change the order of these ... wave must come first!'
	$forecasts['wave'] = array('qs'=>"spotId=$spotId&days=5&intervalHours=1&cacheEnabled=true&units%5BswellHeight%5D=M&units%5BwaveHeight%5D=M");
	$forecasts['wind'] = array('qs'=>"spotId=$spotId&days=5&intervalHours=1&corrected=false&cacheEnabled=true&units%5BwindSpeed%5D=KPH");
	$forecasts['rating'] = array('qs'=>"spotId=$spotId&days=5&intervalHours=1&cacheEnabled=true");
	
	$baseurl = "http://services.surfline.com/kbyg/spots/forecasts/";

	$tideurl = "http://services.surfline.com/kbyg/spots/forecasts/tides";
	$tideqs = "spotId=$spotId&days=6&cacheEnabled=true&units%5BtideHeight%5D=M";

	
	$rows = array();
	foreach($forecasts as $k=>$f){
		$url = $baseurl.$k.'?'.$f['qs'];

		echo "Starting download of $k: $url $lf";
		$s = download($url, null, null);
		$data = json_decode($s, true);
		if(json_last_error()){
			throw new Exception("JSON error: ".json_last_error());
		}
		echo "Download $k successful $lf";
		sleep(1);

		//all teh data from the feed we want to add
		$data2add = $data['data'][$k];
		foreach($data2add as $d){
			//create the row we want to fill
			$ts = $d['timestamp'];
			if(!isset($rows[$ts])){
				if($k == 'wave'){
					$rows[$ts] = array('timestamp'=>$ts, 'date_time'=>date('Y-m-d H:i:s', $ts)); //this is UTC
				} else {
					echo "Cannot find timestamp key $ts in array when processing $k so skipping $lf ";
					continue;
				}
			}
			
			//now we do individual parsing
			$row = array();
			switch($k){
				case 'wave':
					$surf = $d['surf'];
					$swells = $d['swells'];
					$dkey = 'height'; //in meters
					$primarySwell = null;
					$secondarySwell = null;
					foreach($swells as $swell){
						if(!$primarySwell && $swell['height'] > 0){
							$primarySwell = $swell;
						} elseif($primarySwell && !$secondarySwell && $swell['height'] > 0){
							$secondarySwell = $swell;
						}
					}

					$min = $surf['min'];
					$max = min($surf['max'], $min + 1);
					$row['swell_height'] = ($max + $min) / 2;
					$row['swell_height_primary'] = $primarySwell ? $primarySwell[$dkey] : null;
					$row['swell_height_secondary'] = $secondarySwell ? $secondarySwell[$dkey] : null;

					$dkey = 'period'; //in seconds
					$row['swell_period'] = $primarySwell ? $primarySwell[$dkey] : null;
					$row['swell_period_primary'] = $primarySwell ? $primarySwell[$dkey] : null;
					$row['swell_period_secondary'] = $secondarySwell ? $secondarySwell[$dkey] : null;
					
					$dkey = 'direction'; //in degrees
					$row['swell_direction'] = $primarySwell ? $primarySwell[$dkey] : null;
					$row['swell_direction_primary'] = $primarySwell ? $primarySwell[$dkey] : null;
					$row['swell_direction_secondary'] = $secondarySwell ? $secondarySwell[$dkey] : null;
					break;

				case 'wind':
					$row['wind_speed'] = $d['speed']; //in kph
					$row['direction'] = $d['direction']; //in degrees
					break;

				case 'rating':
					$rating = $d['rating'];
					$row['rating'] = $rating['value'];
					break;
			}

			//assign this row to the row by timestamp
			foreach($row as $rk=>$rv){
				$rows[$ts][$rk] = $rv;
			}
		} //end adding data from a particular url
	} //end looping throw urls

	//here are rows are complete
	print_r($rows);


} catch (Exception $e){
	if($router && $router->loggedIn){
		$router->logout();
	}
	echo $e->getMessage();
}
?>