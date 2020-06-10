Internet Archive Upload Tool
============================

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Tpt/ia-upload/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/Tpt/ia-upload/?branch=master)
[![Build Status](https://travis-ci.org/wikisource/ia-upload.svg?branch=master)](https://travis-ci.org/wikisource/ia-upload)
[![Dependency Status](https://www.versioneye.com/user/projects/58a64b614ca76f004ed471fa/badge.svg?style=flat-square)](https://www.versioneye.com/user/projects/58a64b614ca76f004ed471fa)

A small tool to import DjVu files from Internet Archive to Wikimedia Commons.
See it in operation at [tools.wmflabs.org/ia-upload/](https://tools.wmflabs.org/ia-upload/)
and read the documentation at [wikitech.wikimedia.org/wiki/Tool:IA_Upload](https://wikitech.wikimedia.org/wiki/Tool:IA_Upload).

## Prerequesites
The actual format conversions are done by the following external tools, called from within IA Upload:

1. [ImageMagick](https://www.imagemagick.org)
2. [DjVuLibre](https://sourceforge.net/p/djvu/)

## Installation

1. Clone from GitHub: `git clone https://github.com/wikisource/ia-upload` 
2. Install dependencies: `composer install`
3. Set up URL rewriting:
   * For Apache use the following in `public/.htacess`:

         RewriteEngine On
         RewriteCond %{REQUEST_FILENAME} !-f
         RewriteRule ^(.*)$ index.php/$1 [L]

   * For Lighttpd, use:

         url.rewrite-if-not-file += ( "(.*)" => "index.php/$0" )

4. Register an oAuth consumer on [Meta](https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration)
   with a callback to e.g. `http://localhost/ia-upload/public/oauth/callback` (i.e. ending in `oauth/callback`)
5. Edit `config.ini` to add your consumer key and secret
6. Make sure the `jobqueue` directory is writable by the web server
