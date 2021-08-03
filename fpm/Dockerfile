FROM php:7.3-fpm
RUN apt-get update && apt-get install -y \
        wget \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libgraphicsmagick1-dev \
    && docker-php-ext-install -j$(nproc) iconv \
    && docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
    && docker-php-ext-install -j$(nproc) gd \
    && pecl channel-update pecl.php.net  \
    && pecl install gmagick-2.0.6RC1

WORKDIR /tmp
RUN   wget https://github.com/tjko/jpegoptim/archive/RELEASE.1.4.6.tar.gz
RUN   zcat RELEASE.1.4.6.tar.gz | tar xf -
RUN   cd /tmp/jpegoptim-RELEASE.1.4.6 && ./configure  &&  make  &&  make strip && make install

RUN usermod -u 33 www-data
RUN usermod -G 33  www-data

WORKDIR "/srv/photon"