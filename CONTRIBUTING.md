Contributing
============

This file contains information for software developers working on IA Upload.

## Developing using Docker

1. Clone from GitHub: `git clone https://github.com/wikisource/ia-upload`
2. `cd ia-upload`
3. Install dependencies: `composer install`
4. Register an oAuth consumer on [Meta](https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration)
   with a callback of `http://localhost:8000/oauth/callback`
5. Edit `config.ini` to add your consumer key and secret
6. Build the Docker image: `docker build -t ia-upload .`
7. Run the Docker container: `docker run -it -v $PWD:/work -p8000:80 ia-upload`
8. You can now
   1. browse to http://localhost:8000
   2. and enter the CLI with: `docker exec <container ID or name> bash`
