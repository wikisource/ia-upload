Internet Archive Upload Tool
============================

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Tpt/ia-upload/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/Tpt/ia-upload/?branch=master)

A small tool based on Silex and Guzzle to import files from Internet Archive to Wikimedia Commons.

## Installation

1. Clone from GitHub: `git clone https://github.com/Tpt/ia-upload` 
2. Install dependences: `composer install`
3. Set up URL rewriting:
   * For Apache use the following in `web/.htacess`:

         RewriteEngine On
         RewriteCond %{REQUEST_FILENAME} !-f
         RewriteRule ^(.*)$ index.php/$1 [L]

   * For Lighttpd, use:

         url.rewrite-if-not-file += ( "(.*)" => "index.php/$0" )

4. Register an oAuth consumer on [Meta](https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration)
   with a callback to e.g. `http://localhost/ia-upload/web/oauth/callback` (i.e. ending in `oauth/callback`)
5. Edit `config.ini` to add your consumer key and secret
6. Make sure the `temp` directory is writable by the web server
