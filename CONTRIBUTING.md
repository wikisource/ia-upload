Contributing
============

This file contains information for software developers working on IA Upload.

## Developing using Docker

1. Clone from GitHub: `git clone https://github.com/wikisource/ia-upload`
2. `cd ia-upload`
3. Create a `.env` file:
```
IAUPLOAD_PORT=8000
IAUPLOAD_DOCKER_UID=1000
IAUPLOAD_DOCKER_GID=100
```
and set the variable to match your system and the port you want.
4. Register an oAuth consumer on [Meta](https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration)
   with a callback of `http://localhost:8000/oauth/callback`
5. Edit `config.ini` to add your consumer key and secret
6. Build the Docker image: `docker-compose build`
7. Start the container: `docker-compose up -d`
8. Install dependencies: `docker-compose exec ia-upload composer install`
9. You can now
   1. browse to http://localhost:8000
   2. and enter the CLI with: `docker-compose exec ia-upload bash`
