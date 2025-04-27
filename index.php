<?php 
echo '<!DOCTYPE html>
<html>
   <head>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>
         #solar-system {
            background: url("./images/solar-system-9.png") center center;
            background-repeat: no-repeat;			  
            background-size: cover;
            content: "";
            position: static;
            animation: spin 25s linear infinite;
            width: 50vw;
            height: 50vw;
            z-index: -1; /* This makes sure solar system is in the background */
         }

         @keyframes spin {
            100% { transform: rotate(360deg); }
         }
         
         body {
            display: flex;
            align-items: center;
            justify-content: center;
            background: url("./images/background.png");
            height: 100vh;
            margin: 0;
         }

         .foreground-text {
            font-size: 2rem;
            color: white;
            font-weight: bold;
            z-index: 1; /* Ensures text appears above the solar system */
            position: absolute;
         }
      </style>
   </head>
   <body>
      <div class="foreground-text">Amey-version:3</div>
      <div id="solar-system"></div>
   </body>
</html>';