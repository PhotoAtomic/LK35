Sunricher SR1009FAWi / LK35 php WebService
==========================================

Introduction
------------
The WiFi LED Controller Sunricher SR1009FAWi aka LK35 is a receive only controller. It is not possible to get the current rgbw-values back from it.
This project introduces a web service to relay light-commands to the LED controller and persist the last known values in a json file.
The web service can return the stored last known rgbw-values to any client application.

Advantages
----------
You can show the current rgbw-values in any client that has access to the web service.
The LED controller is searched automatically via brodcast, but it must be in the same LAN segment.

Using the Code
--------------
You need an apache web server with PHP in the same LAN as your WiFi LED controller.
Configure a directory/location on your web server e.g. /led/ and copy all files to this folder.
Attention: The php engine must have write access to this folder because a json and a sync file are updated periodically.

Call the main file /led/index.html from your client.
You can also write an app tat will use the web service /led/led.php?... directly. 

License
-------
This article, along with any associated source code and files, is licensed under The MIT License

