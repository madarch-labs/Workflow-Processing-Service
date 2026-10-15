# Mock Event Service: PHP built-in server + phpredis (the Swoole image already ships ext-redis)
FROM phpswoole/swoole:6.0-php8.3
WORKDIR /srv/mock
COPY mock/ ./
EXPOSE 9502
CMD ["php", "-S", "0.0.0.0:9502", "-t", ".", "mock_server.php"]
