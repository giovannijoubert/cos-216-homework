//npm modules
const express = require('express'); //web framework
const app = express();
const server = require('http').createServer(app);
const io = require('socket.io')(server);
const cors = require('cors'); //cors management
const fs = require('fs');
const path = require('path');
const request = require('request');
const ApiPath = Buffer.from("aHR0cDovL3UxODAwOTAzNTpHMTB2QG5uMUB3aGVhdGxleS5jcy51cC5hYy56YS91MTgwMDkwMzUvYXBpLnBocA==", 'base64').toString();
var ip = require("ip"); //get local ip

//allow for consuming json input
app.use(express.json());

//allow cross origin
app.use(cors());

app.use(express.static(__dirname + '/node_modules'));

var stdin = process.openStdin();
var port;
var init = true;
stdin.addListener("data", function(d) {
    var input = d.toString().trim();
    if(init){ //initial input is server port
        port = input;
        //bind port
        server.listen(port);
        console.log("=== Listening on "+ip.address()+":"+port+" ===");
        console.log("=== No users ===");;
        init = false;
    } else {
      if(input == "LIST"){
        console.log("=== Server connections ===");
        listAllConnections();
      }

      if(input.substring(0,4) == "KILL"){
        killConnection(input.substring(5,input.length));
      }

      if(input == "QUIT"){
        quitAll();
      }
    }
});
//Ask for port number through command line
console.log("== Specify server port: ");

//kill specific connection
function killConnection(connectionNo){
  if((typeof allUsers[connectionNo] == 'undefined')) //check array bounds
    {
      console.log("=== Invalid connection number");
    } else {
      allUsers[connectionNo].emit("bye");
      allUsers[connectionNo].disconnect();
      console.log("=== Connection " + connectionNo + " successfully killed");
    }
}

//list all connections' details
function listAllConnections(){
  for(i = 0; i < allUsers.length; i++){
    if(allClients.indexOf(allUsers[i]) >= 0){
      console.log("====== AUTHENTICATED Connection " + i + " From " + allUsers[i].api);
    } else {
      console.log("====== Connection " + i + " From " + allUsers[i].id);
    }
  }
}
//quit server gracefully
function quitAll(){
  for(i = 0; i < allUsers.length; i++){
    allUsers[i].emit("bye");
    allUsers[i].disconnect();
    i--;
  }
    console.log("=== SERVER SHUTDOWN, KTHXBYE ==");
    server.close();
    process.exit(0);  
}

app.get('/css/main.css', function(req, res) {
  res.sendFile(__dirname + "/client/css/main.css");
});

app.get('/js/cluedup_stream.js', function(req, res) {
  res.sendFile(__dirname + "/client/js/cluedup_stream.js");
});

app.get('/css/dark.css', function(req, res) {
  res.sendFile(__dirname + "/client/css/dark.css");
});

app.get('/img/mainlogo.svg', function(req, res) {
  res.sendFile(__dirname + "/client/img/mainlogo.svg");
});

app.get('/img/favico.png', function(req, res) {
  res.sendFile(__dirname + "/client/img/favico.png");
});

app.get('/', function(req, res,next) {
  res.sendFile('client/index.html', {root: __dirname })
});

//stream the video to the HTML <video> element
app.get('/video', function(req, res, next) {
  const path = __dirname + '/media/' + req._parsedOriginalUrl.query;
  const stat = fs.statSync(path)
  const fileSize = stat.size
  const range = req.headers.range
  if (range) { //file is not yet buffered
    const parts = range.replace(/bytes=/, "").split("-")
    const start = parseInt(parts[0], 10)
    const end = parts[1]
      ? parseInt(parts[1], 10)
      : fileSize-1
    const chunksize = (end-start)+1
    const file = fs.createReadStream(path, {start, end})
    const head = {
      'Content-Range': `bytes ${start}-${end}/${fileSize}`,
      'Accept-Ranges': 'bytes',
      'Content-Length': chunksize,
      'Content-Type': 'video/mp4',
    }

    res.writeHead(206, head)
    file.pipe(res)
  } else { //file is completely buffered
    const head = {
      'Content-Length': fileSize,
      'Content-Type': 'video/mp4',
    }
    res.writeHead(200, head)
    fs.createReadStream(path).pipe(res)
  }
});

//helper function to get Streamable videos through API
function getStreamable(apikey, callback) {
  var options = {
    uri: ApiPath,
    method: 'POST',
    json: {
      "request": {
        "type": "streamable",
        "key": apikey
      }
    }
  };
  request(options, function (error, response, body) {
    if (!error && response.statusCode == 200) {
      callback(response.body);
    }
  });
}; 

//helper function to Login through API
function login(email, password, callback) {
  var options = {
    uri: ApiPath,
    method: 'POST',
    json: {
      "request": {
        "type": "login",
        "uEmail": email,
        "uPassword": password
      }
    }
  };
  request(options, function (error, response, body) {
    if (!error && response.statusCode == 200) {
      callback(response.body);
    }
  });
}; 

//helper function to retrieve Trakt progress from API
function getProgress(apikey, vidID, callback) {
  var options = {
    uri: ApiPath,
    method: 'POST',
    json: {
      "request": {
        "key" : apikey,
        "type": "trakt",
        "vidID": vidID
      }
    }
  };
  request(options, function (error, response, body) {
    if (!error && response.statusCode == 200) {
      callback(response.body);
    }
  });
}; 

//helper function to set Trakt progress from API
function setProgress(apikey, vidID, progress, callback) {
  var options = {
    uri: ApiPath,
    method: 'POST',
    json: {
      "request": {
        "key" : apikey,
        "type": "trakt",
        "vidID": vidID,
        "setProgress": progress
      }
    }
  };
  request(options, function (error, response, body) {
    if (!error && response.statusCode == 200) {
      callback(response);
    }
  });
}; 

var LastSaveToDBTimestamp = 0; //ensure that you don't push data that isn't new

var allUsers = []; //info about all sockets / unauthenticated users
var allClients = []; //keep info about all authenticated clients
io.on('connection', function(client) {
  console.log("=== User connected " + client.id);
  allUsers.push(client); 

  client.on('authenticated', function(apikey){
      client["api"] = apikey;
      allClients.push(client); 
      console.log("=== Client Authenticated: " + apikey);
  })

  var retrieveProgressFromDB;
  var saveProgressToDB;
  client.on('join', function(path, id) { //client starts streaming video
          var apikey = client.api;
          client["vidID"] = id;
          client["vidPath"] = path;
          //check if progress exists for the vidID
          getProgress (apikey, id, function (response){ 
            if(response.status == "unsuccessful"){ //progress does not exist, set to 0
              LastSaveToDBTimestamp = Date.now();
              setProgress (apikey, id, 0, function (){
                client["progress"] = 0;
                for(i = 0; i < allClients.length; i++){
                  if(allClients[i].api == apikey && allClients[i].progress != 0){
                    client["progress"] = allClients[i].progress;
                  }
                } 
       
                console.log("=== Client Started Playing: " + path + " - " + apikey);
                //send file and progress to client
                client.emit('load', "http://"+ip.address()+":"+port+"/video?"+path, client["progress"] );
              });
            } else { 

              //remove extra precision to match with PHP standards
              LastSaveToDBTimestamp = LastSaveToDBTimestamp.toString();
              responseTimestamp = response.timestamp.toString();
              LastSaveToDBTimestamp = LastSaveToDBTimestamp.substring(0,responseTimestamp.length);
          
             if(Number(LastSaveToDBTimestamp)-1000 < Number(response.timestamp)){ //check response timestamp
                client["progress"] = response.data;
             }
              for(i = 0; i < allClients.length; i++){
                if(allClients[i].api == apikey && allClients[i].progress != 0 && allClients[i].progress != undefined){
                  client["progress"] = allClients[i].progress;
                }
              } 
              //send file and progress to client
              console.log("=== Client Started Playing: " + path + " - " + apikey);
              client.emit('load', "http://"+ip.address()+":"+port+"/video?"+path, client["progress"] );
            }
          });

          saveProgressToDB = setInterval(() => {
            LastSaveToDBTimestamp = Date.now();
            setProgress (client.api, client.vidID, client.progress, function (){}); 
          }, 6000);


          retrieveProgressFromDB = setInterval(() => {
            getProgress (client.api, client.id, function (response){
              //remove extra precision to match with PHP standards
              LastSaveToDBTimestamp = LastSaveToDBTimestamp.toString();
              responseTimestamp = response.timestamp.toString();
              LastSaveToDBTimestamp = LastSaveToDBTimestamp.substring(0,responseTimestamp.length);

          
              if(Number(LastSaveToDBTimestamp)+1000 < Number(response.timestamp))
                client["Progress"] = response.data;

            });
          }, 4000);
  });

  //listen for incoming login through POST
  app.use(express.urlencoded({ extended: true }));
  app.post('/login', function(req, res,next) {
    var loginClient;
    for(i = 0; i < allUsers.length; i++)
      if(allUsers[i].id == req.body.socketID)
        loginClient = allUsers[i]; //only emit to user trying to login
    if(loginClient != undefined)
    login (req.body.uEmail, req.body.uPassword, function (req){
      if(req.status == "unsuccessful"){ //login failed
        loginClient.emit("loginUnsuccessful")
      } else { //logged in
        getStreamable (req.data, function (streamableReq){
          var images = new Array();

          var counter = 0;
          var counter2 = 0;
          streamableReq.data.forEach(vid => {
            counter2++;
            setTimeout(() => {
                fs.readFile(__dirname + "/media/" + vid.vidImagePath, function(err, data){ 
                counter++;
                images.push({ image: true, buffer: data });
                if(counter == streamableReq.data.length){
                  loginClient.emit("loginSuccess", req.data, streamableReq, images);
                }
              });
              }, 200+counter2*10);
          }); 

        });
      }
    });
  });
  //update client progress
  client.on('timeupdate', function(currentPos){
    for(i = 0; i < allClients.length; i++)
      if(allClients[i].api == client["api"] && allClients[i].vidID == client["vidID"] && currentPos != 0){
        allClients[i]["progress"] = currentPos;
      }

      
  });

  //sync tabs on pause
  client.on('pause', function(currentPos){
    for(i = 0; i < allClients.length; i++)
      if(allClients[i].api == client["api"] && allClients[i].vidID == client["vidID"] && allClients[i] != client)
        allClients[i].emit("pausesync", currentPos);
  });

  //sync tabs on resume
  client.on('resume', function(currentPos){
    for(i = 0; i < allClients.length; i++)
      if(allClients[i].api == client["api"] && allClients[i].vidID == client["vidID"] && allClients[i] != client)
        allClients[i].emit("resumesync", currentPos);
  });

  //Client close video stream
  client.on('closeVideoStream',function(socketID){
    var closeClient;
    for(i = 0; i < allClients.length; i++){
      if(allClients[i].id == socketID)
        closeClient = allClients[i];
    }
    clearInterval(saveProgressToDB);
    console.log("=== Client Stopped Playing: " + closeClient.vidPath + " - " + closeClient.api);
    console.log("= Saving Video Progress " + closeClient.progress);
    LastSaveToDBTimestamp = Date.now();
    setProgress (closeClient.api, closeClient.vidID, closeClient.progress, function (response){
    }); 
    closeClient.vidID = undefined;
  });


  client.on('disconnect', function() {
    clearInterval(retrieveProgressFromDB);
    clearInterval(saveProgressToDB);
    //save client progress to the database
    LastSaveToDBTimestamp = Date.now();
    console.log("= Saving Video Progress " + client.progress);
    setProgress (client.api, client.vidID, client.progress, function (){}); 

    var i = allClients.indexOf(client);
    allClients.splice(i, 1); //remove client from AUTHENTICATED array

    var i = allUsers.indexOf(client);
    allUsers.splice(i, 1); //remove client from USER array

    console.log("=== User disconnected: " + client.id + " COUNT: " + allUsers.length);
    if(allUsers.length == 0)
      console.log("=== NO USERS ===");
  });
});