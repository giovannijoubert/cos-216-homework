<?php 

class Database{ //connection to database to confirm API key
    public static function instance()
    {
        static $instance = null;
        if($instance === null) $instance = new Database();
        return $instance;
    }
    
    // Database Detail
    private $host = "wheatley.cs.up.ac.za";
    private $db_name = "u18009035_COS216";
    private $username = "u18009035";
    private $password = "G10v@nn1";
    private $conn;
    
    private function __construct(){
        $this->conn = null;
    }
    
    public function connectDB(){
        //Connect
        try{
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            return true;
        }catch(PDOException $exception){
            return false;
        }
    }
    
    public function __destruct(){
        $this->conn = null;
    }
    
    public function runQuery($qry){
        return $this->conn->query($qry); 
    }
    
    public function prepareQuery($qry){
        return $this->conn->prepare($qry);
    }
    
    public function checkAPI($uAPIKey){
        //Check whether uAPIKey exists in Database
        $sql = "SELECT count(*) FROM Users WHERE uAPIKey = '$uAPIKey'"; 
        $result = $this->conn->query($sql); 
        $number_of_rows = $result->fetchColumn(); 
        
        //if uAPIKey exists, return connection, else return false
        if($number_of_rows>0){
           return true; 
        } else {
            return false;
        }
    }   
}

class AggregatorAPI{
    private $reqTitle;
    private $reqIMDBID;
    private $return;
    
    public function __construct(){
        header("Content-Type: application/json"); //ALWAYS return JSON    
    }
    
    public function processInfoRequest($reqTitle, $reqIMDBID, $special_req, $return){
    if($return == false){
        $localError = true;
    } else {
    
    $localError = false;
   
    if($reqIMDBID != false){ //Favor given to IMDBID if provided
        $OMDB_RES = array();
        $OMDB_SINGLE_RES = $this->cURLRetrieve("http://www.omdbapi.com/?i=$reqIMDBID&apikey=d3422035");
        if ($OMDB_SINGLE_RES != false)
            array_push($OMDB_RES, $OMDB_SINGLE_RES);
    } else if ($reqTitle != false){ //Else do search by title
        if ($reqTitle == "*"){ //accomodate for wildcard title search
            if($special_req != false){
                $TMDB_RES = $this->cURLRetrieve("https://api.themoviedb.org/3/movie/now_playing?api_key=3f51c3329a20300b741b815efe1b5daa&language=en-US&page=1"); 
            } else {
                $TMDB_RES = $this->cURLRetrieve("https://api.themoviedb.org/3/discover/movie?api_key=3f51c3329a20300b741b815efe1b5daa&language=en-US&sort_by=popularity.desc&include_adult=false&include_video=false&page=1");   
            }
            
            $OMDB_RES = $this->getOMDBFromTMDB($TMDB_RES);
        } else { 
            $TMDB_RES = $this->cURLRetrieve("https://api.themoviedb.org/3/search/movie?api_key=3f51c3329a20300b741b815efe1b5daa&language=en-US&query=".urlencode($reqTitle)."&page=1&include_adult=false");
            $OMDB_RES = $this->getOMDBFromTMDB($TMDB_RES);
        }
    } else {
        $localError = true;
        $TMDB_RES = false;
        $OMDB_RES = false;
    }
    }
     
    $this->processInfoResponse($OMDB_RES, $localError, $return);
    
    }
    
    private function getOMDBFromTMDB($TMDB_RES){
    $OMDB_RES = array();
    $OMDB_SINGLE_RES = array();
            if($TMDB_RES != false){
              
                $TMDB_RES = json_decode($TMDB_RES);
                    
                foreach ($TMDB_RES->results as $result) {
                $OMDB_SINGLE_RES = json_decode($this->cURLRetrieve("http://www.omdbapi.com/?t=".urlencode($result->title)."&apikey=d3422035"), true);
                    if($OMDB_SINGLE_RES == false){
                        $OMDB_RES = false;
                        break;
                    }
                    $OMDB_SINGLE_RES["Fanart"] = "https://image.tmdb.org/t/p/w1280" . $result->backdrop_path;
                    $OMDB_SINGLE_RES["Poster"] = "https://image.tmdb.org/t/p/w500". $result->poster_path;
                    $OMDB_SINGLE_RES["Genres"] = $result->genre_ids;
                
                    if(isset($OMDB_SINGLE_RES["imdbID"])){
                        $stmt = Database::instance()->prepareQuery("SELECT imdbid, AVG(rating) AS avgRating FROM movieRatings WHERE imdbid=? GROUP BY imdbid");
                        $res = $stmt->execute([$OMDB_SINGLE_RES["imdbID"]]);
                      
                    $val = $stmt->fetch()["avgRating"];
                    
                    if($val == null){
                        
                        $OMDB_SINGLE_RES["cluedRating"] = "N/A";
                    } else {
                        $OMDB_SINGLE_RES["cluedRating"] =  floor($val)."%";

                    }
                    }
            
                    if($result->poster_path != "") // exclude results without images, those are gross man
                        array_push($OMDB_RES, json_encode($OMDB_SINGLE_RES));
                }
            }
    return $OMDB_RES;
    }
    
    public function processInfoResponse($OMDB_RES, $localError, $return){
    $date = new DateTime(); //get date for timestamp
    if($localError == true){ //local error
        $response_data  = array(
            'status'    => 'unsuccessful',
            'timestamp' => $date->getTimestamp(),
            'data'      => array()
        );
    } else if ($OMDB_RES == false){ //external error, one or more sources failed
        $response_data  = array(
            'status'    => 'error',
            'timestamp' => $date->getTimestamp(),
            'data'      => array()
        );
    } else { //success, prepare and return data
        $response_data  =   array(
            'status'    =>  'success',
            'timestamp' =>  $date->getTimestamp(),
            'data'      =>  array()
        );

        foreach($OMDB_RES as $result){
            $SINGE_RES =  $this->processReturn($result, $return);
            if($SINGE_RES)
                array_push($response_data["data"],$SINGE_RES);
        }
         unset($result);    
        
        if(empty($response_data["data"])) //check if no movies found for paramaters
             array_push($response_data["data"],"no results");
    }
        
    $response_data = json_encode($response_data);
    echo $response_data;
    }

    public function processReturn($result, $return){
            static $VideoCount = 0;
            $result = json_decode($result);
            $movieResult  = array();
            if(isset($result->Title)){
            if($return == "*") //wild card     
            {
                if(isset($result->Title))
                $movieResult += array("title"      => $result->Title);
                if(isset($result->Poster))
                $movieResult += array("poster"     => $result->Poster);
                if(isset($result->Fanart))
                $movieResult += array("fanart" => $result->Fanart);
                if(isset($result->Year))
                $movieResult += array("year"       => $result->Year);
                if(isset($result->Released))
                $movieResult += array("released"   => $result->Released);
                if(isset($result->Plot))
                $movieResult += array("synopsis"   => $result->Plot);
                if(isset($result->Genres))
                $movieResult += array("genres" => $result->Genres);
                if(isset($result->imdbID))
                $movieResult += array("imdbid"     => $result->imdbID);
                if(isset($result->imdbRating))
                $movieResult += array("imdbRating" => $result->imdbRating);
                if(isset($result->cluedRating))
                $movieResult += array("cluedRating" => $result->cluedRating);
                if(isset($result->Rated))
                $movieResult += array("ageRating" => $result->Rated);
                if(isset($result->Country))
                $movieResult += array("country" => $result->Country);
                if(isset($result->Runtime))
                $movieResult += array("runtime" => $result->Runtime);
                if(isset($result->BoxOffice))
                $movieResult += array("boxoffice" => $result->BoxOffice);
                
            } else {
              //  var_dump($return);
                foreach($return as $r){
                    switch ($r) {
                        case "title":
                            $movieResult += array("title"      => $result->Title);
                            break;
                        case "poster":
                            $movieResult += array("poster"     => $result->Poster);
                            break;
                        case "fanart":
                            $movieResult += array("fanart"     => $result->Fanart);
                            break;
                        case "year":
                            $movieResult += array("year"       => $result->Year);
                            break;
                        case "released":
                            $movieResult += array("released"   => $result->Released);
                            break;
                        case "synopsis":
                            $movieResult += array("synopsis"   => $result->Plot);
                            break;
                        case "genres":
                            $movieResult += array("genres" => $result->Genres);
                             break;
                        case "imdbid":
                            $movieResult += array("imdbid"     => $result->imdbID);
                            break;
                        case "imdbRating":
                            $movieResult += array("imdbRating" => $result->imdbRating);
                            break;
                        case "cluedRating":
                            $movieResult += array("cluedRating" => $result->cluedRating);
                            break;
                        case "ageRating":
                            $movieResult += array("ageRating" => $result->Rated);
                            break;
                        case "video":
                            if($VideoCount < 3){
                            $video = $this->cURLRetrieve("https://api.themoviedb.org/3/movie/$result->imdbID?api_key=3f51c3329a20300b741b815efe1b5daa&language=en-US&append_to_response=videos");
                            $video = json_decode($video,true);
                            $movieResult += array("video" => $video["videos"]["results"][0]["key"]);
                            }
                            $VideoCount++;
                            break;
                        case "country":
                            $movieResult += array("country" => $result->Country);
                            break;
                         case "runtime":
                            $movieResult += array("runtime" => $result->Runtime);
                            break;
                        case "boxoffice":
                            $movieResult += array("boxoffice" => $result->BoxOffice);
                            break;
                        
                    }
                }
            }
                return $movieResult;
        }
    }

    private function cURLRetrieve($externalURL){    
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $externalURL,
            CURLOPT_RETURNTRANSFER => true,
             CURLOPT_PROXY => "phugeet.cs.up.ac.za:3128", //UNCOMMENT ON WHEATLEY
            CURLOPT_CUSTOMREQUEST => "GET",
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
    
        curl_close($curl);

        if ($err) {
            return false;
        } else {
            return $response;
        }
    }
    
    public function getTrailer($movieID){
        $TMDB_SINGLE_RES = $this->cURLRetrieve("https://api.themoviedb.org/3/movie/$movieID/videos?api_key=3f51c3329a20300b741b815efe1b5daa&language=en-US");
        header("Content-Type: application/json"); 
        echo $TMDB_SINGLE_RES;
    }
    
    public function getMovieOnDay($calDay, $calMonth, $calYear){
        $TMDB_SINGLE_RES = $this->cURLRetrieve("https://api.themoviedb.org/3/discover/movie?api_key=3f51c3329a20300b741b815efe1b5daa&language=en-US&sort_by=popularity.desc&include_adult=false&include_video=true&page=1&primary_release_date.gte=$calYear-$calMonth-$calDay&primary_release_date.lte=$calYear-$calMonth-$calDay&release_date.gte=$calYear-$calMonth-$calDay&release_date.lte=$calYear-$calMonth-$calDay");
        
        echo $TMDB_SINGLE_RES;
    }
    
    private function getOMDBFromIDArray($IDArray){
    $OMDB_RES = array();
    $OMDB_SINGLE_RES = array();
              
                foreach ($IDArray as $result) {
                    
                $OMDB_SINGLE_RES = json_decode($this->cURLRetrieve("http://www.omdbapi.com/?i=".urlencode($result["imdbid"])."&apikey=d3422035"), true);
                    if($OMDB_SINGLE_RES == false){
                        $OMDB_RES = false;
                        break;
                    }
                    
                    
                    $OMDB_SINGLE_RES["Fanart"] = "";
                    $OMDB_SINGLE_RES["Genres"] = $OMDB_SINGLE_RES["Genre"];
                    $OMDB_SINGLE_RES["cluedRating"] =  floor($result["avgRating"])."%";
           
                    if($OMDB_SINGLE_RES["Poster"]  != "") // exclude results without images, those are gross man
                        array_push($OMDB_RES, json_encode($OMDB_SINGLE_RES));
                }
            
        return $OMDB_RES;
    }
    
    public function getTopRated($return){
        $qry = Database::instance()->runQuery("SELECT imdbid, AVG(rating) AS avgRating FROM movieRatings GROUP BY imdbid ORDER BY avgRating DESC LIMIT 24");
        $res = $qry->fetchAll();
        
        $this->processInfoResponse($this->getOMDBFromIDArray($res), false, $return);
        

        die();
       
    }
    
    public function endWithLocalError($error){
        $date = new DateTime();
        $response_data  = array(
            'status'    => 'unsuccessful',
            'error'     => $error,
            'timestamp' => $date->getTimestamp()
        );
        $response_data = json_encode($response_data);
        echo $response_data;
        die();
    }
    
     public function endWithSuccess($msg){
        $date = new DateTime();
        $response_data  = array(
            'status'    => 'success',
            'timestamp' => $date->getTimestamp(),
            'data'     => $msg
        );
        $response_data = json_encode($response_data);
        echo $response_data;
        die();
    }
    
    
}

        $Aggregate = new AggregatorAPI();

        //Make sure that it is a POST request.
        if(strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') != 0){
            $Aggregate->endWithLocalError("Request method must be POST!");
        }

        //Make sure that the content type of the POST request has been set to application/json
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
        if(strcasecmp($contentType, 'application/json') != 0){
            $Aggregate->endWithLocalError('POST Content type must be: application/json');
        }

        //Receive the RAW post data.
        $POSTData = trim(file_get_contents("php://input"));

        //Attempt to decode the incoming RAW post data from JSON.
        $decodedData = json_decode($POSTData, true);

        //If json_decode failed, the JSON is invalid.
        if(!is_array($decodedData)){
            $Aggregate->endWithLocalError('POST Content contains invalid JSON!');
        }

        //check DB connection & SINGLETON instances
        if(Database::instance()->connectDB() == false)
            $Aggregate->endWithLocalError("Cannot connect to Database / multiple instances");

        if(!isset($decodedData["request"]["type"]))
            $Aggregate->endWithLocalError("Require a request type");

        

        if($decodedData["request"]["type"] == "login"){
            //implement login type for API
            

                if(!isset($decodedData["request"]["uEmail"]) || !isset($decodedData["request"]["uPassword"]))
                {
                    $Aggregate->endWithLocalError("0: One or more fields empty");
                    die();
                }
            
                //Retrieve submitted data
                $uEmail = $decodedData["request"]["uEmail"];
                $uPassword = $decodedData["request"]["uPassword"];

                //Sanitizing email address
                $uEmail = filter_var($uEmail, FILTER_SANITIZE_EMAIL);
                $uPassword = filter_var($uPassword, FILTER_SANITIZE_STRING);

                //REVALIDATING user input SERVERSIDE

                $emailRegEx = '/^(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){255,})(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){65,}@)(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22))(?:\\.(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\\]))$/iD';

                if(!preg_match($emailRegEx, $uEmail)){
                    $Aggregate->endWithLocalError("1: Invalid email");
                    die();
                }

                //Get SALT for corresponding user in database
                $result = Database::instance()->runQuery("SELECT * FROM Users WHERE uEmail='$uEmail'");
            
                //Email not in DB (not registered)
                if (! $result->rowCount() > 0) {
                    $Aggregate->endWithLocalError("2: Email does not exist in Database");
                    die();
                }

                $row = $result->fetch();

                $uSalt = $row["uSalt"];

                $options = array(
                    'salt' => $uSalt
                );
                //PASSWORD_DEFAULT ensures the latest, most secure algorithm is used (currently PASSWORD_BCRYPT)
                $password_hash = password_hash($uPassword, PASSWORD_DEFAULT, $options);

                //Compare password hash with that stored in DB
                $uPasswordDB = $row["uPassword"];

                if($uPasswordDB != $password_hash){
                    $Aggregate->endWithLocalError("3: Incorrect password");
                    die();
                }
            
                //Made it till here! Congratz! Login by returning APIKEY
                $Aggregate->endWithSuccess($row["uAPIKey"]);
    
                die();
            
        }
    
        //check if uAPIKey is valid
        if(isset($decodedData["request"]["key"])){
            $uAPIKey = $decodedData["request"]["key"];
        } else {
             $Aggregate->endWithLocalError("Need to provide an API Key");
        }

        if(Database::instance()->checkAPI($uAPIKey) != true){
            $Aggregate->endWithLocalError("Invalid API Key");
        } 
        
        if($decodedData["request"]["key"] == "fccf52f13282789e5d877003c8b30518")
        	if(isset($decodedData["request"]["type"]))
        		if ($decodedData["request"]["type"] != "info")
        			$Aggregate->endWithLocalError("Unauthorized API Key usage!");

        if($decodedData["request"]["type"] == "trakt"){
            
            if(isset($decodedData["request"]["vidID"])){
                 $vidID = $decodedData["request"]["vidID"];
             } else {$Aggregate->endWithLocalError("You need to provide an vidID to request trakt for."); }
            
            $result = Database::instance()->runQuery("SELECT * FROM Trakt WHERE uAPIKey='$uAPIKey' AND vidID='$vidID'");
            
            if(isset($decodedData["request"]["setProgress"])){
                 $setprogress = $decodedData["request"]["setProgress"];
               
                if($result->rowCount() > 0){  //Update progress
                        $stmt = Database::instance()->prepareQuery("UPDATE Trakt SET progress=? WHERE uAPIKey=? AND vidID=?");
                        $res = $stmt->execute([$setprogress, $uAPIKey, $vidID]);
                        if(! $res){
                            $Aggregate->endWithLocalError("Error writing to Database");
                        } else {  $Aggregate->endWithSuccess("Progress updated for vidID $vidID"); }
                } else {  //Create progress
                        $stmt = Database::instance()->prepareQuery("INSERT INTO Trakt (vidID, uAPIKey, progress) VALUES(?,?,?)");
                        $res = $stmt->execute([$vidID, $uAPIKey, $setprogress]); 
                         if(! $res){
                            $Aggregate->endWithLocalError("Error writing to Database");
                        } else { $Aggregate->endWithSuccess("Progress updated for vidID $vidID");  }
                }
            } else { //Retrieve progress
                    $progress = $result->fetch()["progress"];
                    if($progress == null)
                        $Aggregate->endWithLocalError("No progress for vidID $vidID");
                
                    
                   $Aggregate->endWithSuccess($progress);
            }
         
            die();
        }

        if($decodedData["request"]["type"] == "streamable"){

            $result = Database::instance()->runQuery("SELECT * FROM Streamable");
            $result = $result->fetchAll(PDO::FETCH_ASSOC);
            $Aggregate->endWithSuccess(($result));
            die();
        }
        		        	    
         if($decodedData["request"]["type"] == "rate"){
             
             if(isset($decodedData["request"]["imdbid"])){
                 $imdbid = $decodedData["request"]["imdbid"];
             } else {$Aggregate->endWithLocalError("You need to provide an imdbid to rate."); }
             
             if(isset($decodedData["request"]["uRating"])){
                 $uRating = $decodedData["request"]["uRating"];
             } else {$Aggregate->endWithLocalError("You need to provide an uRating value."); }
             
             $result = Database::instance()->runQuery("SELECT * FROM movieRatings WHERE uAPIKey='$uAPIKey' AND imdbid='$imdbid'");
             
              
             
             if($result->rowCount() > 0){ //update user rating for this movie
                $stmt = Database::instance()->prepareQuery("UPDATE movieRatings SET rating=? WHERE uAPIKey=? AND imdbid=?");
                $res = $stmt->execute([$uRating, $uAPIKey, $imdbid]);
                if(! $res){
                    $Aggregate->endWithLocalError("Error writing to Database");
                } 

             } else {//create user rating for this movie
                $stmt = Database::instance()->prepareQuery("INSERT INTO movieRatings (imdbid, uAPIKey, rating) VALUES(?,?,?)");
                $res = $stmt->execute([$imdbid, $uAPIKey, $uRating]); 
                 if(! $res){
                    $Aggregate->endWithLocalError("Error writing to Database");
                } 
             }
             
             $Aggregate->endWithSuccess("Rating submitted");
             
         }
             

        if($decodedData["request"]["type"] == "update"){
            if(isset($decodedData["request"]["special_req"]))
             if($decodedData["request"]["special_req"] == "removeFilters")
             {
                $stmt = Database::instance()->prepareQuery("UPDATE Users SET genrePref=NULL, ratingPref=NULL, yearPref=NULL WHERE uAPIKey=?");
                $stmt->execute([$uAPIKey]);
                die();
             }
            
            
            if(isset($decodedData["request"]["genrePref"])){
            $genrePref = $decodedData["request"]["genrePref"];
            $stmt = Database::instance()->prepareQuery("UPDATE Users SET genrePref=? WHERE uAPIKey=?");
            $stmt->execute([$genrePref, $uAPIKey]);
            } 
            
            if(isset($decodedData["request"]["yearPref"])){
            $yearPref = $decodedData["request"]["yearPref"];
            $stmt = Database::instance()->prepareQuery("UPDATE Users SET yearPref=? WHERE uAPIKey=?");
            $stmt->execute([$yearPref, $uAPIKey]);
            } 
            
            if(isset($decodedData["request"]["theme"])){
            $theme = $decodedData["request"]["theme"];
            $stmt = Database::instance()->prepareQuery("UPDATE Users SET theme=? WHERE uAPIKey=?");
            $stmt->execute([$theme, $uAPIKey]);
            } 
            
            if(isset($decodedData["request"]["ratingPref"])){
            $ratingPref = $decodedData["request"]["ratingPref"];
            $stmt = Database::instance()->prepareQuery("UPDATE Users SET ratingPref=? WHERE uAPIKey=?");
            $stmt->execute([$ratingPref, $uAPIKey]);
            } 
            
            $Aggregate->endWithSuccess("Updated");
            
            die();
            
        } 

        if($decodedData["request"]["type"] == "info"){
            
             if(isset($decodedData["request"]["return"])){
                        if($decodedData["request"]["return"] != "")
                            $return = $decodedData["request"]["return"];
                    } else {
                         $Aggregate->endWithLocalError("Must specify return parameters");
                    }
            
             if(isset($decodedData["request"]["special_req"]))
                if($decodedData["request"]["special_req"] == "getTrailer"){
                    
                if(isset($decodedData["request"]["imdbid"])){
                        $movieID = $decodedData["request"]["imdbid"];
                } else {
                     $Aggregate->endWithLocalError("Must specify imdbid parameters");
                }
                    
                    
                    $Aggregate->getTrailer($movieID);
                    die();
                }
            
            if(isset($decodedData["request"]["special_req"]))
                if($decodedData["request"]["special_req"] == "calendar"){
                    
                    if(isset($decodedData["request"]["month"]))
                       $calMonth = $decodedData["request"]["month"];
                    else $Aggregate->endWithLocalError("Must specify month parameter");
                    
                    if(isset($decodedData["request"]["day"]))
                       $calDay = $decodedData["request"]["day"];
                    else $Aggregate->endWithLocalError("Must specify day parameter");
                    
                    if(isset($decodedData["request"]["year"]))
                       $calYear = $decodedData["request"]["year"];
                    else $Aggregate->endWithLocalError("Must specify year parameter");
                    
                    $Aggregate->getMovieOnDay($calDay, $calMonth, $calYear);
                    die();
                }
            
            if(isset($decodedData["request"]["special_req"]))
                if($decodedData["request"]["special_req"] == "toprated"){
                    
                    $Aggregate->getTopRated($return);
                    die();
                } 
        
        //Get data from post
        if(isset($decodedData["request"]["title"])){
            if($decodedData["request"]["title"] != "")
                $reqTitle = $decodedData["request"]["title"];
            } else {
                $reqTitle = false;
            }

        if(isset($decodedData["request"]["imdbid"])){
            if($decodedData["request"]["imdbid"] != "")
                $reqIMDBID = $decodedData["request"]["imdbid"];
        } else {
            $reqIMDBID = false;
        }
            
        if(isset($decodedData["request"]["special_req"])){
        if($decodedData["request"]["special_req"] == "latest")
                $special_req = $decodedData["request"]["special_req"];
        } else {
             $special_req = false;
        }
        
        $Aggregate->processInfoRequest($reqTitle, $reqIMDBID, $special_req, $return);
            die();
        }
            
        $Aggregate->endWithLocalError("Invalid request type");
        


?>