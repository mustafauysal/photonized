# Photonized

Photonized is an only-fly image resizer and optimizer that works on docker container. It's dead simple; you only need docker & docker-compose.

## Requirements

* [Docker](https://www.docker.com/)
* [docker-compose](https://docs.docker.com/compose/)


## Installation

```
git clone https://github.com/mustafauysal/photonized.git
cd photonized
docker-compose up -d
```

## Proxy Cache

Photonized exposes port 80 and 9001. If you don't want to use proxy caching, use 9001 instead of 80.

## Examples

**Proxy Cached resize:** http://127.0.0.1/mustafauysal.files.wordpress.com/2016/08/wapuu.jpg?w=200

**Resize without caching:** http://127.0.0.1:9001/mustafauysal.files.wordpress.com/2016/08/wapuu.jpg?w=200

**Crop:** http://127.0.0.1:9001/mustafauysal.files.wordpress.com/2016/08/wapuu.jpg?crop=0,100,150,50

## Docs

Detailed documentation is available on https://developer.wordpress.com/docs/photon/

## Credits
* [Photon](http://code.svn.wordpress.org/photon/) for the source code
* [jpegoptim](https://github.com/tjko/jpegoptim) for optimize/compress JPEG files
* [optipng](http://optipng.sourceforge.net/) for optimizing PNG images
* [pngquant](https://pngquant.org/) for lossy compression of PNG images
* [cwebp](https://developers.google.com/speed/webp/docs/cwebp)  WebP encoder 
