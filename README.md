# Student Portal API Demo
Sydney Boys High School

## Overview

A simple demonstration of using the the Sydney Boys High School Student Portal API in PHP.

As available at https://apidemo.sydneyhigh.community

## Requirements

1. Any supported PHP version
1. [Composer](https://getcomposer.org/), the PHP package manager

## Instructions

1. Register your application on the Student Portal *My SBHS Apps* section. 
    * You will need to supply a *Redirect URI*, which is an address your user's browser will be directed to after your user authorises your app.<br /><br />
    For testing on your own computer this will just be ``http://localhost/`` or ``http://localhost:8000/``
    * Note the ``App ID`` and ``App Secret``
2. Clone this repository or download/extract the archive.
3. Install the dependencies using composer - ``composer install``
4. Create a ``.env`` file by copying the ``.env.example`` file. This file needs to contain the following info for your app:
   1. App ID (``PORTAL_API_CLIENT_ID``) 
   2. App Secret (``PORTAL_API_CLIENT_SECRET``)
   3. Your Redirect URI (``APP_REDIRECT_URI``)
5. Serve the app's ``public`` directory, eg using the PHP built-in webserver: 
```
php -S localhost -t public
```

You can now load the app in your browser.