# Lumen Swoole

This project is used to speed up lumen framework with swoole extension.

## version support

has been used with ``lumen 5.7.x``

## install

change composer.json

```bash
composer require kyfjake/lumen-swoole @dev
```

## usage

```bash
./vendor/bin/lumen-server [options] [start|status|stop]
```

### Options

0. ``-d`` run as daemonize
1. ``-p port`` change listen port. default is **8080**
2. ``-h ip `` change listen ip. default is **127.0.0.1**
3. ``-n num `` change worker num to process http request. default is **8** 
4. ```-v``` verbose log
5. ``--queue_num num`` set lumen queue worker num. default is **0**, disable
6. ``--log_file`` change swoole log file path. default is **./swoole.log**
