    var socket = io.connect(window.location.href, {
      //  'forceNew': true

    });
    var formData;
    //Login to server using api key through POST
    $(document).ready(function(){
    $("#login").on("submit", function(){
        $(":submit").toggleClass("loginbtnAnimate");
        $(":submit").text("Logging in...");
        $(":submit").attr("disabled", true);
        document.getElementById("socketID").value = socket.id;
        formData = $('#login').serialize();
        $.ajax({
            url:'/login',
            type:'post',
            data: $('#login').serialize()
        });
        return false;
    });
    });

    socket.on("loginUnsuccessful",function(){
        $(":submit").toggleClass("loginbtnAnimate");
        $(":submit").text("Sign in");
        $(":submit").attr("disabled", false);
        createModal("Login Fail", "Please check your username / password.","");
    });

    //base 64decoder
    function b64(e){var t="";var n=new Uint8Array(e);var r=n.byteLength;for(var i=0;i<r;i++){t+=String.fromCharCode(n[i])}return window.btoa(t)}
    var apikey = false;

    socket.on("loginSuccess",function(uApiKey, streamableReq, images){
        $(".modal").remove();
        apikey = uApiKey;
        socket.emit("authenticated", uApiKey);
        $(".hero-posters").remove();
        $("#login").fadeOut("slow",function(){
            var hero_posters = document.createElement("div");
            hero_posters.classList.add("hero-posters");
            hero_posters.style.display = "none";
            for(i = 0; i < images.length; i++){            
                var single_splash_poster = document.createElement("img");
                var a = document.createElement("a");

                var att = document.createAttribute("data-path");
				att.value = streamableReq.data[i].vidPath;
                single_splash_poster.setAttributeNode(att);

                var att2 = document.createAttribute("data-id");
                att2.value = streamableReq.data[i].vidID;
                single_splash_poster.setAttributeNode(att2);
                
                a.addEventListener("click", function(a){
                    loadVideo(a);
                });

                single_splash_poster.classList.add("single-splash-poster");
                single_splash_poster.src = "data:image/png;base64,"+b64(images[i].buffer);
                a.appendChild(single_splash_poster)
                hero_posters.appendChild(a);
            }
           
            document.getElementsByTagName("body")[0].appendChild(hero_posters);

            $(hero_posters).fadeIn("slow");

        });    
    });

    function loadVideo(a){
        var path = a.target.getAttribute("data-path");
        var id = a.target.getAttribute("data-id");
        socket.emit("join", path, id);
    }

    socket.on('load', function(src, currentPos) {
        if(currentPos != 0){
            var pos = Number(currentPos);
            var minutes =  Math.floor(pos / 60);
            var seconds = pos - minutes * 60;
            seconds = seconds.toFixed(2);

            createVideoModal("","<video id='videoPlayer' controls src=''></video>","Continuing at: " + minutes + ":" + seconds);
            setTimeout(() => {
                $(".modal-footer h3").fadeOut("slow");
            }, 1500);
        } else {
            createVideoModal("","<video id='videoPlayer' controls src=''></video>","");
        }

        var vid = document.getElementById("videoPlayer");
        vid.currentTime = currentPos;
        vid.src = src;
        vid.play();

        var triggeredByServer = false;
        var lasttime = 0;

        //emit pause sync
        vid.onpause = function(){
            if (Date.now() - lasttime < 700) //safety net to prevent pause resume loops
                return;
            lasttime = Date.now();

            socket.emit("pause", vid.currentTime); 
        }
    
        //receive pause sync
        socket.on('pausesync',function(currentPos){
            vid.currentTime = currentPos;
            vid.pause();
        });
    
        //emit resume sync
        vid.onplay = function(){
            socket.emit("resume", vid.currentTime);
        }
    
        //receive resume sync
        socket.on('resumesync',function(currentPos){
        vid.currentTime = currentPos;
        vid.play();
        });
    
        //load video file and sync time from DB
        socket.on('load', function(src, currentPos) {
        vid.currentTime = currentPos;
        vid.src = src;
        });
    
        //sync video currentTime to server
        setInterval(function(){
            socket.emit('timeupdate', vid.currentTime);
        }, 100);

    });

        
    var reconnectAttempt;
    var reconnectTimeout;

    socket.on('bye', function(){
        createModal("Connection Lost", "The connection to the server has been lost.","Attempting to reconnect...");

        if(!reconnectAttempt)
        reconnectAttempt = setInterval(() => {
            socket.connect();
        }, 1000);

        if(!reconnectTimeout)
        reconnectTimeout = setTimeout(() => {
            document.getElementsByTagName("body")[0].innerHTML = "";
            createModal("Reconnect Failed","The server is unreachable, please try to refresh the page.","");
            clearInterval(reconnectAttempt);
        }, 15000);
    });
    
    socket.on("disconnect", function(){
        createModal("Connection Lost", "The connection to the server has been lost.","Attempting to reconnect...");

        if(!reconnectAttempt)
        reconnectAttempt = setInterval(() => {
            socket.connect();
        }, 1000);

        if(!reconnectTimeout)
        reconnectTimeout = setTimeout(() => {
            document.getElementsByTagName("body")[0].innerHTML = "";
            createModal("Reconnect Failed","The server is unreachable, please try to refresh the page.","");
            clearInterval(reconnectAttempt);
        }, 10000);
    })

    socket.on('connect',function(){

        clearTimeout(reconnectTimeout);
        clearInterval(reconnectAttempt);
        reconnectTimeout = undefined;
        reconnectAttempt = undefined;

        $(".modal").remove();

        //was logged in before server lost connection
        if(apikey != false){
         createModal("Authenticating","Logging in...","");
          var n = formData.indexOf("socketID");
          formData = formData.substring(0,n);
          formData = formData + "socketID="+socket.id;
            $.ajax({
                url:'/login',
                type:'post',
                data: formData
            });
        }
    })

function createModal(header, body, footer){

    $(".modal").remove();
    
    var modalParent = document.createElement("div");
    modalParent.classList.add("modal");
    modalParent.id = "cluedUPModal"
    
    var modal = document.createElement("div");
    modal.classList.add("modal-content");

    
    var modalHeader = document.createElement("div");
    modalHeader.classList.add("modal-header");
    
    var closebtn = document.createElement("span");
    closebtn.classList.add("close");
    closebtn.innerHTML = "&times;";
    
    var headerText = document.createElement("h2");
    headerText.innerHTML = header;
    
    modalHeader.appendChild(closebtn);
    modalHeader.appendChild(headerText);
    
    modal.appendChild(modalHeader);
    
    var modalBody = document.createElement("div");
    modalBody.classList.add("modal-body");
    
    var bodyText = document.createElement("p");
    bodyText.innerHTML = body;
    
    modalBody.appendChild(bodyText);
    modal.appendChild(modalBody);
    
    var modalFooter = document.createElement("div");
    modalFooter.classList.add("modal-footer");
    
    var footerText = document.createElement("h3");
    footerText.innerHTML = footer;
    
    modalFooter.appendChild(footerText);
    
    modal.appendChild(modalFooter);
    
    modalParent.appendChild(modal);
    
    document.getElementsByTagName("body")[0].appendChild(modalParent);
    
    var modal = document.getElementById('cluedUPModal');
    
    var span = document.getElementsByClassName("close")[0];
    
    span.onclick = function() {
        modal.style.display = "none";
        document.getElementsByTagName("body")[0].removeChild(modal);
    }
    
    window.onclick = function(event) {
    if (event.target == modal) {
        modal.style.display = "none";
        document.getElementsByTagName("body")[0].removeChild(modal);
        }
    }
    
    modal.style.display = "block";
}


function createVideoModal(header, body, footer){

    $(".videoModal").remove();
    
    var modalParent = document.createElement("div");
    modalParent.classList.add("modal");
    modalParent.classList.add("videoModal");
    modalParent.id = "cluedUPModal"
    
    var modal = document.createElement("div");
    modal.classList.add("modal-content");

    
    var modalHeader = document.createElement("div");
    modalHeader.classList.add("modal-header");
    
    var closebtn = document.createElement("span");
    closebtn.classList.add("close");
    closebtn.innerHTML = "&times;";
    
    var headerText = document.createElement("h2");
    headerText.innerHTML = header;
    
    modalHeader.appendChild(closebtn);
    modalHeader.appendChild(headerText);
    
    modal.appendChild(modalHeader);
    
    var modalBody = document.createElement("div");
    modalBody.classList.add("modal-body");
    
    var bodyText = document.createElement("div");
    bodyText.classList.add("bodyText");
    bodyText.innerHTML = body;
    
    modalBody.appendChild(bodyText);
    modal.appendChild(modalBody);
    
    var modalFooter = document.createElement("div");
    modalFooter.classList.add("modal-footer");
    
    var footerText = document.createElement("h3");
    footerText.innerHTML = footer;
    
    modalFooter.appendChild(footerText);
    
    modal.appendChild(modalFooter);
    
    modalParent.appendChild(modal);
    
    document.getElementsByTagName("body")[0].appendChild(modalParent);
    
    var modal = document.getElementById('cluedUPModal');
    
    var span = document.getElementsByClassName("close")[0];
    
    span.onclick = function() {
        document.getElementById("videoPlayer").pause();
        modal.style.display = "none";
        document.getElementsByTagName("body")[0].removeChild(modal);
        closeVideoStream();
    }
    
    window.onclick = function(event) {
    if (event.target == modal) {
        document.getElementById("videoPlayer").pause();
        modal.style.display = "none";
        document.getElementsByTagName("body")[0].removeChild(modal);
        closeVideoStream();
        }
    }
    
    modal.style.display = "block";
}

function closeVideoStream(){
    socket.emit("closeVideoStream", socket.id);
}