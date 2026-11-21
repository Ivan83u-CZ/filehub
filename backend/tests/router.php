<?php
if (($_SERVER['REQUEST_URI']??'')==='/_health') require __DIR__.'/../app/health.php';
else require __DIR__.'/../app/api.php';
