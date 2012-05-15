<?php
/**
 * This class will grab an RSS feed from BBC Weather
 * and optionally cache it in a local database.
 *
 * You should satisfy yourself that how you use this code does not breach any licensing restrictions
 * by either the Met Office or the BBC. See Terms of Use below.
 *
 *
 * @author tswann
 * @since 1st July 2008
 * Example feed urls
 * Forecast::     newsrss.bbc.co.uk/weather/forecast/2579/Next3DaysRSS.xml
 * Observations:: newsrss.bbc.co.uk/weather/forecast/1/ObservationsRSS.xml
 * Outlook::      newsrss.bbc.co.uk/weather/forecast/1/MonthlyOutlookRSS.xml
 *
 * You can find the feed id from :
 *
 * BBC Terms Of use for weather feeds
 * (http://www.bbc.co.uk/terms/additional_rss.shtml)
 * If you run your own website, you can display information from other websites on your own site using RSS.
 * We encourage the use of BBC Weather feeds as part of a website, however,
 * we do require that the proper format and attribution is used when BBC Weather content appears.
 * The attribution text should read "BBC Weather" or "bbc.co.uk/weather" as appropriate.
 * You may not use any BBC logo or other BBC trademark.
 * We reserve the right to prevent the distribution of BBC Weather content and the BBC
 * does not accept any liability for its feeds. Please see our Terms of Use for full details.
 * If you want to use BBC Weather content for any purposes or in any way that does not comply
 * with the above terms you will need to obtain the consent of the
 * Met Office (the provider and copyright owner of the weather content).
 * (http://www.metoffice.gov.uk/)
 *
 *
 *
 * This code is released under the FreeBSD license.
 * So you're pretty much free to do whatever you like with it.
 *
 * Copyright 2008-2011 tswann. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 *  1. Redistributions of source code must retain the above copyright notice, this list of
 *     conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice, this list
 *     of conditions and the following disclaimer in the documentation and/or other materials
 *     provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY tswann ''AS IS'' AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND
 * FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL tswann OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The views and conclusions contained in the software and documentation are those of the
 * authors and should not be interpreted as representing official policies, either expressed
 * or implied, of tswann.
 *
 */



class BBCWeather {

  /**
   * which type of feed is this.
   * Options are forecast,observations,outlook
   *
   * @var string
   */
  public $sFeedType = 'forecast' ;


  /**
   * feeds host is protected - so we are always accessing the BBc feed.
   *
   * @var string
   */
  protected $sFeedHost = 'open.live.bbc.co.uk';


  /**
   * feed uri, the feed address for a specific location.
   *
   * example feed URL is
   * http://open.live.bbc.co.uk/weather/feeds/en/2643743/3dayforecast.rss
   *
   * @var string
   */
  public $sFeedUri = '/weather/feeds/en/2643743/3dayforecast.rss';


  /**
  * The XML returned from the feed url.
  *
  * @var string $sXml
  **/
  public $sXml='';


  /**
   * you can optionally save the forecast data to a local database
   *
   * @var string
   */
  public $DB_TABLE = 'bbc_weather' ;

  /**
   * cache lifetime in seconds
   *
   * @var int
   */
  protected $CACHE_TIME = 3600  ;

  /**
   * holder the forecast data for on screen output
   *
   * @var array
   */
  public $aForecastData = array();


  /**
   * display temperatures in degrees Celsius or Farenheit
   * acceptible values are 'metric', 'imperial'
   *
   * @var string
   */
  public $sUnits = 'metric' ;

 /**
 * Constructor
 * set up class properties to determine the feeds to be fetched/parsed
 **/
  public function __construct()
  {

  }//end constructor



  /**
   * setter injection of object properties
   *
   * @param array $aProperties
   */
  public function setProperties($aProperties)
  {
    foreach ($aProperties as $k => $v) {
      if ( property_exists($this,$k) && empty($this->{$k}) ) {
        $this->{$k} = $v;
      }
    }
  }


  /**
   * Get weather data for a specified location
   *
   * @param string $sLocation  e.g. Limavady, Northern Ireland
   * @return array
   */
  public function getWeather()
  {

    if(function_exists('apc_fetch')) {
      $aWeather = apc_fetch($this->sFeedUri);
    } else {
      //user_error('Cache not available', E_USER_NOTICE);
    }


    // Use cached weather if it exists, otherwise fetch from the feed url
    if(empty($aWeather)) {
      $aWeather = $this->refreshCache();

      //populate cache with new data
      if(function_exists('apc_add')) {
        apc_add($aWeather,$this->sFeedUri,$this->CACHE_TIME);
      }
    }

    //set class property and return the array
    $this->aForecastData = $aWeather;
    return $aWeather;



  }



  /**
   * If cached Data is empty or too old then get it from the web sevice
   * and store it into the cache table
   *
   */
  private function refreshCache()
  {
    $sFetchUri = 'http://'.$this->sFeedHost.$this->sFeedUri;

    $sXml = file_get_contents($sFetchUri);
    $this->sXml = $sXml;
    $aFeedData = $this->parseFeed($sXml);
    return $aFeedData;
  }



  /**
   * parse the feed grabbed from the web service
   *
   */
  private function parseFeed($sXml)
  {
    //load the xml into a simple XML object
    try {
      $oXml = new SimpleXMLElement($sXml) ;
    } Catch (Exception $e) {
      print_r($sXml);
      echo $e->getMessage();
      user_error('Feed not available', E_USER_NOTICE);
    }

    //search for errors
    if(!isset($oXml->channel) ){
      trigger_error("Unspecified error retreiving weather data", E_USER_ERROR);
      return;
    }

    $oForecastInfo = $oXml->channel ;

    //Current day
    $oCurrent = $oForecastInfo->item[0] ;
    $oCurrent->title = substr($oCurrent->title,0,strpos($oCurrent->title = $oCurrent->title,', Max Temp') );
    $oCurrent->description = utf8_decode($oCurrent->description) ;
    $this->splitDescription($oCurrent);
    $this->restrictUnits($oCurrent);

    // Day 2
    $oDay1 = $oForecastInfo->item[1] ;
    $oDay1->title = substr($oDay1->title,0,strpos($oDay1->title = $oDay1->title,', Max Temp') );
    $oDay1->description = utf8_decode($oDay1->description) ;
    $this->splitDescription($oDay1);
    $this->restrictUnits($oDay1);
    // Day 3
    $oDay2 = $oForecastInfo->item[2] ;
    $oDay2->title = substr($oDay2->title,0,strpos($oDay2->title = $oDay2->title,', Max Temp') );
    $oDay2->description = utf8_decode($oDay2->description) ;
    $this->splitDescription($oDay2);
    $this->restrictUnits($oDay2);


    $aForecastInfo = array();
    $aForecastInfo['location'] = $oForecastInfo->title;
    $aForecastInfo['image'] = $oForecastInfo->image;
    $aForecastInfo['current'] = $oCurrent;
    $aForecastInfo['day1'] = $oDay1;
    $aForecastInfo['day2'] = $oDay2;

    return $aForecastInfo;


  }


  private function splitDescription(SimpleXmlElement &$oDay)
  {
    $aParts = explode(',',$oDay->description);
    foreach ($aParts as $aPart) {
      list($label,$value) = explode(':',$aPart);
      $oDay->{str_replace(' ','_',trim($label))} = trim($value);
    }

  }

  private function restrictUnits(&$oDay)
  {
    $sT1 = 'Min_Temp';
    $sT2 = 'Max_Temp';
    $sTempString = $oDay->{$sT1};
    preg_match('/\d{1,3}.C/',$sTempString,$acent);
    preg_match('/(\d{1,3}.F)/',$sTempString,$afar);
    $oDay->{$sT1} = $this->sUnits=='imperial' ? $afar[0] : $acent[0];
    $sTempString = $oDay->{$sT2};
    preg_match('/\d{1,3}.C/',$sTempString,$acent);
    preg_match('/(\d{1,3}.F)/',$sTempString,$afar);
    $oDay->{$sT2} = $this->sUnits=='imperial' ? $afar[0] : $acent[0];
  }


}//end class
